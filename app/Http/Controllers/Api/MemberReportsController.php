<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Members\MemberReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MemberReportsController extends Controller
{
    public function __invoke(Request $request, MemberReportService $memberReportService): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', Rule::in(['normal', 'detailed'])],
            'user_types' => ['nullable', 'array', 'max:50'],
            'user_types.*' => ['string', 'max:100'],
            'age_groups' => ['nullable', 'array', 'max:50'],
            'age_groups.*' => ['string', 'max:100'],
            'statuses' => ['nullable', 'array', 'max:10'],
            'statuses.*' => ['string', Rule::in(['ativo', 'inativo', 'suspenso'])],
        ]);

        return response()->json($memberReportService->build($validated));
    }
}
