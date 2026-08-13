<?php

namespace App\Services\Desportivo;

use App\Models\SportsModality;
use App\Models\SportsStroke;
use App\Models\SportsTrainingMaterial;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanSeries;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingTypeConfig;
use App\Models\TrainingZoneConfig;
use Illuminate\Support\Collection;

final class SportsTrainingLibraryQueryService
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    public function payload(): array
    {
        $clubId = $this->clubContext->id();
        $plans = TrainingPlan::query()->withTrashed()->where('club_id', $clubId)->with([
            'creator:id,nome_completo', 'modality:id,code,name',
            'currentVersion.blocks.series.zone', 'currentVersion.blocks.series.stroke', 'currentVersion.blocks.series.materials',
            'currentVersion.series.zone', 'currentVersion.series.stroke', 'currentVersion.series.materials',
            'versions' => fn ($query) => $query->withCount('sessions')->orderByDesc('version'),
        ])->orderByDesc('updated_at')->get()->map(fn (TrainingPlan $plan): array => $this->mapPlan($plan))->values()->all();

        return [
            'libraryPlans' => $plans,
            'libraryModalities' => SportsModality::query()->forClub($clubId)->where('active', true)->orderBy('name')->get(['id', 'code', 'name'])->values(),
            'libraryTrainingTypes' => TrainingTypeConfig::query()->forClub($clubId)->ativo()->ordenado()->get(['id', 'codigo', 'nome'])->values(),
            'libraryZones' => TrainingZoneConfig::query()->forClub($clubId)->ativo()->ordenado()->get(['id', 'codigo', 'nome'])->values(),
            'libraryStrokes' => SportsStroke::query()->forClub($clubId)->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name'])->values(),
            'libraryMaterials' => SportsTrainingMaterial::query()->forClub($clubId)->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name'])->values(),
        ];
    }

    private function mapPlan(TrainingPlan $plan): array
    {
        $version = $plan->currentVersion; $blocks = $version ? $this->versionBlocks($version) : [];
        $zoneDistribution = []; $strokeDistribution = []; $materialMap = [];
        foreach ($blocks as $block) {
            $rounds = max(1, (int) $block['rounds']);
            foreach ($block['series'] as $line) {
                $distance = ((int) ($line['distancia_total_m'] ?? 0)) * $rounds;
                $zoneKey = (string) ($line['zona_codigo'] ?? 'Sem zona');
                $strokeKey = (string) ($line['estilo_nome'] ?? $line['estilo'] ?? 'Outro');
                $zoneDistribution[$zoneKey] = ($zoneDistribution[$zoneKey] ?? 0) + $distance;
                $strokeDistribution[$strokeKey] = ($strokeDistribution[$strokeKey] ?? 0) + $distance;
                foreach ($line['materials'] as $material) $materialMap[$material['id']] = $material;
            }
        }
        $volume = (int) ($version?->volume_planeado_m ?? array_sum($zoneDistribution));

        return [
            'id' => (string) $plan->id, 'nome' => $plan->nome, 'codigo' => $plan->codigo, 'descricao' => $plan->descricao,
            'sports_modality_id' => $plan->sports_modality_id, 'modalidade' => $plan->modality?->name ?? $plan->modalidade,
            'tags' => $plan->tags ?? [], 'estado' => $plan->estado, 'archived' => $plan->trashed(),
            'autor' => $plan->creator?->nome_completo, 'updated_at' => $plan->updated_at?->toIso8601String(),
            'current_version' => $version ? [
                'id' => (string) $version->id, 'version' => (int) $version->version, 'tipo_treino' => $version->tipo_treino,
                'descricao_treino' => $version->descricao_treino, 'notas_gerais' => $version->notas_gerais,
                'volume_planeado_m' => $volume, 'instrucao' => $version->instrucao, 'motivo_revisao' => $version->motivo_revisao,
                'publicado_em' => $version->publicado_em?->toIso8601String(), 'blocks' => $blocks,
                'zone_distribution' => $this->distribution($zoneDistribution, $volume),
                'stroke_distribution' => $this->distribution($strokeDistribution, $volume), 'materials' => array_values($materialMap),
            ] : null,
            'versions' => $plan->versions->sortByDesc('version')->map(fn (TrainingPlanVersion $item): array => [
                'id' => (string) $item->id, 'version' => (int) $item->version, 'motivo_revisao' => $item->motivo_revisao,
                'publicado_em' => $item->publicado_em?->toIso8601String(), 'created_at' => $item->created_at?->toIso8601String(),
                'sessions_count' => (int) ($item->sessions_count ?? 0),
            ])->values()->all(),
        ];
    }

    private function versionBlocks(TrainingPlanVersion $version): array
    {
        if ($version->blocks->isNotEmpty()) return $version->blocks->map(fn (TrainingPlanBlock $block): array => [
            'id' => (string) $block->id, 'name' => $block->name, 'rounds' => (int) $block->rounds, 'notes' => $block->notes,
            'series' => $block->series->map(fn (TrainingPlanSeries $line): array => $this->mapSeries($line))->values()->all(),
        ])->values()->all();

        $groups = $version->series->groupBy(fn (TrainingPlanSeries $line): string => $line->bloco ?: 'Treino');
        return $groups->map(fn (Collection $series, string $name): array => [
            'id' => null, 'name' => $name, 'rounds' => 1, 'notes' => null,
            'series' => $series->map(fn (TrainingPlanSeries $line): array => $this->mapSeries($line))->values()->all(),
        ])->values()->all();
    }

    private function mapSeries(TrainingPlanSeries $line): array
    {
        $materials = $line->materials->map(fn (SportsTrainingMaterial $material): array => ['id' => (string) $material->id, 'code' => $material->code, 'name' => $material->name])->values()->all();
        if ($materials === [] && is_array($line->material)) {
            $materials = collect($line->material)->map(function ($item): array {
                if (is_array($item)) return ['id' => (string) ($item['id'] ?? $item['code'] ?? $item['name'] ?? ''), 'code' => $item['code'] ?? null, 'name' => $item['name'] ?? $item['code'] ?? 'Material'];
                return ['id' => (string) $item, 'code' => null, 'name' => (string) $item];
            })->values()->all();
        }
        return [
            'id' => (string) $line->id, 'repeticoes' => $line->repeticoes, 'distancia_m' => $line->distancia_m,
            'distancia_total_m' => $line->distancia_total_m, 'exercicio' => $line->exercicio,
            'sports_stroke_id' => $line->sports_stroke_id, 'estilo' => $line->estilo, 'estilo_nome' => $line->stroke?->name ?? $line->estilo,
            'training_zone_config_id' => $line->training_zone_config_id, 'zona_codigo' => $line->zone?->codigo ?? $line->zona_intensidade,
            'intervalo' => $line->intervalo, 'saida' => $line->saida, 'timing_mode' => $line->timing_mode ?: 'none',
            'observacoes' => $line->observacoes, 'materials' => $materials,
        ];
    }

    private function distribution(array $values, int $total): array
    {
        arsort($values);
        return collect($values)->map(fn (int $meters, string $label): array => [
            'label' => $label, 'meters' => $meters, 'percent' => $total > 0 ? round(($meters / $total) * 100, 1) : 0.0,
        ])->values()->all();
    }
}
