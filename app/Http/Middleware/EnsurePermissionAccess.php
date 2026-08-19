<?php

namespace App\Http\Middleware;

use App\Services\AccessControl\UserTypeAccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermissionAccess
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permissionKey, string $capability = 'view'): Response
    {
        // The current permission model stores creation and edition under the same
        // can_edit capability (legacy pode_criar/pode_editar are both projected
        // from it). Route declarations may still use the semantically clearer
        // "create" capability, so normalize it before enforcing the policy.
        $normalizedCapability = $capability === 'create' ? 'edit' : $capability;

        abort_unless(
            $this->accessControlService->canAccessPermission($request->user(), $permissionKey, $normalizedCapability)
                || $this->accessControlService->canBypassOwnMemberProfileView($request->user(), $request, null, $permissionKey, $normalizedCapability),
            Response::HTTP_FORBIDDEN,
            'Sem permissão para executar esta ação.'
        );

        return $next($request);
    }
}
