<?php

namespace App\Http\Middleware;

use App\Services\AccessControl\AdministratorAuthority;
use App\Services\AccessControl\UserTypeAccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermissionAccess
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
        private readonly AdministratorAuthority $administratorAuthority,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permissionKey, string $capability = 'view'): Response
    {
        // The current permission model stores creation and edition under the same
        // can_edit capability (legacy pode_criar/pode_editar are both projected
        // from it). Route declarations may still use the semantically clearer
        // "create" capability, so normalize it before enforcing the policy.
        $normalizedCapability = $capability === 'create' ? 'edit' : $capability;
        $isAdministrator = $this->administratorAuthority->isAdministrator($request->user());

        if ($this->administratorAuthority->isProtectedGovernanceMutation($permissionKey, $normalizedCapability)) {
            abort_unless(
                $isAdministrator,
                Response::HTTP_FORBIDDEN,
                'A gestão de tipos de utilizador e permissões está reservada ao Administrador.'
            );
        }

        if ($isAdministrator) {
            return $next($request);
        }

        abort_unless(
            $this->accessControlService->canAccessPermission($request->user(), $permissionKey, $normalizedCapability)
                || $this->accessControlService->canBypassOwnMemberProfileView($request->user(), $request, null, $permissionKey, $normalizedCapability),
            Response::HTTP_FORBIDDEN,
            'Sem permissão para executar esta ação.'
        );

        return $next($request);
    }
}
