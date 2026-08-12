<?php

namespace App\Services\Desportivo;

use App\Contracts\Communication\SportsCommunicationGateway;
use App\Contracts\Communication\SportsCommunicationIntentRequest;
use App\Contracts\Communication\SportsCommunicationIntentResult;
use App\Models\ConvocationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsConvocationPublicationService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly SportsCommunicationGateway $communicationGateway,
    ) {
    }

    /** @return array{group:ConvocationGroup,communication:SportsCommunicationIntentResult} */
    public function publish(ConvocationGroup $group, User $actor): array
    {
        [$publishedGroup, $intentRequest] = DB::transaction(function () use ($group, $actor): array {
            $locked = ConvocationGroup::query()
                ->lockForUpdate()
                ->with('evento')
                ->findOrFail($group->id);

            $recipientIds = collect($locked->atletas_ids ?? [])
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->unique()
                ->values();

            if ($recipientIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'convocation_group' => 'A convocatória não tem atletas para publicar.',
                ]);
            }

            if (User::query()->whereIn('id', $recipientIds)->count() !== $recipientIds->count()) {
                throw ValidationException::withMessages([
                    'convocation_group' => 'A convocatória contém atletas que já não são identificáveis.',
                ]);
            }

            $event = $locked->evento;
            if (! $event) {
                throw ValidationException::withMessages([
                    'convocation_group' => 'O evento associado à convocatória não foi encontrado.',
                ]);
            }

            $fingerprint = $this->fingerprint($locked, $recipientIds->all());
            $version = max(1, (int) ($locked->publication_version ?? 1));

            if ($locked->published_fingerprint
                && ! hash_equals((string) $locked->published_fingerprint, $fingerprint)
                && (string) $locked->publication_status === 'published') {
                $version += 1;
            }

            if ((string) $locked->publication_status !== 'published'
                || (string) $locked->published_fingerprint !== $fingerprint
                || (int) $locked->publication_version !== $version) {
                $locked->forceFill([
                    'publication_status' => 'published',
                    'publication_version' => $version,
                    'published_at' => now(),
                    'published_by' => $actor->id,
                    'published_fingerprint' => $fingerprint,
                ])->save();
            }

            $request = new SportsCommunicationIntentRequest(
                clubId: $this->clubContext->id(),
                sourceType: 'convocation_group',
                sourceId: (string) $locked->id,
                sourceVersion: $version,
                intentType: 'convocation_published',
                recipientUserIds: $recipientIds->all(),
                context: [
                    'event_id' => (string) $event->id,
                    'event_title' => (string) $event->titulo,
                    'event_date' => optional($event->data_inicio)->format('Y-m-d') ?: (string) $event->data_inicio,
                    'event_location' => $event->local,
                    'meeting_location' => $locked->local_encontro,
                    'meeting_time' => $locked->hora_encontro,
                ],
                requestedBy: (string) $actor->id,
            );

            return [$locked->fresh(['evento']), $request];
        });

        $communication = $this->communicationGateway->publish($intentRequest);

        return [
            'group' => $publishedGroup,
            'communication' => $communication,
        ];
    }

    /** @param list<string> $recipientIds */
    private function fingerprint(ConvocationGroup $group, array $recipientIds): string
    {
        sort($recipientIds);
        $event = $group->evento;

        return hash('sha256', json_encode([
            'event_id' => $group->evento_id,
            'event_title' => $event?->titulo,
            'event_date' => optional($event?->data_inicio)->format('Y-m-d'),
            'event_location' => $event?->local,
            'athletes' => $recipientIds,
            'meeting_time' => $group->hora_encontro,
            'meeting_location' => $group->local_encontro,
            'notes' => $group->observacoes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
