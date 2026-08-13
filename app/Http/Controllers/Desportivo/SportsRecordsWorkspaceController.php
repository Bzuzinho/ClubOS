<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Services\Desportivo\SportsRecordsReadModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

final class SportsRecordsWorkspaceController extends Controller
{
    public function __construct(private readonly SportsRecordsReadModelService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/RecordsWorkspace', $this->service->workspace($request));
    }

    public function training(Training $training): JsonResponse
    {
        return response()->json($this->service->trainingDetail($training));
    }

    public function athlete(Request $request, string $athlete): JsonResponse
    {
        return response()->json($this->service->athleteTimeline($athlete, $request));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->service->exportRows($request);
        $type = $request->string('record_type')->toString() ?: 'timing';
        $filename = 'registos-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $first = $rows->first();
            if (is_array($first)) {
                fputcsv($out, array_keys($first), ';');
                foreach ($rows as $row) fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
