<?php

namespace App\Services\Patrocinios;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorshipGoodsItem;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SponsorshipService
{
    public function __construct(
        private SponsorshipIntegrationService $integrationService,
        private SponsorshipFinancialGuardService $financialGuardService,
        private StockLedgerService $stockLedger,
    ) {
    }

    public function create(array $data, ?User $actor = null): array
    {
        $sponsorship = DB::transaction(function () use ($data, $actor) {
            $sponsor = Sponsor::query()->findOrFail($data['sponsor_id']);

            $sponsorship = Sponsorship::create([
                'codigo' => $this->generateCode(),
                'sponsor_name' => $sponsor->nome,
                'sponsor_id' => $sponsor->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'periodicity' => $data['periodicity'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->createMoneyItems($sponsorship, $data['money_items'] ?? []);
            $this->createGoodsItems($sponsorship, $data['goods_items'] ?? []);

            return $sponsorship;
        });

        $summary = $this->integrationService->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']), $actor);

        return [
            'sponsorship' => $sponsorship->fresh(['sponsor', 'supplier', 'costCenter', 'moneyItems.financialMovement', 'goodsItems.item', 'goodsItems.stockEntry', 'integrations']),
            'integration' => $summary,
        ];
    }

    public function update(Sponsorship $sponsorship, array $data, ?User $actor = null): array
    {
        if (in_array($sponsorship->status, ['fechado', 'cancelado'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Não é possível editar um patrocínio fechado ou cancelado.',
            ]);
        }

        DB::transaction(function () use ($sponsorship, $data, $actor) {
            $sponsor = Sponsor::query()->findOrFail($data['sponsor_id']);

            $sponsorship->update([
                'sponsor_name' => $sponsor->nome,
                'sponsor_id' => $sponsor->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'periodicity' => $data['periodicity'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor?->id,
            ]);

            $sponsorship->loadMissing(['moneyItems', 'goodsItems']);
            $this->syncMoneyItems($sponsorship, $data['money_items'] ?? []);
            $this->syncGoodsItems($sponsorship, $data['goods_items'] ?? []);
        });

        $summary = $this->integrationService->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']), $actor);

        return [
            'sponsorship' => $sponsorship->fresh(['sponsor', 'supplier', 'costCenter', 'moneyItems.financialMovement', 'goodsItems.item', 'goodsItems.stockEntry', 'integrations']),
            'integration' => $summary,
        ];
    }

    public function changeStatus(Sponsorship $sponsorship, string $status, ?User $actor = null): Sponsorship
    {
        if (!in_array($status, ['fechado', 'cancelado'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Estado de transição inválido.',
            ]);
        }

        $sponsorship->update([
            'status' => $status,
            'updated_by' => $actor?->id,
        ]);

        return $sponsorship->fresh(['moneyItems', 'goodsItems', 'integrations']);
    }

    public function delete(Sponsorship $sponsorship): void
    {
        DB::transaction(function () use ($sponsorship) {
            $sponsorship->refresh()->load(['moneyItems', 'goodsItems', 'integrations']);

            foreach ($sponsorship->goodsItems as $item) {
                if (!$item->stock_entry_id) {
                    continue;
                }

                $stockMovement = StockMovement::query()->find($item->stock_entry_id);
                if (!$stockMovement) {
                    continue;
                }

                $product = Product::query()->lockForUpdate()->find($stockMovement->article_id);
                if (!$product) {
                    continue;
                }

                if ($stockMovement->movement_type === 'entry') {
                    try {
                        $this->stockLedger->registerExit($product, (int) $stockMovement->quantity, [
                            'source_type' => 'sponsorship_goods_item_delete',
                            'source_id' => $item->id,
                            'notes' => 'Reversão de entrada de stock por eliminação de patrocínio',
                            'occurred_at' => now(),
                            'idempotency_key' => 'sponsorship-goods-delete-'.$sponsorship->id.'-'.$item->id,
                        ]);
                    } catch (\App\Exceptions\Inventario\InsufficientStockException) {
                        throw ValidationException::withMessages([
                            'sponsorship' => 'Não é possível apagar: o stock atual ficaria negativo ao reverter a integração logística.',
                        ]);
                    }
                }
            }

            foreach ($sponsorship->moneyItems as $item) {
                if (!$item->financial_movement_id) {
                    $item->delete();
                    continue;
                }

                if (!$this->financialGuardService->canDelete($item)) {
                    throw ValidationException::withMessages([
                        'sponsorship' => 'Este item de patrocínio já possui liquidação, conciliação ou documento financeiro associado e não pode ser removido diretamente.',
                    ]);
                }

                $movement = Movement::query()->find($item->financial_movement_id);
                if ($movement) {
                    MovementItem::query()->where('movimento_id', $movement->id)->delete();
                    $movement->delete();
                }

                SponsorshipIntegration::query()
                    ->where('sponsorship_id', $sponsorship->id)
                    ->where('integration_type', 'financial')
                    ->where('source_type', 'money_item')
                    ->where('source_id', $item->id)
                    ->delete();

                $item->delete();
            }

            $sponsorship->integrations()->delete();
            $sponsorship->moneyItems()->delete();
            $sponsorship->goodsItems()->delete();
            $sponsorship->delete();
        });
    }

    public function getDashboardSummary(): array
    {
        $activeStatuses = ['ativo', 'pendente', 'fechado'];

        return [
            'active_total' => Sponsorship::query()->where('status', 'ativo')->count(),
            'monetary_total' => (float) SponsorshipMoneyItem::query()
                ->whereHas('sponsorship', fn ($query) => $query->whereIn('status', $activeStatuses))
                ->sum('amount'),
            'goods_total' => Sponsorship::query()
                ->whereIn('type', ['goods', 'mixed'])
                ->where('status', 'ativo')
                ->count(),
            'integrations' => [
                'pending' => SponsorshipIntegration::query()->where('status', 'pending')->count(),
                'failed' => SponsorshipIntegration::query()->where('status', 'failed')->count(),
                'generated' => SponsorshipIntegration::query()->where('status', 'generated')->count(),
            ],
            'by_type' => [
                'money' => Sponsorship::query()->where('type', 'money')->count(),
                'goods' => Sponsorship::query()->where('type', 'goods')->count(),
                'mixed' => Sponsorship::query()->where('type', 'mixed')->count(),
            ],
            'latest' => Sponsorship::query()
                ->with(['sponsor:id,nome,tipo,estado', 'supplier:id,nome', 'costCenter:id,nome', 'moneyItems', 'goodsItems'])
                ->latest()
                ->limit(6)
                ->get(),
            'integration_block' => [
                'financial_movements' => SponsorshipMoneyItem::query()->where('integration_status', 'generated')->count(),
                'stock_entries' => SponsorshipGoodsItem::query()->where('integration_status', 'generated')->count(),
            ],
            'alerts' => SponsorshipIntegration::query()
                ->with('sponsorship:id,codigo,sponsor_name,title')
                ->whereIn('status', ['pending', 'failed'])
                ->latest('executed_at')
                ->limit(5)
                ->get(),
        ];
    }

    private function createMoneyItems(Sponsorship $sponsorship, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['description']) || (float) ($item['amount'] ?? 0) <= 0) {
                continue;
            }

            $sponsorship->moneyItems()->create([
                'description' => $item['description'],
                'amount' => $item['amount'],
                'expected_date' => $item['expected_date'] ?? null,
                'integration_status' => 'pending',
            ]);
        }
    }

    private function createGoodsItems(Sponsorship $sponsorship, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['item_name']) || (float) ($item['quantity'] ?? 0) <= 0) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            $unitValue = isset($item['unit_value']) ? (float) $item['unit_value'] : null;

