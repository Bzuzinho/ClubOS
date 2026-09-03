<?php

declare(strict_types=1);

namespace Tests\Feature\Logistica;

use App\Contracts\Logistica\SportsLogisticsGateway;
use App\Contracts\Logistica\SportsLogisticsRequest;
use App\Models\EquipmentLoan;
use App\Models\LogisticsRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Logistica\ApproveLogisticsRequestAction;
use App\Services\Logistica\CreateEquipmentLoanAction;
use App\Services\Logistica\CreateLogisticsRequestAction;
use App\Services\Logistica\DeliverLogisticsRequestAction;
use App\Services\Logistica\ReturnEquipmentLoanAction;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InternalLogisticsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_lifecycle_is_retry_safe_and_preserves_sports_source_contract(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(stock: 8);
        $gateway = app(SportsLogisticsGateway::class);
        $contract = new SportsLogisticsRequest(
            sourceType: 'training_material_need',
            sourceId: 'training-session-h5e',
            sourceVersion: 3,
            requesterUserId: $actor->id,
            requesterNameSnapshot: $actor->nome_completo ?? $actor->name,
            requesterType: 'treinador',
            items: [['article_id' => $product->id, 'quantity' => 2]],
            actorId: $actor->id,
        );

        $first = $gateway->requestClubEquipment($contract);
        $second = $gateway->requestClubEquipment($contract);
        $request = LogisticsRequest::query()->findOrFail($first->requestId);

        $this->assertTrue($second->reused);
        $this->assertSame($first->requestId, $second->requestId);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->stock_reservado);

        app(ApproveLogisticsRequestAction::class)->execute($request, $actor);
        app(ApproveLogisticsRequestAction::class)->execute($request->fresh(), $actor);

        $this->assertSame(2, (int) $product->fresh()->stock_reservado);
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'logistics_request')
            ->where('reference_id', $request->id)
            ->where('movement_type', 'reservation')
            ->where('quantity', 2)
            ->count());

        app(DeliverLogisticsRequestAction::class)->execute($request->fresh(), $actor);
        app(DeliverLogisticsRequestAction::class)->execute($request->fresh(), $actor);

        $this->assertSame('delivered', $request->fresh()->status);
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->stock_reservado);
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'logistics_request')
            ->where('reference_id', $request->id)
            ->where('movement_type', 'exit')
            ->where('quantity', 2)
            ->count());
    }

    public function test_equipment_loan_return_is_retry_safe(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(stock: 5);
        $loan = app(CreateEquipmentLoanAction::class)->execute($this->loanPayload($product), $actor);

        app(ReturnEquipmentLoanAction::class)->execute($loan, $actor);
        app(ReturnEquipmentLoanAction::class)->execute($loan->fresh(), $actor);

        $this->assertSame('returned', $loan->fresh()->status);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'equipment_loan')
            ->where('reference_id', $loan->id)
            ->where('movement_type', 'return')
            ->count());
    }

    public function test_non_requestable_product_is_rejected_without_persistence(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(stock: 5, overrides: ['allow_request' => false]);

        try {
            app(CreateLogisticsRequestAction::class)->execute([
                'requester_name_snapshot' => 'Pedido H5e',
                'items' => [['article_id' => $product->id, 'quantity' => 1]],
            ], $actor);
            $this->fail('A requisição deveria ter sido rejeitada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(0, LogisticsRequest::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_non_loanable_product_is_rejected_without_persistence(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(stock: 5, overrides: ['allow_loan' => false]);

        try {
            app(CreateEquipmentLoanAction::class)->execute($this->loanPayload($product), $actor);
            $this->fail('O empréstimo deveria ter sido rejeitado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('article_id', $exception->errors());
        }

        $this->assertSame(0, EquipmentLoan::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_internal_lifecycle_audit_is_read_only_and_exposes_all_contracts(): void
    {
        $path = storage_path('app/audits/h5e-test.json');

        $this->artisan('inventory:audit-internal-logistics-lifecycle', [
            '--json' => true,
            '--report-path' => $path,
        ])->assertSuccessful();

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('h5e-internal-logistics-lifecycle-audit-v1', $payload['version']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['schema_detected']['product_capability_fields']['allow_request']);
        $this->assertTrue($payload['schema_detected']['product_capability_fields']['allow_loan']);
        $this->assertTrue($payload['schema_detected']['sports_request_source_fields']['idempotency_key']);
        $this->assertTrue($payload['interpretation']['no_data_changed']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_configuration_retires_shared_product_without_deleting_ledger_history(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product(stock: 0, overrides: ['allow_sale' => true, 'visible_in_store' => true]);
        app(StockLedgerService::class)->registerEntry($product, 3, [
            'source_type' => 'manual_adjustment',
            'source_id' => (string) str()->uuid(),
            'idempotency_key' => 'h5e-retirement-history',
        ]);
        $movementId = StockMovement::query()->where('article_id', $product->id)->latest('created_at')->value('id');

        $this->actingAs($admin)
            ->delete(route('configuracoes.artigos.destroy', $product))
            ->assertRedirect(route('configuracoes'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'ativo' => false,
            'visible_in_store' => false,
            'allow_sale' => false,
            'allow_request' => false,
            'allow_loan' => false,
        ]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movementId, 'article_id' => $product->id]);
    }

    /** @param array<string,mixed> $overrides */
    private function product(int $stock, array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Material H5e '.(string) str()->uuid(),
            'stock' => $stock,
            'stock_reservado' => 0,
            'stock_minimo' => 0,
            'ativo' => true,
            'visible_in_store' => false,
            'allow_sale' => false,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function loanPayload(Product $product): array
    {
        return [
            'borrower_user_id' => null,
            'borrower_name_snapshot' => 'Atleta H5e',
            'article_id' => $product->id,
            'quantity' => 2,
            'loan_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
        ];
    }
}
