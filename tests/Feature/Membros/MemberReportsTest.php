<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AgeGroup;
use App\Models\Season;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsModality;
use App\Models\User;
use App\Models\UserType;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_filters_members_and_only_exposes_rows_in_detailed_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $clubId = app(SportsClubContext::class)->id();

        $athleteType = UserType::query()->create([
            'codigo' => 'atleta',
            'nome' => 'Atleta',
            'descricao' => 'Atletas',
            'ativo' => true,
        ]);
        $guardianType = UserType::query()->create([
            'codigo' => 'encarregado_educacao',
            'nome' => 'Encarregado de Educação',
            'descricao' => 'Encarregados',
            'ativo' => true,
        ]);

        $junior = AgeGroup::query()->create([
            'club_id' => $clubId,
            'code' => 'junior-report-test',
            'nome' => 'Juniores',
            'ativo' => true,
        ]);
        $senior = AgeGroup::query()->create([
            'club_id' => $clubId,
            'code' => 'senior-report-test',
            'nome' => 'Seniores',
            'ativo' => true,
        ]);

        $activeJunior = User::factory()->create([
            'numero_socio' => 'R-001',
            'nome_completo' => 'Atleta Júnior',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'escalao' => [$junior->id],
        ]);
        $activeJunior->userTypes()->attach($athleteType->id);

        $suspendedSenior = User::factory()->create([
            'numero_socio' => 'R-002',
            'nome_completo' => 'Atleta Sénior',
            'estado' => 'suspenso',
            'tipo_membro' => ['atleta'],
            'escalao' => [$senior->id],
        ]);
        $suspendedSenior->userTypes()->attach($athleteType->id);

        $guardian = User::factory()->create([
            'numero_socio' => 'R-003',
            'nome_completo' => 'Encarregado',
            'estado' => 'ativo',
            'tipo_membro' => ['encarregado_educacao'],
            'escalao' => [],
        ]);
        $guardian->userTypes()->attach($guardianType->id);

        $detailed = $this->actingAs($admin)->getJson(route('api.membros.reports', [
            'mode' => 'detailed',
            'user_types' => ['atleta'],
            'age_groups' => [(string) $junior->id],
            'statuses' => ['ativo'],
        ]));

        $detailed->assertOk()
            ->assertJsonPath('mode', 'detailed')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.ativos', 1)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', (string) $activeJunior->id)
            ->assertJsonPath('rows.0.user_type_labels.0', 'Atleta')
            ->assertJsonPath('rows.0.age_group_labels.0', 'Juniores');

        $normal = $this->actingAs($admin)->getJson(route('api.membros.reports', [
            'mode' => 'normal',
            'user_types' => ['atleta'],
            'statuses' => ['ativo', 'suspenso'],
        ]));

        $normal->assertOk()
            ->assertJsonPath('mode', 'normal')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.ativos', 1)
            ->assertJsonPath('summary.suspensos', 1)
            ->assertJsonCount(0, 'rows');
    }

    public function test_current_canonical_age_group_takes_precedence_over_legacy_member_value(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 4));

        $admin = User::factory()->admin()->create();
        $clubId = app(SportsClubContext::class)->id();

        $legacyGroup = AgeGroup::query()->create([
            'club_id' => $clubId,
            'code' => 'legacy-report-test',
            'nome' => 'Legacy',
            'ativo' => true,
        ]);
        $canonicalGroup = AgeGroup::query()->create([
            'club_id' => $clubId,
            'code' => 'canonical-report-test',
            'nome' => 'Canónico',
            'ativo' => true,
        ]);

        $member = User::factory()->create([
            'nome_completo' => 'Atleta Canónico',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'escalao' => [$legacyGroup->id],
        ]);

        $modality = SportsModality::query()
            ->where('club_id', $clubId)
            ->where('code', 'swimming')
            ->first();

        $modality ??= SportsModality::query()->create([
            'club_id' => $clubId,
            'code' => 'swimming',
            'name' => 'Natação',
            'active' => true,
        ]);

        $season = Season::query()->create([
            'nome' => 'Época 2026/2027',
            'ano_temporada' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'club_id' => $clubId,
            'sports_modality_id' => $modality->id,
            'status' => 'active',
        ]);

        $participation = SportsAthleteParticipation::query()->create([
            'club_id' => $clubId,
            'user_id' => $member->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
        ]);

        SportsAthleteSeasonProfile::query()->create([
            'club_id' => $clubId,
            'user_id' => $member->id,
            'sports_athlete_participation_id' => $participation->id,
            'season_id' => $season->id,
            'sports_modality_id' => $modality->id,
            'official_age_group_id' => $canonicalGroup->id,
            'calculated_age_group_id' => $canonicalGroup->id,
            'placement_source' => 'rule',
            'evaluated_at' => now(),
        ]);

        $canonical = $this->actingAs($admin)->getJson(route('api.membros.reports', [
            'mode' => 'detailed',
            'age_groups' => [(string) $canonicalGroup->id],
        ]));

        $canonical->assertOk();
        $canonicalIds = collect($canonical->json('rows'))->pluck('id');
        $this->assertTrue($canonicalIds->contains((string) $member->id));

        $legacy = $this->actingAs($admin)->getJson(route('api.membros.reports', [
            'mode' => 'detailed',
            'age_groups' => [(string) $legacyGroup->id],
        ]));

        $legacy->assertOk();
        $legacyIds = collect($legacy->json('rows'))->pluck('id');
        $this->assertFalse($legacyIds->contains((string) $member->id));
    }

    public function test_report_api_requires_authentication(): void
    {
        $this->getJson(route('api.membros.reports'))
            ->assertUnauthorized();
    }
}
