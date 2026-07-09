<?php

namespace App\Services\Patrocinios;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Sponsorship;
use App\Models\SponsorshipGoodsItem;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\User;
use App\Services\Logistica\RegisterStockMovementAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SponsorshipIntegrationService
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction,
        private SponsorshipFinancialGuardService $financialGuardService
    ) {
    }

    public function syncForSponsorship(Sponsorship $sponsorship, ?User $actor = null): array
    {
        $summary = [
            'generated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $sponsorship->loadMissing(['moneyItems', 'goodsItems']);

        foreach ($sponsorship->moneyItems as $item) {
            $result = $this->syncMoneyItem($sponsorship, $item);
            $summary[$result] += 1;
        }

        foreach ($sponsorship->goodsItems as $item) {
            $result = $this->syncGoodsItem($sponsorship, $item, $actor);
            $summary[$result] += 1;
        }

        return $summary;
    }

    public function getConsolidatedStatus(Sponsorship $sponsorship): string
    {
        $statuses = collect($sponsorship->moneyItems)
            ->pluck('integration_status')
            ->merge(collect($sponsorship->goodsItems)->pluck('integration_status'))
            ->filter()
            ->values();

        if ($statuses->contains('failed')) {
            return 'failed';
        }

        if ($statuses->contains('pending') || $statuses->isEmpty()) {
            return 'pending';
        }

        return 'generated';
    }

    private function syncMoneyItem(Sponsorship $sponsorship, SponsorshipMoneyItem $item): string
    {
        try {
            return DB::transaction(function () use ($sponsorship, $item): string {
                $lockedSponsorship = Sponsorship::query()->lockForUpdate()->findOrFail($sponsorship->id);
                $lockedItem = SponsorshipMoneyItem::query()->lockForUpdate()->findOrFail($item->id);

                $existingIntegration = SponsorshipIntegration::query()
                    ->where('sponsorship_id', $lockedSponsorship->id)
                    ->where('integration_type', 'financial')
                    ->where('source_type', 'money_item')
                    ->where('source_id', $lockedItem->id)
                    ->lockForUpdate()
                    ->latest('created_at')
                    ->first();

                $movement = $this->resolveCanonicalMovement($lockedItem);
                $payload = $this->movementPayload($lockedSponsorship, $lockedItem, $movement);

                if ($movement) {
                    if (!$this->financialGuardService->canMutate($lockedItem)) {
                        throw ValidationException::withMessages([
                            'money_items' => 'Este item de patrocínio já possui liquidação, conciliação ou documento financeiro associado e não pode ser alterado diretamente.',
                        ]);
                    }

                    if ($this->movementHasChanges($movement, $payload)) {
                        $movement->update($payload);
                    }
                } else {
                    $movement = Movement::query()->create($payload);
                }

                $movement->items()->delete();
                $this->persistMovementItems($movement, $lockedItem, $lockedSponsorship);

                $lockedItem->update([
                    'financial_movement_id' => $movement->id,
                    'integration_status' => 'generated',
                    'integration_message' => 'Movimento financeiro criado com sucesso.',
                ]);

                $integration = $existingIntegration ?: new SponsorshipIntegration();
                $integration->fill([
                    'sponsorship_id' => $lockedSponsorship->id,
                    'integration_type' => 'financial',
                    'source_type' => 'money_item',
                    'source_id' => $lockedItem->id,
                    'target_module' => 'financeiro',
                    'target_table' => 'movements',
                    'target_record_id' => $movement->id,
                    'status' => 'generated',
                    'message' => 'Movimento financeiro criado automaticamente.',
                    'executed_at' => now(),
                ]);
                $integration->save();

                return 'generated';
            });
        } catch (Throwable $exception) {
            Log::error('Falha ao integrar item monetário de patrocínio.', [
                'sponsorship_id' => $sponsorship->id,
                'money_item_id' => $item->id,
                'message' => $exception->getMessage(),
            ]);

            $message = $exception->getMessage();

            $item->update([
                'integration_status' => 'failed',
                'integration_message' => $message,
            ]);

            SponsorshipIntegration::query()
                ->where('sponsorship_id', $sponsorship->id)
                ->where('integration_type', 'financial')
                ->where('source_type', 'money_item')
                ->where('source_id', $item->id)
                ->latest('created_at')
                ->first()?->update([
                    'status' => 'failed',
                    'message' => $message,
                    'executed_at' => now(),
                ]);

            return 'failed';
        }
    }

    protected function resolveCanonicalMovement(SponsorshipMoneyItem $item): ?Movement
    {
        if ($item->financial_movement_id) {
            $linkedMovement = Movement::query()->find($item->financial_movement_id);

            if ($linkedMovement && (string) $linkedMovement->origem_tipo === 'sponsorship_money_item' && (string) $linkedMovement->origem_id === (string) $item->id) {
                return $linkedMovement;
            }
        }

        return Movement::query()
            ->where('origem_tipo', 'sponsorship_money_item')
            ->where('origem_id', $item->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    protected function movementPayload(Sponsorship $sponsorship, SponsorshipMoneyItem $item, ?Movement $movement = null): array
    {
        $movementDate = $item->expected_date?->toDateString() ?? $sponsorship->start_date?->toDateString() ?? now()->toDateString();

        return [
            'user_id' => null,
            'nome_manual' => $sponsorship->sponsor_name,
            'classificacao' => 'receita',
            'data_emissao' => $movementDate,
            'data_vencimento' => $movementDate,
            'valor_total' => round(abs((float) $item->amount), 2),
            'estado_pagamento' => $movement?->estado_pagamento ?: 'pendente',
            'centro_custo_id' => $sponsorship->cost_center_id,
            'tipo' => 'patrocinio',
            'origem_tipo' => 'sponsorship_money_item',
            'origem_id' => $item->id,
            'observacoes' => $sponsorship->codigo.' - '.$item->description,
        ];
    }

    protected function movementHasChanges(Movement $movement, array $payload): bool
    {
        return (string) $movement->nome_manual !== (string) $payload['nome_manual']
            || (string) $movement->classificacao !== (string) $payload['classificacao']
            || optional($movement->data_emissao)->toDateString() !== $payload['data_emissao']
            || optional($movement->data_vencimento)->toDateString() !== $payload['data_vencimento']
            || abs((float) $movement->valor_total - (float) $payload['valor_total']) > 0.009
            || (string) $movement->estado_pagamento !== (string) $payload['estado_pagamento']
            || (string) $movement->centro_custo_id !== (string) ($payload['centro_custo_id'] ?? null)
            || (string) $movement->tipo !== (string) $payload['tipo']
            || (string) $movement->origem_tipo !== (string) $payload['origem_tipo']
            || (string) $movement->origem_id !== (string) $payload['origem_id']
            || (string) ($movement->observacoes ?? '') !== (string) ($payload['observacoes'] ?? '');
    }

    protected function persistMovementItems(Movement $movement, SponsorshipMoneyItem $item, Sponsorship $sponsorship): void
    {
        MovementItem::query()->create([
            'movimento_id' => $movement->id,
            'descricao' => $item->description,
            'quantidade' => 1,
            'valor_unitario' => round(abs((float) $item->amount), 2),
            'imposto_percentual' => 0,
            'total_linha' => round(abs((float) $item->amount), 2),
            'centro_custo_id' => $sponsorship->cost_center_id,
        ]);
    }

    private function syncGoodsItem(Sponsorship $sponsorship, SponsorshipGoodsItem $item, ?User $actor = null): string
    {
        if ($item->stock_entry_id && $item->integration_status === 'generated') {
            return 'skipped';
        }

        $existingIntegration = SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('integration_type', 'stock')
            ->where('source_type', 'goods_item')
            ->where('source_id', $item->id)
            ->where('status', 'generated')
            ->latest('executed_at')
            ->first();

        if ($existingIntegration?->target_record_id && !$item->stock_entry_id) {
            $item->update([
                'stock_entry_id' => $existingIntegration->target_record_id,
                'integration_status' => 'generated',
                'integration_message' => 'Entrada de stock reconciliada a partir do histórico.',
            ]);

            return 'skipped';
        }

        $integration = SponsorshipIntegration::create([
            'sponsorship_id' => $sponsorship->id,
            'integration_type' => 'stock',
            'source_type' => 'goods_item',
            'source_id' => $item->id,
            'target_module' => 'logistica',
            'target_table' => 'stock_movements',
            'status' => 'pending',
        ]);

        try {
            if (!$item->item_id) {
                throw new \RuntimeException('O artigo tem de estar associado a um registo existente de inventário para gerar entrada de stock.');
            }

            if ((float) $item->quantity !== (float) (int) $item->quantity) {
                throw new \RuntimeException('A quantidade para integração em stock tem de ser inteira no modelo atual de logística.');
            }

            $stockMovement = $this->registerStockMovementAction->execute([
                'article_id' => $item->item_id,
                'movement_type' => 'entry',
                'quantity' => (int) $item->quantity,
                'reference_type' => 'sponsorship_goods_item',
                'reference_id' => $item->id,
                'unit_cost' => $item->unit_value,
                'notes' => 'Entrada de stock gerada automaticamente por patrocínio '.$sponsorship->codigo,
            ], $actor);

            $item->update([
                'stock_entry_id' => $stockMovement->id,
                'integration_status' => 'generated',
                'integration_message' => 'Entrada de stock criada com sucesso.',
            ]);

            $integration->update([
                'target_record_id' => $stockMovement->id,
                'status' => 'generated',
                'message' => 'Entrada de stock criada automaticamente.',
                'executed_at' => now(),
            ]);

            return 'generated';
        } catch (Throwable $exception) {
            Log::error('Falha ao integrar item em géneros de patrocínio.', [
                'sponsorship_id' => $sponsorship->id,
                'goods_item_id' => $item->id,
                'message' => $exception->getMessage(),
            ]);

            $message = $exception->getMessage();

            $item->update([
                'integration_status' => 'failed',
                'integration_message' => $message,
            ]);

            $integration->update([
                'status' => 'failed',
                'message' => $message,
                'executed_at' => now(),
            ]);

            return 'failed';
        }
    }
}