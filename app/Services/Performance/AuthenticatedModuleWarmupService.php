<?php

namespace App\Services\Performance;

use App\Http\Controllers\ComunicacaoController;
use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Desportivo\SportsDashboardWorkspaceController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\FinanceiroController;
use App\Models\InAppAlert;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuthenticatedModuleWarmupService
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function scheduleForUser(?User $user): void
    {
        if (! $user || Cache::has($this->freshKey($user->id))) {
            return;
        }

        App::terminating(function () use ($user) {
            $this->warmForUserId($user->id);
        });
    }

    public function warmForUserId(string $userId): void
    {
        if (Cache::has($this->freshKey($userId)) || ! Cache::add($this->lockKey($userId), true, now()->addMinutes(2))) {
            return;
        }

        try {
            $user = User::query()->find($userId);

            if (! $user) {
                return;
            }

            Cache::remember(
                'shared:access_control:' . $user->id,
                now()->addMinutes(5),
                fn () => $this->accessControlService->getCurrentUserAccess($user)
            );

            if (Schema::hasTable('in_app_alerts')) {
                Cache::remember(
                    'shared:communication_alerts:' . $user->id,
                    now()->addSeconds(30),
                    fn () => $this->buildCommunicationAlertsPayload($user)
                );
            }

            $this->runWithRequest($user, '/dashboard', fn () => app(DashboardController::class)->index());
            $this->runWithRequest($user, '/comunicacao', fn (Request $request) => app(ComunicacaoController::class)->index($request));
            $this->runWithRequest($user, '/configuracoes', fn (Request $request) => app(ConfiguracoesController::class)->index($request));
            $this->runWithRequest($user, '/eventos', fn (Request $request) => app(EventosController::class)->index($request));
            $this->runWithRequest($user, '/financeiro', fn () => app(FinanceiroController::class)->index());
            $this->runWithRequest($user, '/desportivo', fn () => app(SportsDashboardWorkspaceController::class)->index());

            Cache::put($this->freshKey($user->id), true, now()->addMinutes(5));
        } finally {
            Cache::forget($this->lockKey($userId));
        }
    }

    private function runWithRequest(User $user, string $path, callable $callback): void
    {
        $originalRequest = App::bound('request') ? App::make('request') : null;
        $originalUser = Auth::guard('web')->user();
        $request = Request::create($path, 'GET');

        if (App::bound('session.store')) {
            $request->setLaravelSession(App::make('session.store'));
        }

        $request->setUserResolver(fn () => $user);

        App::instance('request', $request);
        Auth::shouldUse('web');
        Auth::guard('web')->setUser($user);

        try {
            $callback($request);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            if ($originalRequest) {
                App::instance('request', $originalRequest);
            } else {
                App::forgetInstance('request');
            }

            if ($originalUser) {
                Auth::guard('web')->setUser($originalUser);
            } else {
                Auth::guard('web')->forgetUser();
            }
        }
    }

    private function buildCommunicationAlertsPayload(User $user): array
    {
        return [
            'unreadCount' => InAppAlert::where('user_id', $user->id)
                ->where('is_read', false)
                ->where(function ($query) {
                    $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('visible_until')->orWhere('visible_until', '>=', now());
                })
                ->count(),
            'recent' => InAppAlert::where('user_id', $user->id)
                ->where(function ($query) {
                    $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('visible_until')->orWhere('visible_until', '>=', now());
                })
                ->latest()
                ->limit(8)
                ->get(['id', 'title', 'message', 'type', 'link', 'is_read', 'created_at']),
        ];
    }

    private function freshKey(string $userId): string
    {
        return 'performance:warm-modules:fresh:' . $userId;
    }

    private function lockKey(string $userId): string
    {
        return 'performance:warm-modules:lock:' . $userId;
    }
}
