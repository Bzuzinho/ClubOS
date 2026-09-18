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

        // Preserve the legacy profile field because existing installations may
        // still identify the structural administrator through users.perfil.
        if ($this->isAdministratorIdentifier($user->perfil)) {
            return true;
        }

        // UserType.nome is human-facing and editable. Authority must only be
        // derived from the canonical code so renaming a normal type can never
        // become a privilege-escalation path.
        return $user->userTypes()
            ->where('ativo', true)
            ->get(['codigo'])
            ->contains(fn (UserType $userType): bool => $this->isAdministratorType($userType));
    }

    public function isAdministratorType(?UserType $userType): bool
    {
        if ($userType === null) {
            return false;
        }

        return $this->isAdministratorIdentifier($userType->codigo);
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
