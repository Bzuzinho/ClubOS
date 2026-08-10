<?php

namespace App\Services\Desportivo;

use App\Models\TrainingPlan;
use App\Models\TrainingPlanSeries;
use App\Models\TrainingPlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingPlanService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, User $actor): TrainingPlan
    {
        $this->validatePlanPayload($data, true);

        return DB::transaction(function () use ($data, $actor): TrainingPlan {
            $plan = TrainingPlan::query()->create([
                'club_id' => $this->clubContext->id(),
                'nome' => trim((string) $data['nome']),
                'codigo' => $this->nullableString($data['codigo'] ?? null),
                'descricao' => $this->nullableString($data['descricao'] ?? null),
                'modalidade' => $this->nullableString($data['modalidade'] ?? null),
                'estado' => $data['estado'] ?? 'draft',
                'criado_por' => $actor->id,
            ]);

            $this->createVersion($plan, $data, $actor, 1);

            return $plan->fresh(['versions.series', 'currentVersion.series']);
        });
    }

    /**
     * Criar uma nova versão é a única forma suportada de alterar conteúdo do plano.
     * Versões anteriores nunca são atualizadas.
     *
     * @param array<string,mixed> $data
     */
    public function revise(TrainingPlan $plan, array $data, User $actor, ?string $reason = null): TrainingPlanVersion
    {
        $this->assertTenant($plan);
        $this->validatePlanPayload($data, false);

        return DB::transaction(function () use ($plan, $data, $actor, $reason): TrainingPlanVersion {
            $lockedPlan = TrainingPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertTenant($lockedPlan);

            if (array_key_exists('nome', $data) && filled($data['nome'])) {
                $lockedPlan->nome = trim((string) $data['nome']);
            }
            if (array_key_exists('descricao', $data)) {
                $lockedPlan->descricao = $this->nullableString($data['descricao']);
            }
            if (array_key_exists('modalidade', $data)) {
                $lockedPlan->modalidade = $this->nullableString($data['modalidade']);
            }
            if (array_key_exists('estado', $data)) {
                $lockedPlan->estado = (string) $data['estado'];
            }
            $lockedPlan->save();

            $nextVersion = ((int) $lockedPlan->versions()->max('version')) + 1;
            $payload = $data;
            $payload['motivo_revisao'] = $reason ?? ($data['motivo_revisao'] ?? null);

            return $this->createVersion($lockedPlan, $payload, $actor, $nextVersion)
                ->fresh(['series', 'plan']);
        });
    }

    public function archive(TrainingPlan $plan): void
    {
        $this->assertTenant($plan);

        DB::transaction(function () use ($plan): void {
            $plan->forceFill(['estado' => 'archived'])->save();
            $plan->delete();
        });
    }

    /** @param array<string,mixed> $data */
    private function createVersion(TrainingPlan $plan, array $data, User $actor, int $version): TrainingPlanVersion
    {
        $series = $this->normalizeSeries($data['series_linhas'] ?? $data['series'] ?? []);
        $volume = array_key_exists('volume_planeado_m', $data) && $data['volume_planeado_m'] !== null
            ? (int) $data['volume_planeado_m']
            : array_sum(array_column($series, 'distancia_total_m'));

        $versionModel = TrainingPlanVersion::query()->create([
            'club_id' => $this->clubContext->id(),
            'training_plan_id' => $plan->id,
            'version' => $version,
            'nome_snapshot' => trim((string) ($data['nome'] ?? $plan->nome)),
            'tipo_treino' => $this->nullableString($data['tipo_treino'] ?? null),
            'descricao_treino' => $this->nullableString($data['descricao_treino'] ?? null),
            'notas_gerais' => $this->nullableString($data['notas_gerais'] ?? null),
            'volume_planeado_m' => $volume > 0 ? $volume : null,
            'instrucao' => $this->nullableString($data['instrucao'] ?? null),
            'motivo_revisao' => $this->nullableString($data['motivo_revisao'] ?? null),
            'metadados' => is_array($data['metadados'] ?? null) ? $data['metadados'] : null,
            'criado_por' => $actor->id,
            'publicado_em' => !empty($data['publicar']) ? now() : null,
        ]);

        foreach ($series as $row) {
            TrainingPlanSeries::query()->create($row + [
                'club_id' => $this->clubContext->id(),
                'training_plan_version_id' => $versionModel->id,
            ]);
        }

        return $versionModel;
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSeries(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $repetitions = max(0, (int) ($row['repeticoes'] ?? 0));
            $distance = max(0, (int) ($row['distancia_m'] ?? $row['metros'] ?? 0));
            $total = max(0, (int) ($row['distancia_total_m'] ?? 0));
            if ($total === 0 && $repetitions > 0 && $distance > 0) {
                $total = $repetitions * $distance;
            }

            $exercise = $this->nullableString($row['exercicio'] ?? $row['descricao_texto'] ?? null);
            $zone = $this->nullableString($row['zona_intensidade'] ?? $row['zona'] ?? null);

            if ($repetitions === 0 && $distance === 0 && $total === 0 && $exercise === null && $zone === null) {
                continue;
            }

            $normalized[] = [
                'ordem' => count($normalized) + 1,
                'bloco' => $this->nullableString($row['bloco'] ?? null),
                'repeticoes' => $repetitions > 0 ? $repetitions : null,
                'distancia_m' => $distance > 0 ? $distance : null,
                'distancia_total_m' => $total > 0 ? $total : null,
                'exercicio' => $exercise,
                'estilo' => $this->nullableString($row['estilo'] ?? null),
                'zona_intensidade' => $zone,
                'intervalo' => $this->nullableString($row['intervalo'] ?? null),
                'saida' => $this->nullableString($row['saida'] ?? null),
                'material' => is_array($row['material'] ?? null) ? $row['material'] : null,
                'observacoes' => $this->nullableString($row['observacoes'] ?? null),
            ];
        }

        return $normalized;
    }

    /** @param array<string,mixed> $data */
    private function validatePlanPayload(array $data, bool $creating): void
    {
        $rules = [
            'nome' => ($creating ? 'required' : 'sometimes') . '|string|max:255',
            'codigo' => 'nullable|string|max:80',
            'descricao' => 'nullable|string',
            'modalidade' => 'nullable|string|max:80',
            'estado' => 'nullable|in:draft,published,archived',
            'tipo_treino' => 'nullable|string|max:100',
            'volume_planeado_m' => 'nullable|integer|min:0',
            'instrucao' => 'nullable|string',
            'motivo_revisao' => 'nullable|string',
            'metadados' => 'nullable|array',
            'series_linhas' => 'nullable|array',
            'series' => 'nullable|array',
        ];

        $validator = validator($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
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
