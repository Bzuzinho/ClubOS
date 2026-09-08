<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

final class MemberAccessActivationController extends Controller
{
    public function create(Request $request, PlatformAccessService $platformAccessService): Response
    {
        $email = (string) $request->query('email', '');
        $token = (string) $request->route('token');
        $invitedUser = User::query()->where('email', $email)->first();
        $invitationValid = $invitedUser instanceof User
            && $platformAccessService->canActivatePlatformAccess($invitedUser)
            && Password::broker('member_access')->tokenExists($invitedUser, $token);

        return Inertia::render('Auth/ActivateAccess', [
            'email' => $email,
            'token' => $token,
            'invitationValid' => $invitationValid,
            'expiresInHours' => max(1, (int) ceil(((int) config('auth.passwords.member_access.expire', 4320)) / 60)),
        ]);
    }

    public function store(Request $request, PlatformAccessService $platformAccessService): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::min(8)->letters()->numbers()],
        ], [
            'email.required' => 'Não foi possível identificar o email deste convite.',
            'email.email' => 'O email associado ao convite não é válido.',
            'password.required' => 'Escolha uma palavra-passe.',
            'password.confirmed' => 'As duas palavras-passe não são iguais.',
            'password.min' => 'A palavra-passe deve ter pelo menos 8 caracteres.',
            'password.letters' => 'A palavra-passe deve incluir pelo menos uma letra.',
            'password.numbers' => 'A palavra-passe deve incluir pelo menos um número.',
        ]);

        $invitedUser = User::query()->where('email', $validated['email'])->first();

        if (! $invitedUser instanceof User || ! $platformAccessService->canActivatePlatformAccess($invitedUser)) {
            return back()->withErrors([
                'email' => 'Este convite já não está ativo. Peça ao clube para enviar um novo convite.',
            ]);
        }

        $activatedUser = null;
        $status = Password::broker('member_access')->reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password) use (&$activatedUser, $platformAccessService): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                $platformAccessService->activatePlatformAccess($user);
                $activatedUser = $user;

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $activatedUser instanceof User) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Este link expirou ou já foi utilizado. Peça ao clube para enviar um novo convite.',
            ]);
        }

        Auth::login($activatedUser);
        $request->session()->regenerate();
        $request->session()->put('onboarding.just_activated', true);

        return redirect()->route('onboarding.welcome');
    }
}
