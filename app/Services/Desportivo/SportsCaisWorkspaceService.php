<?php

namespace App\Services\Desportivo;

use App\Models\SportsCaisMetricDefinition;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsCaisWorkspaceService
{
    private const FIXED_CODES = ['behavior', 'material', 'technical_note', 'advice'];

    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly UpdateTrainingAthleteAction $updateAthlete,
        private readonly MemberIdentityDisplayResolver $identityDisplayResolver,
    ) {}

    public function payload(Request $request): array
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        $sessions = Training::query()
            ->where('club_id', $this->clubContext->id())
            ->whereDate('data', $date)
            ->whereNotIn('session_status', ['cancelled', 'completed'])
            ->with(['venue:id,name', 'pool:id,name,length_m', 'sessionGroups.group'])
            ->orderBy('hora_inicio')
            ->get();

        $requestedId = $request->string('training_id')->toString();
        $selected = $requestedId !== '' ? $sessions->firstWhere('id', $requestedId) : $this->defaultSession($sessions);
        if ($selected === null && $requestedId !== '') {
            $selected = Training::query()
                ->where('club_id', $this->clubContext->id())
                ->whereKey($requestedId)
                ->whereNotIn('session_status', ['cancelled', 'completed'])
                ->first();
        }

        $definitions = $this->definitions();

        return [
            'date' => $date,
            'sessions' => $sessions->map(fn (Training $training): array => $this->sessionOption($training))->values(),
            'selectedSession' => $selected ? $this->sessionPayload($selected, $definitions) : null,
            'metricDefinitions' => $definitions->map(fn (SportsCaisMetricDefinition $definition): array => $this->definitionPayload($definition))->values(),
        ];
    }

    public function updatePresence(Training $training, User $athlete, string $status, User $actor): TrainingAthlete
    {
        $this->assertMutable($training);
        $record = $this->athleteRecord($training, $athlete);
        return $this->updateAthlete->execute($record, ['estado' => $status], $actor);
    }

    public function saveQuick(Training $training, User $athlete, string $code, mixed $value, User $actor): array
    {
        $this->assertMutable($training);
        $this->athleteRecord($training, $athlete);
        $definition = SportsCaisMetricDefinition::query()
            ->where('club_id', $this->clubContext->id())
            ->where('codigo', $code)
            ->where('ativo', true)
            ->whereNull('archived_at')
            ->first();

        if (! $definition || ! $definition->quick_action) {
            throw ValidationException::withMessages(['metric' => 'Este registo não está configurado como ação rápida do Cais.']);
        }

        $normalized = $this->normalizeDefinitionValue($definition, $value);
        $this->upsertMetric($training, $athlete, $code, $normalized, $actor);

        return $this->athleteRegisterPayload($training, $athlete, $this->definitions());
    }

    public function saveRegister(Training $training, User $athlete, array $data, User $actor): array
    {
        $this->assertMutable($training);
        $record = $this->athleteRecord($training, $athlete);
        $definitions = $this->definitions();

        DB::transaction(function () use ($training, $athlete, $record, $data, $actor, $definitions): void {
            if (isset($data['status'])) {
                $this->updateAthlete->execute($record, ['estado' => (string) $data['status']], $actor);
            }

            foreach (['behavior', 'material'] as $code) {
                if (! array_key_exists($code, $data)) continue;
                $definition = $definitions->firstWhere('codigo', $code);
                if ($definition) {
                    $this->upsertMetric($training, $athlete, $code, $this->normalizeDefinitionValue($definition, $data[$code]), $actor);
                }
            }

            foreach (['technical_note', 'advice'] as $code) {
                if (! array_key_exists($code, $data)) continue;
                $this->upsertMetric($training, $athlete, $code, $this->nullableText($data[$code]), $actor);
            }

            foreach ($data['metrics'] ?? [] as $metric) {
                if (! is_array($metric)) continue;
                $code = trim((string) ($metric['code'] ?? ''));
                if ($code === '' || in_array($code, self::FIXED_CODES, true)) continue;
                $definition = $definitions->firstWhere('codigo', $code);
                if (! $definition) {
                    throw ValidationException::withMessages(['metrics' => "A métrica {$code} não está ativa no Cais."]);
                }
                $this->upsertMetric($training, $athlete, $code, $this->normalizeDefinitionValue($definition, $metric['value'] ?? null), $actor);
            }
        }, 3);

        return $this->athleteRegisterPayload($training, $athlete, $definitions);
    }

    private function sessionPayload(Training $training, Collection $definitions): array
    {
        $training->load([
            'responsibleCoach:id,name',
            'responsibleCoach.dadosPessoais:id,user_id,nome_completo',
            'venue:id,name',
            'pool:id,name,length_m',
            'series.zone',
            'series.stroke',
            'sessionGroups.group',
            'sessionGroups.lanes',
            'athleteRecords.atleta:id,name',
            'athleteRecords.atleta.dadosPessoais:id,user_id,nome_completo',
            'scheduleExceptions.recordedBy:id,name',
            'scheduleExceptions.recordedBy.dadosPessoais:id,user_id,nome_completo',
        ]);
        $metrics = TrainingMetric::query()->where('treino_id', $training->id)->get()->groupBy('user_id');
        $athleteUsers = $training->athleteRecords->pluck('atleta')->filter()->values();
        $athleteNames = $this->identityDisplayResolver->mapDisplayNames($athleteUsers);

        return [
            ...$this->sessionOption($training),
            'status' => $training->session_status,
            'coach' => $training->responsibleCoach
                ? $this->identityDisplayResolver->displayNameOrFallback($training->responsibleCoach, 'Treinador')
                : null,
            'venue' => $training->venue?->name ?? $training->local,
            'pool' => $training->pool?->name,
            'pool_length_m' => $training->pool?->length_m,
            'blocks' => $this->blocks($training),
            'athletes' => $training->athleteRecords
                ->sortBy(fn (TrainingAthlete $row): string => mb_strtolower((string) ($athleteNames[(string) $row->user_id] ?? '')))
                ->map(function (TrainingAthlete $record) use ($metrics, $definitions, $training, $athleteNames): array {
                    $athlete = $record->atleta;
                    if (! $athlete) return [];
                    $register = $this->registerFromRows($metrics->get((string) $record->user_id, collect()), $definitions);
                    $assignment = $training->sessionGroups->first();
                    $lane = $assignment?->lanes?->first();
                    return [
                        'id' => (string) $athlete->id,
                        'training_athlete_id' => (string) $record->id,
                        'name' => $athleteNames[(string) $athlete->id] ?? $this->identityDisplayResolver->displayNameOrFallback($athlete, 'Atleta'),
                        'status' => $record->estado ?: ($record->presente ? 'presente' : 'ausente'),
                        'lane' => $lane?->name ?: ($lane?->lane_number ? 'Pista '.$lane->lane_number : null),
                        'group' => $assignment?->group?->name,
                        'register' => $register,
                    ];
                })->filter()->values(),
            'occurrences' => $training->scheduleExceptions->map(fn ($row): array => [
                'id' => (string) $row->id,
                'type' => $row->exception_type,
                'reason' => $row->reason,
                'recorded_at' => $row->recorded_at?->toIso8601String(),
                'recorded_by' => $row->recordedBy
                    ? $this->identityDisplayResolver->displayNameOrFallback($row->recordedBy, 'Utilizador')
                    : null,
                'before' => $row->before_state,
                'after' => $row->after_state,
            ])->values(),
        ];
    }

    private function sessionOption(Training $training): array
    {
        return [
            'id' => (string) $training->id,
            'number' => $training->numero_treino,
            'date' => $training->data?->toDateString(),
            'start_time' => $training->hora_inicio ? substr((string) $training->hora_inicio, 0, 5) : null,
            'end_time' => $training->hora_fim ? substr((string) $training->hora_fim, 0, 5) : null,
            'training_type' => $training->tipo_treino,
            'volume_m' => (int) ($training->volume_planeado_m ?? 0),
            'label' => $training->sessionGroups->pluck('group.name')->filter()->join(', ') ?: ($training->tipo_treino ?: 'Treino'),
        ];
    }

    private function blocks(Training $training): array
    {
        return $training->series->groupBy(fn ($line): string => (string) ($line->block_name ?? $line->bloco ?? 'Treino'))
            ->map(function (Collection $lines, string $name): array {
                $first = $lines->first();
                return [
                    'name' => $name,
                    'rounds' => max(1, (int) ($first?->block_rounds ?? 1)),
                    'volume_m' => $lines->sum(fn ($line): int => (int) ($line->distancia_total_m ?? 0) * max(1, (int) ($line->block_rounds ?? 1))),
                    'series' => $lines->map(fn ($line): array => [
                        'id' => (string) $line->id,
                        'repeticoes' => (int) ($line->repeticoes ?? 0),
                        'distancia_m' => (int) ($line->distancia_m ?? 0),
                        'exercicio' => $line->descricao_texto,
                        'zona' => $line->zone?->codigo ?? $line->zona_intensidade,
                        'estilo' => $line->stroke?->name ?? $line->estilo,
                        'intervalo' => $line->intervalo,
                        'saida' => $line->saida,
                        'timing_mode' => $line->timing_mode ?: 'none',
                    ])->values(),
                ];
            })->values()->all();
    }

    private function athleteRegisterPayload(Training $training, User $athlete, Collection $definitions): array
    {
        $record = $this->athleteRecord($training, $athlete)->fresh();
        $rows = TrainingMetric::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->get();
        return ['status' => $record->estado, 'register' => $this->registerFromRows($rows, $definitions)];
    }

    private function registerFromRows(Collection $rows, Collection $definitions): array
    {
        $byCode = $rows->keyBy(fn (TrainingMetric $row): string => (string) $row->metrica);
        $metrics = $definitions->reject(fn (SportsCaisMetricDefinition $definition): bool => in_array($definition->codigo, ['behavior', 'material'], true))
            ->map(fn (SportsCaisMetricDefinition $definition): array => [
                ...$this->definitionPayload($definition),
                'value' => $byCode->get($definition->codigo)?->valor,
            ])->values();
        return [
            'behavior' => $byCode->get('behavior')?->valor,
            'material' => $byCode->get('material')?->valor,
            'technical_note' => $byCode->get('technical_note')?->observacao ?? $byCode->get('technical_note')?->valor,
            'advice' => $byCode->get('advice')?->observacao ?? $byCode->get('advice')?->valor,
            'metrics' => $metrics,
        ];
    }

    private function definitionPayload(SportsCaisMetricDefinition $definition): array
    {
        return [
            'id' => (string) $definition->id,
            'code' => $definition->codigo,
            'name' => $definition->nome,
            'input_type' => $definition->input_type,
            'unit' => $definition->unit,
            'options' => $definition->options_json ?? [],
            'quick_action' => (bool) $definition->quick_action,
        ];
    }

    private function definitions(): Collection
    {
        return SportsCaisMetricDefinition::query()
            ->where('club_id', $this->clubContext->id())
            ->where('ativo', true)
            ->whereNull('archived_at')
            ->orderBy('ordem')
            ->get();
    }

    private function upsertMetric(Training $training, User $athlete, string $code, mixed $value, User $actor): void
    {
        $text = $this->nullableText($value);
        $query = TrainingMetric::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->where('metrica', $code);
        if ($text === null) {
            $query->delete();
            return;
        }
        $row = $query->first();
        if ($row) {
            $row->forceFill([
                'valor' => $text,
                'observacao' => in_array($code, ['technical_note', 'advice'], true) ? $text : $row->observacao,
                'atualizado_por' => $actor->id,
            ])->save();
            return;
        }
        $order = (int) TrainingMetric::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->max('ordem') + 1;
        TrainingMetric::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'ordem' => $order,
            'metrica' => $code,
            'valor' => $text,
            'observacao' => in_array($code, ['technical_note', 'advice'], true) ? $text : null,
            'registado_por' => $actor->id,
            'atualizado_por' => $actor->id,
        ]);
    }

    private function normalizeDefinitionValue(SportsCaisMetricDefinition $definition, mixed $value): ?string
    {
        $text = $this->nullableText($value);
        if ($text === null) return null;
        if ($definition->input_type === 'choice') {
            $options = collect($definition->options_json ?? [])->map('strval');
            if ($options->isNotEmpty() && ! $options->contains($text)) {
                throw ValidationException::withMessages(['value' => "Valor inválido para {$definition->nome}."]);
            }
        }
        if ($definition->input_type === 'number' && ! is_numeric(str_replace(',', '.', $text))) {
            throw ValidationException::withMessages(['value' => "{$definition->nome} exige um valor numérico."]);
        }
        return $text;
    }

    private function athleteRecord(Training $training, User $athlete): TrainingAthlete
    {
        $record = TrainingAthlete::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->first();
        if (! $record) throw ValidationException::withMessages(['athlete' => 'O atleta não está preparado para esta sessão.']);
        return $record;
    }

    private function assertMutable(Training $training): void
    {
        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training' => 'A sessão pertence a outro clube.']);
        }
        if ($training->isOperationallyClosed()) {
            throw ValidationException::withMessages(['training' => 'Uma sessão concluída ou cancelada não pode ser operada no Cais.']);
        }
    }

    private function defaultSession(Collection $sessions): ?Training
    {
        if ($sessions->isEmpty()) return null;
        $now = CarbonImmutable::now();
        return $sessions->sortBy(function (Training $training) use ($now): int {
            $start = CarbonImmutable::parse(($training->data?->toDateString() ?? $now->toDateString()).' '.($training->hora_inicio ?: '00:00'));
            return abs($now->diffInSeconds($start, false));
        })->first();
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
