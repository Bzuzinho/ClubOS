<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\User;
use App\Services\Desportivo\SportsMemberStatusResolver;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use App\Services\Members\MemberDataReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsMemberCanonicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_creation_creates_automatic_canonical_sports_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $ageGroup = $this->createAgeGroup('Juvenis', 15, 17);
        $birthDate = now()->subYears(16)->subMonth()->toDateString();

        $this->actingAs($admin)
            ->post(route('membros.store'), $this->memberPayload([
                'numero_socio' => 'SPORT-001',
                'data_nascimento' => $birthDate,
                'ativo_desportivo' => true,
                'escalao_manual_override' => false,
                'escalao' => [],
            ]))
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'SPORT-001')->firstOrFail();
        $profile = AthleteSportsData::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertTrue((bool) $profile->ativo);
        $this->assertFalse((bool) $profile->escalao_manual_override);
        $this->assertSame((string) $ageGroup->id, (string) $profile->escalao_id);
        $this->assertSame((string) $ageGroup->id, (string) $profile->escalao_calculado_id);

        $member->refresh();
        $this->assertTrue((bool) $member->ativo_desportivo);
        $this->assertSame([(string) $ageGroup->id], array_values($member->escalao ?? []));
    }

    public function test_manual_age_group_override_preserves_calculated_group_for_comparison(): void
    {
        $admin = User::factory()->admin()->create();
        $automatic = $this->createAgeGroup('Juvenis', 15, 17);
        $override = $this->createAgeGroup('Juniores', 18, 20);

        $this->actingAs($admin)
            ->post(route('membros.store'), $this->memberPayload([
                'numero_socio' => 'SPORT-002',
                'data_nascimento' => now()->subYears(16)->subMonth()->toDateString(),
                'ativo_desportivo' => true,
                'escalao_manual_override' => true,
                'escalao_id' => $override->id,
                'escalao' => [(string) $override->id],
            ]))
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'SPORT-002')->firstOrFail();
        $profile = AthleteSportsData::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertTrue((bool) $profile->escalao_manual_override);
        $this->assertSame((string) $override->id, (string) $profile->escalao_id);
        $this->assertSame((string) $automatic->id, (string) $profile->escalao_calculado_id);
    }

    public function test_switching_back_to_automatic_recalculates_after_birth_date_change(): void
    {
        $admin = User::factory()->admin()->create();
        $juvenis = $this->createAgeGroup('Juvenis', 15, 17);
        $juniores = $this->createAgeGroup('Juniores', 18, 20);

        $this->actingAs($admin)
            ->post(route('membros.store'), $this->memberPayload([
                'numero_socio' => 'SPORT-003',
                'data_nascimento' => now()->subYears(16)->subMonth()->toDateString(),
                'ativo_desportivo' => true,
                'escalao_manual_override' => true,
                'escalao' => [(string) $juniores->id],
            ]))
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'SPORT-003')->firstOrFail();
        $this->assertSame((string) $juniores->id, (string) $member->athleteSportsData()->value('escalao_id'));

        $this->actingAs($admin)
            ->put(route('membros.update', $member), $this->memberPayload([
                'nome_completo' => $member->name,
                'email_utilizador' => $member->email_utilizador,
                'numero_socio' => 'SPORT-003',
                'data_nascimento' => now()->subYears(19)->subMonth()->toDateString(),
                'ativo_desportivo' => true,
                'escalao_manual_override' => false,
                'escalao' => [],
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $profile = $member->athleteSportsData()->firstOrFail();
        $this->assertFalse((bool) $profile->escalao_manual_override);
        $this->assertSame((string) $juniores->id, (string) $profile->escalao_id);
        $this->assertSame((string) $juniores->id, (string) $profile->escalao_calculado_id);
        $this->assertNotSame((string) $juvenis->id, (string) $profile->escalao_id);
    }

    public function test_inactive_member_can_keep_sports_activity_but_is_not_an_active_athlete(): void
    {
        $member = User::factory()->create([
            'estado' => 'inativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'ativo' => true,
        ]);

        $resolver = app(SportsMemberStatusResolver::class);
        $member = $member->fresh(['athleteSportsData', 'userTypes']);

        $this->assertTrue($resolver->sportsActivityActive($member));
        $this->assertFalse($resolver->isActiveAthlete($member));
    }

    public function test_removing_athlete_type_deactivates_profile_without_deleting_history(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('membros.store'), $this->memberPayload([
                'numero_socio' => 'SPORT-004',
                'ativo_desportivo' => true,
                'num_federacao' => 'FED-HISTORY',
            ]))
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'SPORT-004')->firstOrFail();
        $profileId = (string) $member->athleteSportsData()->value('id');

        $this->actingAs($admin)
            ->put(route('membros.update', $member), $this->memberPayload([
                'nome_completo' => $member->name,
                'email_utilizador' => $member->email_utilizador,
                'numero_socio' => 'SPORT-004',
                'tipo_membro' => ['socio'],
                'ativo_desportivo' => false,
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $profile = AthleteSportsData::query()->findOrFail($profileId);
        $this->assertFalse((bool) $profile->ativo);
        $this->assertSame('FED-HISTORY', $profile->num_federacao);
    }

    public function test_member_read_service_prefers_canonical_sports_profile(): void
    {
        $canonicalAgeGroup = $this->createAgeGroup('Canónico', 15, 17);
        $legacyAgeGroup = $this->createAgeGroup('Legacy', 18, 20);
        $member = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
            'escalao' => [(string) $legacyAgeGroup->id],
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'escalao_id' => $canonicalAgeGroup->id,
            'escalao_calculado_id' => $canonicalAgeGroup->id,
            'escalao_manual_override' => false,
            'ativo' => true,
        ]);

        $sports = app(MemberDataReadService::class)->sportsPayload($member->fresh(['athleteSportsData']));

        $this->assertTrue((bool) $sports['ativo']);
        $this->assertSame((string) $canonicalAgeGroup->id, (string) $sports['escalao_id']);
        $this->assertFalse((bool) $sports['escalao_manual_override']);
    }

    public function test_finance_monthly_fee_eligibility_uses_canonical_sports_activity(): void
    {
        $member = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'ativo' => true,
        ]);

        $service = app(MemberMonthlyFeeEligibilityService::class);
        $this->assertTrue($service->shouldHaveMonthlyFee($member->fresh(['athleteSportsData', 'userTypes'])));

        $member->athleteSportsData()->update(['ativo' => false]);
        $member->forceFill(['ativo_desportivo' => true])->saveQuietly();

        $this->assertFalse($service->shouldHaveMonthlyFee($member->fresh(['athleteSportsData', 'userTypes'])));
    }

    private function createAgeGroup(string $name, int $min, int $max): AgeGroup
    {
        return AgeGroup::query()->create([
            'nome' => $name,
            'idade_minima' => $min,
            'idade_maxima' => $max,
            'ativo' => true,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => 'Atleta Canónico',
            'email_utilizador' => null,
            'numero_socio' => 'SPORT-DEFAULT',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ], $overrides);
    }
}
