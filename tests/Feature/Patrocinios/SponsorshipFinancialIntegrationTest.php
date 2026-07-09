<?php

namespace Tests\Feature\Patrocinios;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\User;
use App\Services\Logistica\RegisterStockMovementAction;
use App\Services\Patrocinios\SponsorshipFinancialGuardService;
use App\Services\Patrocinios\SponsorshipIntegrationService;
use App\Services\Patrocinios\SponsorshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SponsorshipFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_item_generates_canonical_movement(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();

        $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

        $this->assertSame('sponsorship_money_item', $movement->origem_tipo);
        $this->assertSame((string) $moneyItem->id, (string) $movement->origem_id);
        $this->assertSame('receita', $movement->classificacao);
        $this->assertSame('1200.50', (string) $movement->valor_total);
        $this->assertNotNull($moneyItem->financial_movement_id);
        $this->assertSame('generated', $moneyItem->integration_status);

        $this->assertSame(1, Movement::query()
            ->where('origem_tipo', 'sponsorship_money_item')
            ->where('origem_id', $moneyItem->id)
            ->count());

        $this->assertSame(1, Movement::query()->whereKey($movement->id)->count());
        $this->assertSame(1, SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('integration_type', 'financial')
            ->where('source_type', 'money_item')
            ->where('source_id', $moneyItem->id)
            ->where('status', 'generated')
            ->count());

        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => $moneyItem->description,
            'valor_unitario' => 1200.50,
            'total_linha' => 1200.50,
        ]);
    }

    public function test_retry_is_idempotent(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
        $firstMovementId = (string) $moneyItem->fresh()->financial_movement_id;
        $firstIntegrationId = (string) SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItem->id)
            ->value('id');

        app(SponsorshipIntegrationService::class)->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']));

        $moneyItem->refresh();
        $secondMovementId = (string) $moneyItem->financial_movement_id;
        $secondIntegrationId = (string) SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItem->id)
            ->value('id');

        $this->assertSame($firstMovementId, $secondMovementId);
        $this->assertSame($firstIntegrationId, $secondIntegrationId);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->where('origem_id', $moneyItem->id)->count());
        $this->assertSame(1, Movement::query()->findOrFail($firstMovementId)->items()->count());
    }

    public function test_retry_repairs_pending_integration_with_existing_canonical_movement(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
        $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

        $moneyItem->forceFill([
            'financial_movement_id' => null,
            'integration_status' => 'pending',
            'integration_message' => null,
        ])->save();

        SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItem->id)
            ->update([
                'status' => 'pending',
                'target_record_id' => $movement->id,
            ]);

        app(SponsorshipIntegrationService::class)->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']));

        $moneyItem->refresh();

        $this->assertSame($movement->id, $moneyItem->financial_movement_id);
        $this->assertSame('generated', $moneyItem->integration_status);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->where('origem_id', $moneyItem->id)->count());
        $this->assertSame('generated', SponsorshipIntegration::query()->where('sponsorship_id', $sponsorship->id)->where('source_id', $moneyItem->id)->value('status'));
    }

    public function test_retry_repairs_failed_integration_without_duplication(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
        $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

        $moneyItem->forceFill([
            'financial_movement_id' => null,
            'integration_status' => 'failed',
            'integration_message' => 'manual failure',
        ])->save();

        SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItem->id)
            ->update([
                'status' => 'failed',
                'target_record_id' => $movement->id,
            ]);

        app(SponsorshipIntegrationService::class)->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']));

        $moneyItem->refresh();

        $this->assertSame($movement->id, $moneyItem->financial_movement_id);
        $this->assertSame('generated', $moneyItem->integration_status);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->where('origem_id', $moneyItem->id)->count());
        $this->assertSame('generated', SponsorshipIntegration::query()->where('sponsorship_id', $sponsorship->id)->where('source_id', $moneyItem->id)->value('status'));
    }

    public function test_update_before_settlement_reuses_same_movement(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
        $originalMovementId = (string) $moneyItem->financial_movement_id;

        app(SponsorshipService::class)->update($sponsorship->fresh(), [
            'sponsor_id' => $sponsorship->sponsor_id,
            'supplier_id' => $sponsorship->supplier_id,
            'type' => $sponsorship->type,
            'title' => $sponsorship->title,
            'description' => $sponsorship->description,
            'periodicity' => $sponsorship->periodicity,
            'start_date' => $sponsorship->start_date->toDateString(),
            'end_date' => $sponsorship->end_date?->toDateString(),
            'cost_center_id' => $sponsorship->cost_center_id,
            'status' => $sponsorship->status,
            'notes' => $sponsorship->notes,
            'money_items' => [[
                'id' => $moneyItem->id,
                'description' => 'Tranche atualizada',
                'amount' => 1400.75,
                'expected_date' => now()->addDays(2)->toDateString(),
            ]],
            'goods_items' => [],
        ]);

        $moneyItem->refresh();
        $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

        $this->assertSame($originalMovementId, (string) $moneyItem->financial_movement_id);
        $this->assertSame('1400.75', (string) $movement->valor_total);
        $this->assertSame('sponsorship_money_item', $movement->origem_tipo);
        $this->assertSame((string) $moneyItem->id, (string) $movement->origem_id);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->where('origem_id', $moneyItem->id)->count());
    }

    public function test_update_is_blocked_after_partial_paid_allocation_reconciliation_and_fiscal_states(): void
    {
        foreach (['parcial', 'pago', 'allocation', 'reconciled', 'fiscal'] as $state) {
            [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
            $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

            $this->lockMovementLifecycle($movement, $state);

            $thrown = false;

            try {
                app(SponsorshipService::class)->update($sponsorship->fresh(), [
                    'sponsor_id' => $sponsorship->sponsor_id,
                    'supplier_id' => $sponsorship->supplier_id,
                    'type' => $sponsorship->type,
                    'title' => $sponsorship->title,
                    'description' => $sponsorship->description,
                    'periodicity' => $sponsorship->periodicity,
                    'start_date' => $sponsorship->start_date->toDateString(),
                    'end_date' => $sponsorship->end_date?->toDateString(),
                    'cost_center_id' => $sponsorship->cost_center_id,
                    'status' => $sponsorship->status,
                    'notes' => $sponsorship->notes,
                    'money_items' => [[
                        'id' => $moneyItem->id,
                        'description' => 'Linha bloqueada',
                        'amount' => 1800.00,
                        'expected_date' => now()->addDays(2)->toDateString(),
                    ]],
                    'goods_items' => [],
                ]);
            } catch (ValidationException) {
                $thrown = true;
            }

            $this->assertTrue($thrown, 'Expected lifecycle guard to block update for state '.$state);
        }
    }

    public function test_delete_pending_money_item_is_allowed(): void
    {
        [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
        $movementId = (string) $moneyItem->financial_movement_id;

        app(SponsorshipService::class)->delete($sponsorship->fresh());

        $this->assertSoftDeleted('sponsorships', ['id' => $sponsorship->id]);
        $this->assertDatabaseMissing('sponsorship_money_items', ['id' => $moneyItem->id]);
        $this->assertDatabaseMissing('movements', ['id' => $movementId]);
        $this->assertDatabaseMissing('movement_items', ['movimento_id' => $movementId]);
    }

    public function test_delete_is_blocked_after_partial_paid_allocation_reconciliation_and_fiscal_states(): void
    {
        foreach (['parcial', 'pago', 'allocation', 'reconciled', 'fiscal'] as $state) {
            [$sponsorship, $moneyItem] = $this->createSponsorshipWithMoneyItem();
            $movement = Movement::query()->findOrFail($moneyItem->financial_movement_id);

            $this->lockMovementLifecycle($movement, $state);

            $thrown = false;

            try {
                app(SponsorshipService::class)->delete($sponsorship->fresh());
            } catch (ValidationException) {
                $thrown = true;
            }

            $this->assertTrue($thrown, 'Expected lifecycle guard to block delete for state '.$state);
            $this->assertDatabaseHas('sponsorships', ['id' => $sponsorship->id]);
            $this->assertDatabaseHas('sponsorship_money_items', ['id' => $moneyItem->id]);
        }
    }

    public function test_delete_sponsorship_with_protected_money_item_rolls_back_entirely(): void
    {
        [$sponsorship, $firstItem, $secondItem] = $this->createSponsorshipWithTwoMoneyItems();
        $protectedMovement = Movement::query()->findOrFail($secondItem->financial_movement_id);
        $this->lockMovementLifecycle($protectedMovement, 'pago');

        $this->expectException(ValidationException::class);

        app(SponsorshipService::class)->delete($sponsorship->fresh());

        $this->assertDatabaseHas('sponsorships', ['id' => $sponsorship->id]);
        $this->assertDatabaseHas('sponsorship_money_items', ['id' => $firstItem->id]);
        $this->assertDatabaseHas('sponsorship_money_items', ['id' => $secondItem->id]);
    }

    public function test_two_money_items_create_two_distinct_movements(): void
    {
        [$sponsorship, $firstItem, $secondItem] = $this->createSponsorshipWithTwoMoneyItems();

        $this->assertNotSame($firstItem->financial_movement_id, $secondItem->financial_movement_id);
        $this->assertSame(2, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->whereIn('origem_id', [$firstItem->id, $secondItem->id])->count());
        $this->assertSame(2, SponsorshipIntegration::query()->where('sponsorship_id', $sponsorship->id)->where('integration_type', 'financial')->count());
    }

    public function test_atomic_sync_rolls_back_on_mid_transaction_failure(): void
    {
        app()->bind(SponsorshipIntegrationService::class, function ($app) {
            return new class(
                $app->make(RegisterStockMovementAction::class),
                $app->make(SponsorshipFinancialGuardService::class)
            ) extends SponsorshipIntegrationService {
                protected function persistMovementItems(Movement $movement, SponsorshipMoneyItem $item, Sponsorship $sponsorship): void
                {
                    throw new RuntimeException('simulated failure');
                }
            };
        });

        $service = app(SponsorshipService::class);
        $admin = User::factory()->admin()->create();
        $sponsor = Sponsor::query()->create([
            'nome' => 'Sponsor Atomicidade',
            'descricao' => 'Sponsor de teste',
            'tipo' => 'principal',
            'contacto' => '919999999',
            'email' => 'atomic@example.com',
            'website' => null,
            'valor_anual' => 1000,
            'data_inicio' => now()->toDateString(),
            'estado' => 'ativo',
        ]);
        $costCenter = CostCenter::create([
            'codigo' => 'CC-SP-AT',
            'nome' => 'Patrocinios Atomic',
            'tipo' => 'operacional',
            'descricao' => 'Centro de custo atomicidade',
            'orcamento' => 1000,
            'ativo' => true,
        ]);

        $result = $service->create([
            'sponsor_id' => $sponsor->id,
            'type' => 'money',
            'title' => 'Patrocínio atomic',
            'description' => 'Caso de rollback',
            'periodicity' => 'pontual',
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'cost_center_id' => $costCenter->id,
            'status' => 'ativo',
            'notes' => null,
            'money_items' => [[
                'description' => 'Linha que vai falhar',
                'amount' => 500,
                'expected_date' => now()->toDateString(),
            ]],
            'goods_items' => [],
        ], $admin);

        $sponsorship = $result['sponsorship'];
        $moneyItem = $sponsorship->moneyItems->first();

        $this->assertSame(0, Movement::query()->count());
        $this->assertSame(0, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->count());
        $this->assertNull($moneyItem?->financial_movement_id);
        $this->assertSame('failed', $moneyItem?->integration_status);
    }

    /**
     * @return array{0:Sponsorship,1:SponsorshipMoneyItem}
     */
    private function createSponsorshipWithMoneyItem(): array
    {
        [$sponsorship] = $this->seedSponsorshipWithMoneyItems([
            [
                'description' => 'Tranche inicial',
                'amount' => 1200.50,
                'expected_date' => now()->toDateString(),
            ],
        ]);

        return [$sponsorship, $sponsorship->fresh(['moneyItems'])->moneyItems->first()];
    }

    /**
     * @return array{0:Sponsorship,1:SponsorshipMoneyItem,2:SponsorshipMoneyItem}
     */
    private function createSponsorshipWithTwoMoneyItems(): array
    {
        [$sponsorship] = $this->seedSponsorshipWithMoneyItems([
            [
                'description' => 'Tranche A',
                'amount' => 100,
                'expected_date' => now()->toDateString(),
            ],
            [
                'description' => 'Tranche B',
                'amount' => 200,
                'expected_date' => now()->addDays(1)->toDateString(),
            ],
        ]);

        $sponsorship->refresh()->load('moneyItems');

        return [$sponsorship, $sponsorship->moneyItems[0], $sponsorship->moneyItems[1]];
    }

    /**
     * @param array<int,array<string,mixed>> $moneyItems
     * @return array{0:Sponsorship}
     */
    private function seedSponsorshipWithMoneyItems(array $moneyItems): array
    {
        $admin = User::factory()->admin()->create();
        $suffix = substr((string) \Illuminate\Support\Str::uuid(), 0, 8);

        $sponsor = Sponsor::query()->create([
            'nome' => 'Sponsor Teste '.$suffix,
            'descricao' => 'Sponsor para testes de lifecycle',
            'tipo' => 'principal',
            'contacto' => '912345'.$suffix,
            'email' => 'sponsor-'.$suffix.'@example.test',
            'website' => 'https://example.test/'.$suffix,
            'valor_anual' => 2500,
            'data_inicio' => now()->toDateString(),
            'estado' => 'ativo',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-SP-'.$suffix,
            'nome' => 'Patrocinios '.$suffix,
            'tipo' => 'operacional',
            'descricao' => 'Centro de custo de patrocinios',
            'orcamento' => 5000,
            'ativo' => true,
        ]);

        $result = app(SponsorshipService::class)->create([
            'sponsor_id' => $sponsor->id,
            'supplier_id' => null,
            'type' => count($moneyItems) > 1 ? 'mixed' : 'money',
            'title' => 'Patrocínio de teste',
            'description' => 'Patrocínio para testes',
            'periodicity' => 'pontual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'cost_center_id' => $costCenter->id,
            'status' => 'ativo',
            'notes' => 'Teste automatizado',
            'money_items' => $moneyItems,
            'goods_items' => [],
        ], $admin);

        return [$result['sponsorship']->fresh(['moneyItems'])];
    }

    private function lockMovementLifecycle(Movement $movement, string $state): void
    {
        switch ($state) {
            case 'parcial':
            case 'pago':
                $movement->forceFill(['estado_pagamento' => $state])->save();
                break;
            case 'allocation':
                $entry = FinancialEntry::query()->create([
                    'data' => now()->toDateString(),
                    'tipo' => 'receita',
                    'categoria' => 'Patrocinios',
                    'descricao' => 'Entrada canonical de teste',
                    'valor' => abs((float) $movement->valor_total),
                    'valor_pago' => 0,
                    'valor_em_aberto' => abs((float) $movement->valor_total),
                    'estado' => 'pendente',
                    'origem_tipo' => 'movement',
                    'origem_modulo' => 'financeiro',
                    'origem_id' => $movement->id,
                ]);

                $payment = Payment::query()->create([
                    'amount' => abs((float) $movement->valor_total),
                    'allocated_amount' => abs((float) $movement->valor_total),
                    'unallocated_amount' => 0,
                    'payment_date' => now()->toDateString(),
                    'method' => 'dinheiro',
                    'source' => Payment::SOURCE_MANUAL,
                    'status' => Payment::STATUS_CONFIRMED,
                ]);

                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'financial_entry_id' => $entry->id,
                    'amount' => abs((float) $movement->valor_total),
                    'status' => PaymentAllocation::STATUS_CONFIRMED,
                    'allocated_at' => now(),
                ]);

                break;
            case 'reconciled':
                $movement->forceFill(['estado_conciliacao' => 'conciliado'])->save();
                break;
            case 'fiscal':
                $movement->forceFill(['numero_recibo' => 'R-'.str()->uuid(), 'estado_pagamento' => 'pago'])->save();
                break;
        }
    }
}