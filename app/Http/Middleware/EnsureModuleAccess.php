<?php

namespace App\Http\Middleware;

use App\Services\AccessControl\AdministratorAuthority;
use App\Services\AccessControl\UserTypeAccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
        private readonly AdministratorAuthority $administratorAuthority,
    ) {
    }

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        if ($this->administratorAuthority->isAdministrator($request->user())) {
            return $next($request);
        }

        abort_unless(
            $this->accessControlService->canAccessModule($request->user(), $moduleKey)
                || $this->accessControlService->canBypassOwnMemberProfileView($request->user(), $request, $moduleKey),
            Response::HTTP_FORBIDDEN,
            'Sem permissão para aceder a este módulo.'
        );

        return $next($request);
    }
}
