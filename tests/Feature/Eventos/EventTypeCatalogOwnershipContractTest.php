<?php

namespace Tests\Feature\Eventos;

use App\Models\EventType;
use App\Models\User;
use App\Services\KeyValue\EventosKeyValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EventTypeCatalogOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_type_kv_catalog_is_retired_and_canonical_settings_crud_remains(): void
    {
        $admin = User::factory()->admin()->create();

        EventType::query()->create([
            'nome' => 'Prova Oficial',
            'descricao' => 'Tipo canónico',
            'cor' => '#3b82f6',
            'icon' => 'flag',
            'ativo' => true,
            'gera_taxa' => false,
            'permite_convocatoria' => true,
            'gera_presencas' => true,
            'requer_transporte' => false,
            'visibilidade_default' => 'restrito',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/kv/club-eventos-tipos')
            ->assertStatus(410);

        $this->actingAs($admin)
            ->putJson('/api/kv/club-eventos-tipos', ['value' => []])
            ->assertStatus(410);

        $this->actingAs($admin)
            ->deleteJson('/api/kv/club-eventos-tipos')
            ->assertStatus(410);

        $this->assertDatabaseHas('event_types', [
            'nome' => 'Prova Oficial',
            'ativo' => true,
        ]);

        $this->assertTrue(Route::has('configuracoes.tipos-evento.store'));
        $this->assertTrue(Route::has('configuracoes.tipos-evento.update'));
        $this->assertTrue(Route::has('configuracoes.tipos-evento.destroy'));

        $this->assertFalse(app(EventosKeyValueService::class)->supports('club-eventos-tipos'));
    }

    public function test_runtime_frontend_has_no_event_type_kv_consumers(): void
    {
        $findings = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            $contents = File::get($file->getPathname());

            if (str_contains($contents, 'club-eventos-tipos')) {
                $findings[] = str_replace(resource_path('js').DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $findings);

        $settingsPage = File::get(resource_path('js/Pages/Configuracoes/Index.tsx'));
        $this->assertStringContainsString("route('configuracoes.tipos-evento.store')", $settingsPage);
        $this->assertStringContainsString("route('configuracoes.tipos-evento.update'", $settingsPage);
        $this->assertStringContainsString("route('configuracoes.tipos-evento.destroy'", $settingsPage);
    }
}
