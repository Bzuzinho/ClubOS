<?php

namespace App\Services\Desportivo;

use App\Models\AbsenceReasonConfig;
use App\Models\AthleteStatusConfig;
use App\Models\InjuryReasonConfig;
use App\Models\PoolTypeConfig;
use App\Models\ProvaTipo;
use App\Models\SportsCaisMetricDefinition;
use App\Models\SportsLimitationType;
use App\Models\SportsLiveMetricDefinition;
use App\Models\TrainingTypeConfig;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SportsConfigurationService
{
    /** @var array<string,class-string<Model>> */
    private const MODELS = [
        'athlete_statuses' => AthleteStatusConfig::class,
        'training_types' => TrainingTypeConfig::class,
        'training_zones' => TrainingZoneConfig::class,
        'absence_reasons' => AbsenceReasonConfig::class,
        'pool_types' => PoolTypeConfig::class,
        'race_types' => ProvaTipo::class,
        'limitation_types' => SportsLimitationType::class,
        'cais_metrics' => SportsCaisMetricDefinition::class,
        'live_metrics' => SportsLiveMetricDefinition::class,
    ];

    /** @var array<string,list<array{0:string,1:string}>> */
    private const LEGACY_CODE_REFERENCES = [
        'athlete_statuses' => [['training_athletes', 'estado']],
        'training_types' => [['trainings', 'tipo_treino'], ['training_plan_versions', 'tipo_treino']],
        'training_zones' => [['training_series', 'zona_intensidade'], ['training_plan_series', 'zona_intensidade']],
        'absence_reasons' => [],
        'pool_types' => [['events', 'tipo_piscina']],
        'race_types' => [],
        'limitation_types' => [],
        'cais_metrics' => [['training_metrics', 'metrica']],
        'live_metrics' => [['sports_live_metric_records', 'metric_code']],
    ];

    public function __construct(private readonly SportsClubContext $clubContext) {}

    /** @return array<string,mixed> */
    public function pagePayload(): array
    {
        $payload = [];
        foreach (array_keys(self::MODELS) as $catalog) $payload[$catalog] = $this->rows($catalog);

        $payload['legacy_injury_reasons'] = InjuryReasonConfig::query()
            ->orderBy('ordem')->orderBy('nome')->get()
            ->map(fn (InjuryReasonConfig $item): array => [
                'id' => (string) $item->id,
                'codigo' => $item->codigo,
                'nome' => $item->nome,
                'descricao' => $item->descricao,
                'gravidade' => $item->gravidade,
                'ativo' => (bool) $item->ativo,
                'legacy_read_only' => true,
            ])->values()->all();

        return $payload;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $catalog): array
    {
        $class = $this->modelClass($catalog);
        $rows = $class::query()
            ->where('club_id', $this->clubContext->id())
            ->orderBy('ordem')->orderBy('nome')->get();

        return $rows->map(function (Model $row) use ($catalog): array {
            $data = $row->toArray();
            if (in_array($catalog, ['cais_metrics', 'live_metrics'], true)) {
                $data['options'] = collect($row->getAttribute('options_json') ?? [])->join(', ');
            }
            $used = $this->isUsed($catalog, $row);
            $data['used'] = $used;
            $data['code_locked'] = $used;
            $data['archived'] = $row->getAttribute('archived_at') !== null;
            return $data;
        })->values()->all();
    }

    /** @param array<string,mixed> $data */
    public function create(string $catalog, array $data, ?User $actor = null): Model
    {
        $class = $this->modelClass($catalog);
        $clubId = $this->clubContext->id();
        $payload = $this->normalizePayload($catalog, $data);
        $payload['club_id'] = $clubId;
        $payload['created_by'] = $actor?->id;
        $payload['updated_by'] = $actor?->id;

        if (array_key_exists('codigo', $payload)) $this->ensureCodeAvailable($class, $clubId, (string) $payload['codigo']);
        return DB::transaction(fn (): Model => $class::query()->create($payload));
    }

    /** @param array<string,mixed> $data */
    public function update(string $catalog, string $id, array $data, ?User $actor = null): Model
    {
        $row = $this->findForClub($catalog, $id);
        $payload = $this->normalizePayload($catalog, $data, false);
        $used = $this->isUsed($catalog, $row);

        if (array_key_exists('codigo', $payload)) {
            $newCode = (string) $payload['codigo'];
            $oldCode = (string) $row->getAttribute('codigo');
            if ($newCode !== $oldCode && $used) {
                throw ValidationException::withMessages(['codigo' => 'O código técnico já está referenciado historicamente e não pode ser alterado.']);
            }
            if ($newCode !== $oldCode) $this->ensureCodeAvailable($row::class, $this->clubContext->id(), $newCode, (string) $row->getKey());
        }

        if ($catalog === 'live_metrics' && $used) {
            foreach (['input_type', 'unit'] as $lockedField) {
                if (array_key_exists($lockedField, $payload) && (string) $payload[$lockedField] !== (string) $row->getAttribute($lockedField)) {
                    throw ValidationException::withMessages([$lockedField => 'O tipo e a unidade ficam bloqueados depois de existir histórico, para preservar a coerência estatística.']);
                }
            }
        }

        $payload['updated_by'] = $actor?->id;
        return DB::transaction(function () use ($row, $payload): Model {
            $row->fill($payload)->save();
            return $row->fresh();
        });
    }

    /** @return array{action:string,record:?Model} */
    public function remove(string $catalog, string $id, ?User $actor = null): array
    {
        $row = $this->findForClub($catalog, $id);
        if (! $this->isUsed($catalog, $row)) {
            DB::transaction(fn () => $row->delete());
            return ['action' => 'deleted', 'record' => null];
        }

        return DB::transaction(function () use ($row, $actor): array {
            $row->forceFill(['ativo' => false, 'archived_at' => now(), 'updated_by' => $actor?->id])->save();
            return ['action' => 'archived', 'record' => $row->fresh()];
        });
    }

    public function isUsed(string $catalog, Model $row): bool
    {
        $code = trim((string) $row->getAttribute('codigo'));
        if ($code === '') return false;
        foreach (self::LEGACY_CODE_REFERENCES[$catalog] ?? [] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) continue;
            if (DB::table($table)->where($column, $code)->exists()) return true;
        }
        return false;
    }

    private function findForClub(string $catalog, string $id): Model
    {
        $class = $this->modelClass($catalog);
        return $class::query()->where('club_id', $this->clubContext->id())->findOrFail($id);
    }

    /** @return class-string<Model> */
    private function modelClass(string $catalog): string
    {
        $class = self::MODELS[$catalog] ?? null;
        if ($class === null) throw ValidationException::withMessages(['catalog' => 'Catálogo de configuração desportiva inválido.']);
        return $class;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function normalizePayload(string $catalog, array $data, bool $creating = true): array
    {
        $allowed = match ($catalog) {
            'athlete_statuses' => ['codigo', 'nome', 'nome_en', 'descricao', 'cor', 'ativo', 'ordem', 'counts_as_present', 'requires_reason', 'allows_training', 'allows_competition'],
            'training_types' => ['codigo', 'nome', 'nome_en', 'descricao', 'cor', 'ativo', 'ordem', 'is_recovery', 'is_high_intensity'],
            'training_zones' => ['codigo', 'nome', 'descricao', 'percentagem_min', 'percentagem_max', 'cor', 'ativo', 'ordem', 'is_recovery', 'is_high_intensity'],
            'absence_reasons' => ['codigo', 'nome', 'nome_en', 'descricao', 'requer_justificacao', 'health_related', 'ativo', 'ordem'],
            'pool_types' => ['codigo', 'nome', 'comprimento_m', 'is_open_water', 'ativo', 'ordem'],
            'race_types' => ['codigo', 'nome', 'distancia', 'unidade', 'modalidade', 'ativo', 'ordem'],
            'limitation_types' => ['codigo', 'nome', 'descricao', 'instrucao_padrao', 'allows_training', 'allows_competition', 'requires_end_date', 'ativo', 'ordem'],
            'cais_metrics' => ['codigo', 'nome', 'input_type', 'unit', 'options', 'quick_action', 'ativo', 'ordem'],
            'live_metrics' => ['codigo', 'nome', 'input_type', 'unit', 'options', 'ativo', 'ordem'],
            default => [],
        };

        $payload = collect($data)->only($allowed)->all();
        if (array_key_exists('codigo', $payload)) {
            $payload['codigo'] = $this->normalizeCode((string) $payload['codigo']);
        } elseif ($creating) {
            throw ValidationException::withMessages(['codigo' => 'O código técnico é obrigatório.']);
        }

        if (in_array($catalog, ['cais_metrics', 'live_metrics'], true) && array_key_exists('options', $payload)) {
            $options = collect(preg_split('/[,\n]+/', (string) $payload['options']))
                ->map(fn (string $item): string => trim($item))->filter()->unique()->values()->all();
            $payload['options_json'] = $options === [] ? null : $options;
            unset($payload['options']);
        }

        if ($creating) {
            $payload['ativo'] = array_key_exists('ativo', $payload) ? (bool) $payload['ativo'] : true;
            $payload['ordem'] = (int) ($payload['ordem'] ?? 0);
        }
        return $payload;
    }

    private function normalizeCode(string $value): string
    {
        $normalized = Str::of(Str::ascii($value))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        if ($normalized === '') throw ValidationException::withMessages(['codigo' => 'Indica um código técnico válido.']);
        return $normalized;
    }

    /** @param class-string<Model> $class */
    private function ensureCodeAvailable(string $class, string $clubId, string $code, ?string $ignoreId = null): void
    {
        $query = $class::query()->where('club_id', $clubId)->where('codigo', $code);
        if ($ignoreId !== null) $query->whereKeyNot($ignoreId);
        if ($query->exists()) throw ValidationException::withMessages(['codigo' => 'Já existe uma configuração com este código técnico neste clube.']);
    }
}
