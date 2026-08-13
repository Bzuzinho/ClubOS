<?php

namespace App\Services\Desportivo;

use App\Models\SportsLiveFreeClassification;
use App\Models\SportsLiveMeasurement;
use App\Models\SportsLiveMeasurementAthlete;
use App\Models\SportsLiveMeasurementEvent;
use App\Models\SportsLiveMetricDefinition;
use App\Models\SportsLiveMetricRecord;
use App\Models\SportsLiveMonitoring;
use App\Models\SportsLiveMonitoringAthlete;
use App\Models\SportsStroke;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SportsLiveWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
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
            ->orderBy('hora_inicio')->get();

        $requestedId = $request->string('training_id')->toString();
        $selected = $requestedId !== '' ? $sessions->firstWhere('id', $requestedId) : $sessions->first();
        if ($selected === null && $requestedId !== '') {
            $selected = Training::query()->where('club_id', $this->clubContext->id())->whereKey($requestedId)
                ->whereNotIn('session_status', ['cancelled', 'completed'])->first();
        }

        return [
            'date' => $date,
            'sessions' => $sessions->map(fn (Training $training): array => $this->sessionOption($training))->values(),
            'selectedSession' => $selected ? $this->sessionPayload($selected) : null,
            'metricDefinitions' => $this->definitions()->map(fn (SportsLiveMetricDefinition $row): array => $this->definitionPayload($row))->values(),
            'strokes' => SportsStroke::query()->forClub($this->clubContext->id())->active()->orderBy('sort_order')->get(['id','code','name'])
                ->map(fn (SportsStroke $stroke): array => ['id'=>(string)$stroke->id,'code'=>$stroke->code,'name'=>$stroke->name])->values(),
            'activeMonitorings' => $selected ? $this->activeMonitoringsPayload($selected) : [],
            'handoff' => [
                'training_series_id' => $request->string('training_series_id')->toString() ?: null,
                'athlete_ids' => collect($request->input('athlete_ids', []))->map('strval')->values(),
            ],
        ];
    }

    public function startPlanned(Training $training, TrainingSeries $series, array $trainingAthleteIds, User $actor, ?string $clientMeasurementId = null): array
    {
        $this->assertTrainingMutable($training);
        $this->assertSeriesBelongsToTraining($training, $series);
        if (($series->timing_mode ?: 'none') === 'none') {
            throw ValidationException::withMessages(['series_id' => 'Esta linha não está configurada para cronometragem.']);
        }
        $clientMeasurementId = $clientMeasurementId ?: (string) Str::uuid();
        if ($existing = SportsLiveMeasurement::query()->where('client_measurement_id', $clientMeasurementId)->first()) {
            return $this->monitoringPayload($existing->monitoring()->firstOrFail(), $existing);
        }

        return DB::transaction(function () use ($training, $series, $trainingAthleteIds, $actor, $clientMeasurementId): array {
            $records = TrainingAthlete::query()->where('treino_id', $training->id)->whereIn('id', $trainingAthleteIds)
                ->where('presente', true)->lockForUpdate()->get();
            if ($records->count() !== count(array_unique($trainingAthleteIds)) || $records->isEmpty()) {
                throw ValidationException::withMessages(['athletes' => 'Seleciona apenas atletas presentes neste treino.']);
            }
            $this->assertAthletesFree($records->pluck('id')->map('strval')->all());

            $monitoring = SportsLiveMonitoring::query()->create([
                'club_id'=>$this->clubContext->id(),'training_id'=>$training->id,'training_series_id'=>$series->id,
                'type'=>'planned','state'=>'active','current_repetition'=>1,'current_round'=>1,'created_by'=>$actor->id,
            ]);
            foreach ($records as $record) {
                SportsLiveMonitoringAthlete::query()->create(['monitoring_id'=>$monitoring->id,'training_athlete_id'=>$record->id,'user_id'=>$record->user_id,'active'=>true]);
            }
            $measurement = $this->createMeasurement($monitoring, $actor, $clientMeasurementId);
            return $this->monitoringPayload($monitoring->fresh(), $measurement);
        }, 3);
    }

    public function startFree(Training $training, User $athlete, User $actor, ?string $clientMeasurementId = null): array
    {
        $this->assertTrainingMutable($training);
        $clientMeasurementId = $clientMeasurementId ?: (string) Str::uuid();
        if ($existing = SportsLiveMeasurement::query()->where('client_measurement_id', $clientMeasurementId)->first()) {
            return $this->monitoringPayload($existing->monitoring()->firstOrFail(), $existing);
        }
        return DB::transaction(function () use ($training, $athlete, $actor, $clientMeasurementId): array {
            $record = TrainingAthlete::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->where('presente', true)->lockForUpdate()->first();
            if (! $record) throw ValidationException::withMessages(['athlete' => 'O atleta não está presente neste treino.']);
            $this->assertAthletesFree([(string) $record->id]);
            $monitoring = SportsLiveMonitoring::query()->create([
                'club_id'=>$this->clubContext->id(),'training_id'=>$training->id,'training_series_id'=>null,
                'type'=>'free','state'=>'active','current_repetition'=>1,'current_round'=>1,'created_by'=>$actor->id,
            ]);
            SportsLiveMonitoringAthlete::query()->create(['monitoring_id'=>$monitoring->id,'training_athlete_id'=>$record->id,'user_id'=>$record->user_id,'active'=>true]);
            $measurement = $this->createMeasurement($monitoring, $actor, $clientMeasurementId);
            return $this->monitoringPayload($monitoring->fresh(), $measurement);
        }, 3);
    }

    public function split(SportsLiveMeasurement $measurement, User $athlete, int $elapsedMs, string $occurredAt, string $clientEventId, User $actor): array
    {
        $this->assertMeasurementClub($measurement);
        if (SportsLiveMeasurementEvent::query()->where('client_event_id', $clientEventId)->exists()) return $this->monitoringPayload($measurement->monitoring, $measurement);
        return DB::transaction(function () use ($measurement, $athlete, $elapsedMs, $occurredAt, $clientEventId, $actor): array {
            $row = SportsLiveMeasurementAthlete::query()->where('measurement_id', $measurement->id)->where('user_id', $athlete->id)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'active') throw ValidationException::withMessages(['athlete' => 'A medição deste atleta já terminou.']);
            $sequence = (int) SportsLiveMeasurementEvent::query()->where('measurement_athlete_id', $row->id)->max('sequence') + 1;
            SportsLiveMeasurementEvent::query()->create([
                'measurement_id'=>$measurement->id,'measurement_athlete_id'=>$row->id,'event_type'=>'split','sequence'=>$sequence,
                'elapsed_ms'=>$elapsedMs,'occurred_at'=>$occurredAt,'client_event_id'=>$clientEventId,'recorded_by'=>$actor->id,
            ]);
            return $this->monitoringPayload($measurement->monitoring()->firstOrFail(), $measurement->fresh());
        }, 3);
    }

    public function stop(SportsLiveMeasurement $measurement, User $athlete, int $elapsedMs, string $occurredAt, string $clientEventId, User $actor): array
    {
        $this->assertMeasurementClub($measurement);
        if (SportsLiveMeasurementEvent::query()->where('client_event_id', $clientEventId)->exists()) return $this->monitoringPayload($measurement->monitoring, $measurement);
        return DB::transaction(function () use ($measurement, $athlete, $elapsedMs, $occurredAt, $clientEventId, $actor): array {
            $row = SportsLiveMeasurementAthlete::query()->where('measurement_id', $measurement->id)->where('user_id', $athlete->id)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'active') return $this->monitoringPayload($measurement->monitoring()->firstOrFail(), $measurement);
            $sequence = (int) SportsLiveMeasurementEvent::query()->where('measurement_athlete_id', $row->id)->max('sequence') + 1;
            SportsLiveMeasurementEvent::query()->create([
                'measurement_id'=>$measurement->id,'measurement_athlete_id'=>$row->id,'event_type'=>'stop','sequence'=>$sequence,
                'elapsed_ms'=>$elapsedMs,'occurred_at'=>$occurredAt,'client_event_id'=>$clientEventId,'recorded_by'=>$actor->id,
            ]);
            $row->forceFill(['state'=>'stopped','duration_ms'=>$elapsedMs,'stopped_at'=>$occurredAt])->save();
            $this->closeMeasurementIfFinished($measurement, $occurredAt);
            return $this->monitoringPayload($measurement->monitoring()->firstOrFail(), $measurement->fresh());
        }, 3);
    }

    public function stopAll(SportsLiveMeasurement $measurement, int $elapsedMs, string $occurredAt, string $clientEventId, User $actor): array
    {
        $this->assertMeasurementClub($measurement);
        return DB::transaction(function () use ($measurement, $elapsedMs, $occurredAt, $clientEventId, $actor): array {
            $rows = SportsLiveMeasurementAthlete::query()->where('measurement_id', $measurement->id)->where('state', 'active')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $eventId = $clientEventId.':'.$row->id;
                if (! SportsLiveMeasurementEvent::query()->where('client_event_id', $eventId)->exists()) {
                    $sequence = (int) SportsLiveMeasurementEvent::query()->where('measurement_athlete_id', $row->id)->max('sequence') + 1;
                    SportsLiveMeasurementEvent::query()->create([
                        'measurement_id'=>$measurement->id,'measurement_athlete_id'=>$row->id,'event_type'=>'stop','sequence'=>$sequence,
                        'elapsed_ms'=>$elapsedMs,'occurred_at'=>$occurredAt,'client_event_id'=>$eventId,'recorded_by'=>$actor->id,
                    ]);
                }
                $row->forceFill(['state'=>'stopped','duration_ms'=>$elapsedMs,'stopped_at'=>$occurredAt])->save();
            }
            $measurement->forceFill(['state'=>'stopped','ended_at'=>$occurredAt])->save();
            return $this->monitoringPayload($measurement->monitoring()->firstOrFail(), $measurement->fresh());
        }, 3);
    }

    public function next(SportsLiveMonitoring $monitoring, User $actor, ?string $clientMeasurementId = null): array
    {
        $this->assertMonitoringClub($monitoring);
        if ($monitoring->type !== 'planned' || $monitoring->state !== 'active') throw ValidationException::withMessages(['monitoring' => 'Esta monitorização não pode avançar.']);
        $latest = $monitoring->measurements()->latest('created_at')->first();
        if (! $latest || $latest->state !== 'stopped') throw ValidationException::withMessages(['monitoring' => 'Termina a medição atual antes de avançar.']);
        $clientMeasurementId = $clientMeasurementId ?: (string) Str::uuid();
        if ($existing = SportsLiveMeasurement::query()->where('client_measurement_id', $clientMeasurementId)->first()) return $this->monitoringPayload($existing->monitoring, $existing);

        return DB::transaction(function () use ($monitoring, $actor, $clientMeasurementId): array {
            $monitoring = SportsLiveMonitoring::query()->whereKey($monitoring->id)->lockForUpdate()->firstOrFail();
            $series = TrainingSeries::query()->findOrFail($monitoring->training_series_id);
            $reps = max(1, (int) ($series->repeticoes ?: 1));
            if (($series->timing_mode ?: 'none') === 'each_rep' && $monitoring->current_repetition < $reps) {
                $monitoring->current_repetition++;
                $monitoring->save();
            } else {
                $next = $this->nextTimedContext($monitoring->training_id, $series, (int) $monitoring->current_round);
                if ($next === null) {
                    $monitoring->forceFill(['state'=>'completed','completed_at'=>now()])->save();
                    return $this->monitoringPayload($monitoring);
                }
                $monitoring->forceFill(['training_series_id'=>$next['series']->id,'current_repetition'=>1,'current_round'=>$next['round']])->save();
            }
            $measurement = $this->createMeasurement($monitoring, $actor, $clientMeasurementId);
            return $this->monitoringPayload($monitoring->fresh(), $measurement);
        }, 3);
    }

    public function complete(SportsLiveMonitoring $monitoring): array
    {
        $this->assertMonitoringClub($monitoring);
        if ($monitoring->measurements()->where('state', 'running')->exists()) throw ValidationException::withMessages(['monitoring'=>'Existe uma medição ainda em curso.']);
        if ($monitoring->type === 'free') {
            $latest = $monitoring->measurements()->latest('created_at')->first();
            $hasUnclassified = $latest?->athletes()->where('state', 'stopped')->whereDoesntHave('classification')->exists() ?? false;
            if ($hasUnclassified) {
                throw ValidationException::withMessages(['monitoring'=>'Classifica ou apaga a medição livre antes de fechar.']);
            }
        }
        $monitoring->athletes()->update(['active'=>false]);
        $monitoring->forceFill(['state'=>'completed','completed_at'=>now()])->save();
        return $this->monitoringPayload($monitoring);
    }

    public function cancel(SportsLiveMonitoring $monitoring): array
    {
        $this->assertMonitoringClub($monitoring);
        DB::transaction(function () use ($monitoring): void {
            $monitoring->measurements()->where('state', 'running')->update(['state'=>'cancelled','ended_at'=>now()]);
            $monitoring->athletes()->update(['active'=>false]);
            $monitoring->forceFill(['state'=>'cancelled','cancelled_at'=>now()])->save();
        }, 3);
        return $this->monitoringPayload($monitoring->fresh());
    }

    public function classifyFree(SportsLiveMeasurement $measurement, User $athlete, int $distanceM, ?string $strokeId, string $strokeLabel, User $actor): array
    {
        $this->assertMeasurementClub($measurement);
        $monitoring = $measurement->monitoring()->firstOrFail();
        if ($monitoring->type !== 'free') throw ValidationException::withMessages(['measurement'=>'Apenas medições livres precisam de classificação.']);
        $row = SportsLiveMeasurementAthlete::query()->where('measurement_id',$measurement->id)->where('user_id',$athlete->id)->firstOrFail();
        if ($row->state !== 'stopped') throw ValidationException::withMessages(['measurement'=>'Termina a medição antes de classificar.']);
        if ($strokeId !== null) {
            $stroke = SportsStroke::query()->forClub($this->clubContext->id())->active()->findOrFail($strokeId);
            $strokeLabel = $stroke->name;
        }
        $segments = SportsLiveMeasurementEvent::query()->where('measurement_athlete_id',$row->id)->where('event_type','split')->count() + 1;
        SportsLiveFreeClassification::query()->updateOrCreate(['measurement_athlete_id'=>$row->id],[
            'total_distance_m'=>$distanceM,'segment_count'=>$segments,'segment_distance_m'=>$distanceM / max(1,$segments),
            'sports_stroke_id'=>$strokeId,'stroke_label'=>$strokeLabel,'classified_at'=>now(),'classified_by'=>$actor->id,
        ]);
        return $this->monitoringPayload($monitoring, $measurement);
    }

    public function saveMetric(Training $training, User $athlete, SportsLiveMetricDefinition $definition, mixed $value, ?string $note, ?string $seriesId, ?string $measurementId, User $actor): array
    {
        $this->assertTrainingMutable($training);
        $this->assertDefinition($definition);
        $record = TrainingAthlete::query()->where('treino_id',$training->id)->where('user_id',$athlete->id)->where('presente',true)->first();
        if (! $record) throw ValidationException::withMessages(['athlete'=>'O atleta não está presente neste treino.']);
        $series = $seriesId ? TrainingSeries::query()->where('treino_id',$training->id)->findOrFail($seriesId) : null;
        if (! $series) {
            $active = SportsLiveMonitoring::query()->where('training_id',$training->id)->where('state','active')
                ->whereHas('athletes', fn ($q) => $q->where('user_id',$athlete->id)->where('active',true))->latest('created_at')->first();
            $series = $active?->series;
        }
        $text = trim((string) $value);
        if ($text === '' && trim((string)$note) === '') throw ValidationException::withMessages(['value'=>'Indica um valor ou nota.']);
        $number = null;
        if ($definition->input_type === 'number') {
            $normalized = str_replace(',', '.', $text);
            if (! is_numeric($normalized)) throw ValidationException::withMessages(['value'=>"{$definition->nome} exige um valor numérico."]);
            $number = (float) $normalized;
            $text = $normalized;
        }
        if ($definition->input_type === 'choice' && ! empty($definition->options_json) && ! in_array($text, array_map('strval',$definition->options_json), true)) {
            throw ValidationException::withMessages(['value'=>"Valor inválido para {$definition->nome}."]);
        }
        $measurement = $measurementId ? SportsLiveMeasurement::query()->where('training_id',$training->id)->findOrFail($measurementId) : null;
        $row = SportsLiveMetricRecord::query()->create([
            'club_id'=>$this->clubContext->id(),'training_id'=>$training->id,'training_series_id'=>$series?->id,
            'training_athlete_id'=>$record->id,'user_id'=>$athlete->id,'metric_definition_id'=>$definition->id,
            'metric_code'=>$definition->codigo,'metric_name'=>$definition->nome,'unit_snapshot'=>$definition->unit,
            'value'=>$text !== '' ? $text : trim((string)$note),'value_number'=>$number,'note'=>trim((string)$note) ?: null,
            'live_measurement_id'=>$measurement?->id,'recorded_at'=>now(),'recorded_by'=>$actor->id,
        ]);
        return $this->metricRecordPayload($row->fresh(['series']));
    }

    public function metricHistory(Training $training, User $athlete, SportsLiveMetricDefinition $definition): array
    {
        $this->assertTrainingClub($training); $this->assertDefinition($definition);
        return SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->where('training_id',$training->id)
            ->where('user_id',$athlete->id)->where('metric_definition_id',$definition->id)->whereNull('voided_at')
            ->with('series')->orderByDesc('recorded_at')->get()->map(fn (SportsLiveMetricRecord $row): array => $this->metricRecordPayload($row))->values()->all();
    }

    public function voidLatestMetric(Training $training, User $athlete, SportsLiveMetricDefinition $definition, User $actor): ?array
    {
        $this->assertTrainingClub($training); $this->assertDefinition($definition);
        $row = SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->where('training_id',$training->id)
            ->where('user_id',$athlete->id)->where('metric_definition_id',$definition->id)->whereNull('voided_at')->latest('recorded_at')->first();
        if (! $row) return null;
        $row->forceFill(['voided_at'=>now(),'voided_by'=>$actor->id])->save();
        return $this->metricRecordPayload($row->fresh(['series']));
    }

    private function createMeasurement(SportsLiveMonitoring $monitoring, User $actor, string $clientMeasurementId): SportsLiveMeasurement
    {
        $startedAt = now();
        $measurement = SportsLiveMeasurement::query()->create([
            'monitoring_id'=>$monitoring->id,'training_id'=>$monitoring->training_id,'training_series_id'=>$monitoring->training_series_id,
            'repetition_number'=>$monitoring->current_repetition,'round_number'=>$monitoring->current_round,'state'=>'running',
            'started_at'=>$startedAt,'started_by'=>$actor->id,'client_measurement_id'=>$clientMeasurementId,
        ]);
        foreach ($monitoring->athletes()->where('active',true)->get() as $monitoringAthlete) {
            SportsLiveMeasurementAthlete::query()->create([
                'measurement_id'=>$measurement->id,'monitoring_athlete_id'=>$monitoringAthlete->id,
                'training_athlete_id'=>$monitoringAthlete->training_athlete_id,'user_id'=>$monitoringAthlete->user_id,'state'=>'active',
            ]);
        }
        SportsLiveMeasurementEvent::query()->create([
            'measurement_id'=>$measurement->id,'measurement_athlete_id'=>null,'event_type'=>'start','sequence'=>0,'elapsed_ms'=>0,
            'occurred_at'=>$startedAt,'client_event_id'=>$clientMeasurementId.':start','recorded_by'=>$actor->id,
        ]);
        return $measurement;
    }

    private function closeMeasurementIfFinished(SportsLiveMeasurement $measurement, string $occurredAt): void
    {
        if (! SportsLiveMeasurementAthlete::query()->where('measurement_id',$measurement->id)->where('state','active')->exists()) {
            $measurement->forceFill(['state'=>'stopped','ended_at'=>$occurredAt])->save();
        }
    }

    private function nextTimedContext(string $trainingId, TrainingSeries $current, int $round): ?array
    {
        $rows = TrainingSeries::query()->where('treino_id',$trainingId)->orderBy('block_order')->orderBy('ordem')->get();
        $contexts = collect();
        foreach ($rows->groupBy(fn (TrainingSeries $row): string => (string)($row->block_name ?? $row->bloco ?? '__training__')) as $blockRows) {
            $blockRows = $blockRows->values();
            $rounds = max(1, (int)($blockRows->first()?->block_rounds ?? 1));
            for ($r=1; $r<=$rounds; $r++) {
                foreach ($blockRows as $row) if (($row->timing_mode ?: 'none') !== 'none') $contexts->push(['series'=>$row,'round'=>$r]);
            }
        }
        $index = $contexts->search(fn (array $ctx): bool => (string)$ctx['series']->id === (string)$current->id && (int)$ctx['round'] === $round);
        if ($index === false) return null;
        return $contexts->get($index + 1);
    }

    private function activeMonitoringsPayload(Training $training): array
    {
        return SportsLiveMonitoring::query()->where('club_id',$this->clubContext->id())->where('training_id',$training->id)->where('state','active')
            ->orderBy('created_at')->get()->map(fn (SportsLiveMonitoring $row): array => $this->monitoringPayload($row))->values()->all();
    }

    private function monitoringPayload(SportsLiveMonitoring $monitoring, ?SportsLiveMeasurement $preferredMeasurement = null): array
    {
        $monitoring->load(['series.stroke','series.zone','athletes.athlete.dadosPessoais']);
        $users = $monitoring->athletes->pluck('athlete')->filter()->values();
        $names = $this->identityDisplayResolver->mapDisplayNames($users);
        $latest = $preferredMeasurement;
        if ($latest === null) {
            $latest = $monitoring->measurements()->latest('created_at')->first();
        }
        if ($latest !== null) {
            $latest->loadMissing(['athletes.athlete.dadosPessoais','athletes.events','athletes.classification']);
        }
        return [
            'id'=>(string)$monitoring->id,'type'=>$monitoring->type,'state'=>$monitoring->state,
            'training_id'=>(string)$monitoring->training_id,'training_series_id'=>$monitoring->training_series_id ? (string)$monitoring->training_series_id : null,
            'current_repetition'=>(int)$monitoring->current_repetition,'current_round'=>(int)$monitoring->current_round,
            'series'=>$monitoring->series ? $this->seriesPayload($monitoring->series) : null,
            'athletes'=>$monitoring->athletes->map(fn (SportsLiveMonitoringAthlete $row): array => [
                'id'=>(string)$row->user_id,'training_athlete_id'=>(string)$row->training_athlete_id,
                'name'=>$names[(string)$row->user_id] ?? $this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta'),
            ])->values(),
            'measurement'=>$latest ? $this->measurementPayload($latest,$names) : null,
        ];
    }

    private function measurementPayload(SportsLiveMeasurement $measurement, array|Collection $names = []): array
    {
        $measurement->loadMissing(['athletes.athlete.dadosPessoais','athletes.events','athletes.classification']);
        return [
            'id'=>(string)$measurement->id,'state'=>$measurement->state,'started_at'=>$measurement->started_at?->toIso8601String(),'ended_at'=>$measurement->ended_at?->toIso8601String(),
            'repetition_number'=>(int)$measurement->repetition_number,'round_number'=>(int)$measurement->round_number,
            'athletes'=>$measurement->athletes->map(function (SportsLiveMeasurementAthlete $row) use ($names): array {
                $resolved = $names[(string)$row->user_id] ?? ($row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta') : 'Atleta');
                return [
                    'id'=>(string)$row->user_id,'measurement_athlete_id'=>(string)$row->id,'name'=>$resolved,'state'=>$row->state,
                    'duration_ms'=>$row->duration_ms !== null ? (int)$row->duration_ms : null,
                    'splits'=>$row->events->where('event_type','split')->values()->map(fn (SportsLiveMeasurementEvent $event): array => ['id'=>(string)$event->id,'sequence'=>(int)$event->sequence,'elapsed_ms'=>(int)$event->elapsed_ms,'occurred_at'=>$event->occurred_at?->toIso8601String()]),
                    'classification'=>$row->classification ? [
                        'total_distance_m'=>(int)$row->classification->total_distance_m,'segment_count'=>(int)$row->classification->segment_count,
                        'segment_distance_m'=>(float)$row->classification->segment_distance_m,'stroke_label'=>$row->classification->stroke_label,
                    ] : null,
                ];
            })->values(),
        ];
    }

    private function sessionPayload(Training $training): array
    {
        $training->load(['venue:id,name','pool:id,name,length_m','series.zone','series.stroke','sessionGroups.group','athleteRecords'=>fn($q)=>$q->where('presente',true),'athleteRecords.atleta:id,name','athleteRecords.atleta.dadosPessoais:id,user_id,nome_completo']);
        $users = $training->athleteRecords->pluck('atleta')->filter()->values();
        $names = $this->identityDisplayResolver->mapDisplayNames($users);
        return [
            ...$this->sessionOption($training),'venue'=>$training->venue?->name ?? $training->local,'pool'=>$training->pool?->name,'pool_length_m'=>$training->pool?->length_m,
            'blocks'=>$training->series->groupBy(fn (TrainingSeries $row): string => (string)($row->block_name ?? $row->bloco ?? 'Treino'))->map(function (Collection $lines,string $name): array {
                $first=$lines->first(); return ['name'=>$name,'rounds'=>max(1,(int)($first?->block_rounds ?? 1)),'series'=>$lines->map(fn (TrainingSeries $line): array => $this->seriesPayload($line))->values()];
            })->values(),
            'athletes'=>$training->athleteRecords->filter(fn (TrainingAthlete $row): bool => (bool)$row->presente && $row->atleta !== null)
                ->sortBy(fn (TrainingAthlete $row): string => mb_strtolower((string)($names[(string)$row->user_id] ?? '')))
                ->map(fn (TrainingAthlete $row): array => ['id'=>(string)$row->user_id,'training_athlete_id'=>(string)$row->id,'name'=>$names[(string)$row->user_id] ?? $this->identityDisplayResolver->displayNameOrFallback($row->atleta,'Atleta'),'status'=>$row->estado])->values(),
        ];
    }

    private function sessionOption(Training $training): array
    {
        return ['id'=>(string)$training->id,'number'=>$training->numero_treino,'date'=>$training->data?->toDateString(),'start_time'=>$training->hora_inicio ? substr((string)$training->hora_inicio,0,5):null,'end_time'=>$training->hora_fim ? substr((string)$training->hora_fim,0,5):null,'training_type'=>$training->tipo_treino,'volume_m'=>(int)($training->volume_planeado_m ?? 0),'label'=>$training->sessionGroups->pluck('group.name')->filter()->join(', ') ?: ($training->tipo_treino ?: 'Treino')];
    }

    private function seriesPayload(TrainingSeries $line): array
    {
        return ['id'=>(string)$line->id,'repeticoes'=>max(1,(int)($line->repeticoes ?? 1)),'distancia_m'=>(int)($line->distancia_m ?? 0),'exercicio'=>$line->descricao_texto,'zona'=>$line->zone?->codigo ?? $line->zona_intensidade,'estilo'=>$line->stroke?->name ?? $line->estilo,'intervalo'=>$line->intervalo,'saida'=>$line->saida,'timing_mode'=>$line->timing_mode ?: 'none','block_name'=>$line->block_name ?? $line->bloco,'block_rounds'=>max(1,(int)($line->block_rounds ?? 1))];
    }

    private function definitions(): Collection
    {
        return SportsLiveMetricDefinition::query()->where('club_id',$this->clubContext->id())->where('ativo',true)->whereNull('archived_at')->orderBy('ordem')->get();
    }

    private function definitionPayload(SportsLiveMetricDefinition $row): array
    {
        return ['id'=>(string)$row->id,'code'=>$row->codigo,'name'=>$row->nome,'input_type'=>$row->input_type,'unit'=>$row->unit,'options'=>$row->options_json ?? []];
    }

    private function metricRecordPayload(SportsLiveMetricRecord $row): array
    {
        return ['id'=>(string)$row->id,'value'=>$row->value,'value_number'=>$row->value_number !== null ? (float)$row->value_number : null,'unit'=>$row->unit_snapshot,'note'=>$row->note,'recorded_at'=>$row->recorded_at?->toIso8601String(),'training_series_id'=>$row->training_series_id ? (string)$row->training_series_id : null,'exercise'=>$row->series ? $this->seriesPayload($row->series) : null,'voided'=>$row->voided_at !== null];
    }

    private function assertAthletesFree(array $trainingAthleteIds): void
    {
        $busy = SportsLiveMonitoringAthlete::query()->whereIn('training_athlete_id',$trainingAthleteIds)->where('active',true)
            ->whereHas('monitoring', fn($q)=>$q->where('state','active'))->exists();
        if ($busy) throw ValidationException::withMessages(['athletes'=>'Um dos atletas já pertence a outra monitorização ativa.']);
    }

    private function assertTrainingMutable(Training $training): void
    {
        $this->assertTrainingClub($training);
        if ($training->isOperationallyClosed()) throw ValidationException::withMessages(['training'=>'Este treino já está fechado operacionalmente.']);
    }
    private function assertTrainingClub(Training $training): void
    {
        if ((string)$training->club_id !== $this->clubContext->id()) abort(404);
    }
    private function assertSeriesBelongsToTraining(Training $training, TrainingSeries $series): void
    {
        if ((string)$series->treino_id !== (string)$training->id) throw ValidationException::withMessages(['series_id'=>'A linha não pertence a este treino.']);
    }
    private function assertMonitoringClub(SportsLiveMonitoring $monitoring): void
    {
        if ((string)$monitoring->club_id !== $this->clubContext->id()) abort(404);
    }
    private function assertMeasurementClub(SportsLiveMeasurement $measurement): void
    {
        $this->assertMonitoringClub($measurement->monitoring()->firstOrFail());
    }
    private function assertDefinition(SportsLiveMetricDefinition $definition): void
    {
        if ((string)$definition->club_id !== $this->clubContext->id() || ! $definition->ativo || $definition->archived_at !== null) abort(404);
    }
}
