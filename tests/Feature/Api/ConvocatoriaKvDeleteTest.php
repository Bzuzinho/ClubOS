<?php

namespace Tests\Feature\Api;

use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ConvocatoriaKvDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_convocation_kv_mutations_are_retired_without_deleting_existing_data(): void
    {
        $admin = User::factory()->admin()->create();
        $event = Event::query()->create([
            'titulo' => 'Convocatória canónica',
            'descricao' => '',
            'data_inicio' => now()->addWeek()->toDateString(),
            'tipo' => 'prova',
            'estado' => 'agendado',
            'criado_por' => $admin->id,
        ]);
        $group = ConvocationGroup::query()->create([
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $admin->id,
            'atletas_ids' => [],
            'tipo_custo' => 'sem_custo',
        ]);

        foreach ([
            'club-convocatorias',
            'club-convocatorias-grupo',
            'club-convocatorias-atleta',
            'movimentos-convocatoria',
        ] as $key) {
            $this->actingAs($admin)
                ->putJson("/api/kv/{$key}", ['value' => []])
                ->assertStatus(410);

            $this->actingAs($admin)
                ->deleteJson("/api/kv/{$key}")
                ->assertStatus(410);
        }

        $this->assertDatabaseHas('convocation_groups', ['id' => $group->id]);
    }

    public function test_desportivo_convocation_workspace_remains_the_canonical_write_surface(): void
    {
        foreach ([
            'desportivo.convocatorias.index',
            'desportivo.convocatorias.show',
            'desportivo.convocatorias.store',
            'desportivo.convocatorias.update',
            'desportivo.convocatorias.publish',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name));
        }
    }
}
