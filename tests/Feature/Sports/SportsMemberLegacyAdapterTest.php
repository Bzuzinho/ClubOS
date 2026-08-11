<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\AthleteSportsData;
use App\Models\DadosPessoais;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsMemberProfileService;
use App\Services\Desportivo\SportsMemberProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsMemberLegacyAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_member_save_cannot_revert_canonical_activity_or_pmb_from_stale_snapshot(): void
    {
        $member = $this->athlete('F3-STALE');
        $admin = User::factory()->admin()->create();
        $swimming = $this->swimming();

        app(SportsMemberProfileService::class)->updateFromMemberSurface($member, [
            'participations' => [[
                'sports_modality_id' => $swimming->id,
                'active' => true,
                'starts_at' => '2026-09-01',
            ]],
            'legacy_identifiers' => [
                'numero_pmb' => 'PMB-NEW',
            ],
        ], $admin);

        app(SportsMemberProvisioningService::class)->sync($member->fresh(), [
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
            'numero_pmb' => 'PMB-OLD',
        ]);

        $this->assertTrue(SportsAthleteParticipation::query()
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->exists());
        $this->assertSame(
            'PMB-NEW',
            AthleteSportsData::query()->where('user_id', $member->id)->value('numero_pmb')
        );
        $this->assertTrue((bool) $member->fresh()->ativo_desportivo);
    }

    public function test_ambiguous_legacy_activity_is_preserved_when_multiple_modalities_exist(): void
    {
        $member = $this->athlete('F3-AMBIGUOUS');
        $member->forceFill(['ativo_desportivo' => true])->saveQuietly();

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'ativo' => true,
            'numero_pmb' => 'PMB-LEGACY',
        ]);

        SportsModality::query()->create([
            'club_id' => $this->clubId(),
            'code' => 'triathlon-f3-ambiguous',
            'name' => 'Triatlo',
            'active' => true,
        ]);

        app(SportsMemberProvisioningService::class)->sync($member->fresh(), [
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
            'numero_pmb' => 'PMB-LEGACY',
        ]);

        $this->assertFalse(SportsAthleteParticipation::query()->where('user_id', $member->id)->exists());
        $this->assertTrue((bool) AthleteSportsData::query()->where('user_id', $member->id)->value('ativo'));
        $this->assertTrue((bool) $member->fresh()->ativo_desportivo);
        $this->assertSame(
            'PMB-LEGACY',
            AthleteSportsData::query()->where('user_id', $member->id)->value('numero_pmb')
        );
    }

    private function athlete(string $memberNumber): User
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
            'data_nascimento' => '2008-08-20',
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

    private function clubId(): string
    {
        return app(SportsClubContext::class)->id();
    }
}
