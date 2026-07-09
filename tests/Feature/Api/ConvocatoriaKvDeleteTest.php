<?php

namespace Tests\Feature\Api;

use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvocatoriaKvDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_kv_sync_deletes_removed_convocation_group(): void
    {
        $user = User::factory()->create();
        $athlete = User::factory()->create();

        $event = Event::create([
            'id' => (string) Str::uuid(),
            'titulo' => 'Evento Teste KV',
            'descricao' => 'Teste integração KV convocatória',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'transporte_necessario' => false,
            'estado' => 'rascunho',
            'criado_por' => $user->id,
            'recorrente' => false,
        ]);

        $groupId = (string) Str::uuid();

        $createPayload = [
            'value' => [[
                'id' => $groupId,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_inscricao_unitaria' => 10,
            ]],
            'scope' => 'global',
        ];

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', $createPayload)
            ->assertOk();

        $this->assertDatabaseHas('convocation_groups', [
            'id' => $groupId,
            'evento_id' => $event->id,
        ]);

        $deleteBySyncPayload = [
            'value' => [],
            'scope' => 'global',
        ];

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', $deleteBySyncPayload)
            ->assertOk();

        $this->assertDatabaseMissing('convocation_groups', [
            'id' => $groupId,
        ]);
    }

    public function test_kv_sync_deletes_only_removed_group_when_multiple_exist(): void
    {
        $user = User::factory()->create();
        $athlete = User::factory()->create();

        $event = Event::create([
            'id' => (string) Str::uuid(),
            'titulo' => 'Evento Teste KV 2',
            'descricao' => 'Teste integração KV convocatória múltipla',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'transporte_necessario' => false,
            'estado' => 'rascunho',
            'criado_por' => $user->id,
            'recorrente' => false,
        ]);

        $keepId = (string) Str::uuid();
        $removeId = (string) Str::uuid();

        $initialPayload = [
            'value' => [
                [
                    'id' => $keepId,
                    'evento_id' => $event->id,
                    'data_criacao' => now()->toISOString(),
                    'criado_por' => $user->id,
                    'atletas_ids' => [$athlete->id],
                    'tipo_custo' => 'por_salto',
                    'valor_por_salto' => 2,
                    'valor_inscricao_unitaria' => 10,
                ],
                [
                    'id' => $removeId,
                    'evento_id' => $event->id,
                    'data_criacao' => now()->toISOString(),
                    'criado_por' => $user->id,
                    'atletas_ids' => [$athlete->id],
                    'tipo_custo' => 'por_salto',
                    'valor_por_salto' => 2,
                    'valor_inscricao_unitaria' => 10,
                ],
            ],
            'scope' => 'global',
        ];

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', $initialPayload)
            ->assertOk();

        $filteredPayload = [
            'value' => [[
                'id' => $keepId,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_inscricao_unitaria' => 10,
            ]],
            'scope' => 'global',
        ];

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', $filteredPayload)
            ->assertOk();

        $this->assertDatabaseHas('convocation_groups', [
            'id' => $keepId,
        ]);

        $this->assertDatabaseMissing('convocation_groups', [
            'id' => $removeId,
        ]);
    }

    public function test_kv_sync_blocks_deleting_financially_protected_group(): void
    {
        $user = User::factory()->create();
        $athlete = User::factory()->create();

        $event = Event::create([
            'id' => (string) Str::uuid(),
            'titulo' => 'Evento Protegido',
            'descricao' => 'Teste proteção delete',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'transporte_necessario' => false,
            'estado' => 'rascunho',
            'criado_por' => $user->id,
            'recorrente' => false,
        ]);

        $groupId = (string) Str::uuid();
        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'value' => [[
                'id' => $groupId,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_inscricao_unitaria' => 10,
            ]],
            'scope' => 'global',
        ])->assertOk();

        $group = ConvocationGroup::query()->findOrFail($groupId);
        $movement = Movement::query()->findOrFail($group->movimento_id);
        $movement->update(['estado_pagamento' => 'parcial']);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Convocatoria',
            'descricao' => 'Entry protegida',
            'valor' => (float) $movement->valor_total,
            'valor_pago' => 1,
            'valor_em_aberto' => max((float) $movement->valor_total - 1, 0),
            'estado' => 'parcial',
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'value' => [],
            'scope' => 'global',
        ])->assertStatus(422);

        $this->assertDatabaseHas('convocation_groups', ['id' => $groupId]);
    }
}
