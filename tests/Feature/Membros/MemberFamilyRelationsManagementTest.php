<?php

namespace Tests\Feature\Membros;

use App\Models\Familia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberFamilyRelationsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_flow_adds_and_removes_guardian_with_reciprocal_legacy_state(): void
    {
        $this->withoutMiddleware();

        $member = User::factory()->athlete()->create([
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'tipo_membro' => ['encarregado_educacao'],
            'educandos' => [],
        ]);

        $response = $this->from(route('membros.show', $member))
            ->post(route('membros.familia.encarregados.store', $member), [
                'guardian_id' => $guardian->id,
            ]);

        $response->assertRedirect(route('membros.show', $member));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertContains($guardian->id, $member->refresh()->encarregado_educacao);
        $this->assertContains($member->id, $guardian->refresh()->educandos);

        $deleteResponse = $this->from(route('membros.show', $member))
            ->delete(route('membros.familia.encarregados.destroy', [$member, $guardian]));

        $deleteResponse->assertRedirect(route('membros.show', $member));
        $deleteResponse->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertNotContains($guardian->id, $member->refresh()->encarregado_educacao);
        $this->assertNotContains($member->id, $guardian->refresh()->educandos);
    }

    public function test_admin_flow_creates_family_from_existing_relations_and_manages_members(): void
    {
        $this->withoutMiddleware();

        $member = User::factory()->athlete()->create([
            'nome_completo' => 'Atleta Família',
            'encarregado_educacao' => [],
        ]);
        $guardian = User::factory()->create([
            'nome_completo' => 'Responsável Família',
            'tipo_membro' => ['encarregado_educacao'],
            'educandos' => [],
        ]);
        $relative = User::factory()->create([
            'nome_completo' => 'Familiar Adicionado',
        ]);

        $this->from(route('membros.show', $member))
            ->post(route('membros.familia.encarregados.store', $member), [
                'guardian_id' => $guardian->id,
            ])
            ->assertSessionHasNoErrors();

        $response = $this->from(route('membros.show', $member))
            ->post(route('membros.familia.membros.store', $member), [
                'member_id' => $relative->id,
                'papel_na_familia' => 'familiar',
            ]);

        $response->assertRedirect(route('membros.show', $member));
        $response->assertSessionHasNoErrors();

        $family = Familia::query()->firstOrFail();
        $this->assertSame($guardian->id, $family->responsavel_user_id);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $guardian->id,
            'papel_na_familia' => 'responsavel',
        ]);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $member->id,
            'papel_na_familia' => 'educando',
        ]);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $relative->id,
            'papel_na_familia' => 'familiar',
        ]);

        $updateResponse = $this->from(route('membros.show', $member))
            ->patch(route('membros.familia.membros.update', [$member, $family, $relative]), [
                'papel_na_familia' => 'encarregado_educacao',
            ]);

        $updateResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $relative->id,
            'papel_na_familia' => 'encarregado_educacao',
            'pode_editar' => true,
        ]);

        $deleteResponse = $this->from(route('membros.show', $member))
            ->delete(route('membros.familia.membros.destroy', [$member, $family, $relative]));

        $deleteResponse->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $relative->id,
        ]);
        $this->assertDatabaseHas('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_member_outside_the_displayed_members_family_cannot_be_mutated(): void
    {
        $this->withoutMiddleware();

        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $target = User::factory()->create();
        $family = Familia::query()->create([
            'nome' => 'Família Externa',
            'responsavel_user_id' => $otherMember->id,
            'ativo' => true,
        ]);
        DB::table('familia_user')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'familia_id' => $family->id,
            'user_id' => $otherMember->id,
            'papel_na_familia' => 'responsavel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post(route('membros.familia.membros.store', $member), [
            'family_id' => $family->id,
            'member_id' => $target->id,
            'papel_na_familia' => 'familiar',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('familia_user', [
            'familia_id' => $family->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_family_management_controls_live_only_in_family_tab(): void
    {
        $personalSource = file_get_contents(resource_path('js/Components/Members/Tabs/PersonalTab.tsx'));
        $familySource = file_get_contents(resource_path('js/Components/Members/Tabs/FamilyTab.tsx'));

        $this->assertStringNotContainsString('Selecionar Encarregado de Educação', $personalSource);
        $this->assertStringNotContainsString('Relações Familiares', $personalSource);
        $this->assertStringContainsString('Encarregados de educação', $familySource);
        $this->assertStringContainsString('membros.familia.encarregados.store', $familySource);
        $this->assertStringContainsString('membros.familia.membros.update', $familySource);
        $this->assertStringContainsString('Editar ficha', $familySource);
        $this->assertStringContainsString('Remover', $familySource);
    }
}
