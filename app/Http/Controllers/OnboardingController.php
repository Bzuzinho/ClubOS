<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function appEntry(Request $request, UserTypeAccessControlService $accessControl): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->guest(route('login'));
        }

        return redirect()->to($accessControl->resolveLandingRouteForUser($request->user()));
    }

    public function install(Request $request, UserTypeAccessControlService $accessControl): Response
    {
        $continueUrl = $request->user()
            ? $accessControl->resolveLandingRouteForUser($request->user())
            : route('login');

        return Inertia::render('Onboarding/InstallApp', [
            'continueUrl' => $continueUrl,
            'firstAccess' => false,
        ]);
    }

    public function welcome(Request $request, UserTypeAccessControlService $accessControl): Response
    {
        return Inertia::render('Onboarding/InstallApp', [
            'continueUrl' => $accessControl->resolveLandingRouteForUser($request->user()),
            'firstAccess' => (bool) $request->session()->pull('onboarding.just_activated', false),
        ]);
    }
}
