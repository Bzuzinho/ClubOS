<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Pessoas\PlatformAccessService;
use App\Services\Performance\AuthenticatedModuleWarmupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        UserTypeAccessControlService $accessControlService,
        AuthenticatedModuleWarmupService $moduleWarmupService,
        PlatformAccessService $platformAccessService,
    ): RedirectResponse
    {
        $request->authenticate($platformAccessService);

        $request->session()->regenerate();

        $moduleWarmupService->scheduleForUser($request->user());

        return redirect()->intended($accessControlService->resolveLandingRouteForUser($request->user()));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
