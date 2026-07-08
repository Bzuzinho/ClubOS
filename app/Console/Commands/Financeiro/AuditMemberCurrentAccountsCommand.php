<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\User;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\MemberManualAccountBalanceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditMemberCurrentAccountsCommand extends Command
{
    protected $signature = 'finance:audit-member-current-accounts
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--user= : Audita apenas um user_id}
        {--fail-on-divergence : Falha com codigo 1 quando existem divergencias no ajuste manual}
        {--fail-on-fallback : Falha com codigo 1 quando existe fallback legacy em uso}';

    protected $description = 'Audita conta corrente operacional canónica do membro sem alterar dados';

    public function __construct(
        private readonly CurrentAccountService $currentAccountService,
        private readonly MemberManualAccountBalanceResolver $manualBalanceResolver,
    ) {
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

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        if ((bool) $this->option('fail-on-divergence') && (int) ($summary['divergent_manual_count'] ?? 0) > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-fallback') && (int) ($summary['legacy_fallback_count'] ?? 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $users = User::query()
            ->with('dadosFinanceiros:id,user_id,conta_corrente_manual')
            ->select('id', 'conta_corrente')
            ->when($this->option('user'), fn ($query, $userId) => $query->whereKey((string) $userId))
            ->orderBy('id')
            ->get();

        $cases = [];
        $summary = [
            'total_users' => 0,
            'canonical_manual_only_count' => 0,
            'matching_manual_count' => 0,
            'legacy_fallback_count' => 0,
            'divergent_manual_count' => 0,
            'no_manual_adjustment_count' => 0,
            'invalid_value_count' => 0,
            'operational_debt_count' => 0,
            'operational_credit_count' => 0,
            'operational_balanced_count' => 0,
            'gross_debt_total' => 0.0,
            'available_credit_total' => 0.0,
            'net_debt_total' => 0.0,
        ];

        foreach ($users as $user) {
            $manualDiagnostic = $this->manualBalanceResolver->detectDivergence($user);
            $accountSummary = $this->currentAccountService->summarize([
                'user_id' => (string) $user->id,
            ]);

            $manualClassification = $this->classifyManualSource($manualDiagnostic);
            $balanceClassification = $this->classifyOperationalBalance((float) ($accountSummary['net_debt'] ?? 0));

            $reasonCodes = [];
            if ((bool) ($manualDiagnostic['uses_legacy_fallback'] ?? false)) {
                $reasonCodes[] = 'legacy_fallback_in_use';
            }
            if ((bool) ($manualDiagnostic['has_divergence'] ?? false)) {
                $reasonCodes[] = 'manual_values_divergent';
            }
            if ((bool) ($manualDiagnostic['has_invalid_value'] ?? false)) {
                $reasonCodes[] = 'invalid_manual_value';
            }
            if ($manualClassification === 'none') {
                $reasonCodes[] = 'no_manual_adjustment';
            }

            $case = [
                'user_id' => (string) $user->id,
                'manual_source_classification' => $manualClassification,
                'operational_balance_classification' => $balanceClassification,
                'canonical_manual_balance' => $manualDiagnostic['canonical_manual_balance'] ?? null,
                'legacy_account_balance' => $manualDiagnostic['legacy_account_balance'] ?? null,
                'resolved_manual_balance' => round((float) ($manualDiagnostic['resolved_manual_balance'] ?? 0), 2),
                'gross_debt' => round((float) ($accountSummary['gross_debt'] ?? 0), 2),
                'available_credit' => round((float) ($accountSummary['available_credit'] ?? 0), 2),
                'net_debt' => round((float) ($accountSummary['net_debt'] ?? 0), 2),
                'uses_legacy_fallback' => (bool) ($manualDiagnostic['uses_legacy_fallback'] ?? false),
                'has_divergence' => (bool) ($manualDiagnostic['has_divergence'] ?? false),
                'reason_codes' => $reasonCodes,
            ];

            $cases[] = $case;
            $summary['total_users']++;
            $summary[$this->manualSummaryCounterKey($manualClassification)]++;
            $summary[$this->operationalSummaryCounterKey($balanceClassification)]++;

            $summary['gross_debt_total'] += (float) $case['gross_debt'];
            $summary['available_credit_total'] += (float) $case['available_credit'];
            $summary['net_debt_total'] += (float) $case['net_debt'];
        }

        $summary['gross_debt_total'] = round((float) $summary['gross_debt_total'], 2);
        $summary['available_credit_total'] = round((float) $summary['available_credit_total'], 2);
        $summary['net_debt_total'] = round((float) $summary['net_debt_total'], 2);

        return [
            'version' => 'f3-member-current-accounts-audit-v1',
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'user' => $this->option('user') ? (string) $this->option('user') : null,
            ],
            'summary' => $summary,
            'cases' => $cases,
        ];
    }

    /**
     * @param array<string, mixed> $manualDiagnostic
     */
    private function classifyManualSource(array $manualDiagnostic): string
    {
        if ((bool) ($manualDiagnostic['has_invalid_value'] ?? false)) {
            return 'invalid';
        }

        $hasCanonical = (bool) ($manualDiagnostic['has_canonical_manual_balance'] ?? false);
        $hasLegacy = (bool) ($manualDiagnostic['has_legacy_fallback'] ?? false);
        $hasDivergence = (bool) ($manualDiagnostic['has_divergence'] ?? false);

        if ($hasCanonical && !$hasLegacy) {
            return 'canonical_only';
        }

        if ($hasCanonical && $hasLegacy && !$hasDivergence) {
            return 'matching';
        }

        if ($hasCanonical && $hasLegacy && $hasDivergence) {
            return 'divergent';
        }

        if (!$hasCanonical && $hasLegacy) {
            return 'legacy_fallback';
        }

        return 'none';
    }

    private function classifyOperationalBalance(float $netDebt): string
    {
        if ($netDebt > 0.009) {
            return 'debt';
        }

        if ($netDebt < -0.009) {
            return 'credit';
        }

        return 'balanced';
    }

    private function manualSummaryCounterKey(string $manualClassification): string
    {
        return match ($manualClassification) {
            'canonical_only' => 'canonical_manual_only_count',
            'matching' => 'matching_manual_count',
            'legacy_fallback' => 'legacy_fallback_count',
            'divergent' => 'divergent_manual_count',
            'none' => 'no_manual_adjustment_count',
            'invalid' => 'invalid_value_count',
            default => 'no_manual_adjustment_count',
        };
    }

    private function operationalSummaryCounterKey(string $operationalClassification): string
    {
        return match ($operationalClassification) {
            'debt' => 'operational_debt_count',
            'credit' => 'operational_credit_count',
            default => 'operational_balanced_count',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->info('Audit member current accounts');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', (int) ($summary['total_users'] ?? 0)],
                ['canonical_manual_only_count', (int) ($summary['canonical_manual_only_count'] ?? 0)],
                ['matching_manual_count', (int) ($summary['matching_manual_count'] ?? 0)],
                ['legacy_fallback_count', (int) ($summary['legacy_fallback_count'] ?? 0)],
                ['divergent_manual_count', (int) ($summary['divergent_manual_count'] ?? 0)],
                ['no_manual_adjustment_count', (int) ($summary['no_manual_adjustment_count'] ?? 0)],
                ['invalid_value_count', (int) ($summary['invalid_value_count'] ?? 0)],
                ['operational_debt_count', (int) ($summary['operational_debt_count'] ?? 0)],
                ['operational_credit_count', (int) ($summary['operational_credit_count'] ?? 0)],
                ['operational_balanced_count', (int) ($summary['operational_balanced_count'] ?? 0)],
                ['gross_debt_total', number_format((float) ($summary['gross_debt_total'] ?? 0), 2, '.', '')],
                ['available_credit_total', number_format((float) ($summary['available_credit_total'] ?? 0), 2, '.', '')],
                ['net_debt_total', number_format((float) ($summary['net_debt_total'] ?? 0), 2, '.', '')],
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
