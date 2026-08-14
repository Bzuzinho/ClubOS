<?php

namespace App\Services\Desportivo;

use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Models\CompetitionEventProjection;
use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\SportsConvocationPublication;
use App\Models\User;
use App\Services\Eventos\SyncConvocationGroupFinancialMovementAction;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsConvocationWorkspaceService
{
    public function __construct(
        private readonly SportsAudienceProvider $audience,
        private readonly MemberIdentityDisplayResolver $identity,
        private readonly SyncConvocationGroupFinancialMovementAction $financialSync,
        private readonly SportsConvocationPublicationService $publication,
    ) {}

    public function workspace(): array
    {
        $groups = ConvocationGroup::query()
            ->with(['evento', 'convocationAthletes.atleta'])
            ->orderByDesc('data_criacao')
            ->get();

        $eventIds = $groups->pluck('evento_id')->filter()->unique()->values();
        $responses = EventConvocation::query()
            ->whereIn('evento_id', $eventIds)
            ->get()
            ->keyBy(fn (EventConvocation $r) => $r->evento_id.'|'.$r->user_id);

        $projections = CompetitionEventProjection::query()
            ->with('competition')
            ->whereIn('event_id', $eventIds)
            ->get()
            ->keyBy('event_id');

        $athleteIds = $this->audience->activeAthleteIds();
        $athletes = User::query()->whereIn('id', $athleteIds)->get();
        $names = $this->identity->mapDisplayNames($athletes);

        return [
            'convocations' => $groups->map(fn (ConvocationGroup $group) => $this->groupPayload($group, $responses, $projections))->values()->all(),
            'events' => Event::query()
                ->where('estado', '!=', 'cancelado')
                ->orderBy('data_inicio')
                ->limit(250)
                ->get()
                ->map(fn (Event $event) => [
                    'id' => (string) $event->id,
                    'title' => (string) $event->titulo,
                    'starts_at' => optional($event->data_inicio)->format('Y-m-d') ?: (string) $event->data_inicio,
                    'location' => $event->local,
                    'type' => $event->tipo,
                ])->values()->all(),
            'athletes' => $athletes->map(fn (User $u) => [
                'id' => (string) $u->id,
                'name' => $names[$u->id] ?? $this->identity->displayName($u),
                'member_number' => $u->numero_socio,
            ])->values()->all(),
            'cost_centers' => CostCenter::query()->where('ativo', true)->orderBy('nome')->get(['id','nome'])->map(fn ($c) => ['id'=>(string)$c->id,'name'=>(string)$c->nome])->all(),
        ];
    }

    public function detail(ConvocationGroup $group): array
    {
        $group->load(['evento','convocationAthletes.atleta']);
        $responses = EventConvocation::query()->where('evento_id', $group->evento_id)->get()->keyBy(fn (EventConvocation $r) => $r->evento_id.'|'.$r->user_id);
        $projections = CompetitionEventProjection::query()->with('competition')->where('event_id', $group->evento_id)->get()->keyBy('event_id');
        $payload = $this->groupPayload($group, $responses, $projections);
        $payload['publications'] = SportsConvocationPublication::query()
            ->where('convocation_group_id', $group->id)
            ->orderByDesc('version')
            ->get()
            ->map(fn (SportsConvocationPublication $p) => [
                'version' => $p->version,
                'published_at' => optional($p->published_at)->toIso8601String(),
                'published_by' => $p->published_by,
                'recipient_count' => $p->recipient_count,
                'communication_status' => $p->communication_status,
                'fingerprint' => $p->fingerprint,
            ])->all();
        return $payload;
    }

    public function create(array $data, User $actor): ConvocationGroup
    {
        return DB::transaction(function () use ($data, $actor): ConvocationGroup {
            $event = Event::query()->findOrFail($data['event_id']);
            $athletes = collect($data['athletes'] ?? [])->filter(fn ($x) => is_array($x) && !empty($x['user_id']))->values();
            if ($athletes->isEmpty()) {
                throw ValidationException::withMessages(['athletes' => 'Seleciona pelo menos um atleta.']);
            }
            $ids = $athletes->pluck('user_id')->map('strval')->unique()->values();
            $active = collect($this->audience->activeAthleteIds());
            if ($ids->diff($active)->isNotEmpty()) {
                throw ValidationException::withMessages(['athletes' => 'A convocatória contém atletas sem participação desportiva ativa.']);
            }

            $group = ConvocationGroup::query()->create([
                'evento_id' => $event->id,
                'data_criacao' => now(),
                'criado_por' => $actor->id,
                'atletas_ids' => $ids->all(),
                'hora_encontro' => $data['meeting_time'] ?? null,
                'local_encontro' => $data['meeting_location'] ?? null,
                'observacoes' => $data['notes'] ?? null,
                'tipo_custo' => $data['cost_type'] ?? 'sem_custo',
                'valor_por_salto' => $data['value_per_race'] ?? 0,
                'valor_por_estafeta' => $data['value_per_relay'] ?? 0,
                'valor_inscricao_unitaria' => $data['unit_registration_value'] ?? 0,
                'centro_custo_id' => $data['cost_center_id'] ?? null,
                'publication_status' => 'draft',
                'publication_version' => 1,
            ]);

            foreach ($athletes as $athlete) {
                ConvocationAthlete::query()->create([
                    'convocatoria_grupo_id' => $group->id,
                    'atleta_id' => $athlete['user_id'],
                    'provas' => array_values($athlete['race_ids'] ?? []),
                    'estafetas' => (int) ($athlete['relays'] ?? 0),
                    'presente' => false,
                    'confirmado' => false,
                ]);
                EventConvocation::query()->firstOrCreate([
                    'evento_id' => $event->id,
                    'user_id' => $athlete['user_id'],
                ], [
                    'data_convocatoria' => now()->toDateString(),
                    'estado_confirmacao' => 'pendente',
                    'transporte_clube' => false,
                ]);
            }

            $this->financialSync->execute($group);
            $group->refresh();
            if (($data['publish_now'] ?? false) === true) {
                $this->publish($group, $actor);
            }
            return $group->fresh(['evento','convocationAthletes.atleta']);
        });
    }

    public function update(ConvocationGroup $group, array $data): ConvocationGroup
    {
        return DB::transaction(function () use ($group, $data): ConvocationGroup {
            $group->update([
                'hora_encontro' => $data['meeting_time'] ?? $group->hora_encontro,
                'local_encontro' => $data['meeting_location'] ?? $group->local_encontro,
                'observacoes' => array_key_exists('notes',$data) ? $data['notes'] : $group->observacoes,
                'tipo_custo' => $data['cost_type'] ?? $group->tipo_custo,
                'valor_por_salto' => $data['value_per_race'] ?? $group->valor_por_salto,
                'valor_por_estafeta' => $data['value_per_relay'] ?? $group->valor_por_estafeta,
                'valor_inscricao_unitaria' => $data['unit_registration_value'] ?? $group->valor_inscricao_unitaria,
                'centro_custo_id' => $data['cost_center_id'] ?? $group->centro_custo_id,
            ]);
            $this->financialSync->execute($group);
            return $group->fresh(['evento','convocationAthletes.atleta']);
        });
    }

    public function publish(ConvocationGroup $group, User $actor): array
    {
        $result = $this->publication->publish($group, $actor);
        $published = $result['group'];
        $communication = $result['communication']->toArray();
        $published->load(['evento','convocationAthletes']);

        SportsConvocationPublication::query()->updateOrCreate([
            'convocation_group_id' => $published->id,
            'version' => (int) $published->publication_version,
        ], [
            'fingerprint' => (string) $published->published_fingerprint,
            'published_by' => $published->published_by,
            'published_at' => $published->published_at ?? now(),
            'recipient_count' => count($published->atletas_ids ?? []),
            'communication_status' => $communication['status'] ?? null,
            'communication_key' => $communication['idempotency_key'] ?? null,
            'snapshot_json' => [
                'event_id' => (string) $published->evento_id,
                'event_title' => $published->evento?->titulo,
                'athletes' => $published->atletas_ids ?? [],
                'meeting_time' => $published->hora_encontro,
                'meeting_location' => $published->local_encontro,
                'notes' => $published->observacoes,
                'race_assignments' => $published->convocationAthletes->map(fn (ConvocationAthlete $a) => [
                    'user_id' => (string) $a->atleta_id,
                    'race_ids' => $a->provas ?? [],
                    'relays' => (int) ($a->estafetas ?? 0),
                ])->values()->all(),
            ],
        ]);

        return ['group' => $published, 'communication' => $communication];
    }

    private function groupPayload(ConvocationGroup $group, $responses, $projections): array
    {
        $projection = $projections->get($group->evento_id);
        $athletes = $group->convocationAthletes->map(function (ConvocationAthlete $row) use ($responses, $group) {
            $response = $responses->get($group->evento_id.'|'.$row->atleta_id);
            return [
                'user_id' => (string) $row->atleta_id,
                'name' => $row->atleta ? $this->identity->displayName($row->atleta) : 'Atleta indisponível',
                'race_ids' => $row->provas ?? [],
                'relays' => (int) ($row->estafetas ?? 0),
                'response_status' => $response?->estado_confirmacao ?? 'pendente',
                'responded_at' => optional($response?->data_resposta)->toIso8601String(),
                'justification' => $response?->justificacao,
                'club_transport' => (bool) ($response?->transporte_clube ?? false),
            ];
        })->values();
        return [
            'id' => (string) $group->id,
            'event' => [
                'id' => (string) $group->evento_id,
                'title' => $group->evento?->titulo ?? 'Evento indisponível',
                'starts_at' => optional($group->evento?->data_inicio)->format('Y-m-d') ?: (string) ($group->evento?->data_inicio ?? ''),
                'location' => $group->evento?->local,
                'type' => $group->evento?->tipo,
            ],
            'competition' => $projection?->competition ? ['id'=>(string)$projection->competition->id,'name'=>(string)$projection->competition->nome] : null,
            'publication_status' => (string) ($group->publication_status ?? 'draft'),
            'publication_version' => (int) ($group->publication_version ?? 1),
            'published_at' => optional($group->published_at)->toIso8601String(),
            'meeting_time' => $group->hora_encontro,
            'meeting_location' => $group->local_encontro,
            'notes' => $group->observacoes,
            'cost' => [
                'type' => $group->tipo_custo,
                'value_per_race' => (float) ($group->valor_por_salto ?? 0),
                'value_per_relay' => (float) ($group->valor_por_estafeta ?? 0),
                'unit_registration_value' => (float) ($group->valor_inscricao_unitaria ?? 0),
                'calculated_value' => (float) ($group->valor_inscricao_calculado ?? 0),
                'cost_center_id' => $group->centro_custo_id,
                'movement_id' => $group->movimento_id,
            ],
            'athletes' => $athletes->all(),
            'stats' => [
                'total' => $athletes->count(),
                'confirmed' => $athletes->where('response_status','confirmado')->count(),
                'pending' => $athletes->where('response_status','pendente')->count(),
                'declined' => $athletes->where('response_status','recusado')->count(),
                'club_transport' => $athletes->where('club_transport',true)->count(),
            ],
        ];
    }
}
