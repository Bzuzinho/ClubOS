<?php

declare(strict_types=1);

namespace Tests\Feature\Patrocinios;

use App\Http\Controllers\PatrocinosController;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SponsorOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sponsor_entity_crud_is_owned_by_patronage_module(): void
    {
        foreach (['store', 'update', 'destroy'] as $action) {
            $this->assertFalse(Route::has("configuracoes.patrocinadores.{$action}"));
            $this->assertTrue(Route::has("patrocinios.patrocinadores.{$action}"));
        }

        $store = Route::getRoutes()->getByName('patrocinios.patrocinadores.store');

        $this->assertNotNull($store);
        $this->assertSame('patrocinios/patrocinadores', $store->uri());
        $this->assertSame(PatrocinosController::class.'@storeSponsor', $store->getActionName());
        $this->assertContains('module.access:patrocinios', $store->gatherMiddleware());

        $settingsRoutes = File::get(base_path('routes/web_settings.php'));
        $sponsorshipRoutes = File::get(base_path('routes/web_sponsorships.php'));
        $settingsPage = File::get(resource_path('js/Pages/Configuracoes/Index.tsx'));

        $this->assertStringNotContainsString('configuracoes.patrocinadores', $settingsRoutes);
        $this->assertStringNotContainsString('logistica-patrocinadores', $settingsPage);
        $this->assertStringContainsString("patrocinios.patrocinadores.store", $sponsorshipRoutes);
        $this->assertStringContainsString('SponsorDirectoryTab', File::get(resource_path('js/Pages/Patrocinios/Index.tsx')));
    }

    public function test_sponsor_directory_crud_keeps_the_same_canonical_sponsor_records(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('patrocinios.patrocinadores.store'), [
            'nome' => 'Parceiro P2.7',
            'descricao' => 'Entidade criada no owner funcional correto',
            'website' => 'https://example.test',
            'contacto' => '912345678',
            'email' => 'sponsor@example.test',
            'tipo' => 'principal',
            'valor_anual' => 2500,
            'data_inicio' => '2026-09-24',
            'data_fim' => '2027-09-24',
            'estado' => 'ativo',
        ])->assertRedirect(route('patrocinios.index', ['tab' => 'patrocinadores']));

        $sponsor = Sponsor::query()->where('nome', 'Parceiro P2.7')->firstOrFail();

        $this->actingAs($admin)->put(route('patrocinios.patrocinadores.update', $sponsor), [
            'nome' => 'Parceiro P2.7 Atualizado',
            'descricao' => 'Mantém a mesma entidade canónica',
            'website' => 'https://example.test',
            'contacto' => '912345678',
            'email' => 'sponsor@example.test',
            'tipo' => 'secundario',
            'valor_anual' => 3000,
            'data_inicio' => '2026-09-24',
            'data_fim' => '2027-09-24',
            'estado' => 'ativo',
        ])->assertRedirect(route('patrocinios.index', ['tab' => 'patrocinadores']));

        $this->assertDatabaseHas('sponsors', [
            'id' => $sponsor->id,
            'nome' => 'Parceiro P2.7 Atualizado',
            'tipo' => 'secundario',
        ]);

        $this->actingAs($admin)
            ->delete(route('patrocinios.patrocinadores.destroy', $sponsor))
            ->assertRedirect(route('patrocinios.index', ['tab' => 'patrocinadores']));

        $this->assertDatabaseMissing('sponsors', ['id' => $sponsor->id]);
    }
}
