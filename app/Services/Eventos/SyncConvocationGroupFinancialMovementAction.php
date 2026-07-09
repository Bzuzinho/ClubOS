<?php

namespace App\Services\Eventos;

use App\Models\ConvocationGroup;
use App\Models\Movement;
use App\Models\MovementItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncConvocationGroupFinancialMovementAction
{
    public const BLOCK_MESSAGE = 'Esta convocatória já possui liquidação, conciliação ou documento financeiro associado e os dados com impacto financeiro não podem ser alterados diretamente.';

    public function __construct(
        private readonly ConvocationGroupFinancialCalculator $calculator,
        private readonly ConvocationGroupFinancialGuardService $guardService,
    ) {
    }

    public function execute(ConvocationGroup $group): ?Movement
    {
        return DB::transaction(function () use ($group): ?Movement {
            $lockedGroup = ConvocationGroup::query()
                ->lockForUpdate()
                ->findOrFail($group->id);

            $result = $this->calculator->calculate($lockedGroup);
            $total = (float) ($result['total'] ?? 0);
            $items = $result['items'] ?? [];

            $movement = $this->resolveCanonicalMovement($lockedGroup);

            if ($total <= 0.009 || $items === []) {
                if ($movement && !$this->guardService->canDelete($lockedGroup)) {
                    throw ValidationException::withMessages([
                        'convocation_group' => self::BLOCK_MESSAGE,
                    ]);
                }

                if ($movement) {
                    MovementItem::query()->where('movimento_id', $movement->id)->delete();
                    $movement->delete();
                }

                $lockedGroup->updateQuietly([
                    'movimento_id' => null,
                    'valor_inscricao_calculado' => round(max($total, 0), 2),
                ]);

                return null;
            }

            if ($movement && !$this->guardService->canMutate($lockedGroup)) {
                throw ValidationException::withMessages([
                    'convocation_group' => self::BLOCK_MESSAGE,
                ]);
            }

            $eventDate = Carbon::parse($lockedGroup->data_criacao ?? $lockedGroup->created_at ?? now());
            $dueDate = optional($lockedGroup->evento)->data_inicio
                ? Carbon::parse($lockedGroup->evento->data_inicio)
                : $eventDate->copy();

            $movementPayload = [
                'user_id' => null,
                'nome_manual' => 'Convocatoria ' . ($result['event_title'] ?? ''),
                'classificacao' => 'despesa',
                'data_emissao' => $eventDate->toDateString(),
                'data_vencimento' => $dueDate->toDateString(),
                'valor_total' => round(abs($total), 2),
                'estado_pagamento' => $movement?->estado_pagamento ?: 'pendente',
                'centro_custo_id' => $result['event_cost_center_id'] ?? null,
                'tipo' => $result['movement_type'] ?? 'outro',
                'origem_tipo' => 'convocation_group',
                'origem_id' => $lockedGroup->id,
                'observacoes' => 'Convocatoria (' . ((int) ($result['athlete_count'] ?? 0)) . ' atletas)',
            ];

            if (!$movement) {
                $movement = Movement::query()->create($movementPayload);
            } else {
                $movement->update($movementPayload);
            }

            MovementItem::query()->where('movimento_id', $movement->id)->delete();
            foreach ($items as $item) {
                MovementItem::query()->create([
                    'movimento_id' => $movement->id,
                    'descricao' => (string) ($item['descricao'] ?? 'Convocatoria'),
                    'valor_unitario' => round(abs((float) ($item['valor_unitario'] ?? 0)), 2),
                    'quantidade' => max(1, (int) ($item['quantidade'] ?? 1)),
                    'imposto_percentual' => round((float) ($item['imposto_percentual'] ?? 0), 2),
                    'total_linha' => round(abs((float) ($item['total_linha'] ?? 0)), 2),
                    'centro_custo_id' => $item['centro_custo_id'] ?? null,
                ]);
            }

            $lockedGroup->updateQuietly([
                'movimento_id' => $movement->id,
                'valor_inscricao_calculado' => round(abs($total), 2),
            ]);

            return $movement->fresh();
        });
    }

    private function resolveCanonicalMovement(ConvocationGroup $group): ?Movement
    {
        if ($group->movimento_id) {
            $byReference = Movement::query()->find($group->movimento_id);
            if ($byReference && (string) $byReference->origem_tipo === 'convocation_group' && (string) $byReference->origem_id === (string) $group->id) {
                return $byReference;
            }
        }

        return Movement::query()
            ->where('origem_tipo', 'convocation_group')
            ->where('origem_id', $group->id)
            ->orderByDesc('created_at')
            ->first();
    }
}
