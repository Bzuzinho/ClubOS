<?php

namespace App\Services\Desportivo;

use App\Models\SportsModality;
use App\Models\SportsStroke;
use App\Models\SportsTrainingMaterial;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanBlock;
use App\Models\TrainingPlanSeries;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingPlanService
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    public function create(array $data, User $actor): TrainingPlan
    {
        $this->validatePlanPayload($data, true);
        $modality = $this->resolveModality($data['sports_modality_id'] ?? null);

        return DB::transaction(function () use ($data, $actor, $modality): TrainingPlan {
            $published = ! empty($data['publicar']) || ($data['estado'] ?? null) === 'published';
            $plan = TrainingPlan::query()->create([
                'club_id' => $this->clubContext->id(),
                'nome' => trim((string) $data['nome']),
                'codigo' => $this->nullableString($data['codigo'] ?? null),
                'descricao' => $this->nullableString($data['descricao'] ?? null),
                'modalidade' => $modality?->name ?? $this->nullableString($data['modalidade'] ?? null),
                'sports_modality_id' => $modality?->id,
                'tags' => $this->normalizeTags($data['tags'] ?? []),
                'estado' => $published ? 'published' : ($data['estado'] ?? 'draft'),
                'criado_por' => $actor->id,
            ]);

            $this->createVersion($plan, $data, $actor, 1);

            return $plan->fresh([
                'modality',
                'versions.blocks.series.zone',
                'versions.blocks.series.stroke',
                'versions.blocks.series.materials',
                'currentVersion.blocks.series.zone',
                'currentVersion.blocks.series.stroke',
                'currentVersion.blocks.series.materials',
            ]);
        });
    }

    public function revise(TrainingPlan $plan, array $data, User $actor, ?string $reason = null): TrainingPlanVersion
    {
        $this->assertTenant($plan);
        $this->validatePlanPayload($data, false);
        $modality = array_key_exists('sports_modality_id', $data) ? $this->resolveModality($data['sports_modality_id']) : null;

        return DB::transaction(function () use ($plan, $data, $actor, $reason, $modality): TrainingPlanVersion {
            $lockedPlan = TrainingPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertTenant($lockedPlan);

            if (array_key_exists('nome', $data) && filled($data['nome'])) $lockedPlan->nome = trim((string) $data['nome']);
            if (array_key_exists('descricao', $data)) $lockedPlan->descricao = $this->nullableString($data['descricao']);
            if (array_key_exists('sports_modality_id', $data)) {
                $lockedPlan->sports_modality_id = $modality?->id;
                $lockedPlan->modalidade = $modality?->name;
            } elseif (array_key_exists('modalidade', $data)) {
                $lockedPlan->modalidade = $this->nullableString($data['modalidade']);
            }
            if (array_key_exists('tags', $data)) $lockedPlan->tags = $this->normalizeTags($data['tags']);
            if (! empty($data['publicar'])) $lockedPlan->estado = 'published';
            elseif (array_key_exists('estado', $data)) $lockedPlan->estado = (string) $data['estado'];
            $lockedPlan->save();

            $nextVersion = ((int) $lockedPlan->versions()->max('version')) + 1;
            $payload = $data;
            $payload['motivo_revisao'] = $reason ?? ($data['motivo_revisao'] ?? null);

            return $this->createVersion($lockedPlan, $payload, $actor, $nextVersion)
                ->fresh(['blocks.series.zone', 'blocks.series.stroke', 'blocks.series.materials', 'series', 'plan']);
        });
    }

    public function duplicate(TrainingPlan $plan, User $actor, array $overrides = []): TrainingPlan
    {
        $this->assertTenant($plan);
        $plan->loadMissing(['currentVersion.blocks.series.zone', 'currentVersion.blocks.series.stroke', 'currentVersion.blocks.series.materials']);
        $version = $plan->currentVersion;
        if ($version === null) throw ValidationException::withMessages(['training_plan' => 'O plano não tem uma versão utilizável para duplicar.']);

        $blocks = $version->blocks->map(fn (TrainingPlanBlock $block): array => [
            'nome' => $block->name,
            'rondas' => $block->rounds,
            'notas' => $block->notes,
            'series' => $block->series->map(fn (TrainingPlanSeries $line): array => [
                'repeticoes' => $line->repeticoes,
                'distancia_m' => $line->distancia_m,
                'exercicio' => $line->exercicio,
                'sports_stroke_id' => $line->sports_stroke_id,
                'training_zone_config_id' => $line->training_zone_config_id,
                'intervalo' => $line->intervalo,
                'saida' => $line->saida,
                'timing_mode' => $line->timing_mode,
                'observacoes' => $line->observacoes,
                'material_ids' => $line->materials->pluck('id')->map('strval')->all(),
            ])->values()->all(),
        ])->values()->all();

        return $this->create([
            'nome' => trim((string) ($overrides['nome'] ?? ($plan->nome . ' — cópia'))),
            'codigo' => $this->nullableString($overrides['codigo'] ?? null),
            'descricao' => $plan->descricao,
            'sports_modality_id' => $plan->sports_modality_id,
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

    public function archive(TrainingPlan $plan): void
    {
        $this->assertTenant($plan);
        DB::transaction(function () use ($plan): void {
            $plan->forceFill(['estado' => 'archived'])->save();
            $plan->delete();
        });
    }

    private function createVersion(TrainingPlan $plan, array $data, User $actor, int $version): TrainingPlanVersion
    {
        $blocks = $this->normalizeBlocks($data);
        $computedVolume = 0;
        foreach ($blocks as $block) $computedVolume += array_sum(array_column($block['series'], 'distancia_total_m')) * $block['rounds'];
        $volume = array_key_exists('volume_planeado_m', $data) && $data['volume_planeado_m'] !== null ? (int) $data['volume_planeado_m'] : $computedVolume;

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
            'publicado_em' => ! empty($data['publicar']) ? now() : null,
        ]);

        $globalOrder = 1;
        foreach ($blocks as $blockIndex => $block) {
            $blockModel = TrainingPlanBlock::query()->create([
                'club_id' => $this->clubContext->id(), 'training_plan_version_id' => $versionModel->id,
                'sort_order' => $blockIndex + 1, 'name' => $block['name'], 'rounds' => $block['rounds'], 'notes' => $block['notes'],
            ]);
            foreach ($block['series'] as $row) {
                $seriesModel = TrainingPlanSeries::query()->create([
                    'club_id' => $this->clubContext->id(), 'training_plan_version_id' => $versionModel->id,
                    'training_plan_block_id' => $blockModel->id, 'ordem' => $globalOrder++, 'bloco' => $block['name'],
                    'repeticoes' => $row['repeticoes'], 'distancia_m' => $row['distancia_m'], 'distancia_total_m' => $row['distancia_total_m'],
                    'exercicio' => $row['exercicio'], 'estilo' => $row['estilo'], 'sports_stroke_id' => $row['sports_stroke_id'],
                    'zona_intensidade' => $row['zona_intensidade'], 'training_zone_config_id' => $row['training_zone_config_id'],
                    'intervalo' => $row['intervalo'], 'saida' => $row['saida'], 'timing_mode' => $row['timing_mode'],
                    'material' => $row['material_snapshot'], 'observacoes' => $row['observacoes'],
                ]);
                if ($row['material_ids'] !== []) {
                    $seriesModel->materials()->sync(collect($row['material_ids'])->mapWithKeys(fn (string $id): array => [$id => ['quantity' => null, 'notes' => null]])->all());
                }
            }
        }
        return $versionModel;
    }

    private function normalizeBlocks(array $data): array
    {
        $rawBlocks = $data['blocks'] ?? null;
        if (is_array($rawBlocks) && $rawBlocks !== []) {
            $blocks = [];
            foreach ($rawBlocks as $index => $block) {
                if (! is_array($block)) continue;
                $series = $this->normalizeSeries($block['series'] ?? []);
                if ($series === []) continue;
                $blocks[] = [
                    'name' => $this->nullableString($block['nome'] ?? $block['name'] ?? null) ?? 'Bloco ' . ($index + 1),
                    'rounds' => max(1, (int) ($block['rondas'] ?? $block['rounds'] ?? 1)),
                    'notes' => $this->nullableString($block['notas'] ?? $block['notes'] ?? null),
                    'series' => $series,
                ];
            }
            return $blocks;
        }

        $rows = $data['series_linhas'] ?? $data['series'] ?? [];
        if (! is_array($rows)) return [];
        $grouped = []; $order = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $name = $this->nullableString($row['bloco'] ?? null) ?? 'Treino';
            if (! array_key_exists($name, $grouped)) { $grouped[$name] = []; $order[] = $name; }
            $grouped[$name][] = $row;
        }
        $blocks = [];
        foreach ($order as $name) {
            $series = $this->normalizeSeries($grouped[$name]);
            if ($series !== []) $blocks[] = ['name' => $name, 'rounds' => 1, 'notes' => null, 'series' => $series];
        }
        return $blocks;
    }

    private function normalizeSeries(mixed $rows): array
    {
        if (! is_array($rows)) return [];
        $normalized = [];
        foreach (array_values($rows) as $row) {
            if (! is_array($row)) continue;
            $repetitions = max(0, (int) ($row['repeticoes'] ?? 0));
            $distance = max(0, (int) ($row['distancia_m'] ?? $row['metros'] ?? 0));
            $total = max(0, (int) ($row['distancia_total_m'] ?? 0));
            if ($total === 0 && $repetitions > 0 && $distance > 0) $total = $repetitions * $distance;
            $exercise = $this->nullableString($row['exercicio'] ?? $row['descricao_texto'] ?? null);
            $zone = $this->resolveZone($row['training_zone_config_id'] ?? null);
            $stroke = $this->resolveStroke($row['sports_stroke_id'] ?? null);
            $materialIds = collect($row['material_ids'] ?? [])->filter()->map('strval')->unique()->values()->all();
            $materials = $this->resolveMaterials($materialIds);
            $legacyZone = $this->nullableString($row['zona_intensidade'] ?? $row['zona'] ?? null);
            $legacyStroke = $this->nullableString($row['estilo'] ?? null);
            $timingMode = (string) ($row['timing_mode'] ?? 'none');
            if (! in_array($timingMode, ['none', 'each_rep', 'whole_series'], true)) $timingMode = 'none';
            if ($repetitions === 0 && $distance === 0 && $total === 0 && $exercise === null && $zone === null && $legacyZone === null) continue;

            $normalized[] = [
                'repeticoes' => $repetitions > 0 ? $repetitions : null,
                'distancia_m' => $distance > 0 ? $distance : null,
                'distancia_total_m' => $total > 0 ? $total : null,
                'exercicio' => $exercise,
                'estilo' => $stroke?->name ?? $legacyStroke,
                'sports_stroke_id' => $stroke?->id,
                'zona_intensidade' => $zone?->codigo ?? $legacyZone,
                'training_zone_config_id' => $zone?->id,
                'intervalo' => $this->nullableString($row['intervalo'] ?? null),
                'saida' => $this->nullableString($row['saida'] ?? null),
                'timing_mode' => $timingMode,
                'material_ids' => $materials->pluck('id')->map('strval')->all(),
                'material_snapshot' => $materials->map(fn (SportsTrainingMaterial $material): array => ['id' => (string) $material->id, 'code' => $material->code, 'name' => $material->name])->values()->all() ?: (is_array($row['material'] ?? null) ? $row['material'] : null),
                'observacoes' => $this->nullableString($row['observacoes'] ?? null),
            ];
        }
        return $normalized;
    }

    private function validatePlanPayload(array $data, bool $creating): void
    {
        $rules = [
            'nome' => ($creating ? 'required' : 'sometimes') . '|string|max:255', 'codigo' => 'nullable|string|max:80',
            'descricao' => 'nullable|string', 'modalidade' => 'nullable|string|max:80', 'sports_modality_id' => 'nullable|uuid|exists:sports_modalities,id',
            'tags' => 'nullable|array', 'tags.*' => 'string|max:60', 'estado' => 'nullable|in:draft,published,archived', 'publicar' => 'nullable|boolean',
            'tipo_treino' => 'nullable|string|max:100', 'volume_planeado_m' => 'nullable|integer|min:0', 'instrucao' => 'nullable|string',
            'motivo_revisao' => 'nullable|string', 'metadados' => 'nullable|array', 'blocks' => 'nullable|array', 'series_linhas' => 'nullable|array', 'series' => 'nullable|array',
        ];
        $validator = validator($data, $rules);
        if ($validator->fails()) throw new ValidationException($validator);
    }

    private function resolveModality(mixed $id): ?SportsModality
    {
        if (blank($id)) return null;
        $model = SportsModality::query()->forClub($this->clubContext->id())->whereKey((string) $id)->first();
        if ($model === null) throw ValidationException::withMessages(['sports_modality_id' => 'A modalidade não pertence ao clube ativo.']);
        return $model;
    }

    private function resolveZone(mixed $id): ?TrainingZoneConfig
    {
        if (blank($id)) return null;
        $model = TrainingZoneConfig::query()->forClub($this->clubContext->id())->whereKey((string) $id)->first();
        if ($model === null) throw ValidationException::withMessages(['training_zone_config_id' => 'A zona de treino não pertence ao clube ativo.']);
        return $model;
    }

    private function resolveStroke(mixed $id): ?SportsStroke
    {
        if (blank($id)) return null;
        $model = SportsStroke::query()->forClub($this->clubContext->id())->whereKey((string) $id)->first();
        if ($model === null) throw ValidationException::withMessages(['sports_stroke_id' => 'O estilo não pertence ao clube ativo.']);
        return $model;
    }

    private function resolveMaterials(array $ids)
    {
        if ($ids === []) return collect();
        $materials = SportsTrainingMaterial::query()->forClub($this->clubContext->id())->whereIn('id', $ids)->get();
        if ($materials->count() !== count($ids)) throw ValidationException::withMessages(['material_ids' => 'Um ou mais materiais técnicos não pertencem ao clube ativo.']);
        return $materials;
    }

    private function assertTenant(TrainingPlan $plan): void
    {
        if ((string) $plan->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training_plan' => 'O plano de treino pertence a outro clube.']);
    }

    private function normalizeTags(mixed $value): array
    {
        if (! is_array($value)) return [];
        return collect($value)->map(fn ($tag): string => trim((string) $tag))->filter()->unique(fn (string $tag): string => mb_strtolower($tag))->take(20)->values()->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
