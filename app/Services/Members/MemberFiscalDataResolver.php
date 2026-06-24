<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\DadosPessoais;
use App\Models\User;

final class MemberFiscalDataResolver
{
    /**
     * @return array{nome: string|null, nif: string|null, morada: string|null, codigo_postal: string|null, localidade: string|null}
     */
    public function resolve(User $user): array
    {
        $user->loadMissing('dadosPessoais');

        /** @var DadosPessoais|null $dadosPessoais */
        $dadosPessoais = $user->dadosPessoais;

        return [
            'nome' => $this->resolveNome($user, $dadosPessoais),
            'nif' => $this->resolveField($dadosPessoais, 'nif', $user->nif),
            'morada' => $this->resolveField($dadosPessoais, 'morada', $user->morada),
            'codigo_postal' => $this->resolveField($dadosPessoais, 'codigo_postal', $user->codigo_postal),
            'localidade' => $this->resolveField($dadosPessoais, 'localidade', $user->localidade),
        ];
    }

    private function resolveNome(User $user, ?DadosPessoais $dadosPessoais): ?string
    {
        $nome = $this->resolveField($dadosPessoais, 'nome_completo', $user->nome_completo);
        if ($nome !== null) {
            return $nome;
        }

        return $this->normalizedString($user->name);
    }

    private function resolveField(?DadosPessoais $dadosPessoais, string $dadosPessoaisField, mixed $legacyValue): ?string
    {
        $canonicalValue = $this->normalizedString($dadosPessoais?->getAttribute($dadosPessoaisField));
        if ($canonicalValue !== null) {
            return $canonicalValue;
        }

        return $this->normalizedString($legacyValue);
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
