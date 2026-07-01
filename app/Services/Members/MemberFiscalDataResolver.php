<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;

final class MemberFiscalDataResolver
{
    public function __construct(
        private readonly MemberDataReadService $memberDataReadService,
    ) {
    }

    /**
     * @return array{nome: string|null, nif: string|null, morada: string|null, codigo_postal: string|null, localidade: string|null}
     */
    public function resolve(User $user): array
    {
        $personal = $this->memberDataReadService->personalPayload($user);

        return [
            'nome' => $this->resolveNome($user, $personal),
            'nif' => $this->normalizedString($personal['nif'] ?? null),
            'morada' => $this->normalizedString($personal['morada'] ?? null),
            'codigo_postal' => $this->normalizedString($personal['codigo_postal'] ?? null),
            'localidade' => $this->normalizedString($personal['localidade'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $personal
     */
    private function resolveNome(User $user, array $personal): ?string
    {
        $nome = $this->normalizedString($personal['nome_completo'] ?? null);
        if ($nome !== null) {
            return $nome;
        }

        return $this->normalizedString($user->name);
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
