<?php

namespace App\Services\Desportivo;

use App\Models\SportsTrainingMaterial;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanSeries;
use App\Models\TrainingPlanVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TrainingPlanDuplicateService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly TrainingPlanService $plans,
    ) {
    }

    /** @param array<string,mixed> $overrides */
    public function duplicate(TrainingPlan $plan, User $actor, array $overrides = []): TrainingPlan
    {
        $this->assertTenant($plan);
        $plan->loadMissing([
            'currentVersion.blocks.series.zone',
            'currentVersion.blocks.series.stroke',
            'currentVersion.blocks.series.materials',
            'currentVersion.series.zone',
            'currentVersion.series.stroke',
            'currentVersion.series.materials',
        ]);

        $version = $plan->currentVersion;
        if ($version === null) {
            throw ValidationException::withMessages([
                'training_plan' => 'O plano não tem uma versão utilizável para duplicar.',
            ]);
        }

        $blocks = $version->blocks->isNotEmpty()
            ? $version->blocks->map(fn (TrainingPlanBlock $block): array => [
                'nome' => $block->name,
                'rondas' => max(1, (int) $block->rounds),
                'notas' => $block->notes,
                'series' => $block->series->map(fn (TrainingPlanSeries $line): array => $this->seriesPayload($line))->values()->all(),
            ])->values()->all()
            : $this->legacyBlocks($version);

        if ($blocks === []) {
            throw ValidationException::withMessages([
                'training_plan' => 'A versão corrente não contém séries que possam ser duplicadas.',
            ]);
        }

        return $this->plans->create([
            'nome' => trim((string) ($overrides['nome'] ?? ($plan->nome . ' — cópia'))),
            'codigo' => $this->nullableString($overrides['codigo'] ?? null),
            'descricao' => $plan->descricao,
            'sports_modality_id' => $plan->sports_modality_id,
            'modalidade' => $plan->modalidade,
            'tags' => $plan->tags ?? [],
            'estado' => 'draft',
            'tipo_treino' => $version->tipo_treino,
            'descricao_treino' => $version->descricao_treino,
            'notas_gerais' => $version->notas_gerais,
            'instrucao' => $version->instrucao,
            'metadados' => $version->metadados,
            'blocks' => $blocks,
        ], $actor);
    }

    /** @return array<int,array<string,mixed>> */
    private function legacyBlocks(TrainingPlanVersion $version): array
    {
        /** @var Collection<string,Collection<int,TrainingPlanSeries>> $grouped */
        $grouped = $version->series->groupBy(fn (TrainingPlanSeries $line): string => $line->bloco ?: 'Treino');

        return $grouped->map(fn (Collection $series, string $name): array => [
            'nome' => $name,
            'rondas' => 1,
            'notas' => null,
            'series' => $series->map(fn (TrainingPlanSeries $line): array => $this->seriesPayload($line))->values()->all(),
        ])->values()->all();
    }

    /** @return array<string,mixed> */
    private function seriesPayload(TrainingPlanSeries $line): array
    {
        $materialIds = $line->materials->pluck('id')->map('strval')->all();
        $materialSnapshot = $materialIds === [] && is_array($line->material) ? $line->material : null;

        return [
            'repeticoes' => $line->repeticoes,
            'distancia_m' => $line->distancia_m,
            'distancia_total_m' => $line->distancia_total_m,
            'exercicio' => $line->exercicio,
            'sports_stroke_id' => $line->sports_stroke_id,
            'estilo' => $line->stroke?->name ?? $line->estilo,
            'training_zone_config_id' => $line->training_zone_config_id,
            'zona' => $line->zone?->codigo ?? $line->zona_intensidade,
            'intervalo' => $line->intervalo,
            'saida' => $line->saida,
            'timing_mode' => $line->timing_mode ?: 'none',
            'observacoes' => $line->observacoes,
            'material_ids' => $materialIds,
            'material' => $materialSnapshot,
        ];
    }

    private function assertTenant(TrainingPlan $plan): void
    {
        if ((string) $plan->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training_plan' => 'O plano de treino pertence a outro clube.',
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
