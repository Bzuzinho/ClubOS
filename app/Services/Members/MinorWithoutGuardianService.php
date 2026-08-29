<?php

namespace App\Services\Members;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MinorWithoutGuardianService
{
    private const GUARDIAN_ROLES = [
        'responsavel',
        'responsável',
        'encarregado',
        'encarregado_educacao',
        'encarregado de educacao',
        'encarregado de educação',
        'guardian',
    ];

    /**
     * @return array{total:int,items:array<int,array<string,mixed>>,has_more:bool,all_url:string}
     */
    public function summary(int $limit = 5): array
    {
        $items = $this->all();

        return [
            'total' => $items->count(),
            'items' => $items->take($limit)->values()->all(),
            'has_more' => $items->count() > $limit,
            'all_url' => route('membros.index'),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function all(): Collection
    {
        if (! Schema::hasTable('users')) {
            return collect();
        }

        $hasPersonalData = Schema::hasTable('dados_pessoais');
        $query = DB::table('users as users')
            ->select([
                'users.id',
                'users.name',
                'users.estado',
                'users.menor',
                'users.data_nascimento as legacy_birthdate',
            ]);

        if ($hasPersonalData) {
            $query->leftJoin('dados_pessoais as personal', 'personal.user_id', '=', 'users.id')
                ->addSelect([
                    'personal.nome_completo as canonical_name',
                    'personal.data_nascimento as canonical_birthdate',
                ]);
        }

        $guardianUserIds = $this->guardianUserIds();

        return $query->orderBy('users.name')->get()
            ->map(function (object $user) use ($hasPersonalData): ?array {
                $birthdate = $this->validBirthdate(
                    $hasPersonalData ? ($user->canonical_birthdate ?? null) : null,
                    $user->legacy_birthdate ?? null,
                );
                $isMinor = (bool) ($user->menor ?? false)
                    || ($birthdate !== null && $birthdate->age < 18);

                if (! $isMinor) {
                    return null;
                }

                return [
                    'id' => (string) $user->id,
                    'name' => trim((string) (($user->canonical_name ?? null) ?: $user->name ?: 'Membro')),
                    'age' => $birthdate?->age,
                    'birthdate' => $birthdate?->toDateString(),
                    'status' => (string) (($user->estado ?? null) ?: 'Sem estado'),
                    'member_url' => route('membros.show', ['member' => $user->id]),
                ];
            })
            ->filter()
            ->reject(fn (array $item): bool => $guardianUserIds->contains($item['id']))
            ->values();
    }

    private function validBirthdate(mixed ...$values): ?CarbonImmutable
    {
        foreach ($values as $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            try {
                $birthdate = CarbonImmutable::parse((string) $value)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if ($birthdate->year >= 1900 && ! $birthdate->isFuture() && $birthdate->age <= 110) {
                return $birthdate;
            }
        }

        return null;
    }

    /**
     * @return Collection<int,string>
     */
    private function guardianUserIds(): Collection
    {
        $ids = collect();

        if (Schema::hasTable('user_guardian')) {
            $ids = $ids->merge(DB::table('user_guardian')->pluck('user_id'));
        }

        if (Schema::hasTable('familia_user')) {
            $guardianFamilyIds = DB::table('familia_user')
                ->whereIn('papel_na_familia', self::GUARDIAN_ROLES)
                ->pluck('familia_id');

            if (Schema::hasTable('familias')) {
                $guardianFamilyIds = $guardianFamilyIds->merge(
                    DB::table('familias')->whereNotNull('responsavel_user_id')->pluck('id')
                );
            }

            if ($guardianFamilyIds->isNotEmpty()) {
                $ids = $ids->merge(
                    DB::table('familia_user')->whereIn('familia_id', $guardianFamilyIds->unique())->pluck('user_id')
                );
            }
        }

        return $ids->map(fn (mixed $id): string => (string) $id)->unique()->values();
    }
}
