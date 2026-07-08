<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use App\Services\Financeiro\MemberMonthlyFeeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditMemberMonthlyFeesCommand extends Command
{
    protected $signature = 'finance:audit-member-monthly-fees
        {--json : Devolve o relatorio em JSON}
        {--fail-on-divergence : Falha com codigo 1 quando existem divergencias}
        {--fail-on-fallback : Falha com codigo 1 quando existe fallback legacy em uso}
        {--report-path= : Caminho para guardar o payload JSON}
        {--user= : Audita apenas um user_id}';

    protected $description = 'Audita plano de mensalidade canónico (dados_financeiros.mensalidade_id) e fallback legacy (users.tipo_mensalidade)';

    public function __construct(
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->buildPayload();
        $this->writeReportFileIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        $hasDivergence = (int) ($payload['summary']['divergent_count'] ?? 0) > 0;
        $hasFallback = (int) ($payload['summary']['legacy_fallback_count'] ?? 0) > 0;

        if ((bool) $this->option('fail-on-divergence') && $hasDivergence) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-fallback') && $hasFallback) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $validMonthlyFeeIds = MonthlyFee::query()->pluck('id')->map(static fn ($id) => (string) $id)->flip();

        $users = User::query()
            ->with([
                'dadosFinanceiros:id,user_id,mensalidade_id',
                'userTypes:id,codigo,nome',
            ])
            ->select('id', 'numero_socio', 'estado', 'ativo_desportivo', 'tipo_membro')
            ->when($this->option('user'), fn ($query, $userId) => $query->whereKey((string) $userId))
            ->orderBy('numero_socio')
            ->get();

        $classifications = [
            'canonical_only' => [],
            'matching' => [],
            'legacy_fallback' => [],
            'divergent' => [],
            'missing' => [],
            'missing_required' => [],
            'not_required' => [],
            'invalid_reference' => [],
        ];

        $cases = [];

        foreach ($users as $user) {
            $diagnostic = $this->memberMonthlyFeeResolver->detectDivergence($user);
            $canonicalId = $diagnostic['canonical_monthly_fee_id'] ?? null;
            $legacyId = $diagnostic['legacy_monthly_fee_id'] ?? null;
            $resolvedId = $diagnostic['resolved_monthly_fee_id'] ?? null;

            $referenceValid = $resolvedId !== null
                ? $validMonthlyFeeIds->has((string) $resolvedId)
                : true;

            $eligibility = $this->memberMonthlyFeeEligibilityService->evaluate($user);
            $required = (bool) ($eligibility['should_have_monthly_fee'] ?? false);
            $eligibilityReasonCodes = is_array($eligibility['reason_codes'] ?? null)
                ? $eligibility['reason_codes']
                : [];

            $classification = 'missing';
            $reason = 'No canonical or legacy monthly fee configured.';

            if (!$referenceValid) {
                $classification = 'invalid_reference';
                $reason = 'Resolved monthly fee does not reference an existing plan.';
            } elseif ($canonicalId !== null && $legacyId !== null && $canonicalId !== $legacyId) {
                $classification = 'divergent';
                $reason = 'Canonical and legacy monthly fee ids diverge.';
            } elseif ($canonicalId !== null && $legacyId === null) {
                $classification = 'canonical_only';
                $reason = 'Canonical monthly fee is set and legacy is empty.';
            } elseif ($canonicalId !== null && $legacyId !== null && $canonicalId === $legacyId) {
                $classification = 'matching';
                $reason = 'Canonical and legacy monthly fee ids are aligned.';
            } elseif ($canonicalId === null && $legacyId !== null) {
                $classification = 'legacy_fallback';
                $reason = 'Legacy monthly fee is being used because canonical is empty.';
            } elseif ($canonicalId === null && $legacyId === null) {
                if ($required) {
                    $classification = 'missing_required';
                    $reason = 'Monthly fee is required by canonical eligibility but is missing.';
                } else {
                    $classification = 'not_required';
                    $reason = 'Monthly fee is not required by canonical eligibility.';
                }
            }

            $case = [
                'user_id' => (string) $user->id,
                'classification' => $classification,
                'canonical_monthly_fee_id' => $canonicalId,
                'legacy_monthly_fee_id' => $legacyId,
                'resolved_monthly_fee_id' => $resolvedId,
                'uses_legacy_fallback' => (bool) ($diagnostic['uses_legacy_fallback'] ?? false),
                'has_divergence' => (bool) ($diagnostic['has_divergence'] ?? false),
                'reference_valid' => $referenceValid,
                'reason' => $reason,
                'eligibility' => $eligibility,
                'reason_codes' => $eligibilityReasonCodes,
            ];

            $cases[] = $case;
            $classifications[$classification][] = $case;

            if ($classification === 'missing_required') {
                $classifications['missing'][] = $case;
            }

            if ($classification === 'not_required') {
                $classifications['missing'][] = $case;
            }
        }

        return [
            'version' => 'f2-member-monthly-fees-audit-v1',
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'user' => $this->option('user') ? (string) $this->option('user') : null,
            ],
            'summary' => [
                'total_users' => $users->count(),
                'canonical_only_count' => count($classifications['canonical_only']),
                'matching_count' => count($classifications['matching']),
                'legacy_fallback_count' => count($classifications['legacy_fallback']),
                'divergent_count' => count($classifications['divergent']),
                'missing_count' => count($classifications['missing']),
                'missing_required_count' => count($classifications['missing_required']),
                'not_required_count' => count($classifications['not_required']),
                'invalid_reference_count' => count($classifications['invalid_reference']),
            ],
            'classifications' => $classifications,
            'cases' => $cases,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->info('Audit member monthly fees');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', (int) ($summary['total_users'] ?? 0)],
                ['canonical_only_count', (int) ($summary['canonical_only_count'] ?? 0)],
                ['matching_count', (int) ($summary['matching_count'] ?? 0)],
                ['legacy_fallback_count', (int) ($summary['legacy_fallback_count'] ?? 0)],
                ['divergent_count', (int) ($summary['divergent_count'] ?? 0)],
                ['missing_count', (int) ($summary['missing_count'] ?? 0)],
                ['missing_required_count', (int) ($summary['missing_required_count'] ?? 0)],
                ['not_required_count', (int) ($summary['not_required_count'] ?? 0)],
                ['invalid_reference_count', (int) ($summary['invalid_reference_count'] ?? 0)],
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPathOption = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPathOption === '') {
            return;
        }

        $reportPath = str_starts_with($reportPathOption, '/')
            ? $reportPathOption
            : base_path($reportPathOption);

        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, $this->toJson($payload));

        $this->line(sprintf('Report written to: %s', $reportPath));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
