<?php

namespace App\Services\Eventos;

use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ConvocationGroupFinancialCalculator
{
    /**
     * @return array{total:float,athlete_count:int,movement_type:string,event_title:string,event_cost_center_id:?string,items:list<array<string,mixed>>}
     */
    public function calculate(ConvocationGroup $group): array
    {
        $group = $group->fresh();
        if (!$group) {
            return [
                'total' => 0.0,
                'athlete_count' => 0,
                'movement_type' => 'outro',
                'event_title' => '',
                'event_cost_center_id' => null,
                'items' => [],
            ];
        }

        $event = Event::query()->find($group->evento_id);
        if (!$event) {
            return [
                'total' => 0.0,
                'athlete_count' => 0,
                'movement_type' => 'outro',
                'event_title' => '',
                'event_cost_center_id' => null,
                'items' => [],
            ];
        }

        $athleteIds = $this->resolveAthleteIds($group);

        $total = 0.0;
        $athleteCount = 0;
        $items = [];

        foreach ($athleteIds as $athleteId) {
            $user = User::query()->find($athleteId);
            if (!$user) {
                continue;
            }

            $provaCount = $this->getProvasCount($group->id, $athleteId);
            $estafetaCount = $this->getEstafetasCount($group->id, $athleteId);
            $value = $this->calculateCost($group, $event, $provaCount, $estafetaCount);
            if ($value <= 0) {
                continue;
            }

            $lineValue = round(abs($value), 2);
            $items[] = [
                'athlete_id' => $athleteId,
                'descricao' => sprintf(
                    '%s - %s',
                    app(MemberIdentityDisplayResolver::class)->displayName($user),
                    (string) $event->titulo,
                ),
                'valor_unitario' => $lineValue,
                'quantidade' => 1,
                'imposto_percentual' => 0,
                'total_linha' => $lineValue,
                'centro_custo_id' => $event->centro_custo_id,
            ];

            $total += $lineValue;
            $athleteCount++;
        }

        return [
            'total' => round($total, 2),
            'athlete_count' => $athleteCount,
            'movement_type' => $this->resolveMovementType($event),
            'event_title' => (string) ($event->titulo ?? ''),
            'event_cost_center_id' => $event->centro_custo_id,
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function resolveAthleteIds(ConvocationGroup $group): Collection
    {
        $athleteIds = collect($group->atletas_ids ?? [])
            ->filter(fn ($athleteId) => is_string($athleteId) && $athleteId !== '')
            ->unique()
            ->values();

        if ($athleteIds->isNotEmpty()) {
            return $athleteIds;
        }

        return ConvocationAthlete::query()
            ->where('convocatoria_grupo_id', $group->id)
            ->pluck('atleta_id')
            ->filter(fn ($athleteId) => is_string($athleteId) && $athleteId !== '')
            ->unique()
            ->values();
    }

    private function getProvasCount(string $groupId, string $athleteId): int
    {
        $athlete = ConvocationAthlete::query()
            ->where('convocatoria_grupo_id', $groupId)
            ->where('atleta_id', $athleteId)
            ->first();

        if (!$athlete || !is_array($athlete->provas)) {
            return 0;
        }

        return count($athlete->provas);
    }

    private function getEstafetasCount(string $groupId, string $athleteId): int
    {
        $athlete = ConvocationAthlete::query()
            ->where('convocatoria_grupo_id', $groupId)
            ->where('atleta_id', $athleteId)
            ->first();

        if (!$athlete) {
            return 0;
        }

        return (int) ($athlete->estafetas ?? 0);
    }

    private function calculateCost(ConvocationGroup $group, Event $event, int $provaCount, int $estafetaCount): float
    {
        $base = (float) ($group->valor_inscricao_unitaria ?? $event->taxa_inscricao ?? 0);
        $perProva = (float) ($event->custo_inscricao_por_prova ?? 0);
        $perSalto = (float) ($group->valor_por_salto ?? $event->custo_inscricao_por_salto ?? 0);
        $perEstafeta = (float) ($group->valor_por_estafeta ?? $event->custo_inscricao_estafeta ?? 0);

        return $base + ($perProva * $provaCount) + ($perSalto * $provaCount) + ($perEstafeta * $estafetaCount);
    }

    private function resolveMovementType(Event $event): string
    {
        $normalizedType = $this->normalizeType((string) ($event->tipo ?? ''));
        $eventType = EventType::query()
            ->where('ativo', true)
            ->get(['nome', 'categoria', 'gera_taxa'])
            ->first(fn (EventType $type) => in_array($normalizedType, [
                $this->normalizeType((string) $type->nome),
                $this->normalizeType((string) $type->categoria),
            ], true));

        $geraTaxa = $eventType
            ? (bool) $eventType->gera_taxa
            : (bool) $event->tipoConfig?->gera_taxa;
        $isProva = in_array($normalizedType, ['prova', 'competicao'], true);

        return ($geraTaxa || $isProva) ? 'inscricao' : 'outro';
    }

    private function normalizeType(string $value): string
    {
        return Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }
}
