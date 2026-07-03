<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MemberTypeResolver
{
    /**
     * @return list<string>
     */
    public function typesFor(User $user): array
    {
        $canonicalTypes = $this->typesFromUserTypes($user);
        if ($canonicalTypes !== []) {
            return $canonicalTypes;
        }

        return $this->legacyTypesFor($user);
    }

    public function hasType(User $user, string $type): bool
    {
        $normalizedType = $this->normalizeType($type);
        if ($normalizedType === '') {
            return false;
        }

        return in_array($normalizedType, $this->typesFor($user), true);
    }

    public function isAthlete(User $user): bool
    {
        return $this->hasType($user, 'atleta');
    }

    public function isGuardian(User $user): bool
    {
        return $this->hasType($user, 'encarregado_educacao');
    }

    public function isTrainer(User $user): bool
    {
        return $this->hasType($user, 'treinador');
    }

    public function normalizeType(string $value): string
    {
        $normalized = Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return trim($normalized);
    }

    /**
     * @return list<string>
     */
    public function typesFromUserTypes(User $user): array
    {
        $userTypes = $this->loadUserTypes($user);

        if ($userTypes->isEmpty()) {
            return [];
        }

        return $userTypes
            ->map(function ($type): string {
                $code = is_string($type->codigo ?? null) ? $type->codigo : '';
                $name = is_string($type->nome ?? null) ? $type->nome : '';

                return $this->normalizeType($code !== '' ? $code : $name);
            })
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function legacyTypesFor(User $user): array
    {
        $legacyTypes = $user->getAttribute('tipo_membro');

        return collect(is_array($legacyTypes) ? $legacyTypes : (array) $legacyTypes)
            ->map(fn ($type): string => $this->normalizeType((string) $type))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int,mixed>
     */
    private function loadUserTypes(User $user): Collection
    {
        if ($user->relationLoaded('userTypes')) {
            return collect($user->getRelation('userTypes'));
        }

        return $user->userTypes()->get(['user_types.id', 'user_types.codigo', 'user_types.nome']);
    }
}