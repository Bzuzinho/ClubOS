<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\User;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BulkActiveAthleteMovementService
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array{created:int,skipped:int,eligible:int,movement_ids:list<string>}
     */
    public function create(array $data, string $batchKey): array
    {
        $batchKey = trim($batchKey);

        if ($batchKey === '') {
            throw ValidationException::withMessages([
                'batch_key' => 'Não foi possível identificar o lote do lançamento.',
            ]);
        }

        if (($data['classificacao'] ?? null) !== 'receita') {
            throw ValidationException::withMessages([
                'classificacao' => 'O lançamento para atletas ativos tem de ser uma receita/valor a cobrar ao atleta.',
            ]);
        }

        if (in_array($data['estado_pagamento'] ?? 'pendente', ['pago', 'parcial', 'pago_parcial'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'O lançamento coletivo tem de ser criado em aberto e liquidado depois pelo fluxo normal.',
            ]);
        }

        foreach ($data['items'] ?? [] as $item) {
            if (! empty($item['produto_id'])) {
                throw ValidationException::withMessages([
                    'items' => 'O lançamento coletivo para atletas não pode alterar stock.',
                ]);
            }
        }

        $athletes = User::query()
            ->with('userTypes:id,codigo,nome')
            ->where('estado', 'ativo')
            ->where('ativo_desportivo', true)
            ->get()
            ->filter(fn (User $user): bool => $this->memberTypeResolver->isAthlete($user))
            ->values();

        $created = 0;
        $skipped = 0;
        $movementIds = [];

        DB::transaction(function () use ($athletes, $data, $batchKey, &$created, &$skipped, &$movementIds): void {
            foreach ($athletes as $athlete) {
                $originId = sprintf('bulk-active-athletes:%s:%s', $batchKey, $athlete->id);

                $existing = Movement::query()
                    ->where('origem_tipo', 'manual')
                    ->where('origem_id', $originId)
                    ->first();

                if ($existing !== null) {
                    $skipped++;
                    $movementIds[] = (string) $existing->id;
                    continue;
                }

                $movement = Movement::query()->create([
                    'user_id' => $athlete->id,
                    'supplier_id' => null,
                    'classificacao' => 'receita',
                    'categoria' => $data['categoria'] ?? null,
                    'data_emissao' => $data['data_emissao'],
                    'data_vencimento' => $data['data_vencimento'],
                    'valor_total' => $data['valor_total'],
                    'estado_pagamento' => $data['estado_pagamento'] ?? 'pendente',
                    'estado_conciliacao' => 'nao_conciliado',
                    'centro_custo_id' => $data['centro_custo_id'],
                    'tipo' => $data['tipo'],
                    'origem_tipo' => 'manual',
                    'origem_id' => $originId,
                    'observacoes' => $data['observacoes'] ?? null,
                ]);

                foreach ($data['items'] as $item) {
                    MovementItem::query()->create([
                        'movimento_id' => $movement->id,
                        'descricao' => $item['descricao'],
                        'quantidade' => $item['quantidade'],
                        'valor_unitario' => $item['valor_unitario'],
                        'imposto_percentual' => $item['imposto_percentual'] ?? 0,
                        'total_linha' => $item['total_linha'],
                        'produto_id' => null,
                        'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'],
                        'fatura_id' => $item['fatura_id'] ?? null,
                    ]);
                }

                $created++;
                $movementIds[] = (string) $movement->id;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'eligible' => $athletes->count(),
            'movement_ids' => $movementIds,
        ];
    }
}
