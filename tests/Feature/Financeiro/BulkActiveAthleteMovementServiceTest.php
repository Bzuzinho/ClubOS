<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\Movement;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\BulkActiveAthleteMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BulkActiveAthleteMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_one_open_receivable_per_active_athlete_and_skips_non_eligible_users(): void
    {
        $activeAthleteA = $this->typedUser('atleta', 'Atleta A', 'ativo', true);
        $activeAthleteB = $this->typedUser('atleta', 'Atleta B', 'ativo', true);
        $this->typedUser('atleta', 'Atleta inativo', 'inativo', true);
        $this->typedUser('atleta', 'Atleta desportivo inativo', 'ativo', false);
        $this->typedUser('treinador', 'Treinador', 'ativo', true);

        $costCenter = CostCenter::query()->create([
            'nome' => 'Desportivo',
            'codigo' => 'DESP',
            'ativo' => true,
        ]);

        $result = app(BulkActiveAthleteMovementService::class)->create([
            'classificacao' => 'receita',
            'categoria' => 'Inscrição',
            'data_emissao' => '2026-10-01',
            'data_vencimento' => '2026-10-15',
            'valor_total' => 25,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'outro',
            'observacoes' => 'Inscrição época 2026/27',
            'items' => [[
                'descricao' => 'Inscrição época 2026/27',
                'quantidade' => 1,
                'valor_unitario' => 25,
                'imposto_percentual' => 0,
                'total_linha' => 25,
                'centro_custo_id' => $costCenter->id,
            ]],
        ], 'batch-test-2026');

        $this->assertSame(2, $result['eligible']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $this->assertDatabaseHas('movements', [
            'user_id' => $activeAthleteA->id,
            'classificacao' => 'receita',
            'valor_total' => 25,
            'estado_pagamento' => 'pendente',
            'origem_tipo' => 'manual',
        ]);
        $this->assertDatabaseHas('movements', [
            'user_id' => $activeAthleteB->id,
            'classificacao' => 'receita',
            'valor_total' => 25,
            'estado_pagamento' => 'pendente',
            'origem_tipo' => 'manual',
        ]);
        $this->assertDatabaseCount('movements', 2);
        $this->assertDatabaseCount('movement_items', 2);
    }

    public function test_repeating_same_batch_is_idempotent(): void
    {
        $this->typedUser('atleta', 'Atleta A', 'ativo', true);

        $costCenter = CostCenter::query()->create([
            'nome' => 'Desportivo',
            'codigo' => 'DESP',
            'ativo' => true,
        ]);

        $payload = [
            'classificacao' => 'receita',
            'data_emissao' => '2026-10-01',
            'data_vencimento' => '2026-10-15',
            'valor_total' => 20,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'outro',
            'items' => [[
                'descricao' => 'Taxa anual',
                'quantidade' => 1,
                'valor_unitario' => 20,
                'imposto_percentual' => 0,
                'total_linha' => 20,
            ]],
        ];

        $service = app(BulkActiveAthleteMovementService::class);

        $first = $service->create($payload, 'same-batch');
        $second = $service->create($payload, 'same-batch');

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, Movement::query()->count());
    }

    private function typedUser(string $codigo, string $nome, string $estado, bool $ativoDesportivo): User
    {
        $type = UserType::query()->firstOrCreate(
            ['codigo' => $codigo],
            [
                'nome' => ucfirst(str_replace('_', ' ', $codigo)),
                'descricao' => $codigo,
                'ativo' => true,
            ],
        );

        $user = User::factory()->create([
            'name' => $nome,
            'estado' => $estado,
            'tipo_membro' => [],
            'ativo_desportivo' => $ativoDesportivo,
        ]);

        $user->userTypes()->sync([$type->id]);

        return $user->fresh(['userTypes']);
    }
}
