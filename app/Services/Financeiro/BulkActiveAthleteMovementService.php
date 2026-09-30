<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsMemberStatusResolver;
use Illuminate\Support\Facades\DB;

final class BulkActiveAthleteMovementService
{
    private const ORIGIN_PREFIX = 'bulk-active-athletes';

    public function __construct(
        private readonly SportsMemberStatusResolver $sportsMemberStatusResolver,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /**
     * Create one receivable movement per currently active athlete.
     *
     * The batch reference is intentionally stable: rerunning the same reference
     * creates charges only for active athletes that do not already have one.
     *
     * @param array<string,mixed> $data
     * @return array{
     *   club_id:string,
     *   bulk_reference:string,
     *   eligible_count:int,
     *   created_count:int,
     *   skipped_count:int,
     *   created_movement_ids:list<string>
     * }
     */
    public function create(array $data): array
    {
        $bulkReference = trim((string) $data['bulk_reference']);
        $clubId = $this->clubContext->id();

        $items = collect($data['items'])
            ->map(function (array $item) use ($data): array {
                $quantity = (int) $item['quantidade'];
                $unitValue = round((float) $item['valor_unitario'], 2);
                $taxRate = round((float) ($item['imposto_percentual'] ?? 0), 4);
                $lineTotal = round($unitValue * $quantity * (1 + ($taxRate / 100)), 2);

                return [
                    'descricao' => trim((string) $item['descricao']),
                    'quantidade' => $quantity,
                    'valor_unitario' => $unitValue,
                    'imposto_percentual' => $taxRate,
                    'total_linha' => $lineTotal,
                    'centro_custo_id' => $data['centro_custo_id'],
                ];
            })
            ->values();

        $total = round((float) $items->sum('total_linha'), 2);

        return DB::transaction(function () use ($data, $bulkReference, $clubId, $items, $total): array {
            // Lock the candidate member rows so concurrent retries of the same
            // batch cannot create duplicate per-athlete charges.
            $activeMembers = User::query()
                ->where('estado', 'ativo')
                ->with([
                    'userTypes:id,codigo,nome',
                    'athleteSportsData',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $eligibleAthletes = $activeMembers
                ->filter(fn (User $user): bool => $this->sportsMemberStatusResolver->isActiveAthlete($user))
                ->values();

            $createdIds = [];
            $skipped = 0;

            foreach ($eligibleAthletes as $athlete) {
                $originId = $this->originId($clubId, $bulkReference, (string) $athlete->id);

                $alreadyExists = Movement::query()
                    ->where('origem_tipo', 'manual')
                    ->where('origem_id', $originId)
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;

                    continue;
                }

                $movement = Movement::query()->create([
                    'user_id' => $athlete->id,
                    'classificacao' => 'receita',
                    'categoria' => $data['categoria'] ?? null,
                    'data_emissao' => $data['data_emissao'],
                    'data_vencimento' => $data['data_vencimento'],
                    'valor_total' => $total,
                    'estado_pagamento' => 'pendente',
                    'estado_conciliacao' => 'nao_conciliado',
                    'centro_custo_id' => $data['centro_custo_id'],
                    'tipo' => $data['tipo'],
                    'origem_tipo' => 'manual',
                    'origem_id' => $originId,
                    'observacoes' => $data['observacoes'] ?? null,
                ]);

                foreach ($items as $item) {
                    MovementItem::query()->create([
                        'movimento_id' => $movement->id,
                        ...$item,
                    ]);
                }

                $createdIds[] = (string) $movement->id;
            }

            return [
                'club_id' => $clubId,
                'bulk_reference' => $bulkReference,
                'eligible_count' => $eligibleAthletes->count(),
                'created_count' => count($createdIds),
                'skipped_count' => $skipped,
                'created_movement_ids' => $createdIds,
            ];
        }, 3);
    }

    private function originId(string $clubId, string $bulkReference, string $userId): string
    {
        return implode(':', [
            self::ORIGIN_PREFIX,
            $clubId,
            $bulkReference,
            $userId,
        ]);
    }
}
