<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Collection;

final class PendingMonthlyFeeRequirementAuditor
{
    public const VERSION = 'f2-3-pending-monthly-fee-requirements-v1';

    public function __construct(
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $userId = null): array
    {
        $cases = [];

        $users = $this->resolveScopeUsers($userId);
        foreach ($users as $user) {
            $cases[] = $this->buildCase($user);
        }

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'mode' => $userId !== null ? 'single_user' : 'missing_required',
                'user' => $userId,
            ],
            'summary' => [
                'total_cases' => count($cases),
                'missing_required_count' => $this->countByClassification($cases, 'missing_required'),
                'not_required_count' => $this->countByClassification($cases, 'not_required'),
                'resolved_monthly_fee_present_count' => $this->countByClassification($cases, 'resolved_monthly_fee_present'),
            ],
            'cases' => $cases,
        ];
    }

    /**
     * @return Collection<int,User>
     */
    private function resolveScopeUsers(?string $userId): Collection
    {
        $query = User::query()
            ->with([
                'dadosFinanceiros:id,user_id,mensalidade_id',
                'centrosCusto:id',
                'userTypes:id,codigo,nome',
            ])
            ->select([
                'id',
                'perfil',
                'estado',
                'ativo_desportivo',
                'tipo_membro',
            ])
            ->orderBy('id');

        if ($userId !== null) {
            return $query->whereKey($userId)->get();
        }

        return $query
            ->get()
            ->filter(fn (User $user): bool => $this->isMissingRequiredInCanonicalAudit($user))
            ->values();
    }

    private function isMissingRequiredInCanonicalAudit(User $user): bool
    {
        $diagnostic = $this->memberMonthlyFeeResolver->detectDivergence($user);
        $canonicalId = $diagnostic['canonical_monthly_fee_id'] ?? null;
        $legacyId = $diagnostic['legacy_monthly_fee_id'] ?? null;
        $eligibility = $this->memberMonthlyFeeEligibilityService->evaluate($user);

        return $canonicalId === null
            && $legacyId === null
            && (bool) ($eligibility['should_have_monthly_fee'] ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCase(User $user): array
    {
        $monthlyFeeDiagnostic = $this->memberMonthlyFeeResolver->detectDivergence($user);
        $costCenter = $this->memberCostCenterResolver->resolveForUser($user);

        $eligibility = $this->memberMonthlyFeeEligibilityService->evaluate($user);
        $financialHistory = $this->resolveFinancialHistory($user);

        $case = [
            'user_id' => (string) $user->id,
            'operational_state' => [
                'perfil' => $this->normalizeNullableString($user->perfil),
                'estado' => $this->normalizeNullableString($user->estado),
                'ativo_desportivo' => (bool) $user->ativo_desportivo,
            ],
            'member_types' => [
                'resolved_member_types' => is_array($eligibility['member_types'] ?? null)
                    ? $eligibility['member_types']
                    : [],
            ],
            'monthly_fee' => [
                'canonical_monthly_fee_id' => $monthlyFeeDiagnostic['canonical_monthly_fee_id'] ?? null,
                'legacy_monthly_fee_id' => $monthlyFeeDiagnostic['legacy_monthly_fee_id'] ?? null,
                'resolved_monthly_fee_id' => $monthlyFeeDiagnostic['resolved_monthly_fee_id'] ?? null,
                'dados_financeiros_exists' => $user->dadosFinanceiros !== null,
            ],
            'cost_centers' => [
                'canonical_ids' => $costCenter['canonical']['ids'] ?? [],
                'has_cost_center' => !empty($costCenter['canonical']['ids'] ?? []),
            ],
            'eligibility' => $eligibility,
            'financial_history' => $financialHistory,
        ];

        $classification = $this->classifyCase($case);

        $case['classification'] = $classification['classification'];
        $case['reason_codes'] = $this->uniqueReasonCodes(array_merge(
            $classification['reason_codes'],
            is_array($eligibility['reason_codes'] ?? null) ? $eligibility['reason_codes'] : [],
        ));
        $case['recommendation'] = $classification['recommendation'];

        return $case;
    }

    /**
     * @param array<string,mixed> $case
     * @return array{classification:string,reason_codes:list<string>,recommendation:string}
     */
    private function classifyCase(array $case): array
    {
        $eligibility = is_array($case['eligibility'] ?? null) ? $case['eligibility'] : [];
        $monthlyFee = is_array($case['monthly_fee'] ?? null) ? $case['monthly_fee'] : [];

        $shouldHave = (bool) ($eligibility['should_have_monthly_fee'] ?? false);
        $hasResolvedMonthlyFee = ($monthlyFee['resolved_monthly_fee_id'] ?? null) !== null;

        if ($hasResolvedMonthlyFee) {
            return [
                'classification' => 'resolved_monthly_fee_present',
                'reason_codes' => $this->uniqueReasonCodes([
                    'resolved_monthly_fee_present_outside_pending_scope',
                ]),
                'recommendation' => 'Sem acao necessaria; o membro ja possui mensalidade resolvida.',
            ];
        }

        if ($shouldHave) {
            return [
                'classification' => 'missing_required',
                'reason_codes' => $this->uniqueReasonCodes([
                    'missing_required',
                ]),
                'recommendation' => 'Submeter para decisao funcional manual de atribuicao de plano sem auto-escrita nesta sprint.',
            ];
        }

        return [
            'classification' => 'not_required',
            'reason_codes' => $this->uniqueReasonCodes([
                'not_required',
            ]),
            'recommendation' => 'Sem acao automatica; o membro nao e elegivel para obrigacao de mensalidade.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveFinancialHistory(User $user): array
    {
        $query = Invoice::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'mensalidade')
            ->select([
                'id',
                'mes',
                'data_emissao',
                'data_fatura',
                'data_vencimento',
                'valor_total',
                'estado_pagamento',
                'origem_tipo',
                'origem_id',
            ]);

        $total = (clone $query)->count();

        $first = (clone $query)
            ->orderByRaw("COALESCE(mes, '') asc")
            ->orderBy('data_emissao')
            ->orderBy('created_at')
            ->first();

        $last = (clone $query)
            ->orderByRaw("COALESCE(mes, '') desc")
            ->orderByDesc('data_emissao')
            ->orderByDesc('created_at')
            ->first();

        $lastStatuses = (clone $query)
            ->orderByRaw("COALESCE(mes, '') desc")
            ->orderByDesc('data_emissao')
            ->orderByDesc('created_at')
            ->limit(3)
            ->pluck('estado_pagamento')
            ->filter()
            ->values()
            ->all();

        $historicalReferences = (clone $query)
            ->where(function ($nested): void {
                $nested
                    ->whereNotNull('origem_tipo')
                    ->orWhereNotNull('origem_id');
            })
            ->orderByRaw("COALESCE(mes, '') desc")
            ->orderByDesc('data_emissao')
            ->limit(5)
            ->get()
            ->map(static fn (Invoice $invoice): array => [
                'invoice_id' => (string) $invoice->id,
                'mes' => is_string($invoice->mes) ? $invoice->mes : null,
                'origem_tipo' => is_string($invoice->origem_tipo) ? $invoice->origem_tipo : null,
                'origem_id' => is_string($invoice->origem_id) ? $invoice->origem_id : null,
            ])
            ->values()
            ->all();

        return [
            'total_monthly_invoices' => $total,
            'first_date' => $this->invoiceDate($first),
            'last_date' => $this->invoiceDate($last),
            'last_amount' => $last?->valor_total !== null ? (float) $last->valor_total : null,
            'historical_references' => $historicalReferences,
            'latest_statuses' => $lastStatuses,
        ];
    }

    private function invoiceDate(?Invoice $invoice): ?string
    {
        if (!$invoice instanceof Invoice) {
            return null;
        }

        if (is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
            return sprintf('%s-01', $invoice->mes);
        }

        if ($invoice->data_emissao !== null) {
            return $invoice->data_emissao->format('Y-m-d');
        }

        if ($invoice->data_fatura !== null) {
            return $invoice->data_fatura->format('Y-m-d');
        }

        if ($invoice->data_vencimento !== null) {
            return $invoice->data_vencimento->format('Y-m-d');
        }

        return null;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function uniqueReasonCodes(array $codes): array
    {
        return array_values(array_unique(array_values(array_filter($codes, static fn (mixed $code): bool => is_string($code) && trim($code) !== ''))));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param list<array<string,mixed>> $cases
     */
    private function countByClassification(array $cases, string $classification): int
    {
        $count = 0;

        foreach ($cases as $case) {
            if (($case['classification'] ?? null) === $classification) {
                $count++;
            }
        }

        return $count;
    }
}
