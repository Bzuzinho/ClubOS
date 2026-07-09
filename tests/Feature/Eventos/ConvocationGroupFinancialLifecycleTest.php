<?php

namespace Tests\Feature\Eventos;

use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Eventos\SyncConvocationGroupFinancialMovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvocationGroupFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_group_with_positive_cost_creates_canonical_movement(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $response = $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => (string) Str::uuid(),
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
            ]],
        ]);

        $response->assertOk();

        $group = ConvocationGroup::query()->firstOrFail();
        $this->assertNotNull($group->movimento_id);

        $movement = Movement::query()->findOrFail($group->movimento_id);
        $this->assertSame('convocation_group', $movement->origem_tipo);
        $this->assertSame($group->id, (string) $movement->origem_id);
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertGreaterThan(0, (float) $movement->valor_total);
    }

    public function test_cost_zero_or_less_does_not_create_movement(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => (string) Str::uuid(),
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 0,
                'valor_por_estafeta' => 0,
                'valor_inscricao_unitaria' => 0,
            ]],
        ])->assertOk();

        $group = ConvocationGroup::query()->firstOrFail();
        $this->assertNull($group->movimento_id);
        $this->assertSame(0, Movement::query()->count());
    }

    public function test_idempotent_sync_keeps_same_movement_id(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $groupId = (string) Str::uuid();
        $payload = [
            'scope' => 'global',
            'value' => [[
                'id' => $groupId,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
            ]],
        ];

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', $payload)->assertOk();
        $firstMovementId = (string) ConvocationGroup::query()->findOrFail($groupId)->movimento_id;

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', $payload)->assertOk();
        $secondMovementId = (string) ConvocationGroup::query()->findOrFail($groupId)->movimento_id;

        $this->assertSame($firstMovementId, $secondMovementId);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'convocation_group')->where('origem_id', $groupId)->count());
    }

    public function test_financial_update_before_settlement_reuses_same_movement_and_recalculates(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $group = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $movementId = (string) $group->movimento_id;
        $initialTotal = (float) Movement::query()->findOrFail($movementId)->valor_total;

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $group->id,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 5,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 20,
            ]],
        ])->assertOk();

        $movement = Movement::query()->findOrFail($movementId);
        $this->assertSame($movementId, (string) ConvocationGroup::query()->findOrFail($group->id)->movimento_id);
        $this->assertGreaterThan($initialTotal, (float) $movement->valor_total);
        $this->assertGreaterThan(0, $movement->items()->count());
    }

    public function test_athlete_changes_recalculate_movement(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();
        $group = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $movementId = (string) $group->movimento_id;

        ConvocationAthlete::query()->create([
            'convocatoria_grupo_id' => $group->id,
            'atleta_id' => $athlete->id,
            'provas' => [
                ['name' => '100L'],
                ['name' => '200L'],
            ],
            'estafetas' => 1,
            'presente' => false,
            'confirmado' => false,
        ]);

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-atleta', [
            'scope' => 'global',
            'value' => [[
                'convocatoria_grupo_id' => $group->id,
                'atleta_id' => $athlete->id,
                'provas' => [
                    ['name' => '100L'],
                    ['name' => '200L'],
                    ['name' => '400L'],
                ],
                'estafetas' => 2,
                'presente' => true,
                'confirmado' => true,
            ]],
        ])->assertOk();

        $movement = Movement::query()->findOrFail($movementId);
        $this->assertGreaterThan(0, (float) $movement->valor_total);
    }

    public function test_payload_movimento_id_is_ignored(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();
        $group = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $group->id,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
                'movimento_id' => (string) Str::uuid(),
            ]],
        ])->assertOk();

        $this->assertSame((string) $group->movimento_id, (string) ConvocationGroup::query()->findOrFail($group->id)->movimento_id);
    }

    public function test_administrative_update_after_settlement_is_allowed_when_financial_fields_unchanged(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();
        $group = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($group, ['payment_state' => 'parcial']);

        $response = $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $group->id,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 2,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
                'hora_encontro' => '08:15',
                'local_encontro' => 'Piscina A',
                'observacoes' => 'Apenas ajuste administrativo',
            ]],
        ]);

        $response->assertOk();
    }

    public function test_financial_update_is_blocked_after_partial_payment(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();
        $group = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($group, ['payment_state' => 'parcial']);

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $group->id,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 99,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
            ]],
        ])->assertStatus(422);
    }

    public function test_financial_update_is_blocked_for_paid_allocation_reconciled_and_fiscal_states(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $groupPaid = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($groupPaid, ['payment_state' => 'pago']);
        $this->assertFinancialUpdateBlocked($user, $event, $athlete->id, $groupPaid->id);

        $groupAllocation = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($groupAllocation, ['with_allocation' => true]);
        $this->assertFinancialUpdateBlocked($user, $event, $athlete->id, $groupAllocation->id);

        $groupReconciled = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($groupReconciled, ['reconciled' => true]);
        $this->assertFinancialUpdateBlocked($user, $event, $athlete->id, $groupReconciled->id);

        $groupFiscal = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($groupFiscal, ['with_fiscal' => true]);
        $this->assertFinancialUpdateBlocked($user, $event, $athlete->id, $groupFiscal->id);
    }

    public function test_delete_pending_group_is_allowed_and_settled_group_is_blocked(): void
    {
        [$user, $event, $athlete] = $this->seedBaseEntities();

        $deletable = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [],
        ])->assertOk();
        $this->assertDatabaseMissing('convocation_groups', ['id' => $deletable->id]);

        $blocked = $this->createGroupViaKv($user, $event, [$athlete->id], 10, 2, 1);
        $this->markGroupAsSettled($blocked, ['payment_state' => 'parcial']);

        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [],
        ])->assertStatus(422);

        $this->assertDatabaseHas('convocation_groups', ['id' => $blocked->id]);
    }

    private function createGroupViaKv(User $user, Event $event, array $athleteIds, float $base, float $perJump, float $perRelay): ConvocationGroup
    {
        $group = ConvocationGroup::query()->create([
            'id' => (string) Str::uuid(),
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $user->id,
            'atletas_ids' => $athleteIds,
            'tipo_custo' => 'por_salto',
            'valor_por_salto' => $perJump,
            'valor_por_estafeta' => $perRelay,
            'valor_inscricao_unitaria' => $base,
        ]);

        app(SyncConvocationGroupFinancialMovementAction::class)->execute($group);

        return $group->fresh();
    }

    /**
     * @return array{0:User,1:Event,2:User}
     */
    private function seedBaseEntities(): array
    {
        $user = User::factory()->create();
        $athlete = User::factory()->create();

        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'titulo' => 'Evento Convocatoria',
            'descricao' => 'Teste XFIN6',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'prova',
            'visibilidade' => 'publico',
            'transporte_necessario' => false,
            'estado' => 'rascunho',
            'criado_por' => $user->id,
            'recorrente' => false,
            'taxa_inscricao' => 5,
            'custo_inscricao_por_prova' => 1,
            'custo_inscricao_por_salto' => 1,
            'custo_inscricao_estafeta' => 1,
        ]);

        return [$user, $event, $athlete];
    }

    /**
     * @param array{payment_state?:string,with_allocation?:bool,reconciled?:bool,with_fiscal?:bool} $options
     */
    private function markGroupAsSettled(ConvocationGroup $group, array $options = []): void
    {
        $group = $group->fresh();
        $movement = Movement::query()->findOrFail($group->movimento_id);

        if (!empty($options['payment_state'])) {
            $movement->estado_pagamento = $options['payment_state'];
            $movement->save();
        }

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Convocatoria',
            'descricao' => 'Entry Convocatoria',
            'valor' => (float) $movement->valor_total,
            'valor_pago' => ($options['payment_state'] ?? null) === 'pago' ? (float) $movement->valor_total : 0,
            'valor_em_aberto' => ($options['payment_state'] ?? null) === 'pago' ? 0 : (float) $movement->valor_total,
            'estado' => ($options['payment_state'] ?? null) === 'pago' ? 'pago' : 'parcial',
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);

        if (!empty($options['with_allocation'])) {
            $payment = Payment::query()->create([
                'amount' => (float) $movement->valor_total,
                'allocated_amount' => (float) $movement->valor_total,
                'unallocated_amount' => 0,
                'payment_date' => now()->toDateString(),
                'status' => Payment::STATUS_CONFIRMED,
                'source' => Payment::SOURCE_MANUAL,
            ]);

            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'financial_entry_id' => $entry->id,
                'amount' => (float) $movement->valor_total,
                'status' => PaymentAllocation::STATUS_CONFIRMED,
                'allocated_at' => now(),
            ]);
        }

        if (!empty($options['reconciled'])) {
            $movement->estado_conciliacao = 'conciliado';
            $movement->save();

            $statement = \App\Models\BankStatement::query()->create([
                'data_movimento' => now()->toDateString(),
                'descricao' => 'Conciliacao Convocatoria',
                'valor' => (float) $movement->valor_total,
                'referencia' => 'CONV-REC',
                'conciliado' => true,
            ]);

            MapaConciliacao::query()->create([
                'extrato_id' => $statement->id,
                'lancamento_id' => $entry->id,
                'movimento_id' => $movement->id,
                'valor_conciliado' => (float) $movement->valor_total,
                'status' => 'confirmado',
            ]);
        }

        if (!empty($options['with_fiscal'])) {
            FiscalDocumentRequest::query()->create([
                'financial_entry_id' => $entry->id,
                'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
                'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE_RECEIPT,
                'status' => FiscalDocumentRequest::STATUS_ISSUED,
                'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
                'amount' => (float) $movement->valor_total,
            ]);
        }
    }

    private function assertFinancialUpdateBlocked(User $user, Event $event, string $athleteId, string $groupId): void
    {
        $this->actingAs($user)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $groupId,
                'evento_id' => $event->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $user->id,
                'atletas_ids' => [$athleteId],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 77,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 10,
            ]],
        ])->assertStatus(422);
    }
}
