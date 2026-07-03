<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Models\User;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Console\Command;

final class AuditMemberTypesCommand extends Command
{
    protected $signature = 'members:audit-member-types
        {--json : Devolve o relatorio em JSON}
        {--fail-on-divergence : Falha com exit code 1 quando existem divergencias}';

    protected $description = 'Audita convergencia entre userTypes (canónico) e tipo_membro (legacy)';

    public function __construct(private readonly MemberTypeResolver $memberTypeResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->buildPayload();
        $this->renderOutput($payload);

        if ((bool) $this->option('fail-on-divergence') && ((int) ($payload['summary']['divergent_total'] ?? 0) > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(): array
    {
        $rowsWithoutUserTypes = [];
        $rowsDivergent = [];
        $rowsLegacyAthleteOnly = [];
        $rowsCanonicalAthleteOnly = [];

        $users = User::query()
            ->with('userTypes:id,codigo,nome')
            ->select('id', 'name', 'numero_socio', 'tipo_membro')
            ->orderBy('numero_socio')
            ->get();

        foreach ($users as $user) {
            $canonicalTypes = $this->memberTypeResolver->typesFromUserTypes($user);
            $legacyTypes = $this->memberTypeResolver->legacyTypesFor($user);

            $baseRow = [
                'id' => (string) $user->id,
                'numero_socio' => (string) ($user->numero_socio ?? ''),
                'name' => (string) ($user->name ?? ''),
                'canonical_types' => $canonicalTypes,
                'legacy_types' => $legacyTypes,
            ];

            if ($canonicalTypes === [] && $legacyTypes !== []) {
                $rowsWithoutUserTypes[] = $baseRow;
            }

            if ($canonicalTypes !== [] && $legacyTypes !== [] && $this->sortedValues($canonicalTypes) !== $this->sortedValues($legacyTypes)) {
                $rowsDivergent[] = $baseRow;
            }

            $legacyAthlete = in_array('atleta', $legacyTypes, true);
            $canonicalAthlete = in_array('atleta', $canonicalTypes, true);

            if ($legacyAthlete && !$canonicalAthlete) {
                $rowsLegacyAthleteOnly[] = $baseRow;
            }

            if ($canonicalAthlete && !$legacyAthlete) {
                $rowsCanonicalAthleteOnly[] = $baseRow;
            }
        }

        return [
            'version' => 'AC1',
            'summary' => [
                'total_users' => $users->count(),
                'without_user_types_but_legacy_count' => count($rowsWithoutUserTypes),
                'divergent_count' => count($rowsDivergent),
                'legacy_athlete_not_canonical_count' => count($rowsLegacyAthleteOnly),
                'canonical_athlete_not_legacy_count' => count($rowsCanonicalAthleteOnly),
                'divergent_total' => count($rowsDivergent) + count($rowsLegacyAthleteOnly) + count($rowsCanonicalAthleteOnly),
            ],
            'without_user_types_but_legacy' => $rowsWithoutUserTypes,
            'divergent_user_types_vs_legacy' => $rowsDivergent,
            'legacy_athlete_not_canonical' => $rowsLegacyAthleteOnly,
            'canonical_athlete_not_legacy' => $rowsCanonicalAthleteOnly,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->info('Audit member types (AC1)');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', (int) ($summary['total_users'] ?? 0)],
                ['without_user_types_but_legacy_count', (int) ($summary['without_user_types_but_legacy_count'] ?? 0)],
                ['divergent_count', (int) ($summary['divergent_count'] ?? 0)],
                ['legacy_athlete_not_canonical_count', (int) ($summary['legacy_athlete_not_canonical_count'] ?? 0)],
                ['canonical_athlete_not_legacy_count', (int) ($summary['canonical_athlete_not_legacy_count'] ?? 0)],
            ]
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedValues(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}