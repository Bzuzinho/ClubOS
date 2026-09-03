<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SocialNetworkAccount;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMetaWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');
        $account = SocialNetworkAccount::query()->where('provider', $provider)->first();
        $secret = trim((string) $account?->app_secret);

        if ($secret === '') {
            return new JsonResponse(['message' => 'Webhook Meta não configurado.'], 503);
        }

        $signature = trim((string) $request->header('X-Hub-Signature-256', ''));
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : '';
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['message' => 'Assinatura Meta inválida.'], 401);
        }

        return $next($request);
    }
}
