<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyCommunicationWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');
        $secret = trim((string) config("services.communication_webhooks.secrets.{$provider}", ''));

        if ($secret === '') {
            return new JsonResponse(['message' => 'Webhook não configurado.'], 503);
        }

        $timestamp = trim((string) $request->header('X-ClubOS-Timestamp', ''));
        $signature = trim((string) $request->header('X-ClubOS-Signature', ''));
        $maxAge = max(30, (int) config('services.communication_webhooks.max_age_seconds', 300));

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $maxAge) {
            return new JsonResponse(['message' => 'Assinatura inválida ou expirada.'], 401);
        }

        $providedSignature = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;
        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($providedSignature === '' || ! hash_equals($expectedSignature, $providedSignature)) {
            return new JsonResponse(['message' => 'Assinatura inválida ou expirada.'], 401);
        }

        return $next($request);
    }
}
