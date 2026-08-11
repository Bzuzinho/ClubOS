<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\DadosPessoais;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use App\Models\SportsAthleteLimitation;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsLimitationType;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsMemberProfileService;
use App\Services\Desportivo\SportsMemberProvisioningService;
use App\Services\Desportivo\SportsMemberStatusResolver;
use App\Services\Desportivo\SportsStructureService;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SportsMemberCanonicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_f3_schema_is_additive_and_keeps_legacy_profile(): void
    {
        foreach ([
            'sports_athlete_participations',
            'sports_athlete_season_profiles',
            'sports_federations',
            'sports_athlete_federation_affiliations',
            'sports_athlete_limitations',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing F3 table {$table}");
        }

        $this->assertTrue(Schema::hasColumn('sports_athlete_participations', 'current_slot'));
        $this->assertTrue(Schema::hasTable('athlete_sports_data'));
    }

    public function test_athlete_can_have_two_active_modalities_without_duplicate_identity(): void
    {
        $member = $this->athlete('F3-MULTI');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();
        $triathlon = SportsModality::query()->create([
            'club_id' => $this->clubId(),
            'code' => 'triathlon',
            'name' => 'Triatlo',
            'active' => true,
        ]);

        app(SportsMemberProfileService::class)->updateFromMemberSurface($member, [
            'participations' => [
                ['sports_modality_id' => $swimming->id, 'active' => true, 'starts_at' => '2026-09-01'],
                ['sports_modality_id' => $triathlon->id, 'active' => true, 'starts_at' => '2026-09-01'],
            ],
        ], $admin);

        $current = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->get();

        $this->assertCount(2, $current);
        $this->assertEqualsCanonicalizing(
            [(string) $swimming->id, (string) $triathlon->id],
            $current->pluck('sports_modality_id')->map('strval')->all()
        );
        $this->assertSame(1, AthleteSportsData::query()->where('user_id', $member->id)->count());
        $this->assertTrue((bool) $member->fresh()->ativo_desportivo);
    }

    public function test_database_rejects_two_current_periods_for_same_modality(): void
    {
        $member = $this->athlete('F3-SLOT');
        $swimming = $this->swimming();

        SportsAthleteParticipation::query()->create([
            'club_id' => $this->clubId(),
            'user_id' => $member->id,
            'sports_modality_id' => $swimming->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
        ]);

        $this->expectException(QueryException::class);

        SportsAthleteParticipation::query()->create([
            'club_id' => $this->clubId(),
            'user_id' => $member->id,
            'sports_modality_id' => $swimming->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-10-01',
        ]);
    }

    public function test_official_age_group_uses_season_rule_not_current_age(): void
    {
        $member = $this->athlete('F3-SEASON', '2008-08-20');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();
        $junior = $this->ageGroup('junior-f3', 'Juniores F3');
        $season = $this->season($swimming, '2026-09-01', '2027-07-31');

        SeasonAgeGroupRule::query()->create([
            'club_id' => $this->clubId(),
            'season_id' => $season->id,
            'sports_modality_id' => $swimming->id,
            'age_group_id' => $junior->id,
            'age_min' => 18,
            'age_max' => 18,
            'priority' => 100,
            'active' => true,
        ]);

        $service = app(SportsMemberProfileService::class);
        $service->updateFromMemberSurface($member, [
            'participations' => [[
                'sports_modality_id' => $swimming->id,
                'active' => true,
                'starts_at' => '2026-09-01',
            ]],
        ], $admin);
        $service->refreshSeasonProfiles($member, $admin->id);

        $profile = SportsAthleteSeasonProfile::query()
            ->where('user_id', $member->id)
            ->where('season_id', $season->id)
            ->firstOrFail();

        $this->assertSame((string) $junior->id, (string) $profile->calculated_age_group_id);
        $this->assertSame((string) $junior->id, (string) $profile->official_age_group_id);
        $this->assertSame('rule', $profile->placement_source);
        $this->assertSame('2027-07-31', $profile->reference_date?->toDateString());
    }

    public function test_manual_override_keeps_calculated_group_for_comparison(): void
    {
        $member = $this->athlete('F3-OVERRIDE', '2008-08-20');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();
        $calculated = $this->ageGroup('junior-auto-f3', 'Juniores Automático');
        $official = $this->ageGroup('senior-override-f3', 'Seniores Override');
        $season = $this->season($swimming, '2026-09-01', '2027-07-31');

        SeasonAgeGroupRule::query()->create([
            'club_id' => $this->clubId(),
            'season_id' => $season->id,
            'sports_modality_id' => $swimming->id,
            'age_group_id' => $calculated->id,
            'birth_year_min' => 2008,
            'birth_year_max' => 2008,
            'priority' => 100,
            'active' => true,
        ]);

        $service = app(SportsMemberProfileService::class);
        $service->updateFromMemberSurface($member, [
            'participations' => [[
                'sports_modality_id' => $swimming->id,
                'active' => true,
                'starts_at' => '2026-09-01',
            ]],
        ], $admin);

        $override = app(SportsStructureService::class)->createAgeGroupOverride([
            'user_id' => $member->id,
            'season_id' => $season->id,
            'sports_modality_id' => $swimming->id,
            'age_group_id' => $official->id,
            'reason' => 'Decisão técnica validada para competição.',
        ], $admin->id);

        $service->refreshSeasonProfiles($member, $admin->id);

        $profile = SportsAthleteSeasonProfile::query()
            ->where('user_id', $member->id)
            ->where('season_id', $season->id)
            ->firstOrFail();

        $this->assertSame((string) $calculated->id, (string) $profile->calculated_age_group_id);
        $this->assertSame((string) $official->id, (string) $profile->official_age_group_id);
        $this->assertSame('override', $profile->placement_source);
        $this->assertSame((string) $override->id, (string) $profile->athlete_age_group_override_id);
    }

    public function test_removing_athlete_type_closes_current_participation_without_deleting_history(): void
    {
        $member = $this->athlete('F3-HISTORY');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();
        $service = app(SportsMemberProfileService::class);

        $service->updateFromMemberSurface($member, [
            'participations' => [[
                'sports_modality_id' => $swimming->id,
                'active' => true,
                'starts_at' => '2026-09-01',
            ]],
        ], $admin);

        $participationId = SportsAthleteParticipation::query()
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->value('id');

        $member->forceFill(['tipo_membro' => ['socio']])->saveQuietly();
        app(SportsMemberProvisioningService::class)->sync($member->fresh(), ['tipo_membro' => ['socio']]);

        $history = SportsAthleteParticipation::query()->findOrFail($participationId);
        $this->assertFalse((bool) $history->active);
        $this->assertNull($history->current_slot);
        $this->assertNotNull($history->ends_at);
        $this->assertSame(1, SportsAthleteParticipation::query()->where('user_id', $member->id)->count());
        $this->assertFalse((bool) $member->fresh()->ativo_desportivo);
    }

    public function test_status_resolver_prefers_canonical_participation_over_conflicting_legacy_flags(): void
    {
        $member = $this->athlete('F3-STATUS');
        $swimming = $this->swimming();

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'ativo' => false,
        ]);
        $member->forceFill(['ativo_desportivo' => false])->saveQuietly();

        $participation = SportsAthleteParticipation::query()->create([
            'club_id' => $this->clubId(),
            'user_id' => $member->id,
            'sports_modality_id' => $swimming->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
        ]);

        $resolver = app(SportsMemberStatusResolver::class);
        $this->assertTrue($resolver->sportsActivityActive($member->fresh(['athleteSportsData'])));

        $participation->forceFill([
            'active' => false,
            'current_slot' => null,
            'ends_at' => '2026-10-01',
        ])->save();
        $member->athleteSportsData()->update(['ativo' => true]);
        $member->forceFill(['ativo_desportivo' => true])->saveQuietly();

        $this->assertFalse($resolver->sportsActivityActive($member->fresh(['athleteSportsData'])));
    }

    public function test_operational_limitation_does_not_reinterpret_legacy_clinical_json(): void
    {
        $member = $this->athlete('F3-LIMIT');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();
        $legacyClinical = json_encode(['diagnostico' => 'conteúdo clínico legacy']);

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'ativo' => true,
            'informacoes_medicas' => $legacyClinical,
        ]);

        $type = SportsLimitationType::query()->create([
            'club_id' => $this->clubId(),
            'codigo' => 'volume-reduzido-f3',
            'nome' => 'Volume reduzido',
            'instrucao_padrao' => 'Reduzir o volume de treino.',
            'allows_training' => true,
            'allows_competition' => false,
            'requires_end_date' => false,
            'ativo' => true,
            'ordem' => 10,
        ]);

        app(SportsMemberProfileService::class)->updateFromMemberSurface($member, [
            'participations' => [[
                'sports_modality_id' => $swimming->id,
                'active' => true,
                'starts_at' => '2026-09-01',
            ]],
            'limitations' => [[
                'sports_limitation_type_id' => $type->id,
                'sports_modality_id' => $swimming->id,
                'starts_at' => '2026-09-15',
                'operational_instruction' => 'Máximo de 60% do volume planeado.',
            ]],
        ], $admin);

        $limitation = SportsAthleteLimitation::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame('Máximo de 60% do volume planeado.', $limitation->operational_instruction);
        $this->assertTrue((bool) $limitation->allows_training);
        $this->assertFalse((bool) $limitation->allows_competition);
        $this->assertSame(
            $legacyClinical,
            AthleteSportsData::query()->where('user_id', $member->id)->value('informacoes_medicas')
        );
    }

    public function test_finance_eligibility_continues_to_read_sports_state_without_writing_it(): void
    {
        $member = $this->athlete('F3-FINANCE');
        $swimming = $this->swimming();

        SportsAthleteParticipation::query()->create([
            'club_id' => $this->clubId(),
            'user_id' => $member->id,
            'sports_modality_id' => $swimming->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
        ]);

        $service = app(MemberMonthlyFeeEligibilityService::class);
        $this->assertTrue($service->shouldHaveMonthlyFee($member->fresh(['userTypes'])));

        SportsAthleteParticipation::query()
            ->where('user_id', $member->id)
            ->update(['active' => false, 'current_slot' => null, 'ends_at' => '2026-10-01']);
        $member->forceFill(['ativo_desportivo' => true])->saveQuietly();

        $this->assertFalse($service->shouldHaveMonthlyFee($member->fresh(['userTypes'])));
    }

    private function athlete(string $memberNumber, ?string $birthDate = null): User
    {
        $member = User::factory()->create([
            'numero_socio' => $memberNumber,
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'Atleta '.$memberNumber,
            'data_nascimento' => $birthDate,
            'sexo' => 'masculino',
        ]);

        return $member->fresh(['dadosPessoais', 'userTypes']);
    }

    private function swimming(): SportsModality
    {
        return SportsModality::query()
            ->where('club_id', $this->clubId())
            ->where('code', 'swimming')
            ->firstOrFail();
    }

    private function ageGroup(string $code, string $name): AgeGroup
    {
        return AgeGroup::query()->create([
            'club_id' => $this->clubId(),
            'code' => $code,
            'nome' => $name,
            'ativo' => true,
        ]);
    }

    private function season(SportsModality $modality, string $start, string $end): Season
    {
        return Season::query()->create([
            'nome' => 'Época F3 '.$modality->code,
            'ano_temporada' => '2026/2027',
            'data_inicio' => $start,
            'data_fim' => $end,
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'club_id' => $this->clubId(),
            'sports_modality_id' => $modality->id,
            'status' => 'active',
        ]);
    }

    private function clubId(): string
    {
        return app(SportsClubContext::class)->id();
    }
}
