<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\CommunicationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CommunicationDelivery::query()
            ->with(['campaign:id,codigo,title', 'segment:id,name'])
            ->withCount([
                'attempts',
                'recipients as retryable_count' => fn ($builder) => $builder
                    ->where('status', 'failed')
                    ->whereColumn('attempt_count', '<', 'max_attempts')
                    ->whereNotNull('next_attempt_at'),
                'recipients as exhausted_count' => fn ($builder) => $builder
                    ->where('status', 'failed')
                    ->whereColumn('attempt_count', '>=', 'max_attempts'),
            ])
            ->latest();

        if ($request->filled('channel')) {
            $query->where('channel', $request->string('channel')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->string('campaign_id')->toString());
        }

        return response()->json($query->paginate(20));
    }
}
