<?php

namespace App\Http\Controllers;

use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Family\FamilyService;
use App\Services\Loja\StoreProfileResolver;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PortalEventController extends Controller
{
    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function index(
        Request $request,
        FamilyService $familyService,
        StoreProfileResolver $profileResolver,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();
        $allowedProfiles = $profileResolver->allowedProfiles($viewer)->keyBy('id');
        $viewMode = $request->string('scope')->lower()->value() === 'family' && $familyService->userHasFamily($viewer)
            ? 'family'
            : 'personal';

        if ($viewMode === 'family') {
            $cards = $allowedProfiles
                ->values()
                ->flatMap(fn (User $member) => $this->portalEventCardsForMember($member))
                ->sortBy([
                    ['sort_bucket', 'asc'],
                    ['sort_date', 'asc'],
                    ['title', 'asc'],
                    ['subtitle', 'asc'],
                ])
                ->values();

            $selectedProfile = [
                'id' => 'family',
                'name' => 'Família',
                'type' => 'Agregado familiar',
                'portal_href' => route('portal.events', ['scope' => 'family']),
                'viewing_self' => false,
            ];
        } else {
            $cards = $this->portalEventCardsForMember($viewer);
            $selectedProfile = [
                'id' => $viewer->id,
                'name' => $this->displayName($viewer),
                'type' => $this->memberTypeLabel($viewer),
                'portal_href' => route('portal.events'),
                'viewing_self' => true,
            ];
        }

        $heroCard = $cards
            ->first(fn (array $card) => $card['status']['key'] === 'pending')
            ?? $cards->first(fn (array $card) => $card['is_upcoming'])
            ?? $cards->first();

        $activeItems = $cards
            ->filter(fn (array $card) => $card['is_upcoming'])
            ->values();

        $history = $cards
            ->filter(fn (array $card) => ! $card['is_upcoming'])
            ->values();

        $pendingItems = $activeItems
            ->filter(fn (array $card) => $card['status']['key'] === 'pending')
            ->values();

        $nextTrip = $activeItems
            ->first(fn (array $card) => filled($card['trip']['meeting_point']) || filled($card['trip']['departure_time']) || filled($card['trip']['transport']));

        return Inertia::render('Portal/Events', [
            'user' => [
                'id' => $viewer->id,
                'name' => $this->displayName($viewer),
                'email' => $viewer->email,
            ],
            'view_mode' => $viewMode,
            'selected_profile' => $selectedProfile,
            'summary' => [
                'pending_convocations' => $pendingItems->count(),
                'confirmed_events' => $cards->filter(fn (array $card) => $card['status']['key'] === 'confirmed')->count(),
                'upcoming_events' => $activeItems->count(),
                'registered_competitions' => $cards->filter(fn (array $card) => in_array($card['type']['key'], ['prova', 'competicao'], true))->count(),
            ],
            'hero_card' => $heroCard,
            'active_items' => $activeItems->all(),
            'response_state' => [
                'pending_count' => $pendingItems->count(),
                'upcoming_deadlines' => $pendingItems
                    ->take(3)
                    ->map(fn (array $card) => [
                        'id' => $card['id'],
                        'title' => $card['title'],
                        'deadline_label' => $card['date']['full_label'],
                    ])
                    ->all(),
                'alerts' => $this->buildAlerts($pendingItems, $activeItems, $history),
            ],
            'next_trip' => $nextTrip,
            'recent_history' => $history->all(),
            'is_also_admin' => $familyService->userHasAdministratorProfile($viewer),
            'has_family' => $familyService->userHasFamily($viewer),
        ]);
    }

    public function update(
        Request $request,
        EventConvocation $eventConvocation,
        StoreProfileResolver $profileResolver,
    ): RedirectResponse {
        /** @var User $viewer */
        $viewer = $request->user();
        $allowedProfiles = $profileResolver->allowedProfiles($viewer)->keyBy('id');

        abort_unless($allowedProfiles->has($eventConvocation->user_id), 403);

        $eventConvocation->loadMissing('event.competitionProjection');
        abort_if($eventConvocation->event === null, 404);
        abort_if(
            $eventConvocation->event->competitionProjection !== null
                && $eventConvocation->event->competitionProjection->club_id !== $this->clubContext->id(),
            404,
        );
        abort_if($eventConvocation->event->estado === 'cancelado', 422, 'O evento foi cancelado.');

        $eventEndDate = $eventConvocation->event->data_fim ?? $eventConvocation->event->data_inicio;
        abort_if($eventEndDate instanceof Carbon && $eventEndDate->copy()->endOfDay()->isPast(), 422, 'A convocatória já expirou.');

        $data = $request->validate([
            'action' => ['required', 'in:confirm_presence,justify_absence,reset_response'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'scope' => ['nullable', 'in:family'],
        ]);

        if ($data['action'] === 'justify_absence') {
            $request->validate([
                'justification' => ['required', 'string', 'min:5', 'max:1000'],
            ]);
        }

        match ($data['action']) {
            'confirm_presence' => $eventConvocation->update([
                'estado_confirmacao' => 'confirmado',
                'justificacao' => null,
                'data_resposta' => now(),
            ]),
            'justify_absence' => $eventConvocation->update([
                'estado_confirmacao' => 'recusado',
                'justificacao' => $data['justification'],
                'data_resposta' => now(),
            ]),
            'reset_response' => $eventConvocation->update([
                'estado_confirmacao' => 'pendente',
                'justificacao' => null,
                'data_resposta' => null,
            ]),
        };

        $routeParams = $request->string('scope')->trim()->value() === 'family'
            ? ['scope' => 'family']
            : [];

        return redirect()
            ->route('portal.events', $routeParams)
            ->with('success', 'Resposta à convocatória atualizada com sucesso.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function portalEventCardsForMember(User $member): Collection
    {
        $convocations = EventConvocation::query()
            ->with([
                'event:id,titulo,data_inicio,data_fim,hora_inicio,hora_fim,local,tipo,estado,transporte_necessario,transporte_detalhes,hora_partida,local_partida,observacoes,convocatoria_ficheiro,regulamento_ficheiro',
                'event.ageGroups:id,nome',
                'event.competitionProjection:id,event_id,competition_id,club_id,status',
            ])
            ->where('user_id', $member->id)
            ->whereHas('event', fn ($query) => $this->scopeVisibleEvent($query)->where('estado', '!=', 'cancelado'))
            ->get()
            ->filter(fn (EventConvocation $convocation) => $convocation->event !== null)
            ->values();

        $convocationEventIds = $convocations->pluck('evento_id')->filter()->unique()->values()->all();
        $member->loadMissing('athleteSportsData:id,user_id,escalao_id');
        $memberAgeGroupIds = collect(is_array($member->escalao) ? $member->escalao : (array) $member->escalao)
            ->push($member->athleteSportsData?->escalao_id)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $informativeEvents = Event::query()
            ->with(['ageGroups:id,nome', 'competitionProjection:id,event_id,competition_id,club_id,status'])
            ->where(fn ($query) => $this->scopeVisibleEvent($query))
            ->where('estado', '!=', 'cancelado')
            ->whereIn('visibilidade', ['publico', 'restrito'])
            ->whereNotIn('id', $convocationEventIds)
            ->where(function ($query) use ($memberAgeGroupIds) {
                $query->where(function ($publicQuery) {
                    $publicQuery->where('visibilidade', 'publico')
                        ->whereDoesntHave('ageGroups');
                });

                if (! empty($memberAgeGroupIds)) {
                    foreach ($memberAgeGroupIds as $ageGroupId) {
                        $query->orWhereHas('ageGroups', fn ($ageGroupQuery) => $ageGroupQuery->where('age_groups.id', $ageGroupId));
                    }
                }
            })
            ->orderBy('data_inicio')
            ->orderBy('hora_inicio')
            ->get();

        $eventIds = array_values(array_unique(array_filter([
            ...$convocationEventIds,
            ...$informativeEvents->pluck('id')->all(),
        ])));

        $groupsByEvent = ConvocationGroup::query()
            ->whereIn('evento_id', $eventIds)
            ->orderByDesc('data_criacao')
            ->get()
            ->groupBy('evento_id');

        $cards = collect($convocations->all())
            ->map(function (EventConvocation $convocation) use ($groupsByEvent, $member) {
                return $this->mapCardFromConvocation($convocation, $groupsByEvent->get($convocation->evento_id, collect()), $member);
            })
            ->merge($informativeEvents->map(function (Event $event) use ($groupsByEvent, $member) {
                return $this->mapInformativeCard($event, $groupsByEvent->get($event->id, collect()), $member);
            }))
            ->sortBy([
                ['sort_bucket', 'asc'],
                ['sort_date', 'asc'],
                ['title', 'asc'],
            ])
            ->values();

        return $cards;
    }

    /**
     * @param Collection<int, ConvocationGroup> $groups
     * @return array<string, mixed>
     */
    private function mapCardFromConvocation(EventConvocation $convocation, Collection $groups, User $member): array
    {
        /** @var Event $event */
        $event = $convocation->event;
        $group = $this->resolveConvocationGroup($groups, $member->id);
        $status = $this->resolveCardStatus($convocation->estado_confirmacao, $event, false, filled($convocation->justificacao));

        return $this->mapBaseCard(
            id: $convocation->id,
            event: $event,
            member: $member,
            group: $group,
            status: $status,
            source: 'convocation',
            canRespond: $status['key'] !== 'expired',
            allowResponseChange: in_array($status['key'], ['confirmed', 'justified'], true) && ! $this->eventHasEnded($event),
            justification: $convocation->justificacao,
            responseDate: $convocation->data_resposta,
            convocationId: $convocation->id,
        );
    }

    /**
     * @param Collection<int, ConvocationGroup> $groups
     * @return array<string, mixed>
     */
    private function mapInformativeCard(Event $event, Collection $groups, User $member): array
    {
        $group = $this->resolveConvocationGroup($groups, $member->id);
        $status = $this->resolveCardStatus(null, $event, true, false);

        return $this->mapBaseCard(
            id: sprintf('info-%s', $event->id),
            event: $event,
            member: $member,
            group: $group,
            status: $status,
            source: 'informative',
            canRespond: false,
            allowResponseChange: false,
            justification: null,
            responseDate: null,
            convocationId: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBaseCard(
        string $id,
        Event $event,
        User $member,
        ?ConvocationGroup $group,
        array $status,
        string $source,
        bool $canRespond,
        bool $allowResponseChange,
        ?string $justification,
        mixed $responseDate,
        ?string $convocationId,
    ): array {
        $eventDate = $event->data_inicio;
        $isUpcoming = ! $this->eventHasEnded($event);
        $type = $this->mapType($event->tipo);
        $meetingPoint = $group?->local_encontro ?: $event->local_partida;
        $departureTime = $group?->hora_encontro ?: $event->hora_partida;
        $transport = $event->transporte_necessario
            ? trim(implode(' · ', array_filter(['Transporte do clube', $event->transporte_detalhes])))
            : ($event->transporte_detalhes ?: 'Sem transporte planeado');

        return [
            'id' => $id,
            'convocation_id' => $convocationId,
            'event_id' => $event->id,
            'competition_id' => $event->competitionProjection?->competition_id,
            'title' => $event->titulo ?: 'Evento sem título',
            'subtitle' => $this->memberIdentityDisplayResolver->displayName($member),
            'source' => $source,
            'status' => $status,
            'type' => $type,
            'is_upcoming' => $isUpcoming,
            'sort_bucket' => $isUpcoming ? 0 : 1,
            'sort_date' => sprintf(
                '%s %s',
                $eventDate?->toDateString() ?? '9999-12-31',
                $event->hora_inicio ?? '23:59'
            ),
            'date' => [
                'day_label' => $eventDate?->locale('pt_PT')->translatedFormat('l, j M') ?? 'Data por definir',
                'full_label' => $eventDate?->locale('pt_PT')->translatedFormat('l, j \d\e F') ?? 'Data por definir',
                'time_label' => $this->formatTimeRange($event->hora_inicio, $event->hora_fim),
            ],
            'location' => [
                'name' => $event->local ?: 'Local por definir',
                'meeting_point' => $meetingPoint,
            ],
            'group' => [
                'label' => $this->groupLabel($event),
            ],
            'trip' => [
                'meeting_point' => $meetingPoint,
                'departure_time' => $departureTime ? substr((string) $departureTime, 0, 5) : null,
                'transport' => $transport,
                'return_estimate' => $event->hora_fim ? substr((string) $event->hora_fim, 0, 5) : null,
            ],
            'details' => [
                'time' => $this->formatTimeRange($event->hora_inicio, $event->hora_fim),
                'location' => $event->local ?: 'Local por definir',
                'meeting_time' => $group?->hora_encontro ? substr((string) $group->hora_encontro, 0, 5) : null,
                'meeting_point' => $meetingPoint,
                'transport' => $transport,
                'material' => $this->materialLabel($event, $type['label']),
                'notes' => $group?->observacoes ?: $event->observacoes,
                'participations' => $this->participationLabel($type['key'], $event),
                'convocatoria_file' => $event->convocatoria_ficheiro,
                'regulation_file' => $event->regulamento_ficheiro,
            ],
            'actions' => [
                'can_confirm' => $canRespond && $status['key'] !== 'confirmed',
                'can_justify' => $canRespond && $status['key'] !== 'justified',
                'can_change_response' => $allowResponseChange,
            ],
            'justification' => $justification,
            'response_date' => $responseDate instanceof Carbon ? $responseDate->toIso8601String() : null,
        ];
    }

    private function scopeVisibleEvent(Builder $query): Builder
    {
        return $query->where(function ($scope): void {
            $scope->whereDoesntHave('competitionProjection')
                ->orWhereHas(
                    'competitionProjection',
                    fn ($projection) => $projection
                        ->where('club_id', $this->clubContext->id())
                        ->where('status', 'linked'),
                );
        });
    }

    /**
     * @param Collection<int, ConvocationGroup> $groups
     */
    private function resolveConvocationGroup(Collection $groups, string $memberId): ?ConvocationGroup
    {
        return $groups->first(function (ConvocationGroup $group) use ($memberId) {
            return in_array($memberId, $group->atletas_ids ?? [], true);
        }) ?? $groups->first();
    }

    /**
     * @return array{key:string,label:string,tone:string}
     */
    private function resolveCardStatus(?string $rawStatus, Event $event, bool $isInformative, bool $hasJustification): array
    {
        if ($isInformative) {
            return ['key' => 'informative', 'label' => 'Informativo', 'tone' => 'info'];
        }

        if ($this->eventHasEnded($event) && $rawStatus === 'pendente') {
            return ['key' => 'expired', 'label' => 'Expirado', 'tone' => 'danger'];
        }

        return match (trim(mb_strtolower((string) $rawStatus))) {
            'confirmado' => ['key' => 'confirmed', 'label' => 'Confirmado', 'tone' => 'success'],
            'recusado' => ['key' => 'justified', 'label' => $hasJustification ? 'Justificado' : 'Ausência comunicada', 'tone' => 'warning'],
            'pendente' => ['key' => 'pending', 'label' => 'Resposta pendente', 'tone' => 'warning'],
            default => ['key' => 'informative', 'label' => 'Informativo', 'tone' => 'info'],
        };
    }

    private function eventHasEnded(Event $event): bool
    {
        $endDate = $event->data_fim ?? $event->data_inicio;

        return $endDate instanceof Carbon && $endDate->copy()->endOfDay()->isPast();
    }

    /**
     * @return array{key:string,label:string,badge_class:string}
     */
    private function mapType(?string $value): array
    {
        $normalized = str($value ?? 'evento')
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return match ($normalized) {
            'prova', 'competicao' => ['key' => $normalized, 'label' => 'Prova', 'badge_class' => 'bg-blue-50 text-blue-700 border-blue-200'],
            'estagio' => ['key' => 'estagio', 'label' => 'Estágio', 'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
            'reuniao' => ['key' => 'reuniao', 'label' => 'Reunião', 'badge_class' => 'bg-amber-50 text-amber-700 border-amber-200'],
            'convivio', 'evento_interno' => ['key' => 'convivio', 'label' => 'Convívio', 'badge_class' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200'],
            default => ['key' => $normalized ?: 'evento', 'label' => 'Evento', 'badge_class' => 'bg-slate-100 text-slate-700 border-slate-200'],
        };
    }

    private function groupLabel(Event $event): string
    {
        $labels = $event->relationLoaded('ageGroups')
            ? $event->ageGroups->pluck('nome')->filter()->values()->all()
            : [];

        return empty($labels) ? 'Grupo geral' : implode(' · ', array_slice($labels, 0, 3));
    }

    private function participationLabel(string $typeKey, Event $event): ?string
    {
        if (! in_array($typeKey, ['prova', 'competicao'], true)) {
            return null;
        }

        if (filled($event->descricao)) {
            return $event->descricao;
        }

        return 'Participações a definir';
    }

    private function materialLabel(Event $event, string $typeLabel): string
    {
        if (filled($event->observacoes)) {
            return 'Consultar observações do evento';
        }

        return match ($typeLabel) {
            'Prova' => 'Equipamento oficial e material competitivo',
            'Estágio' => 'Equipamento desportivo completo',
            'Reunião' => 'Documentação necessária, se aplicável',
            default => 'Material habitual do clube',
        };
    }

    /**
     * @param Collection<int, array<string, mixed>> $pendingItems
     * @param Collection<int, array<string, mixed>> $activeItems
     * @param Collection<int, array<string, mixed>> $history
     * @return array<int, string>
     */
    private function buildAlerts(Collection $pendingItems, Collection $activeItems, Collection $history): array
    {
        $alerts = collect();

        if ($pendingItems->isNotEmpty()) {
            $alerts->push(sprintf('%d convocatória(s) aguardam resposta.', $pendingItems->count()));
        }

        if ($activeItems->contains(fn (array $card) => $card['type']['key'] === 'prova')) {
            $alerts->push('Existem provas próximas com logística associada.');
        }

        if ($history->isEmpty()) {
            $alerts->push('Sem histórico recente disponível.');
        }

        return $alerts->take(3)->values()->all();
    }

    private function formatTimeRange(?string $start, ?string $end): string
    {
        $startLabel = $start ? substr($start, 0, 5) : null;
        $endLabel = $end ? substr($end, 0, 5) : null;

        if ($startLabel && $endLabel) {
            return sprintf('%s - %s', $startLabel, $endLabel);
        }

        if ($startLabel) {
            return $startLabel;
        }

        return 'Hora por definir';
    }

    private function displayName(?User $user): string
    {
        if (! $user) {
            return 'Utilizador';
        }

        return $this->memberIdentityDisplayResolver->displayNameOrFallback($user, 'Utilizador');
    }

    private function memberTypeLabel(User $user): string
    {
        $types = collect(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->filter()
            ->map(function ($type) {
                $label = trim((string) $type);

                return $label !== '' ? ucfirst(str_replace('_', ' ', $label)) : null;
            })
            ->filter()
            ->values();

        return $types->first() ?: 'Membro';
    }
}
