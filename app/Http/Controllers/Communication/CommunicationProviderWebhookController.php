<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\CommunicationProviderWebhookRequest;
use App\Services\Communication\CommunicationProviderEventService;
use Illuminate\Http\JsonResponse;

final class CommunicationProviderWebhookController extends Controller
{
    public function __construct(private readonly CommunicationProviderEventService $eventService)
    {
    }

    public function __invoke(CommunicationProviderWebhookRequest $request, string $provider): JsonResponse
    {
        $result = $this->eventService->handle($provider, $request->validated());

        $statusCode = match ($result['status']) {
            'unmatched' => 202,
            'conflict' => 409,
            default => 200,
        };

        return response()->json($result, $statusCode);
    }
}
