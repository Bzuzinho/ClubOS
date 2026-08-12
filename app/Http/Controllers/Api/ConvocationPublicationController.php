<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConvocationGroup;
use App\Services\Desportivo\SportsConvocationPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConvocationPublicationController extends Controller
{
    public function __construct(private readonly SportsConvocationPublicationService $publicationService)
    {
    }

    public function __invoke(Request $request, ConvocationGroup $convocationGroup): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $result = $this->publicationService->publish($convocationGroup, $actor);

        return response()->json([
            'group' => [
                'id' => $result['group']->id,
                'publication_status' => $result['group']->publication_status,
                'publication_version' => $result['group']->publication_version,
                'published_at' => optional($result['group']->published_at)->toIso8601String(),
                'published_by' => $result['group']->published_by,
            ],
            'communication' => $result['communication']->toArray(),
        ]);
    }
}
