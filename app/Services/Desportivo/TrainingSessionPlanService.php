<?php

namespace App\Services\Desportivo;

use App\Models\SportsTrainingMaterial;
use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingSeries;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingSessionPlanService
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    public function assign(Training $session, TrainingPlanVersion $version, User $actor): Training
    {
        return DB::transaction(function () use ($session, $version, $actor): Training {
            $locked = Training::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            return $this->applyVersion($locked, $version, $actor);
        });
    }

    public function updateSelectedFutureSessions(TrainingPlanVersion $fromVersion, TrainingPlanVersion $toVersion, array $sessionIds, User $actor): array
    {
        $this->assertVersionTenant($fromVersion); $this->assertVersionTenant($toVersion);
        if ((string) $fromVersion->training_plan_id !== (string) $toVersion->training_plan_id) {
            throw ValidationException::withMessages(['training_plan_version_id' => 'As versões têm de pertencer ao mesmo plano de treino.']);
        }
        $ids = collect($sessionIds)->filter()->map('strval')->unique()->values();
        if ($ids->isEmpty()) return [];

        return DB::transaction(function () use ($ids, $fromVersion, $toVersion, $actor): array {
            $sessions = Training::query()->whereIn('id', $ids->all())->lockForUpdate()->get()->keyBy(fn (Training $training): string => (string) $training->id);
            if ($sessions->count() !== $ids->count()) throw ValidationException::withMessages(['training_ids' => 'Uma ou mais sessões selecionadas já não existem.']);
            $today = Carbon::today(); $updated = [];
            foreach ($ids as $id) {
                $session = $sessions->get($id); $this->assertSessionTenant($session);
                if ($session->isCompleted()) throw ValidationException::withMessages(['training_ids' => "A sessão {$session->numero_treino} já está concluída e não pode receber outra versão."]);
                if ($session->data === null || $session->data->startOfDay()->lt($today)) throw ValidationException::withMessages(['training_ids' => "A sessão {$session->numero_treino} não é futura e não pode ser atualizada em lote."]);
                if ((string) $session->training_plan_version_id !== (string) $fromVersion->id) throw ValidationException::withMessages(['training_ids' => "A sessão {$session->numero_treino} não utiliza a versão de origem selecionada."]);
                $updated[] = $this->applyVersion($session, $toVersion, $actor);
            }
            return $updated;
        });
    }

    public function complete(Training $session): Training
    {
        return DB::transaction(function () use ($session): Training {
            $locked = Training::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertSessionTenant($locked);
            if (!$locked->isCompleted()) $locked->forceFill(['session_status' => 'completed', 'completed_at' => now()])->save();
            return $locked->fresh(['planVersion', 'series']);
        });
    }

    private function applyVersion(Training $session, TrainingPlanVersion $version, User $actor): Training
    {
        $this->assertSessionTenant($session); $this->assertVersionTenant($version);
        if ($session->isCompleted()) throw ValidationException::withMessages(['training_plan_version_id' => 'Uma sessão concluída não pode ser alterada por uma revisão do plano.']);
        $version->loadMissing(['series.block', 'series.zone', 'series.stroke', 'series.materials']);
        $session->series()->delete();

        foreach ($version->series as $line) {
            $materialSnapshot = $line->materials->map(fn (SportsTrainingMaterial $material): array => ['id' => (string) $material->id, 'code' => $material->code, 'name' => $material->name])->values()->all();
            if ($materialSnapshot === [] && is_array($line->material)) $materialSnapshot = $line->material;
            TrainingSeries::query()->create([
                'treino_id' => $session->id, 'ordem' => $line->ordem, 'descricao_texto' => $line->exercicio ?? '',
                'distancia_total_m' => $line->distancia_total_m ?? 0, 'zona_intensidade' => $line->zone?->codigo ?? $line->zona_intensidade,
                'training_zone_config_id' => $line->training_zone_config_id, 'estilo' => $line->stroke?->name ?? $line->estilo,
                'sports_stroke_id' => $line->sports_stroke_id, 'repeticoes' => $line->repeticoes, 'intervalo' => $line->intervalo,
                'observacoes' => $line->observacoes, 'training_plan_version_id' => $version->id, 'training_plan_series_id' => $line->id,
                'source' => 'plan_version', 'bloco' => $line->block?->name ?? $line->bloco, 'block_name' => $line->block?->name ?? $line->bloco,
                'block_order' => $line->block?->sort_order, 'block_rounds' => max(1, (int) ($line->block?->rounds ?? 1)),
                'distancia_m' => $line->distancia_m, 'saida' => $line->saida, 'timing_mode' => $line->timing_mode ?: 'none',
                'material' => $materialSnapshot !== [] ? $materialSnapshot : null,
            ]);
        }

        $session->forceFill([
            'training_plan_version_id' => $version->id, 'tipo_treino' => $version->tipo_treino ?: $session->tipo_treino,
            'volume_planeado_m' => $version->volume_planeado_m, 'descricao_treino' => $version->descricao_treino,
            'notas_gerais' => $version->notas_gerais, 'instrucao' => $version->instrucao,
            'plan_applied_at' => now(), 'plan_applied_by' => $actor->id,
        ])->save();
        return $session->fresh(['planVersion.series', 'series']);
    }

    private function assertSessionTenant(Training $session): void
    {
        if ((string) $session->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training' => 'A sessão de treino pertence a outro clube.']);
    }
    private function assertVersionTenant(TrainingPlanVersion $version): void
    {
        if ((string) $version->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training_plan_version_id' => 'A versão do plano pertence a outro clube.']);
    }
}
