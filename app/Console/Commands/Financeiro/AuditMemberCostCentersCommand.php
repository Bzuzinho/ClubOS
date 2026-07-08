<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeResolver;
use App\Services\Financeiro\MemberCostCenterResolver;
use Illuminate\Console\Command;

final class AuditMemberCostCentersCommand extends Command
{
    protected $signature = 'finance:audit-member-cost-centers
        {--json : Devolve o relatorio em JSON}
        {--fail-on-divergence : Falha com codigo 1 quando existem divergencias entre pivot e legacy}
        {--fail-on-fallback : Falha com codigo 1 quando existe fallback legacy em uso}';

    protected $description = 'Audita centros de custo canónicos e fallback legacy dos membros';

    public function __construct(
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->buildPayload();
        $this->renderOutput($payload);

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
        $legacyFallbackRows = [];
        $divergentRows = [];
        $invalidWeightRows = [];
        $missingRequiredRows = [];

        $users = User::query()
            ->with('dadosFinanceiros:id,user_id,mensalidade_id')
            ->select('id', 'name', 'numero_socio', 'estado')
            ->orderBy('numero_socio')
            ->get();

        foreach ($users as $user) {
            $resolved = $this->memberCostCenterResolver->resolveForUser($user);
            $divergence = is_array($resolved['divergence'] ?? null) ? $resolved['divergence'] : [];
            $shouldHaveCostCenter = $this->shouldHaveCostCenter($user);

            $baseRow = [
                'id' => (string) $user->id,
                'numero_socio' => (string) ($user->numero_socio ?? ''),
                'name' => (string) ($user->name ?? ''),
                'state' => (string) ($user->estado ?? ''),
                'source' => (string) ($resolved['source'] ?? 'none'),
                'canonical_ids' => array_values($resolved['canonical']['ids'] ?? []),
                'legacy_ids' => array_values($resolved['legacy']['ids'] ?? []),
                'has_canonical_cost_centers' => (bool) ($divergence['has_canonical_cost_centers'] ?? false),
                'has_legacy_fallback' => (bool) ($divergence['has_legacy_fallback'] ?? false),
                'uses_legacy_fallback' => (bool) ($divergence['uses_legacy_fallback'] ?? false),
                'has_divergence' => (bool) ($divergence['has_divergence'] ?? false),
                'invalid_weight_ids' => array_values($divergence['invalid_weight_ids'] ?? []),
                'missing_in_canonical' => array_values($divergence['missing_in_canonical'] ?? []),
                'missing_in_legacy' => array_values($divergence['missing_in_legacy'] ?? []),
                'should_have_cost_center' => $shouldHaveCostCenter,
            ];

            if ($baseRow['uses_legacy_fallback']) {
                $legacyFallbackRows[] = $baseRow;
            }

            if ($baseRow['has_divergence']) {
                $divergentRows[] = $baseRow;
            }

            if ($baseRow['invalid_weight_ids'] !== []) {
                $invalidWeightRows[] = $baseRow;
            }

            if ($shouldHaveCostCenter && !$baseRow['has_canonical_cost_centers'] && !$baseRow['has_legacy_fallback']) {
                $missingRequiredRows[] = $baseRow;
            }
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'summary' => [
                'total_users_analyzed' => $users->count(),
                'legacy_fallback_count' => count($legacyFallbackRows),
                'divergent_count' => count($divergentRows),
                'invalid_weight_count' => count($invalidWeightRows),
                'missing_required_count' => count($missingRequiredRows),
            ],
            'legacy_fallback' => $legacyFallbackRows,
            'divergent' => $divergentRows,
            'invalid_weights' => $invalidWeightRows,
            'missing_required' => $missingRequiredRows,
            'passed' => true,
            'failure_reason' => null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->info('Audit member cost centers');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users_analyzed', (int) ($summary['total_users_analyzed'] ?? 0)],
                ['legacy_fallback_count', (int) ($summary['legacy_fallback_count'] ?? 0)],
                ['divergent_count', (int) ($summary['divergent_count'] ?? 0)],
                ['invalid_weight_count', (int) ($summary['invalid_weight_count'] ?? 0)],
                ['missing_required_count', (int) ($summary['missing_required_count'] ?? 0)],
            ]
        );

        $this->newLine();
        $this->renderRows('Legacy fallback', $payload['legacy_fallback'] ?? []);
        $this->newLine();
        $this->renderRows('Divergencias', $payload['divergent'] ?? []);
        $this->newLine();
        $this->renderRows('Pesos invalidos', $payload['invalid_weights'] ?? []);
        $this->newLine();
        $this->renderRows('Sem centro de custo quando esperado', $payload['missing_required'] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderRows(string $title, array $rows): void
    {
        $this->info($title);

        if ($rows === []) {
            $this->table(['id', 'numero_socio', 'name', 'source'], [['(none)', '', '', '']]);

            return;
        }

        $tableRows = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            $tableRows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['numero_socio'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['source'] ?? ''),
            ];
        }

        $this->table(['id', 'numero_socio', 'name', 'source'], $tableRows);
    }

    private function shouldHaveCostCenter(User $user): bool
    {
        if ($user->estado !== 'ativo') {
            return false;
        }

        return $this->memberMonthlyFeeResolver->resolveForUser($user) !== null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}