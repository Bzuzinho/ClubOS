<?php

namespace App\Services\AccessControl;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Str;

final class AdministratorAuthority
{
    /** @var list<string> */
    private const ADMIN_IDENTIFIERS = [
        'admin',
        'administrator',
        'administrador',
        'super_admin',
        'super_administrador',
        'superadministrator',
    ];

    public function isAdministrator(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isAdministratorIdentifier($user->perfil)) {
            return true;
        }

        return $user->userTypes()
            ->where('ativo', true)
            ->get(['codigo', 'nome'])
            ->contains(fn (UserType $userType): bool => $this->isAdministratorType($userType));
    }

    public function isAdministratorType(?UserType $userType): bool
    {
        if ($userType === null) {
            return false;
        }

        return $this->isAdministratorIdentifier($userType->codigo)
            || $this->isAdministratorIdentifier($userType->nome);
    }

    public function isProtectedGovernanceMutation(string $permissionKey, string $capability): bool
    {
        return in_array($capability, ['edit', 'delete'], true)
            && in_array($permissionKey, [
                'configuracoes.permissoes',
                'configuracoes.tipos_utilizador',
            ], true);
    }

    private function isAdministratorIdentifier(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $normalized = Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return in_array($normalized, self::ADMIN_IDENTIFIERS, true);
    }
}
