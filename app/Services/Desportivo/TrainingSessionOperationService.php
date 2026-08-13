<?php

namespace App\Services\Desportivo;

use App\Models\SportsStroke;
use App\Models\SportsTrainingMaterial;
use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingSeries;
use App\Models\TrainingSessionContentRevision;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingSessionOperationService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly TrainingSessionPlanService $planService,
    ) {}

    public function cancel(Training $training, string $reason, User $actor): Training
    {
        $this->assertMutable($training);
        $reason = trim($reason);
        if ($reason === '') throw ValidationException::withMessages(['reason' => 'O motivo do cancelamento é obrigatório.']);

        return DB::transaction(function () use ($training, $reason, $actor): Training {
            $locked = Training::query()->whereKey($training->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($locked);
            $locked->forceFill([
                'session_status' => 'cancelled', 'cancelled_at' => now(),
                'cancelled_by' => $actor->id, 'cancellation_reason' => $reason,
            ])->save();
            return $locked->fresh($this->relations());
        }, 3);
    }

    public function applyPlanVersion(Training $training, TrainingPlanVersion $version, User $actor, ?string $reason = null): Training
    {
        $this->assertMutable($training); $this->assertVersionTenant($version);
        $training->loadMissing('planVersion');
        if (! $training->planVersion) {
            throw ValidationException::withMessages(['training_plan_version_id' => 'Esta sessão não tem um plano global aplicado. A atribuição de conteúdo pertence ao Planeamento.']);
        }
        if ((string) $training->planVersion->training_plan_id !== (string) $version->training_plan_id) {
            throw ValidationException::withMessages(['training_plan_version_id' => 'A nova versão tem de pertencer ao mesmo plano atualmente aplicado à sessão.']);
        }
        $before = $this->snapshot($training->fresh(['series', 'planVersion']));

        return DB::transaction(function () use ($training, $version, $actor, $reason, $before): Training {
            $updated = $this->planService->assign($training, $version, $actor);
            $updated->forceFill(['content_override_at' => null, 'content_override_by' => null, 'content_override_reason' => null])->save();
            $after = $this->snapshot($updated->fresh(['series', 'planVersion']));
            $this->recordRevision($updated, 'plan_version', $reason ?: "Aplicação explícita da versão {$version->version} do plano.", $before, $after, $actor, $version);
            return $updated->fresh($this->relations());
        }, 3);
    }

    public function overrideSnapshot(Training $training, array $blocks, string $reason, User $actor): Training
    {
        $this->assertMutable($training);
        if (! $training->series()->exists()) {
            throw ValidationException::withMessages(['blocks' => 'Esta sessão não tem um snapshot técnico global para adaptar. O conteúdo por grupo deve ser alterado no Planeamento.']);
        }
        $reason = trim($reason);
        if ($reason === '') throw ValidationException::withMessages(['reason' => 'O motivo da adaptação desta sessão é obrigatório.']);
        $normalized = $this->normalizeBlocks($blocks);
        if ($normalized === []) throw ValidationException::withMessages(['blocks' => 'A sessão tem de manter pelo menos uma série técnica.']);
        $before = $this->snapshot($training->fresh(['series', 'planVersion']));

        return DB::transaction(function () use ($training, $normalized, $reason, $actor, $before): Training {
            $locked = Training::query()->whereKey($training->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($locked); $locked->series()->delete();
            $order = 1; $volume = 0;
            foreach ($normalized as $blockOrder => $block) {
                foreach ($block['series'] as $row) {
                    $lineTotal = ((int) ($row['repeticoes'] ?? 0)) * ((int) ($row['distancia_m'] ?? 0));
                    $volume += $lineTotal * $block['rounds'];
                    TrainingSeries::query()->create([
                        'treino_id' => $locked->id, 'ordem' => $order++, 'descricao_texto' => $row['exercicio'],
                        'distancia_total_m' => $lineTotal, 'zona_intensidade' => $row['zone_code'],
                        'training_zone_config_id' => $row['training_zone_config_id'], 'estilo' => $row['stroke_name'],
                        'sports_stroke_id' => $row['sports_stroke_id'], 'repeticoes' => $row['repeticoes'],
                        'intervalo' => $row['intervalo'], 'observacoes' => $row['observacoes'],
                        'training_plan_version_id' => $locked->training_plan_version_id, 'training_plan_series_id' => null,
                        'source' => 'session_override', 'bloco' => $block['name'], 'block_name' => $block['name'],
                        'block_order' => $blockOrder + 1, 'block_rounds' => $block['rounds'],
                        'distancia_m' => $row['distancia_m'], 'saida' => $row['saida'],
                        'timing_mode' => $row['timing_mode'], 'material' => $row['material_snapshot'],
                    ]);
                }
            }
            $locked->forceFill([
                'volume_planeado_m' => $volume, 'content_override_at' => now(),
                'content_override_by' => $actor->id, 'content_override_reason' => $reason,
            ])->save();
            $after = $this->snapshot($locked->fresh(['series', 'planVersion']));
            $this->recordRevision($locked, 'snapshot_override', $reason, $before, $after, $actor, $locked->planVersion);
            return $locked->fresh($this->relations());
        }, 3);
    }

    private function normalizeBlocks(array $blocks): array
    {
        $result = [];
        foreach (array_values($blocks) as $blockIndex => $block) {
            if (! is_array($block)) continue;
            $name = trim((string) ($block['name'] ?? $block['nome'] ?? ''));
            if ($name === '') $name = 'Bloco ' . ($blockIndex + 1);
            $rounds = max(1, min(99, (int) ($block['rounds'] ?? $block['rondas'] ?? 1)));
            $series = [];
            foreach (array_values($block['series'] ?? []) as $row) {
                if (! is_array($row)) continue;
                $repetitions = max(1, (int) ($row['repeticoes'] ?? 1));
                $distance = max(0, (int) ($row['distancia_m'] ?? 0));
                $exercise = trim((string) ($row['exercicio'] ?? $row['descricao_texto'] ?? ''));
                if ($distance <= 0 && $exercise === '') continue;
                $zone = $this->zone($row['training_zone_config_id'] ?? null);
                $stroke = $this->stroke($row['sports_stroke_id'] ?? null);
                $timingMode = (string) ($row['timing_mode'] ?? 'none');
                if (! in_array($timingMode, ['none','each_rep','whole_series'], true)) $timingMode = 'none';
                $series[] = [
                    'repeticoes' => $repetitions, 'distancia_m' => $distance, 'exercicio' => $exercise !== '' ? $exercise : null,
                    'training_zone_config_id' => $zone?->id, 'zone_code' => $zone?->codigo ?? $this->nullableText($row['zona_intensidade'] ?? null),
                    'sports_stroke_id' => $stroke?->id, 'stroke_name' => $stroke?->name ?? $this->nullableText($row['estilo'] ?? null),
                    'intervalo' => $this->nullableText($row['intervalo'] ?? null), 'saida' => $this->nullableText($row['saida'] ?? null),
                    'timing_mode' => $timingMode, 'material_snapshot' => $this->materials($row['material_ids'] ?? [], $row['material'] ?? null),
                    'observacoes' => $this->nullableText($row['observacoes'] ?? null),
                ];
            }
            if ($series !== []) $result[] = ['name' => $name, 'rounds' => $rounds, 'series' => $series];
        }
        return $result;
    }

    private function zone(mixed $id): ?TrainingZoneConfig
    {
        $id = trim((string) $id); if ($id === '') return null;
        $zone = TrainingZoneConfig::query()->where('club_id', $this->clubContext->id())->whereKey($id)->first();
        if (! $zone) throw ValidationException::withMessages(['blocks' => 'Foi indicada uma zona de treino que não pertence ao clube ativo.']);
        return $zone;
    }

    private function stroke(mixed $id): ?SportsStroke
    {
        $id = trim((string) $id); if ($id === '') return null;
        $stroke = SportsStroke::query()->where('club_id', $this->clubContext->id())->whereKey($id)->first();
        if (! $stroke) throw ValidationException::withMessages(['blocks' => 'Foi indicado um estilo que não pertence ao clube ativo.']);
        return $stroke;
    }

    private function materials(mixed $ids, mixed $legacy): ?array
    {
        $ids = collect(is_array($ids) ? $ids : [])->filter()->map('strval')->unique()->values();
        if ($ids->isEmpty()) return is_array($legacy) && $legacy !== [] ? $legacy : null;
        $materials = SportsTrainingMaterial::query()->where('club_id', $this->clubContext->id())->whereIn('id', $ids->all())->get();
        if ($materials->count() !== $ids->count()) throw ValidationException::withMessages(['blocks' => 'Foi indicado material técnico que não pertence ao clube ativo.']);
        return $materials->map(fn (SportsTrainingMaterial $material): array => ['id' => (string) $material->id, 'code' => $material->code, 'name' => $material->name])->values()->all();
    }

    private function snapshot(Training $training): array
    {
        $training->loadMissing(['series','planVersion']);
        return [
            'training_plan_version_id' => $training->training_plan_version_id ? (string) $training->training_plan_version_id : null,
            'plan_version' => $training->planVersion?->version, 'volume_planeado_m' => $training->volume_planeado_m,
            'series' => $training->series->map(fn (TrainingSeries $line): array => [
                'ordem' => $line->ordem, 'block_name' => $line->block_name ?? $line->bloco, 'block_order' => $line->block_order,
                'block_rounds' => $line->block_rounds, 'repeticoes' => $line->repeticoes, 'distancia_m' => $line->distancia_m,
                'distancia_total_m' => $line->distancia_total_m, 'exercicio' => $line->descricao_texto,
                'zona_intensidade' => $line->zona_intensidade, 'training_zone_config_id' => $line->training_zone_config_id,
                'estilo' => $line->estilo, 'sports_stroke_id' => $line->sports_stroke_id, 'intervalo' => $line->intervalo,
                'saida' => $line->saida, 'timing_mode' => $line->timing_mode, 'material' => $line->material,
                'observacoes' => $line->observacoes, 'source' => $line->source,
            ])->values()->all(),
        ];
    }

    private function recordRevision(Training $training, string $type, string $reason, array $before, array $after, User $actor, ?TrainingPlanVersion $version = null): void
    {
        TrainingSessionContentRevision::query()->create([
            'club_id' => $this->clubContext->id(), 'training_id' => $training->id, 'revision_type' => $type,
            'source_plan_version_id' => $version?->id, 'reason' => $reason, 'before_snapshot' => $before,
            'after_snapshot' => $after, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function assertMutable(Training $training): void
    {
        if ((string) $training->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training' => 'A sessão pertence a outro clube.']);
        if ($training->isCompleted()) throw ValidationException::withMessages(['training' => 'Uma sessão concluída não pode ser alterada.']);
        if ($training->isCancelled()) throw ValidationException::withMessages(['training' => 'Uma sessão cancelada não pode ser alterada.']);
    }

    private function assertVersionTenant(TrainingPlanVersion $version): void
    {
        if ((string) $version->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training_plan_version_id' => 'A versão do plano pertence a outro clube.']);
    }

    private function nullableText(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function relations(): array { return ['season','macrocycle','mesocycle','microcycle','responsibleCoach','venue','pool','recurrence','planVersion.plan','series.zone','series.stroke','sessionGroups.group','sessionGroups.planVersion.plan','sessionGroups.lanes.pool','athleteRecords.atleta','scheduleExceptions','contentRevisions.creator','cancelledBy','contentOverrideBy']; }
}
