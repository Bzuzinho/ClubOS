<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AgeGroup;
use App\Models\Season;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\User;
use App\Models\UserType;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MemberReportService
{
    private const VALID_STATUSES = ['ativo', 'inativo', 'suspenso'];

    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly SportsClubContext $sportsClubContext,
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function build(array $filters): array
    {
        $mode = ($filters['mode'] ?? 'normal') === 'detailed' ? 'detailed' : 'normal';
        $selectedTypes = $this->normalizedList($filters['user_types'] ?? []);
        $selectedAgeGroups = $this->stringList($filters['age_groups'] ?? []);
        $selectedStatuses = array_values(array_intersect(
            self::VALID_STATUSES,
            $this->normalizedList($filters['statuses'] ?? [])
        ));

        $userTypes = UserType::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'codigo', 'nome']);

        $typeLabels = $userTypes
            ->mapWithKeys(function (UserType $type): array {
                $code = $this->memberTypeResolver->normalizeType((string) ($type->codigo ?: $type->nome));

                return $code === '' ? [] : [$code => (string) $type->nome];
            });

        $ageGroups = AgeGroup::query()
            ->where('club_id', $this->sportsClubContext->id())
            ->whereNull('archived_at')
            ->orderBy('idade_minima')
            ->orderBy('nome')
            ->get(['id', 'nome']);

        $ageGroupLabels = $ageGroups->mapWithKeys(
            static fn (AgeGroup $group): array => [(string) $group->id => (string) $group->nome]
        );

        $members = User::query()
            ->with(['dadosPessoais:id,user_id,nome_completo', 'userTypes:id,codigo,nome'])
            ->select([
                'id',
                'numero_socio',
                'name',
                'email_utilizador',
                'estado',
                'tipo_membro',
                'escalao',
            ])
            ->when(
                $selectedStatuses !== [],
                fn (Builder $query): Builder => $query->whereIn('estado', $selectedStatuses)
            )
            ->orderBy('name')
            ->get();

        $canonicalAgeGroups = $this->canonicalAgeGroupsByMember($members->pluck('id')->map(
            static fn ($id): string => (string) $id
        ));

        $rows = $members
            ->map(function (User $member) use ($typeLabels, $ageGroupLabels, $canonicalAgeGroups): array {
                $types = $this->memberTypeResolver->typesFor($member);
                $ageGroupIds = $canonicalAgeGroups->get((string) $member->id, []);

                if ($ageGroupIds === []) {
                    $ageGroupIds = $this->stringList($member->escalao ?? []);
                }

                $ageGroupIds = array_values(array_unique($ageGroupIds));

                return [
                    'id' => (string) $member->id,
                    'numero_socio' => (string) ($member->numero_socio ?? ''),
                    'nome_completo' => $this->memberIdentityDisplayResolver->displayName($member),
                    'email_utilizador' => (string) ($member->email_utilizador ?? ''),
                    'estado' => (string) ($member->estado ?? ''),
                    'estado_label' => $this->statusLabel((string) ($member->estado ?? '')),
                    'user_type_values' => $types,
                    'user_type_labels' => collect($types)
                        ->map(fn (string $type): string => (string) ($typeLabels->get($type) ?? $this->fallbackLabel($type)))
                        ->values()
                        ->all(),
                    'age_group_ids' => $ageGroupIds,
                    'age_group_labels' => collect($ageGroupIds)
                        ->map(fn (string $id): string => (string) ($ageGroupLabels->get($id) ?? $id))
                        ->values()
                        ->all(),
                ];
            })
            ->filter(function (array $row) use ($selectedTypes, $selectedAgeGroups, $selectedStatuses): bool {
                if ($selectedStatuses !== [] && ! in_array($row['estado'], $selectedStatuses, true)) {
                    return false;
                }

                if ($selectedTypes !== [] && array_intersect($row['user_type_values'], $selectedTypes) === []) {
                    return false;
                }

                if ($selectedAgeGroups !== [] && array_intersect($row['age_group_ids'], $selectedAgeGroups) === []) {
                    return false;
                }

                return true;
            })
            ->sortBy(static fn (array $row): string => mb_strtolower((string) $row['nome_completo']))
            ->values();

        return [
            'mode' => $mode,
            'filters' => [
                'user_types' => $selectedTypes,
                'age_groups' => $selectedAgeGroups,
                'statuses' => $selectedStatuses,
            ],
            'options' => [
                'user_types' => $userTypes->map(function (UserType $type): array {
                    $value = $this->memberTypeResolver->normalizeType((string) ($type->codigo ?: $type->nome));

                    return ['value' => $value, 'label' => (string) $type->nome];
                })->filter(static fn (array $option): bool => $option['value'] !== '')->values()->all(),
                'age_groups' => $ageGroups->map(static fn (AgeGroup $group): array => [
                    'value' => (string) $group->id,
                    'label' => (string) $group->nome,
                ])->values()->all(),
                'statuses' => [
                    ['value' => 'ativo', 'label' => 'Ativo'],
                    ['value' => 'inativo', 'label' => 'Inativo'],
                    ['value' => 'suspenso', 'label' => 'Suspenso'],
                ],
            ],
            'summary' => [
                'total' => $rows->count(),
                'ativos' => $rows->where('estado', 'ativo')->count(),
                'inativos' => $rows->where('estado', 'inativo')->count(),
                'suspensos' => $rows->where('estado', 'suspenso')->count(),
            ],
            'breakdowns' => [
                'statuses' => $this->statusBreakdown($rows),
                'user_types' => $this->multiValueBreakdown($rows, 'user_type_values', 'user_type_labels'),
                'age_groups' => $this->multiValueBreakdown($rows, 'age_group_ids', 'age_group_labels'),
            ],
            'rows' => $mode === 'detailed' ? $rows->all() : [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param Collection<int,string> $memberIds
     * @return Collection<string,list<string>>
     */
    private function canonicalAgeGroupsByMember(Collection $memberIds): Collection
    {
        if ($memberIds->isEmpty()) {
            return collect();
        }

        $today = today()->toDateString();
        $currentSeasonIds = Season::query()
            ->where('club_id', $this->sportsClubContext->id())
            ->where(function (Builder $query) use ($today): void {
                $query->where('status', 'active')
                    ->orWhere(function (Builder $dateQuery) use ($today): void {
                        $dateQuery
                            ->whereDate('data_inicio', '<=', $today)
                            ->whereDate('data_fim', '>=', $today);
                    });
            })
            ->pluck('id');

        if ($currentSeasonIds->isEmpty()) {
            return collect();
        }

        return SportsAthleteSeasonProfile::query()
            ->where('club_id', $this->sportsClubContext->id())
            ->whereIn('season_id', $currentSeasonIds)
            ->whereIn('user_id', $memberIds)
            ->get(['user_id', 'official_age_group_id', 'calculated_age_group_id'])
            ->groupBy(fn (SportsAthleteSeasonProfile $profile): string => (string) $profile->user_id)
            ->map(static function (Collection $profiles): array {
                return $profiles
                    ->map(static fn (SportsAthleteSeasonProfile $profile): ?string =>
                        $profile->official_age_group_id
                            ? (string) $profile->official_age_group_id
                            : ($profile->calculated_age_group_id ? (string) $profile->calculated_age_group_id : null)
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            });
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return list<array{value:string,label:string,count:int}>
     */
    private function statusBreakdown(Collection $rows): array
    {
        return collect([
            'ativo' => 'Ativo',
            'inativo' => 'Inativo',
            'suspenso' => 'Suspenso',
        ])->map(fn (string $label, string $value): array => [
            'value' => $value,
            'label' => $label,
            'count' => $rows->where('estado', $value)->count(),
        ])->values()->all();
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @return list<array{value:string,label:string,count:int}>
     */
    private function multiValueBreakdown(Collection $rows, string $valuesKey, string $labelsKey): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $values = is_array($row[$valuesKey] ?? null) ? $row[$valuesKey] : [];
            $labels = is_array($row[$labelsKey] ?? null) ? $row[$labelsKey] : [];

            foreach ($values as $index => $value) {
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }

                $label = (string) ($labels[$index] ?? $value);
                $counts[$value] ??= ['value' => $value, 'label' => $label, 'count' => 0];
                $counts[$value]['count']++;
            }
        }

        return collect($counts)
            ->sortBy(static fn (array $row): string => mb_strtolower($row['label']))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function normalizedList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($item): string => $this->memberTypeResolver->normalizeType((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(static fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ativo' => 'Ativo',
            'inativo' => 'Inativo',
            'suspenso' => 'Suspenso',
            default => $this->fallbackLabel($status),
        };
    }

    private function fallbackLabel(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->value();
    }
}
