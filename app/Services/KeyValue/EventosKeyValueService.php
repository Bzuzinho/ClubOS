<?php

namespace App\Services\KeyValue;

use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\ConvocationMovement;
use App\Models\ConvocationMovementItem;
use App\Models\AgeGroup;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventConvocation;
use App\Models\EventResult;
use App\Models\EventType;
use App\Models\ResultProva;
use App\Models\User;
use App\Services\Eventos\DeleteConvocationGroupAction;
use App\Services\Eventos\EventParticipantEligibilityService;
use App\Services\Eventos\SyncConvocationGroupFinancialMovementAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventosKeyValueService
{
    public function __construct(
        private readonly SyncConvocationGroupFinancialMovementAction $financialSyncAction,
        private readonly DeleteConvocationGroupAction $deleteConvocationGroupAction,
        private readonly EventParticipantEligibilityService $participantEligibilityService,
    ) {
    }

    private const CONVOCATION_GROUP_FINANCIAL_FIELDS = [
        'atletas_ids',
        'tipo_custo',
        'valor_por_salto',
        'valor_por_estafeta',
        'valor_inscricao_unitaria',
    ];

    private const CONVOCATION_GROUP_DECIMAL_FIELDS = [
        'valor_por_salto',
        'valor_por_estafeta',
        'valor_inscricao_unitaria',
    ];

    private const SUPPORTED_KEYS = [
        'club-events',
        'club-eventos-tipos',
        'club-presencas',
        'club-resultados',
        'club-resultados-provas',
        'club-convocatorias',
        'club-convocatorias-grupo',
        'club-convocatorias-atleta',
        'movimentos-convocatoria',
    ];

    public function supports(string $key): bool
    {
        return in_array($key, self::SUPPORTED_KEYS, true);
    }

    public function get(string $key, ?string $userId): array
    {
        return match ($key) {
            'club-events' => $this->getEvents(),
            'club-eventos-tipos' => $this->getEventTypeConfigs(),
            'club-presencas' => $this->getAttendances(),
            'club-resultados' => $this->getEventResults(),
            'club-resultados-provas' => $this->getResultProvas(),
            'club-convocatorias' => $this->getEventConvocations(),
            'club-convocatorias-grupo' => $this->getConvocationGroups(),
            'club-convocatorias-atleta' => $this->getConvocationAthletes(),
            'movimentos-convocatoria' => $this->getConvocationMovements(),
            default => [],
        };
    }

    public function set(string $key, mixed $value, ?string $userId): void
    {
        $items = $this->normalizeArray($value);

        match ($key) {
            'club-events' => $this->rejectLegacyLifecycleWrite('Os eventos são geridos pelo CRUD transacional do módulo de Eventos.'),
            'club-eventos-tipos' => $this->syncEventTypeConfigs($items),
            'club-presencas' => $this->syncAttendances($items, $userId),
            'club-resultados' => $this->syncEventResults($items, $userId),
            'club-resultados-provas' => $this->syncResultProvas($items),
            'club-convocatorias' => $this->rejectDirectEventConvocationWrite(),
            'club-convocatorias-grupo' => $this->syncConvocationGroups($items, $userId),
            'club-convocatorias-atleta' => $this->syncConvocationAthletes($items),
            'movimentos-convocatoria' => $this->rejectLegacyLifecycleWrite('Os movimentos de convocatória são geridos pelo fluxo financeiro canónico.'),
            default => null,
        };
    }

    public function delete(string $key, ?string $userId): void
    {
        match ($key) {
            'club-events' => $this->rejectLegacyLifecycleWrite('Os eventos são eliminados pelo CRUD transacional do módulo de Eventos.'),
            'club-eventos-tipos' => EventType::query()->delete(),
            'club-presencas' => EventAttendance::query()->delete(),
            'club-resultados' => EventResult::query()->delete(),
            'club-resultados-provas' => ResultProva::query()->delete(),
            'club-convocatorias' => $this->rejectDirectEventConvocationWrite(),
            'club-convocatorias-grupo' => $this->deleteConvocationGroups(),
            'club-convocatorias-atleta' => ConvocationAthlete::query()->delete(),
            'movimentos-convocatoria' => $this->rejectLegacyLifecycleWrite('Os movimentos de convocatória são geridos pelo fluxo financeiro canónico.'),
            default => null,
        };
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Collection) {
            return $value->all();
        }

        return [];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if (is_string($value)) {
            return $value;
        }

        return null;
    }

    private function formatTime(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return strlen($value) > 5 ? substr($value, 0, 5) : $value;
    }

    private function getEvents(): array
    {
        return Event::query()
            ->orderBy('data_inicio', 'desc')
            ->get()
            ->map(function (Event $event) {
                return [
                    'id' => $event->id,
                    'titulo' => $event->titulo,
                    'descricao' => $event->descricao,
                    'data_inicio' => $this->formatDate($event->data_inicio),
                    'hora_inicio' => $this->formatTime($event->hora_inicio),
                    'data_fim' => $this->formatDate($event->data_fim),
                    'hora_fim' => $this->formatTime($event->hora_fim),
                    'local' => $event->local,
                    'local_detalhes' => $event->local_detalhes,
                    'tipo' => $event->tipo,
                    'tipo_config_id' => $event->tipo_config_id,
                    'tipo_piscina' => $event->tipo_piscina,
                    'visibilidade' => $event->visibilidade,
                    'escaloes_elegiveis' => $event->escaloes_elegiveis,
                    'transporte_necessario' => $event->transporte_necessario,
                    'transporte_detalhes' => $event->transporte_detalhes,
                    'hora_partida' => $this->formatTime($event->hora_partida),
                    'local_partida' => $event->local_partida,
                    'taxa_inscricao' => $event->taxa_inscricao,
                    'custo_inscricao_por_prova' => $event->custo_inscricao_por_prova,
                    'custo_inscricao_por_salto' => $event->custo_inscricao_por_salto,
                    'custo_inscricao_estafeta' => $event->custo_inscricao_estafeta,
                    'centro_custo_id' => $event->centro_custo_id,
                    'observacoes' => $event->observacoes,
                    'convocatoria_ficheiro' => $event->convocatoria_ficheiro,
                    'regulamento_ficheiro' => $event->regulamento_ficheiro,
                    'estado' => $event->estado,
                    'criado_por' => $event->criado_por,
                    'criado_em' => $this->formatDateTime($event->created_at),
                    'atualizado_em' => $this->formatDateTime($event->updated_at),
                    'recorrente' => $event->recorrente,
                    'recorrencia_data_inicio' => $this->formatDate($event->recorrencia_data_inicio),
                    'recorrencia_data_fim' => $this->formatDate($event->recorrencia_data_fim),
                    'recorrencia_dias_semana' => $event->recorrencia_dias_semana,
                    'evento_pai_id' => $event->evento_pai_id,
                ];
            })
            ->all();
    }

    private function getEventTypeConfigs(): array
    {
        return EventType::query()
            ->orderBy('nome')
            ->get()
            ->map(function (EventType $type) {
                return [
                    'id' => $type->id,
                    'nome' => $type->nome,
                    'cor' => $type->cor,
                    'icon' => $type->icon,
                    'ativo' => $type->ativo,
                    'gera_taxa' => $type->gera_taxa,
                    'permite_convocatoria' => $type->permite_convocatoria,
                    'requer_convocatoria' => $type->permite_convocatoria,
                    'gera_presencas' => $type->gera_presencas,
                    'requer_transporte' => $type->requer_transporte,
                    'visibilidade_default' => $type->visibilidade_default,
                    'created_at' => $this->formatDateTime($type->created_at),
                ];
            })
            ->all();
    }

    private function getAttendances(): array
    {
        return EventAttendance::query()
            ->orderBy('registado_em', 'desc')
            ->get()
            ->map(function (EventAttendance $attendance) {
                $horaChegada = null;
                if ($attendance->hora_chegada instanceof Carbon) {
                    $horaChegada = $attendance->hora_chegada->format('H:i');
                } elseif (is_string($attendance->hora_chegada)) {
                    $horaChegada = $attendance->hora_chegada;
                }

                return [
                    'id' => $attendance->id,
                    'evento_id' => $attendance->evento_id,
                    'user_id' => $attendance->user_id,
                    'estado' => $attendance->estado,
                    'hora_chegada' => $this->formatTime($horaChegada),
                    'observacoes' => $attendance->observacoes,
                    'registado_por' => $attendance->registado_por,
                    'registado_em' => $this->formatDateTime($attendance->registado_em),
                ];
            })
            ->all();
    }

    private function getEventResults(): array
    {
        return EventResult::with('ageGroup:id,nome')
            ->orderBy('registado_em', 'desc')
            ->get()
            ->map(function (EventResult $result) {
                return [
                    'id' => $result->id,
                    'evento_id' => $result->evento_id,
                    'user_id' => $result->user_id,
                    'prova' => $result->prova,
                    'tempo' => $result->tempo,
                    'classificacao' => $result->classificacao,
                    'piscina' => $result->piscina,
                    'age_group_id' => $result->age_group_snapshot_id,
                    'escalao' => $result->ageGroup?->nome ?? $result->escalao,
                    'observacoes' => $result->observacoes,
                    'epoca' => $result->epoca,
                    'registado_por' => $result->registado_por,
                    'registado_em' => $this->formatDateTime($result->registado_em),
                ];
            })
            ->all();
    }

    private function getResultProvas(): array
    {
        return ResultProva::query()
            ->orderBy('data', 'desc')
            ->get()
            ->map(function (ResultProva $result) {
                return [
                    'id' => $result->id,
                    'atleta_id' => $result->atleta_id,
                    'evento_id' => $result->evento_id,
                    'evento_nome' => $result->evento_nome,
                    'prova' => $result->prova,
                    'local' => $result->local,
                    'data' => $this->formatDate($result->data),
                    'piscina' => $result->piscina,
                    'tempo_final' => $result->tempo_final,
                    'created_at' => $this->formatDateTime($result->created_at),
                    'updated_at' => $this->formatDateTime($result->updated_at),
                ];
            })
            ->all();
    }

    private function getEventConvocations(): array
    {
        return EventConvocation::query()
            ->orderBy('data_convocatoria', 'desc')
            ->get()
            ->map(function (EventConvocation $convocation) {
                return [
                    'id' => $convocation->id,
                    'evento_id' => $convocation->evento_id,
                    'user_id' => $convocation->user_id,
                    'data_convocatoria' => $this->formatDate($convocation->data_convocatoria),
                    'estado_confirmacao' => $convocation->estado_confirmacao,
                    'data_resposta' => $this->formatDateTime($convocation->data_resposta),
                    'justificacao' => $convocation->justificacao,
                    'observacoes' => $convocation->observacoes,
                    'transporte_clube' => $convocation->transporte_clube,
                ];
            })
            ->all();
    }

    private function getConvocationGroups(): array
    {
        return ConvocationGroup::query()
            ->orderBy('data_criacao', 'desc')
            ->get()
            ->map(function (ConvocationGroup $group) {
                return [
                    'id' => $group->id,
                    'evento_id' => $group->evento_id,
                    'data_criacao' => $this->formatDateTime($group->data_criacao),
                    'criado_por' => $group->criado_por,
                    'atletas_ids' => $group->atletas_ids,
                    'hora_encontro' => $this->formatTime($group->hora_encontro),
                    'local_encontro' => $group->local_encontro,
                    'observacoes' => $group->observacoes,
                    'tipo_custo' => $group->tipo_custo,
                    'valor_por_salto' => $group->valor_por_salto,
                    'valor_por_estafeta' => $group->valor_por_estafeta,
                    'valor_inscricao_unitaria' => $group->valor_inscricao_unitaria,
                    'valor_inscricao_calculado' => $group->valor_inscricao_calculado,
                    'movimento_id' => $group->movimento_id,
                    'centro_custo_id' => $group->centro_custo_id,
                ];
            })
            ->all();
    }

    private function getConvocationAthletes(): array
    {
        return ConvocationAthlete::query()
            ->get()
            ->map(function (ConvocationAthlete $athlete) {
                return [
                    'convocatoria_grupo_id' => $athlete->convocatoria_grupo_id,
                    'atleta_id' => $athlete->atleta_id,
                    'provas' => $athlete->provas,
                    'estafetas' => $athlete->estafetas,
                    'presente' => $athlete->presente,
                    'confirmado' => $athlete->confirmado,
                ];
            })
            ->all();
    }

    private function getConvocationMovements(): array
    {
        return ConvocationMovement::with('items')
            ->orderBy('data_emissao', 'desc')
            ->get()
            ->map(function (ConvocationMovement $movement) {
                return [
                    'id' => $movement->id,
                    'user_id' => $movement->user_id,
                    'convocatoria_grupo_id' => $movement->convocatoria_grupo_id,
                    'evento_id' => $movement->evento_id,
                    'evento_nome' => $movement->evento_nome,
                    'tipo' => $movement->tipo,
                    'data_emissao' => $this->formatDate($movement->data_emissao),
                    'valor' => $movement->valor,
                    'itens' => $movement->items->map(function (ConvocationMovementItem $item) {
                        return [
                            'id' => $item->id,
                            'movimento_convocatoria_id' => $item->movimento_convocatoria_id,
                            'descricao' => $item->descricao,
                            'valor' => $item->valor,
                        ];
                    })->all(),
                    'created_at' => $this->formatDateTime($movement->created_at),
                ];
            })
            ->all();
    }

    private function syncEvents(array $items, ?string $userId): void
    {
        DB::transaction(function () use ($items, $userId) {
            $ids = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;

                $criadoPor = $this->resolveUserId($item['criado_por'] ?? null, $userId);

                Event::updateOrCreate(
                    ['id' => $id],
                    [
                        'titulo' => $item['titulo'] ?? '',
                        'descricao' => $item['descricao'] ?? '',
                        'data_inicio' => $item['data_inicio'] ?? null,
                        'hora_inicio' => $item['hora_inicio'] ?? null,
                        'data_fim' => $item['data_fim'] ?? null,
                        'hora_fim' => $item['hora_fim'] ?? null,
                        'local' => $item['local'] ?? null,
                        'local_detalhes' => $item['local_detalhes'] ?? null,
                        'tipo' => $item['tipo'] ?? 'evento_interno',
                        'tipo_config_id' => $item['tipo_config_id'] ?? null,
                        'tipo_piscina' => $item['tipo_piscina'] ?? null,
                        'visibilidade' => $item['visibilidade'] ?? 'publico',
                        'escaloes_elegiveis' => $item['escaloes_elegiveis'] ?? null,
                        'transporte_necessario' => $item['transporte_necessario'] ?? false,
                        'transporte_detalhes' => $item['transporte_detalhes'] ?? null,
                        'hora_partida' => $item['hora_partida'] ?? null,
                        'local_partida' => $item['local_partida'] ?? null,
                        'taxa_inscricao' => $item['taxa_inscricao'] ?? null,
                        'custo_inscricao_por_prova' => $item['custo_inscricao_por_prova'] ?? null,
                        'custo_inscricao_por_salto' => $item['custo_inscricao_por_salto'] ?? null,
                        'custo_inscricao_estafeta' => $item['custo_inscricao_estafeta'] ?? null,
                        'centro_custo_id' => $item['centro_custo_id'] ?? null,
                        'observacoes' => $item['observacoes'] ?? null,
                        'convocatoria_ficheiro' => $item['convocatoria_ficheiro'] ?? null,
                        'regulamento_ficheiro' => $item['regulamento_ficheiro'] ?? null,
                        'estado' => $item['estado'] ?? 'rascunho',
                        'criado_por' => $criadoPor,
                        'recorrente' => $item['recorrente'] ?? false,
                        'recorrencia_data_inicio' => $item['recorrencia_data_inicio'] ?? null,
                        'recorrencia_data_fim' => $item['recorrencia_data_fim'] ?? null,
                        'recorrencia_dias_semana' => $item['recorrencia_dias_semana'] ?? null,
                        'evento_pai_id' => $item['evento_pai_id'] ?? null,
                    ]
                );
            }

            if (count($ids) === 0) {
                Event::query()->delete();
                return;
            }

            Event::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncEventTypeConfigs(array $items): void
    {
        DB::transaction(function () use ($items) {
            $ids = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;

                EventType::updateOrCreate(
                    ['id' => $id],
                    [
                        'nome' => $item['nome'] ?? '',
                        'descricao' => $item['descricao'] ?? null,
                        'categoria' => $item['categoria'] ?? null,
                        'cor' => $item['cor'] ?? '#3b82f6',
                        'icon' => $item['icon'] ?? 'flag',
                        'ativo' => $item['ativo'] ?? true,
                        'gera_taxa' => $item['gera_taxa'] ?? false,
                        'permite_convocatoria' => $item['permite_convocatoria'] ?? $item['requer_convocatoria'] ?? false,
                        'gera_presencas' => $item['gera_presencas'] ?? false,
                        'requer_transporte' => $item['requer_transporte'] ?? false,
                        'visibilidade_default' => $item['visibilidade_default'] ?? 'restrito',
                    ]
                );
            }

            if (count($ids) === 0) {
                EventType::query()->delete();
                return;
            }

            EventType::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncAttendances(array $items, ?string $userId): void
    {
        DB::transaction(function () use ($items, $userId) {
            $ids = [];
            $eligibilityService = $this->participantEligibilityService;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;
                $event = Event::query()->with('trainings')->findOrFail($item['evento_id'] ?? null);
                if (! $event->canEditAttendances()) {
                    throw ValidationException::withMessages([
                        'evento_id' => 'As presenças deste treino são geridas no módulo Desportivo.',
                    ]);
                }

                $existingAttendance = EventAttendance::query()->find($id);
                if (! $existingAttendance
                    || (string) $existingAttendance->evento_id !== (string) $event->id
                    || (string) $existingAttendance->user_id !== (string) ($item['user_id'] ?? '')) {
                    $athlete = User::query()
                        ->with('athleteSportsData:id,user_id,escalao_id')
                        ->findOrFail($item['user_id'] ?? null);
                    $eligibilityService->assertEligible($event, $athlete);
                }

                EventAttendance::updateOrCreate(
                    ['id' => $id],
                    [
                        'evento_id' => $item['evento_id'] ?? null,
                        'user_id' => $item['user_id'] ?? null,
                        'estado' => $item['estado'] ?? 'ausente',
                        'hora_chegada' => $item['hora_chegada'] ?? null,
                        'observacoes' => $item['observacoes'] ?? null,
                        'registado_por' => $this->resolveUserId($item['registado_por'] ?? null, $userId),
                        'registado_em' => $item['registado_em'] ?? now(),
                    ]
                );
            }

            if (count($ids) === 0) {
                EventAttendance::query()->delete();
                return;
            }

            EventAttendance::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncEventResults(array $items, ?string $userId): void
    {
        DB::transaction(function () use ($items, $userId) {
            $ids = [];
            $eligibilityService = $this->participantEligibilityService;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;
                $event = Event::query()->findOrFail($item['evento_id'] ?? null);
                $athlete = User::query()
                    ->with('athleteSportsData:id,user_id,escalao_id')
                    ->findOrFail($item['user_id'] ?? null);
                $existingResult = EventResult::query()->find($id);
                if (! $existingResult
                    || (string) $existingResult->evento_id !== (string) $event->id
                    || (string) $existingResult->user_id !== (string) $athlete->id) {
                    $eligibilityService->assertEligible($event, $athlete);
                }

                $snapshotId = $existingResult?->age_group_snapshot_id
                    ?? $this->resolveAgeGroupId($item['age_group_id'] ?? $item['escalao'] ?? null)
                    ?? $athlete->athleteSportsData?->escalao_id;

                EventResult::updateOrCreate(
                    ['id' => $id],
                    [
                        'evento_id' => $item['evento_id'] ?? null,
                        'user_id' => $item['user_id'] ?? null,
                        'prova' => $item['prova'] ?? '',
                        'tempo' => $item['tempo'] ?? null,
                        'classificacao' => $item['classificacao'] ?? null,
                        'piscina' => $item['piscina'] ?? null,
                        'age_group_snapshot_id' => $snapshotId,
                        'observacoes' => $item['observacoes'] ?? null,
                        'epoca' => $item['epoca'] ?? null,
                        'registado_por' => $this->resolveUserId($item['registado_por'] ?? null, $userId),
                        'registado_em' => $item['registado_em'] ?? now(),
                    ]
                );
            }

            if (count($ids) === 0) {
                EventResult::query()->delete();
                return;
            }

            EventResult::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncResultProvas(array $items): void
    {
        DB::transaction(function () use ($items) {
            $ids = [];
            $eligibilityService = $this->participantEligibilityService;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;
                $existingResult = ResultProva::query()->find($id);
                $athlete = User::query()
                    ->with('athleteSportsData:id,user_id,escalao_id')
                    ->findOrFail($item['atleta_id'] ?? null);
                $eventId = filled($item['evento_id'] ?? null) ? $item['evento_id'] : null;
                $athleteChanged = ! $existingResult
                    || (string) $existingResult->atleta_id !== (string) $athlete->id;
                $eventChanged = (string) ($existingResult?->evento_id ?? '') !== (string) ($eventId ?? '');

                if ($athleteChanged || $eventChanged) {
                    $eligibilityService->assertActiveAthlete($athlete);
                }

                if ($eventId !== null && ($athleteChanged || $eventChanged)) {
                    $eligibilityService->assertEligible(
                        Event::query()->findOrFail($eventId),
                        $athlete
                    );
                }

                ResultProva::updateOrCreate(
                    ['id' => $id],
                    [
                        'atleta_id' => $item['atleta_id'] ?? null,
                        'evento_id' => $eventId,
                        'evento_nome' => $item['evento_nome'] ?? null,
                        'prova' => $item['prova'] ?? '',
                        'local' => $item['local'] ?? '',
                        'data' => $item['data'] ?? null,
                        'piscina' => $item['piscina'] ?? 'piscina_25m',
                        'tempo_final' => $item['tempo_final'] ?? '',
                    ]
                );
            }

            if (count($ids) === 0) {
                ResultProva::query()->delete();
                return;
            }

            ResultProva::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncEventConvocations(array $items): void
    {
        DB::transaction(function () use ($items) {
            $ids = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;

                EventConvocation::updateOrCreate(
                    ['id' => $id],
                    [
                        'evento_id' => $item['evento_id'] ?? null,
                        'user_id' => $item['user_id'] ?? null,
                        'data_convocatoria' => $item['data_convocatoria'] ?? now()->toDateString(),
                        'estado_confirmacao' => $item['estado_confirmacao'] ?? 'pendente',
                        'data_resposta' => $item['data_resposta'] ?? null,
                        'justificacao' => $item['justificacao'] ?? null,
                        'observacoes' => $item['observacoes'] ?? null,
                        'transporte_clube' => $item['transporte_clube'] ?? false,
                    ]
                );
            }

            if (count($ids) === 0) {
                EventConvocation::query()->delete();
                return;
            }

            EventConvocation::whereNotIn('id', $ids)->delete();
        });
    }

    private function syncConvocationGroups(array $items, ?string $userId): void
    {
        DB::transaction(function () use ($items, $userId) {
            $ids = [];
            $affectedEventIds = ConvocationGroup::query()
                ->pluck('evento_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all();
            $groupsNeedingFinancialSync = [];
            $financialSyncAction = $this->financialSyncAction;
            $deleteAction = $this->deleteConvocationGroupAction;
            $eligibilityService = $this->participantEligibilityService;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;

                $event = Event::query()->findOrFail($item['evento_id'] ?? null);
                $affectedEventIds[] = (string) $event->id;
                $existingGroup = ConvocationGroup::query()->find($id);

                $athleteIds = collect($item['atletas_ids'] ?? [])
                    ->filter(fn ($athleteId) => is_string($athleteId) && $athleteId !== '')
                    ->unique()
                    ->values();

                if ($athleteIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'atletas_ids' => 'A convocatória tem de incluir pelo menos um atleta.',
                    ]);
                }

                $athletesToValidate = $existingGroup
                    && (string) $existingGroup->evento_id === (string) $event->id
                    ? $athleteIds->diff(collect($existingGroup->atletas_ids ?? [])->map(fn ($athleteId) => (string) $athleteId))
                    : $athleteIds;

                User::query()
                    ->with([
                        'athleteSportsData:id,user_id,escalao_id',
                        'userTypes:id,codigo,nome',
                    ])
                    ->whereIn('id', $athletesToValidate)
                    ->get()
                    ->each(fn (User $athlete) => $eligibilityService->assertEligible($event, $athlete));

                if (User::query()->whereIn('id', $athleteIds)->count() !== $athleteIds->count()) {
                    throw ValidationException::withMessages([
                        'atletas_ids' => 'A convocatória contém membros inexistentes.',
                    ]);
                }

                $payload = [
                    'evento_id' => $item['evento_id'] ?? null,
                    'data_criacao' => $item['data_criacao'] ?? now(),
                    'criado_por' => $this->resolveUserId($item['criado_por'] ?? null, $userId),
                    'atletas_ids' => $athleteIds->all(),
                    'hora_encontro' => $item['hora_encontro'] ?? null,
                    'local_encontro' => $item['local_encontro'] ?? null,
                    'observacoes' => $item['observacoes'] ?? null,
                    'tipo_custo' => $item['tipo_custo'] ?? 'por_salto',
                    'valor_por_salto' => $item['valor_por_salto'] ?? null,
                    'valor_por_estafeta' => $item['valor_por_estafeta'] ?? null,
                    'valor_inscricao_unitaria' => $item['valor_inscricao_unitaria'] ?? null,
                    'centro_custo_id' => $item['centro_custo_id'] ?? null,
                ];

                $hasFinancialChange = true;
                if ($existingGroup) {
                    $hasFinancialChange = $this->isConvocationFinancialPayloadChanged($existingGroup, $payload);
                    if (!$hasFinancialChange) {
                        $payload['movimento_id'] = $existingGroup->movimento_id;
                    }
                }

                $group = ConvocationGroup::updateOrCreate(
                    ['id' => $id],
                    $payload
                );

                foreach ($athleteIds as $athleteId) {
                    ConvocationAthlete::query()->firstOrCreate(
                        [
                            'convocatoria_grupo_id' => $group->id,
                            'atleta_id' => $athleteId,
                        ],
                        [
                            'provas' => [],
                            'estafetas' => 0,
                            'presente' => false,
                            'confirmado' => false,
                        ]
                    );
                }

                ConvocationAthlete::query()
                    ->where('convocatoria_grupo_id', $group->id)
                    ->whereNotIn('atleta_id', $athleteIds)
                    ->delete();

                if (!$existingGroup || $hasFinancialChange) {
                    $groupsNeedingFinancialSync[] = (string) $group->id;
                }
            }

            if (count($ids) === 0) {
                ConvocationGroup::query()
                    ->get()
                    ->each(fn (ConvocationGroup $group) => $deleteAction->execute($group));
                $this->syncCanonicalEventConvocations($affectedEventIds);
                return;
            }

            ConvocationGroup::query()
                ->whereNotIn('id', $ids)
                ->get()
                ->each(fn (ConvocationGroup $group) => $deleteAction->execute($group));

            if (!empty($groupsNeedingFinancialSync)) {
                ConvocationGroup::query()
                    ->whereIn('id', array_values(array_unique($groupsNeedingFinancialSync)))
                    ->get()
                    ->each(fn (ConvocationGroup $group) => $financialSyncAction->execute($group));
            }

            $this->syncCanonicalEventConvocations($affectedEventIds);
        });
    }

    private function syncConvocationAthletes(array $items): void
    {
        DB::transaction(function () use ($items) {
            $financialSyncAction = $this->financialSyncAction;

            if (count($items) === 0) {
                ConvocationAthlete::query()->delete();

                ConvocationGroup::query()
                    ->whereNotNull('movimento_id')
                    ->get()
                    ->each(fn (ConvocationGroup $group) => $financialSyncAction->execute($group));

                return;
            }

            $grouped = collect($items)->filter(fn ($item) => is_array($item))
                ->groupBy('convocatoria_grupo_id');

            $updatedGroupIds = [];

            foreach ($grouped as $groupId => $athletes) {
                $athleteIds = $athletes->pluck('atleta_id')->filter()->values();
                $updatedGroupIds[] = (string) $groupId;
                $group = ConvocationGroup::query()->findOrFail($groupId);
                $groupAthleteIds = collect($group->atletas_ids ?? [])->map(fn ($id) => (string) $id);

                foreach ($athletes as $item) {
                    if (! $groupAthleteIds->contains((string) ($item['atleta_id'] ?? ''))) {
                        throw ValidationException::withMessages([
                            'atleta_id' => 'O atleta não pertence ao grupo de convocatória selecionado.',
                        ]);
                    }

                    // Delete existing record first
                    ConvocationAthlete::where('convocatoria_grupo_id', $item['convocatoria_grupo_id'])
                        ->where('atleta_id', $item['atleta_id'])
                        ->delete();
                    
                    // Then create new record
                    ConvocationAthlete::create([
                        'convocatoria_grupo_id' => $item['convocatoria_grupo_id'],
                        'atleta_id' => $item['atleta_id'],
                        'provas' => $item['provas'] ?? [],
                        'estafetas' => $item['estafetas'] ?? 0,
                        'presente' => $item['presente'] ?? false,
                        'confirmado' => $item['confirmado'] ?? false,
                    ]);
                }

                ConvocationAthlete::where('convocatoria_grupo_id', $groupId)
                    ->when($athleteIds->isNotEmpty(), fn ($query) => $query->whereNotIn('atleta_id', $athleteIds))
                    ->delete();
            }

            $groupIds = $grouped->keys()->filter()->values();
            if ($groupIds->isNotEmpty()) {
                ConvocationAthlete::whereNotIn('convocatoria_grupo_id', $groupIds)->delete();
            }

            if (!empty($updatedGroupIds)) {
                ConvocationGroup::query()
                    ->whereIn('id', array_values(array_unique($updatedGroupIds)))
                    ->get()
                    ->each(fn (ConvocationGroup $group) => $financialSyncAction->execute($group));
            }
        });
    }

    private function syncConvocationMovements(array $items): void
    {
        DB::transaction(function () use ($items) {
            $ids = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? (string) Str::uuid();
                $ids[] = $id;

                ConvocationMovement::updateOrCreate(
                    ['id' => $id],
                    [
                        'user_id' => $item['user_id'] ?? null,
                        'convocatoria_grupo_id' => $item['convocatoria_grupo_id'] ?? null,
                        'evento_id' => $item['evento_id'] ?? null,
                        'evento_nome' => $item['evento_nome'] ?? '',
                        'tipo' => $item['tipo'] ?? 'convocatoria',
                        'data_emissao' => $item['data_emissao'] ?? now()->toDateString(),
                        'valor' => $item['valor'] ?? 0,
                    ]
                );

                $itemIds = [];
                $movementItems = $item['itens'] ?? [];

                foreach ($movementItems as $movementItem) {
                    if (!is_array($movementItem)) {
                        continue;
                    }

                    $itemId = $movementItem['id'] ?? (string) Str::uuid();
                    $itemIds[] = $itemId;

                    ConvocationMovementItem::updateOrCreate(
                        ['id' => $itemId],
                        [
                            'movimento_convocatoria_id' => $id,
                            'descricao' => $movementItem['descricao'] ?? '',
                            'valor' => $movementItem['valor'] ?? 0,
                        ]
                    );
                }

                if (count($itemIds) === 0) {
                    ConvocationMovementItem::where('movimento_convocatoria_id', $id)->delete();
                } else {
                    ConvocationMovementItem::where('movimento_convocatoria_id', $id)
                        ->whereNotIn('id', $itemIds)
                        ->delete();
                }
            }

            if (count($ids) === 0) {
                ConvocationMovementItem::query()->delete();
                ConvocationMovement::query()->delete();
                return;
            }

            ConvocationMovementItem::whereNotIn('movimento_convocatoria_id', $ids)->delete();
            ConvocationMovement::whereNotIn('id', $ids)->delete();
        });
    }

    private function resolveUserId(?string $candidate, ?string $fallback): ?string
    {
        if ($candidate && Str::isUuid($candidate)) {
            return $candidate;
        }

        if ($fallback && Str::isUuid($fallback)) {
            return $fallback;
        }

        return User::query()->value('id');
    }

    private function resolveAgeGroupId(mixed $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $trimmedValue = trim($candidate);
        if ($trimmedValue === '') {
            return null;
        }

        if (AgeGroup::query()->whereKey($trimmedValue)->exists()) {
            return $trimmedValue;
        }

        return AgeGroup::query()
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($trimmedValue)])
            ->value('id');
    }

    private function deleteConvocationMovements(): bool
    {
        ConvocationMovementItem::query()->delete();
        ConvocationMovement::query()->delete();

        return true;
    }

    private function deleteConvocationGroups(): void
    {
        $deleteAction = $this->deleteConvocationGroupAction;

        DB::transaction(function () use ($deleteAction): void {
            $affectedEventIds = ConvocationGroup::query()
                ->pluck('evento_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all();

            ConvocationGroup::query()
                ->get()
                ->each(fn (ConvocationGroup $group) => $deleteAction->execute($group));

            $this->syncCanonicalEventConvocations($affectedEventIds);
        });
    }

    /**
     * @param list<string> $eventIds
     */
    private function syncCanonicalEventConvocations(array $eventIds): void
    {
        collect($eventIds)
            ->filter()
            ->unique()
            ->each(function (string $eventId): void {
                $athleteIds = ConvocationGroup::query()
                    ->where('evento_id', $eventId)
                    ->get(['atletas_ids'])
                    ->flatMap(fn (ConvocationGroup $group) => $group->atletas_ids ?? [])
                    ->filter(fn ($id) => is_string($id) && $id !== '')
                    ->unique()
                    ->values();

                foreach ($athleteIds as $athleteId) {
                    EventConvocation::query()->firstOrCreate(
                        [
                            'evento_id' => $eventId,
                            'user_id' => $athleteId,
                        ],
                        [
                            'data_convocatoria' => now()->toDateString(),
                            'estado_confirmacao' => 'pendente',
                            'transporte_clube' => false,
                        ]
                    );
                }

                EventConvocation::query()
                    ->where('evento_id', $eventId)
                    ->when(
                        $athleteIds->isNotEmpty(),
                        fn ($query) => $query->whereNotIn('user_id', $athleteIds),
                    )
                    ->delete();
            });
    }

    private function rejectDirectEventConvocationWrite(): never
    {
        throw ValidationException::withMessages([
            'value' => 'As convocatórias são geridas pelos grupos para manter a ligação aos membros sincronizada.',
        ]);
    }

    private function rejectLegacyLifecycleWrite(string $message): never
    {
        throw ValidationException::withMessages([
            'value' => $message,
        ]);
    }

    private function isConvocationFinancialPayloadChanged(ConvocationGroup $group, array $payload): bool
    {
        foreach (self::CONVOCATION_GROUP_FINANCIAL_FIELDS as $field) {
            $currentValue = $group->{$field};
            $newValue = $payload[$field] ?? null;

            if (is_array($currentValue) || is_array($newValue)) {
                $current = collect($currentValue ?? [])->map(fn ($value) => (string) $value)->values()->all();
                $incoming = collect($newValue ?? [])->map(fn ($value) => (string) $value)->values()->all();

                if ($current !== $incoming) {
                    return true;
                }

                continue;
            }

            if (in_array($field, self::CONVOCATION_GROUP_DECIMAL_FIELDS, true)) {
                $currentDecimal = $currentValue === null ? null : round((float) $currentValue, 2);
                $newDecimal = $newValue === null ? null : round((float) $newValue, 2);

                if ($currentDecimal !== $newDecimal) {
                    return true;
                }

                continue;
            }

            if ((string) ($currentValue ?? '') !== (string) ($newValue ?? '')) {
                return true;
            }
        }

        return false;
    }
}
