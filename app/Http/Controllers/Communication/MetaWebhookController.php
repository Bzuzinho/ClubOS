<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\SocialNetworkAccount;
use App\Services\Communication\MetaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetaWebhookController extends Controller
{
    public function verify(Request $request, string $provider): Response
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);
        $account = SocialNetworkAccount::query()->where('provider', $provider)->first();
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode !== 'subscribe' || blank($account?->webhook_verify_token) || ! hash_equals((string) $account->webhook_verify_token, $token)) {
            return response('Verificação recusada.', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaWebhookService $service, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);

        return response()->json(['status' => 'accepted', ...$service->handle($provider, $request->json()->all())]);
    }
}
