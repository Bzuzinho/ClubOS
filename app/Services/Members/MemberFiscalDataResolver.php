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
     * @return array{nome: string|null, nif: string|null, morada: string|null, codigo_postal: string|null, localidade: string|null, email_secundario: string|null, contacto: string|null}
     */
    public function resolve(User $user): array
    {
        $personal = $this->personalPayload($user);

        return [
            'nome' => $this->resolveNome($user, $personal),
            'nif' => $this->normalizedString($personal['nif'] ?? null),
            'morada' => $this->normalizedString($personal['morada'] ?? null),
            'codigo_postal' => $this->normalizedString($personal['codigo_postal'] ?? null),
            'localidade' => $this->normalizedString($personal['localidade'] ?? null),
            'email_secundario' => $this->normalizedString($personal['email_secundario'] ?? null),
            'contacto' => $this->normalizedString($personal['contacto'] ?? null),
        ];
    }

    /**
     * @return array{nome: string|null, nif: string|null, morada: string|null, codigo_postal: string|null, localidade: string|null, email_secundario: string|null, contacto: string|null}
     */
    public function fiscalPayload(User $user): array
    {
        return $this->resolve($user);
    }

    public function displayName(User $user): ?string
    {
        return $this->resolve($user)['nome'];
    }

    public function contact(User $user): ?string
    {
        return $this->resolve($user)['contacto'];
    }

    public function emailSecondary(User $user): ?string
    {
        return $this->resolve($user)['email_secundario'];
    }

    /**
     * @return array<string, mixed>
     */
    private function personalPayload(User $user): array
    {
        return $this->memberDataReadService->personalPayload($user);
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
