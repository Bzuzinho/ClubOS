<?php

namespace App\Services\Logistica;

use App\Contracts\Logistica\SportsLogisticsGateway;
use App\Contracts\Logistica\SportsLogisticsRequest;
use App\Contracts\Logistica\SportsLogisticsRequestResult;
use App\Models\LogisticsRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsLogisticsGatewayService implements SportsLogisticsGateway
{
    public function __construct(
        private readonly CanonicalProductStockService $stockService,
        private readonly CreateLogisticsRequestAction $createRequestAction,
    ) {
    }

    public function inspectAvailability(array $articleIds): array
    {
        $ids = collect($articleIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        return Product::query()
            ->whereIn('id', $ids)
            ->orderBy('nome')
            ->get()
            ->map(function (Product $product): array {
                $tracksStock = $product->tracks_stock;
                $available = $tracksStock ? $this->stockService->availableStock($product) : null;

                return [
                    'article_id' => (string) $product->id,
                    'name' => (string) $product->nome,
                    'active' => (bool) $product->ativo,
                    'allow_request' => (bool) $product->allow_request,
                    'allow_loan' => (bool) $product->allow_loan,
                    'tracks_stock' => $tracksStock,
                    'available_quantity' => $available,
                    'is_available' => (bool) $product->ativo
                        && (bool) $product->allow_request
                        && (! $tracksStock || (int) $available > 0),
                ];
            })
            ->values()
            ->all();
    }

    public function requestClubEquipment(SportsLogisticsRequest $request): SportsLogisticsRequestResult
    {
        try {
            return DB::transaction(function () use ($request): SportsLogisticsRequestResult {
                $key = $request->idempotencyKey();
                $existing = LogisticsRequest::query()
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->resultFor($existing, true);
                }

                $items = collect($request->items)->values();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => 'O pedido desportivo à Logística precisa de pelo menos um artigo.',
                    ]);
                }

                $products = Product::query()
                    ->whereIn('id', $items->pluck('article_id')->filter())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn (Product $product) => (string) $product->id);

                foreach ($items as $index => $item) {
                    $product = $products->get((string) ($item['article_id'] ?? ''));
                    if (! $product || ! $product->ativo || ! $product->allow_request) {
                        throw ValidationException::withMessages([
                            "items.$index.article_id" => 'O artigo não está disponível para requisição logística.',
                        ]);
                    }
                }

                // Recheck after the product locks. Requests with the same source/version
                // normally touch the same products, so this closes the common race before INSERT.
                $existing = LogisticsRequest::query()
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing) {
                    return $this->resultFor($existing, true);
                }

                $actor = $request->actorId ? User::query()->find($request->actorId) : null;
                $created = $this->createRequestAction->execute([
                    'requester_user_id' => $request->requesterUserId,
                    'requester_name_snapshot' => $request->requesterNameSnapshot,
                    'requester_area' => $request->requesterArea,
                    'requester_type' => $request->requesterType,
                    'items' => $items->all(),
                    'notes' => $request->notes,
                    'source_type' => $request->sourceType,
                    'source_id' => $request->sourceId,
                    'idempotency_key' => $key,
                    'allow_overdraw' => false,
                ], $actor);

                return $this->resultFor($created, false);
            });
        } catch (QueryException $exception) {
            // The unique key is the final authority for less common races where
            // concurrent retries do not lock the same product set.
            $existing = LogisticsRequest::query()
                ->where('idempotency_key', $request->idempotencyKey())
                ->first();

            if ($existing) {
                return $this->resultFor($existing, true);
            }

            throw $exception;
        }
    }

    private function resultFor(LogisticsRequest $request, bool $reused): SportsLogisticsRequestResult
    {
        return new SportsLogisticsRequestResult(
            requestId: (string) $request->id,
            status: (string) $request->status,
            totalAmount: (float) $request->total_amount,
            reused: $reused,
        );
    }
}
