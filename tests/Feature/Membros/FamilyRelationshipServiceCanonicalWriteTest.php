<?php

namespace Tests\Feature\Membros;

use App\Models\Familia;
use App\Models\User;
use App\Services\Family\FamilyRelationshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FamilyRelationshipServiceCanonicalWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_link_without_existing_family_does_not_invent_family_aggregate(): void
    {
        $member = User::factory()->create([
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'educandos' => [],
        ]);

        $this->service()->associateGuardian($member, $guardian);

        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertContains($guardian->id, $member->refresh()->encarregado_educacao);
        $this->assertContains($member->id, $guardian->refresh()->educandos);
        $this->assertDatabaseCount('familias', 0);
        $this->assertDatabaseCount('familia_user', 0);
    }

    public function test_guardian_link_is_projected_into_single_unambiguous_existing_family(): void
    {
        $member = User::factory()->create([
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'educandos' => [],
        ]);
        $family = $this->createFamilyWithMember($member, 'familiar');

        $this->service()->associateGuardian($member, $guardian);

        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $member->id,
            'papel_na_familia' => 'educando',
        ]);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $guardian->id,
            'papel_na_familia' => 'encarregado_educacao',
            'pode_editar' => true,
        ]);
        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
    }

    public function test_removing_guardianship_preserves_family_membership_but_removes_guardian_role(): void
    {
        $member = User::factory()->create([
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'educandos' => [],
        ]);
        $family = $this->createFamilyWithMember($member, 'familiar');

        $this->service()->associateGuardian($member, $guardian);
        $this->service()->removeGuardian($member, $guardian);

        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertNotContains($guardian->id, $member->refresh()->encarregado_educacao);
        $this->assertNotContains($member->id, $guardian->refresh()->educandos);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $guardian->id,
            'papel_na_familia' => 'familiar',
        ]);
    }

    public function test_guardian_link_does_not_guess_between_multiple_existing_families(): void
    {
        $member = User::factory()->create([
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'educandos' => [],
        ]);
        $firstFamily = $this->createFamilyWithMember($member, 'familiar', 'Família A');
        $secondFamily = $this->createFamilyWithMember($member, 'familiar', 'Família B');

        $this->service()->associateGuardian($member, $guardian);

        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertDatabaseMissing('familia_user', [
            'familia_id' => $firstFamily->id,
            'user_id' => $guardian->id,
        ]);
        $this->assertDatabaseMissing('familia_user', [
            'familia_id' => $secondFamily->id,
            'user_id' => $guardian->id,
        ]);
    }

    private function service(): FamilyRelationshipService
    {
        return app(FamilyRelationshipService::class);
    }

    private function createFamilyWithMember(
        User $member,
        string $role,
        string $name = 'Família Canónica',
    ): Familia {
        $family = Familia::query()->create([
            'nome' => $name,
            'responsavel_user_id' => null,
            'ativo' => true,
        ]);

        $family->members()->attach($member->id, [
            'id' => (string) Str::uuid(),
            'papel_na_familia' => $role,
            'pode_editar' => false,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        return $family;
    }
}
