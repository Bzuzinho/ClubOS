<?php

declare(strict_types=1);

namespace Tests\Feature\Eventos;

use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Models\AgeGroup;
use App\Models\CommunicationSegment;
use App\Models\Event;
use App\Models\User;
use App\Services\Communication\SegmentResolverService;
use App\Services\Eventos\EventAudienceResolver;
use App\Services\Eventos\EventParticipantEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EventAudienceTargetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_persists_explicit_audiences_and_copies_them_to_recurring_children(): void
    {
        $admin = User::factory()->admin()->create();
        $ageGroup = AgeGroup::query()->create(['nome' => 'Cadetes', 'ativo' => true]);
        $eventDate = now()->addWeek()->startOfDay();

        $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Convívio de atletas e famílias',
            'descricao' => '',
            'data_inicio' => $eventDate->toDateString(),
            'tipo' => 'evento_interno',
            'visibilidade' => 'restrito',
            'estado' => 'agendado',
            'publicos_alvo' => ['atletas', 'encarregados_educacao'],
            'escaloes_elegiveis' => [$ageGroup->id],
            'recorrente' => true,
            'recorrencia_data_inicio' => $eventDate->toDateString(),
            'recorrencia_data_fim' => $eventDate->copy()->addWeek()->toDateString(),
            'recorrencia_dias_semana' => [(string) $eventDate->dayOfWeek],
        ])->assertRedirect(route('eventos.index'))->assertSessionHasNoErrors();

        $events = Event::query()->where('titulo', 'Convívio de atletas e famílias')->get();

        $this->assertCount(2, $events);
        $events->each(function (Event $event) use ($ageGroup): void {
            $this->assertSame(['atletas', 'encarregados_educacao'], $event->targetAudiences());
            $this->assertSame([$ageGroup->id], $event->ageGroups()->pluck('age_groups.id')->all());
        });
    }

    public function test_audience_resolver_is_shared_with_communication_segments(): void
    {
        $athleteInGroup = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['atleta']]);
        $athleteOutsideGroup = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['atleta']]);
        $guardian = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['encarregado_educacao']]);
        $unrelatedGuardian = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['encarregado_educacao']]);
        $otherUser = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['socio']]);
        $inactiveUser = User::factory()->create(['estado' => 'inativo', 'tipo_membro' => ['socio']]);
        $ageGroup = AgeGroup::query()->create(['nome' => 'Infantis', 'ativo' => true]);

        DB::table('user_guardian')->insert([
            'id' => fake()->uuid(),
            'user_id' => $athleteInGroup->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->instance(SportsAudienceProvider::class, new class(
            $athleteInGroup->id,
            $athleteOutsideGroup->id,
        ) implements SportsAudienceProvider {
            public function __construct(
                private readonly string $athleteInGroup,
                private readonly string $athleteOutsideGroup,
            ) {
            }

            public function activeAthleteIds(): array
            {
                return [$this->athleteInGroup, $this->athleteOutsideGroup];
            }

            public function activeCoachIds(): array
            {
                return [];
            }

            public function trainingGroupMemberIds(string $trainingGroupId): array
            {
                return [];
            }

            public function officialAgeGroupMemberIds(array $ageGroupIds, ?string $seasonId = null): array
            {
                return [$this->athleteInGroup];
            }
        });

        $event = Event::query()->create([
            'titulo' => 'Reunião de equipa e famílias',
            'descricao' => '',
            'data_inicio' => now()->addDay()->toDateString(),
            'tipo' => 'reuniao',
            'visibilidade' => 'restrito',
            'publicos_alvo' => ['atletas', 'encarregados_educacao'],
            'estado' => 'agendado',
            'criado_por' => $guardian->id,
        ]);
        $event->ageGroups()->sync([$ageGroup->id]);

        $expectedIds = [$athleteInGroup->id, $guardian->id];
        sort($expectedIds);

        $this->assertSame(
            $expectedIds,
            app(EventAudienceResolver::class)->recipientUserIds($event),
        );
        $this->assertNotContains($unrelatedGuardian->id, app(EventAudienceResolver::class)->recipientUserIds($event));

        $segment = CommunicationSegment::query()->create([
            'name' => 'Público do evento',
            'type' => 'dynamic',
            'rules_json' => ['source' => 'event_audience', 'event_id' => $event->id],
            'is_active' => true,
        ]);

        $this->assertSame(
            $expectedIds,
            app(SegmentResolverService::class)
                ->resolveRecipients($segment)
                ->pluck('user_id')
                ->sort()
                ->values()
                ->all(),
        );

        $otherEvent = Event::query()->create([
            'titulo' => 'Sessão para restantes utilizadores',
            'descricao' => '',
            'data_inicio' => now()->addDay()->toDateString(),
            'tipo' => 'reuniao',
            'visibilidade' => 'restrito',
            'publicos_alvo' => ['outros_utilizadores'],
            'estado' => 'agendado',
            'criado_por' => $guardian->id,
        ]);

        $this->assertSame(
            [$otherUser->id],
            app(EventAudienceResolver::class)->recipientUserIds($otherEvent),
        );
        $this->assertNotContains($inactiveUser->id, app(EventAudienceResolver::class)->recipientUserIds($otherEvent));
    }

    public function test_invalid_audience_combinations_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $ageGroup = AgeGroup::query()->create(['nome' => 'Juniores', 'ativo' => true]);

        $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Evento inválido',
            'descricao' => '',
            'data_inicio' => now()->addDay()->toDateString(),
            'tipo' => 'evento_interno',
            'publicos_alvo' => ['encarregados_educacao'],
            'escaloes_elegiveis' => [$ageGroup->id],
        ])->assertSessionHasErrors('escaloes_elegiveis');

        $this->actingAs($admin)->post(route('eventos.store'), [
            'titulo' => 'Outro evento inválido',
            'descricao' => '',
            'data_inicio' => now()->addDay()->toDateString(),
            'tipo' => 'evento_interno',
            'publicos_alvo' => ['todos', 'atletas'],
        ])->assertSessionHasErrors('publicos_alvo');
    }

    public function test_non_athlete_event_cannot_receive_an_athlete_participation(): void
    {
        $creator = User::factory()->create();
        $athlete = User::factory()->athlete()->create(['estado' => 'ativo']);
        $event = Event::query()->create([
            'titulo' => 'Reunião de pais',
            'descricao' => '',
            'data_inicio' => now()->addDay()->toDateString(),
            'tipo' => 'reuniao',
            'visibilidade' => 'restrito',
            'publicos_alvo' => ['encarregados_educacao'],
            'estado' => 'agendado',
            'criado_por' => $creator->id,
        ]);

        $this->expectException(ValidationException::class);

        app(EventParticipantEligibilityService::class)->assertEligible($event, $athlete);
    }

    public function test_portal_shows_informative_events_to_the_classified_non_athlete_audience(): void
    {
        $guardian = User::factory()->create([
            'estado' => 'ativo',
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);
        $otherUser = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['socio'],
        ]);

        $guardianEvent = Event::query()->create([
            'titulo' => 'Reunião de encarregados',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'reuniao',
            'visibilidade' => 'restrito',
            'publicos_alvo' => ['encarregados_educacao'],
            'estado' => 'agendado',
            'criado_por' => $guardian->id,
        ]);
        Event::query()->create([
            'titulo' => 'Sessão para outros utilizadores',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'reuniao',
            'visibilidade' => 'restrito',
            'publicos_alvo' => ['outros_utilizadores'],
            'estado' => 'agendado',
            'criado_por' => $guardian->id,
        ]);

        $this->actingAs($guardian)
            ->get(route('portal.events'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Events')
                ->has('active_items', 1)
                ->where('active_items.0.event_id', $guardianEvent->id)
            );

        $this->actingAs($otherUser)
            ->get(route('portal.events'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Events')
                ->has('active_items', 1)
                ->where('active_items.0.title', 'Sessão para outros utilizadores')
            );
    }
}
