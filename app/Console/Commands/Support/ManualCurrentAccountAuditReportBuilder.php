<?php

namespace App\Console\Commands\Support;

use App\Models\DadosFinanceiros;
use App\Models\Movement;
use App\Services\Financeiro\CurrentAccountService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ManualCurrentAccountAuditReportBuilder
{
    public function __construct(
        private readonly CurrentAccountService $currentAccountService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $userId = null): array
    {
        $query = DadosFinanceiros::query()
            ->with(['user:id,name,nome_completo,numero_socio'])
            ->where('conta_corrente_manual', '!=', 0);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $financialDataRows = $query
            ->orderByDesc('conta_corrente_manual')
            ->get();

        $userIds = $financialDataRows
            ->pluck('user_id')
            ->filter()
            ->values();

        $manualAdjustmentCounts = Movement::query()
            ->selectRaw('user_id, COUNT(*) as total')
            ->whereIn('user_id', $userIds)
            ->where('classificacao', 'receita')
            ->where('origem_tipo', 'manual')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $members = $financialDataRows->map(function (DadosFinanceiros $financialData) use ($manualAdjustmentCounts): array {
            $accountSummary = $this->currentAccountService->summarize([
                'user_id' => $financialData->user_id,
            ]);

            $amount = round((float) $financialData->conta_corrente_manual, 2);
            $manualAdjustmentMovementCount = (int) ($manualAdjustmentCounts[$financialData->user_id] ?? 0);
            $openInvoiceCount = count(Arr::get($accountSummary, 'breakdown.invoices', []));
            $openMovementCount = count(Arr::get($accountSummary, 'breakdown.movements', []));

            return [
                'user_id' => $financialData->user_id,
                'numero_socio' => $financialData->user?->numero_socio,
                'name' => $this->resolveMemberName($financialData),
                'value' => $amount,
                'value_sign' => $amount > 0 ? 'positive' : 'negative',
                'manual_adjustment_movement_count' => $manualAdjustmentMovementCount,
                'has_manual_adjustment_movements' => $manualAdjustmentMovementCount > 0,
                'open_invoice_count' => $openInvoiceCount,
                'open_movement_count' => $openMovementCount,
                'has_open_financial_items' => ($openInvoiceCount + $openMovementCount) > 0,
                'gross_debt' => round((float) ($accountSummary['gross_debt'] ?? 0), 2),
                'available_credit' => round((float) ($accountSummary['available_credit'] ?? 0), 2),
                'net_debt' => round((float) ($accountSummary['net_debt'] ?? 0), 2),
                'manual_account_balance' => round((float) ($accountSummary['manual_account_balance'] ?? 0), 2),
                'migration_recommendation' => $this->buildRecommendation(
                    $amount,
                    $manualAdjustmentMovementCount,
                    $openInvoiceCount,
                    $openMovementCount,
                ),
                'dry_run_migration_preview' => $this->buildMigrationPreview(
                    $financialData->user_id,
                    $this->resolveMemberName($financialData),
                    $amount,
                ),
            ];
        })->values();

        $positiveTotal = round((float) $members->where('value', '>', 0)->sum('value'), 2);
        $negativeTotal = round((float) $members->where('value', '<', 0)->sum('value'), 2);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => [
                'user_id' => $userId,
            ],
            'summary' => [
                'affected_members' => $members->count(),
                'positive_total' => $positiveTotal,
                'negative_total' => $negativeTotal,
                'net_legacy_total' => round($positiveTotal + $negativeTotal, 2),
                'members_with_manual_adjustments' => $members->where('has_manual_adjustment_movements', true)->count(),
                'members_with_open_financial_items' => $members->where('has_open_financial_items', true)->count(),
                'semantic_status' => $members->isEmpty()
                    ? 'no_legacy_manual_balance_found'
                    : 'manual_decision_required_before_any_commit',
            ],
            'semantics' => [
                'positive_value_meaning' => 'Nao assumido automaticamente. Pode representar divida do membro ou credito a favor; requer decisao manual antes de qualquer commit.',
                'negative_value_meaning' => 'Nao assumido automaticamente. Pode representar credito, acerto anterior ou convencao invertida; requer decisao manual antes de qualquer commit.',
                'migration_guard' => 'F3.4 apenas audita e prepara. A migracao real nao e executada automaticamente.',
            ],
            'members' => $members->all(),
        ];
    }

    private function resolveMemberName(DadosFinanceiros $financialData): string
    {
        return trim((string) ($financialData->user?->nome_completo ?: $financialData->user?->name)) ?: 'Sem nome';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMigrationPreview(string $userId, string $memberName, float $amount): array
    {
        $today = Carbon::today()->toDateString();
        $metadata = [
            'legacy_source' => 'dados_financeiros.conta_corrente_manual',
            'planned_origin' => 'legacy_manual_current_account',
            'original_value' => round($amount, 2),
            'migration_date' => $today,
            'user_id' => $userId,
            'status' => 'pending_manual_review',
        ];

        return [
            'eligible_for_commit' => false,
            'reason' => 'Semantica nao decidida em F3.4; preview apenas.',
            'movement_payload' => [
                'user_id' => $userId,
                'nome_manual' => $memberName,
                'classificacao' => 'receita',
                'tipo' => 'servico',
                'estado_pagamento' => 'pendente',
                'origem_tipo' => 'manual',
                'planned_origin_label' => 'legacy_manual_current_account',
                'data_emissao' => $today,
                'data_vencimento' => $today,
                'valor_total' => round(abs($amount), 2),
                'observacoes' => 'Migracao planeada de conta_corrente_manual. Metadata: ' . json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ],
            'guarantees' => [
                'never_create_payment' => true,
                'never_create_payment_allocation' => true,
                'never_create_fiscal_document_request' => true,
                'never_reconcile_bank' => true,
                'never_mark_as_paid_automatically' => true,
            ],
        ];
    }

    private function buildRecommendation(float $amount, int $manualAdjustmentMovementCount, int $openInvoiceCount, int $openMovementCount): string
    {
        $parts = [];

        $parts[] = $amount > 0
            ? 'Valor positivo: rever manualmente se representa divida do membro ou credito a favor.'
            : 'Valor negativo: rever manualmente se representa credito do membro ou acerto legado.';

        $parts[] = $manualAdjustmentMovementCount > 0
            ? 'Ja existem movimentos manuais auditaveis; comparar antes de migrar para evitar duplicacao.'
            : 'Nao ha movimentos manuais de ajuste identificados para este membro.';

        if (($openInvoiceCount + $openMovementCount) > 0) {
            $parts[] = 'Existem faturas/movimentos em aberto; exigir revisao humana antes de qualquer commit.';
        } else {
            $parts[] = 'Sem pendencias abertas no retrato atual do CurrentAccountService.';
        }

        return implode(' ', $parts);
    }
}