            $sponsorship->goodsItems()->create([
                'item_name' => $item['item_name'],
                'item_id' => $item['item_id'] ?? null,
                'category' => $item['category'] ?? null,
                'quantity' => $quantity,
                'unit_value' => $unitValue,
                'total_value' => $item['total_value'] ?? ($unitValue !== null ? $quantity * $unitValue : null),
                'integration_status' => 'pending',
            ]);
        }
    }

    private function syncMoneyItems(Sponsorship $sponsorship, array $items): void
    {
        $existing = $sponsorship->moneyItems->keyBy('id');
        $submittedIds = collect($items)->pluck('id')->filter()->values();

        foreach ($existing as $existingItem) {
            if ($submittedIds->contains($existingItem->id)) {
                continue;
            }

            if ($this->financialGuardService->blockingReasons($existingItem) !== []) {
                throw ValidationException::withMessages([
                    'money_items' => 'Este item de patrocínio já possui liquidação, conciliação ou documento financeiro associado e não pode ser removido diretamente.',
                ]);
            }

            $existingItem->delete();
        }

        foreach ($items as $item) {
            if (empty($item['description']) || (float) ($item['amount'] ?? 0) <= 0) {
                continue;
            }

            $attributes = [
                'description' => $item['description'],
                'amount' => $item['amount'],
                'expected_date' => $item['expected_date'] ?? null,
            ];

            if (!empty($item['id']) && $existing->has($item['id'])) {
                $existingItem = $existing->get($item['id']);

                if ($this->financialGuardService->blockingReasons($existingItem) !== [] && $this->moneyItemChanged($existingItem, $attributes)) {
                    throw ValidationException::withMessages([
                        'money_items' => 'Este item de patrocínio já possui liquidação, conciliação ou documento financeiro associado e não pode ser alterado diretamente.',
                    ]);
                }

                if ($this->financialGuardService->blockingReasons($existingItem) === []) {
                    $existingItem->update([
                        ...$attributes,
                        'integration_status' => 'pending',
                        'integration_message' => null,
                    ]);
                }

                continue;
            }

            $sponsorship->moneyItems()->create([
                ...$attributes,
                'integration_status' => 'pending',
            ]);
        }
    }

    private function syncGoodsItems(Sponsorship $sponsorship, array $items): void
    {
        $existing = $sponsorship->goodsItems->keyBy('id');
        $submittedIds = collect($items)->pluck('id')->filter()->values();

        foreach ($existing as $existingItem) {
            if ($submittedIds->contains($existingItem->id)) {
                continue;
            }

            if ($this->isIntegratedGoodsItem($existingItem)) {
                throw ValidationException::withMessages([
                    'goods_items' => 'Não é possível remover linhas de artigos já integradas no stock.',
                ]);
            }

            $existingItem->delete();
        }

        foreach ($items as $item) {
            if (empty($item['item_name']) || (float) ($item['quantity'] ?? 0) <= 0) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            $unitValue = isset($item['unit_value']) ? (float) $item['unit_value'] : null;
            $attributes = [
                'item_name' => $item['item_name'],
                'item_id' => $item['item_id'] ?? null,
                'category' => $item['category'] ?? null,
                'quantity' => $quantity,
                'unit_value' => $unitValue,
                'total_value' => $item['total_value'] ?? ($unitValue !== null ? $quantity * $unitValue : null),
            ];

            if (!empty($item['id']) && $existing->has($item['id'])) {
                $existingItem = $existing->get($item['id']);

                if ($this->isIntegratedGoodsItem($existingItem) && $this->goodsItemChanged($existingItem, $attributes)) {
                    throw ValidationException::withMessages([
                        'goods_items' => 'Não é possível alterar linhas de artigos já integradas na logística.',
                    ]);
                }

                if (!$this->isIntegratedGoodsItem($existingItem)) {
                    $existingItem->update([
                        ...$attributes,
                        'integration_status' => 'pending',
                        'integration_message' => null,
                    ]);
                }

                continue;
            }

            $sponsorship->goodsItems()->create([
                ...$attributes,
                'integration_status' => 'pending',
            ]);
        }
    }

    private function generateCode(): string
    {
        $sequence = Sponsorship::withTrashed()->count() + 1;

        do {
            $code = 'PAT-'.now()->format('Y').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $exists = Sponsorship::withTrashed()->where('codigo', $code)->exists();
            $sequence++;
        } while ($exists);

        return $code;
    }

    private function isIntegratedMoneyItem(SponsorshipMoneyItem $item): bool
    {
        return $item->integration_status === 'generated' || !empty($item->financial_movement_id);
    }

    private function isIntegratedGoodsItem(SponsorshipGoodsItem $item): bool
    {
        return $item->integration_status === 'generated' || !empty($item->stock_entry_id);
    }

    private function moneyItemChanged(SponsorshipMoneyItem $item, array $attributes): bool
    {
        return $item->description !== $attributes['description']
            || (float) $item->amount !== (float) $attributes['amount']
            || optional($item->expected_date)->toDateString() !== $attributes['expected_date'];
    }

    private function goodsItemChanged(SponsorshipGoodsItem $item, array $attributes): bool
    {
        return $item->item_name !== $attributes['item_name']
            || $item->item_id !== $attributes['item_id']
            || $item->category !== $attributes['category']
            || (float) $item->quantity !== (float) $attributes['quantity']
            || (float) ($item->unit_value ?? 0) !== (float) ($attributes['unit_value'] ?? 0)
            || (float) ($item->total_value ?? 0) !== (float) ($attributes['total_value'] ?? 0);
    }
}
