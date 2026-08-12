<?php

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\CompetitionEventProjection;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CompetitionEventProjectionService
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    public function sync(Competition $competition, ?User $actor = null): ?Event
    {
        return DB::transaction(function () use ($competition, $actor): ?Event {
            $clubId = $this->clubContext->id();

            $lockedCompetition = Competition::query()
                ->forClub($clubId)
                ->lockForUpdate()
                ->findOrFail($competition->id);

            $projection = CompetitionEventProjection::query()
                ->where('club_id', $clubId)
                ->where('competition_id', $lockedCompetition->id)
                ->lockForUpdate()
                ->first();

            if ($projection?->status === 'manual_review' && $projection->event_id === null) {
                return null;
            }

            $event = $this->resolveExistingEvent($lockedCompetition, $projection);
            if ($event === null) {
                $projection = CompetitionEventProjection::query()
                    ->where('club_id', $clubId)
                    ->where('competition_id', $lockedCompetition->id)
                    ->lockForUpdate()
                    ->first();

                if ($projection?->status === 'manual_review') {
                    return null;
                }

                $creatorId = $actor?->id ?: $lockedCompetition->created_by;
                if (! $creatorId) {
                    $this->savePendingProjection(
                        $lockedCompetition,
                        $projection,
                        'pending_creator',
                        'projection_creator_required',
                        $actor
                    );

                    return null;
                }

                $event = Event::query()->create([
                    'titulo' => $lockedCompetition->nome,
                    'descricao' => '',
                    'data_inicio' => $lockedCompetition->data_inicio,
                    'data_fim' => $lockedCompetition->data_fim,
                    'local' => $lockedCompetition->local,
                    'tipo' => 'competicao',
                    'visibilidade' => 'publico',
                    'estado' => $this->eventStateFor($lockedCompetition),
                    'criado_por' => $creatorId,
                    'recorrente' => false,
                    'recorrencia_data_inicio' => null,
                    'recorrencia_data_fim' => null,
                    'recorrencia_dias_semana' => null,
                    'evento_pai_id' => null,
                ]);
            } else {
                $event->fill([
                    'titulo' => $lockedCompetition->nome,
                    'data_inicio' => $lockedCompetition->data_inicio,
                    'data_fim' => $lockedCompetition->data_fim,
                    'local' => $lockedCompetition->local,
                    'tipo' => 'competicao',
                    'estado' => $this->eventStateFor($lockedCompetition),
                    'recorrente' => false,
                    'recorrencia_data_inicio' => null,
                    'recorrencia_data_fim' => null,
                    'recorrencia_dias_semana' => null,
                    'evento_pai_id' => null,
                ]);
                $event->save();
            }

            $projection ??= new CompetitionEventProjection();
            $projection->fill([
                'club_id' => $clubId,
                'competition_id' => $lockedCompetition->id,
                'event_id' => $event->id,
                // Historical source pointer is preserved when one already
                // exists, but new F7 projections do not create a second alias.
                'legacy_event_id' => $projection->legacy_event_id,
                'status' => 'linked',
                'manual_review_reason' => null,
                'projected_at' => now(),
                'created_by' => $projection->created_by ?: $actor?->id,
                'updated_by' => $actor?->id,
            ]);
            $projection->save();

            return $event->fresh();
        });
    }

    private function resolveExistingEvent(
        Competition $competition,
        ?CompetitionEventProjection $projection
    ): ?Event {
        if (! $projection?->event_id) {
            return null;
        }

        $event = Event::query()->lockForUpdate()->find($projection->event_id);
        if ($event) {
            return $event;
        }

        $this->savePendingProjection(
            $competition,
            $projection,
            'manual_review',
            'projected_event_missing',
            null,
            $projection->legacy_event_id,
        );

        return null;
    }

    private function savePendingProjection(
        Competition $competition,
        ?CompetitionEventProjection $projection,
        string $status,
        string $reason,
        ?User $actor,
        ?string $legacyEventId = null,
    ): void {
        $projection ??= new CompetitionEventProjection();
        $projection->fill([
            'club_id' => $this->clubContext->id(),
            'competition_id' => $competition->id,
            'event_id' => null,
            'legacy_event_id' => $legacyEventId ?: $projection->legacy_event_id,
            'status' => $status,
            'manual_review_reason' => $reason,
            'created_by' => $projection->created_by ?: $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $projection->save();
    }

    private function eventStateFor(Competition $competition): string
    {
        return in_array($competition->status, ['cancelled', 'archived'], true)
            ? 'cancelado'
            : 'agendado';
    }
}
