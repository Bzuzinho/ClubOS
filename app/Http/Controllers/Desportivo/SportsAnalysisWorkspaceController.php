<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Desportivo\SportsAnalysisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SportsAnalysisWorkspaceController extends Controller
{
    public function __construct(private readonly SportsAnalysisWorkspaceService $workspace)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/AnalysisWorkspace', $this->workspace->workspace());
    }

    public function athlete(Request $request, User $athlete): JsonResponse
    {
        $weeks = (int) $request->integer('weeks', 12);

        return response()->json($this->workspace->athlete($athlete, $weeks));
    }

    public function exportAthlete(Request $request, User $athlete): StreamedResponse
    {
        $weeks = (int) $request->integer('weeks', 12);
        $analysis = $this->workspace->athlete($athlete, $weeks);
        $filename = sprintf(
            'analise-%s-%s.csv',
            $athlete->id,
            $analysis['window']['to']
        );

        return response()->streamDownload(function () use ($analysis): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Secção', 'Indicador', 'Valor'], ';');
            fputcsv($output, ['Atleta', 'Nome', $analysis['athlete']['name']], ';');
            fputcsv($output, ['Janela', 'Semanas', $analysis['window']['weeks']], ';');
            fputcsv($output, ['Janela', 'De', $analysis['window']['from']], ';');
            fputcsv($output, ['Janela', 'Até', $analysis['window']['to']], ';');

            foreach ($analysis['kpis'] as $key => $value) {
                fputcsv($output, ['KPI', (string) $key, $value ?? ''], ';');
            }

            foreach ($analysis['training']['weekly'] as $week) {
                fputcsv($output, ['Treino semanal', $week['week'].' · volume_m', $week['volume_m']], ';');
                fputcsv($output, ['Treino semanal', $week['week'].' · avg_rpe', $week['avg_rpe'] ?? ''], ';');
            }

            foreach (['cais_metrics' => 'Métrica Cais', 'live_metrics' => 'Métrica Live'] as $key => $section) {
                foreach ($analysis['training'][$key] as $metric) {
                    fputcsv($output, [$section, $metric['name'].' · último', $metric['latest'] ?? ''], ';');
                    fputcsv($output, [$section, $metric['name'].' · média', $metric['average'] ?? ''], ';');
                    fputcsv($output, [$section, $metric['name'].' · amostra', $metric['count']], ';');
                }
            }

            foreach ($analysis['evaluations'] as $evaluation) {
                fputcsv($output, ['Avaliação', $evaluation['campaign'].' · score', $evaluation['score'] ?? ''], ';');
                if (! empty($evaluation['summary'])) {
                    fputcsv($output, ['Avaliação', $evaluation['campaign'].' · resumo', $evaluation['summary']], ';');
                }
            }

            foreach ($analysis['results'] as $result) {
                $prefix = $result['competition'].' · '.$result['race'];
                fputcsv($output, ['Resultado', $prefix.' · estado', $result['status']], ';');
                fputcsv($output, ['Resultado', $prefix.' · tempo_oficial_s', $result['official_time'] ?? ''], ';');
                fputcsv($output, ['Resultado', $prefix.' · posição', $result['position'] ?? ''], ';');
                fputcsv($output, ['Resultado', $prefix.' · pontos', $result['points'] ?? ''], ';');

                foreach ($result['splits'] as $split) {
                    fputcsv($output, ['Split', $prefix.' · '.$split['distance_m'].'m', $split['time']], ';');
                }
            }

            fputcsv($output, ['Metodologia', 'Aviso', $analysis['disclaimer']], ';');
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function group(Request $request, TrainingGroup $group): JsonResponse
    {
        $weeks = (int) $request->integer('weeks', 12);

        return response()->json($this->workspace->group($group, $weeks));
    }

    public function competition(Competition $competition): JsonResponse
    {
        return response()->json($this->workspace->competition($competition));
    }
}
