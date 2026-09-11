<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\{AgeGroup, Training, TrainingAthlete, User, UserType, UserTypeMenuModule};
use App\Services\Desportivo\SportsCaisWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

final class AthleteTrainingHistoryTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
        $this->actor = User::factory()->create();
        $this->grantDesportivoAccess($this->actor);
        $this->actingAs($this->actor);
    }

    private function training(string $number, string $club = 'bscn'): Training
    {
        return Training::query()->create([
            'numero_treino' => $number, 'club_id' => $club,
            'data' => '2025-01-01', 'tipo_treino' => 'Técnico', 'session_status' => 'published',
        ]);
    }

    private function includeAthlete(Training $training, User $athlete): TrainingAthlete
    {
        return TrainingAthlete::query()->create([
            'treino_id' => $training->id, 'user_id' => $athlete->id,
            'presente' => true, 'estado' => 'presente',
        ]);
    }

    public function test_history_survives_age_group_change_and_reads_the_cais_record(): void
    {
        $old = AgeGroup::query()->create(['club_id' => 'bscn', 'nome' => 'Anterior', 'ativo' => true]);
        $current = AgeGroup::query()->create(['club_id' => 'bscn', 'nome' => 'Atual', 'ativo' => true]);
        $athlete = User::factory()->create(['escalao' => [$old->id]]);
        $training = $this->training('HISTORY');
        $training->syncAgeGroupsWithPivot([$old->id]);
        $record = $this->includeAthlete($training, $athlete);
        $athlete->update(['escalao' => [$current->id]]);
        app(SportsCaisWorkspaceService::class)->updatePresence($training, $athlete, 'atrasado', $this->actor);
        // More recent club sessions must not push this participation out of the old 100-item catalogue.
        for ($i = 0; $i < 101; $i++) {
            $unrelated = $this->training('UNRELATED-'.$i);
            $unrelated->update(['data' => '2026-01-01']);
            $unrelated->syncAgeGroupsWithPivot([$current->id]);
        }
        $count = TrainingAthlete::query()->count();
        $this->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', (string) $record->id)
            ->assertJsonPath('data.0.age_groups', ['Anterior'])
            ->assertJsonPath('data.0.session_status', 'published')
            ->assertJsonPath('data.0.attendance_status', 'atrasado');
        $this->assertSame($count, TrainingAthlete::query()->count());
        $athlete->update(['escalao' => []]);
        $this->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id)->assertJsonPath('total', 1);
    }

    public function test_history_is_paginated_and_excludes_other_athletes_and_clubs(): void
    {
        $athlete = User::factory()->create();
        for ($i = 0; $i < 26; $i++) $this->includeAthlete($this->training('PAGE-'.$i), $athlete);
        $this->includeAthlete($this->training('OTHER-CLUB', 'other-club'), $athlete);
        $this->includeAthlete($this->training('OTHER-ATHLETE'), User::factory()->create());
        $first = $this->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id)
            ->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data');
        $second = $this->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id.'&page=2')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');
        $this->assertNotContains($second->json('data.0.id'), array_column($first->json('data'), 'id'));
    }

    public function test_season_filter_uses_training_links_and_only_offers_the_athletes_club_seasons(): void
    {
        $athlete = User::factory()->create();
        $season = \App\Models\Season::query()->create([
            'club_id' => 'bscn', 'nome' => 'Histórica', 'ano_temporada' => '2024/25',
            'data_inicio' => '2024-09-01', 'data_fim' => '2025-08-31', 'tipo' => 'Principal',
            'estado' => 'Concluída', 'status' => 'closed',
        ]);
        $training = $this->training('WITH-SEASON');
        $training->update(['epoca_id' => $season->id]);
        $this->includeAthlete($training, $athlete);
        $this->includeAthlete($this->training('WITHOUT-SEASON'), $athlete);
        $url = '/api/desportivo/trainings?athlete_id='.$athlete->id;
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'seasons')->assertJsonPath('seasons.0.id', $season->id);
        $this->getJson($url.'&season_id='.$season->id)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.numero_treino', 'WITH-SEASON');
        $this->getJson($url.'&season_id=invalid')->assertUnprocessable();
        $season->update(['club_id' => 'other-club']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'seasons');
        $this->getJson($url.'&season_id='.$season->id)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_history_validates_parameters_and_preserves_route_authorization(): void
    {
        $this->getJson('/api/desportivo/trainings?athlete_id=invalid')->assertUnprocessable();
        $athlete = User::factory()->create(['perfil' => 'user', 'tipo_membro' => ['Socio']]);
        $type = UserType::query()->create([
            'codigo' => 'history_no_sports', 'nome' => 'Sem Desportivo',
            'ativo' => true, 'menu_visibility_configured' => true,
        ]);
        $athlete->userTypes()->attach($type->id);
        UserTypeMenuModule::query()->create([
            'user_type_id' => $type->id, 'module_key' => 'membros', 'sort_order' => 1,
        ]);
        $this->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id.'&page=0')->assertUnprocessable();
        $this->actingAs($athlete)->getJson('/api/desportivo/trainings?athlete_id='.$athlete->id)->assertForbidden();
    }
}
