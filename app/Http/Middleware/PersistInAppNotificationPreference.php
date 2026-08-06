<?php

namespace App\Http\Middleware;

use App\Models\NotificationPreference;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersistInAppNotificationPreference
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('configuracoes.notificacoes.update') && $request->has('alertas_aplicacao')) {
            $request->validate([
                'alertas_aplicacao' => ['required', 'boolean'],
            ]);

            NotificationPreference::query()->firstOrCreate([])->update([
                'alertas_aplicacao' => $request->boolean('alertas_aplicacao'),
            ]);
        }

        return $next($request);
    }
}
