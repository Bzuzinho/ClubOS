<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogisticsRequestStockAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_with_reservation_and_release_cycle_is_clean(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'cancelled', quantity: 2);
        $this->movement($product, 'reservation', 2, $request->id);
        $this->movement($product, 'reservation', -2, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_reservation_cycle_clean', 'info', $request->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_open_approved_request_with_positive_reservation_is_clean(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'approved', quantity: 3);
        $this->movement($product, 'reservation', 3, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_stock_clean', 'info', $request->id);
    }

    public function test_cancelled_request_with_positive_reservation_is_reported(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'cancelled', quantity: 3);
        $this->movement($product, 'reservation', 3, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_closed_with_reserved_stock', 'warning', $request->id);
        $this->assertFinding($payload, 'logistics_request_missing_reservation_release', 'warning', $request->id);
    }

    public function test_delivered_request_without_physical_exit_is_reported(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'delivered', quantity: 2);
        $this->movement($product, 'reservation', 2, $request->id);
        $this->movement($product, 'reservation', -2, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_missing_physical_exit', 'critical', $request->id);
    }

    public function test_returned_request_without_return_is_reported(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'returned', quantity: 2);
        $this->movement($product, 'exit', 2, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_missing_return', 'warning', $request->id);
    }

    public function test_duplicate_positive_reservations_are_reported_but_reserve_release_is_not_duplicate(): void
    {
        [$duplicateRequest, $duplicateProduct] = $this->requestWithItem(status: 'approved', quantity: 2);
        $this->movement($duplicateProduct, 'reservation', 2, $duplicateRequest->id);
        $this->movement($duplicateProduct, 'reservation', 2, $duplicateRequest->id);

        $duplicatePayload = $this->jsonPayload(['--request' => $duplicateRequest->id]);
        $this->assertFinding($duplicatePayload, 'logistics_request_duplicate_stock_action', 'warning', $duplicateRequest->id);

        [$cycleRequest, $cycleProduct] = $this->requestWithItem(status: 'cancelled', quantity: 2);
        $this->movement($cycleProduct, 'reservation', 2, $cycleRequest->id);
        $this->movement($cycleProduct, 'reservation', -2, $cycleRequest->id);

        $cyclePayload = $this->jsonPayload(['--request' => $cycleRequest->id]);
        $this->assertNotContains('logistics_request_duplicate_stock_action', collect($cyclePayload['findings'])->pluck('code')->all());
    }

    public function test_quantity_mismatch_is_reported(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'approved', quantity: 5);
        $this->movement($product, 'reservation', 3, $request->id);

        $payload = $this->jsonPayload(['--request' => $request->id]);

        $this->assertFinding($payload, 'logistics_request_quantity_mismatch', 'warning', $request->id);
    }

    public function test_invalid_product_and_invalid_quantity_are_reported(): void
    {
        $invalidProductRequest = $this->request(status: 'approved');
        LogisticsRequestItem::query()->create([
            'logistics_request_id' => $invalidProductRequest->id,
            'article_id' => null,
            'article_name_snapshot' => 'Produto removido',
            'quantity' => 1,
            'unit_price' => 1,
            'line_total' => 1,
        ]);

        $invalidQuantityRequest = $this->request(status: 'approved');
        $product = $this->product();
        LogisticsRequestItem::query()->create([
            'logistics_request_id' => $invalidQuantityRequest->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => 0,
            'unit_price' => 1,
            'line_total' => 0,
        ]);

        $invalidProductPayload = $this->jsonPayload(['--request' => $invalidProductRequest->id]);
        $invalidQuantityPayload = $this->jsonPayload(['--request' => $invalidQuantityRequest->id]);

        $this->assertFinding($invalidProductPayload, 'logistics_request_invalid_product', 'critical', $invalidProductRequest->id);
        $this->assertFinding($invalidQuantityPayload, 'logistics_request_invalid_quantity', 'warning', $invalidQuantityRequest->id);
    }

    public function test_empty_uuid_source_reference_is_reported_without_crashing(): void
    {
        $product = $this->product();

        DB::table('stock_movements')->insert([
            'id' => (string) str()->uuid(),
            'article_id' => $product->id,
            'movement_type' => 'reservation',
            'quantity' => 1,
            'reference_type' => 'logistics_request',
            'reference_id' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'logistics_request_invalid_source_reference', 'warning');
        $this->assertSame(1, $payload['summary']['invalid_source_reference_count']);
    }

    public function test_legacy_unlinked_movement_is_info_and_only_actionable_removes_it(): void
    {
        $product = $this->product();
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'reservation',
            'quantity' => 1,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Reserva legacy de requisição logística',
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);
        $actionablePayload = $this->jsonPayload(['--material' => $product->id, '--only-actionable' => true]);

        $this->assertFinding($payload, 'logistics_request_legacy_unlinked_movement', 'info');
        $this->assertNotContains('logistics_request_legacy_unlinked_movement', collect($actionablePayload['findings'])->pluck('code')->all());
    }

    public function test_fail_flags_json_report_path_and_read_only_snapshot(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'delivered', quantity: 2);
        $this->movement($product, 'reservation', 2, $request->id);
        $this->movement($product, 'reservation', -2, $request->id);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/logistics-request-stock-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--request' => $request->id]);
        $exitCode = Artisan::call('inventory:audit-logistics-request-stock', [
            '--request' => $request->id,
            '--fail-on-warning' => true,
        ]);
        $reportExitCode = Artisan::call('inventory:audit-logistics-request-stock', [
            '--request' => $request->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('b4-logistics-request-stock-audit-v1', $payload['version']);
        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $reportExitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b4-logistics-request-stock-audit-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_existing_stock_audits_remain_without_actionable_findings_for_clean_delivered_request(): void
    {
        [$request, $product] = $this->requestWithItem(status: 'delivered', quantity: 2, productStock: 8);
        $this->movement($product, 'adjustment', 10, null, ['reference_type' => 'ledger_opening_snapshot', 'reference_id' => null]);
        $this->movement($product, 'reservation', 2, $request->id);
        $this->movement($product, 'reservation', -2, $request->id);
        $this->movement($product, 'exit', 2, $request->id);

        Artisan::call('inventory:audit-stock-integrity', ['--material' => $product->id, '--json' => true]);
        $integrity = json_decode(trim(Artisan::output()), true);

        Artisan::call('inventory:audit-stock-source-of-truth', ['--json' => true]);
        $sourceOfTruth = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $integrity['summary']['actionable_count']);
        $this->assertSame(0, $sourceOfTruth['summary']['actionable_count']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:audit-logistics-request-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $requestId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code
            && ($requestId === null || ($finding['request_id'] ?? null) === $requestId));

        $this->assertIsArray($finding, 'Expected finding '.$code);
        $this->assertSame($severity, $finding['severity']);
    }

    /**
     * @return array{0:LogisticsRequest,1:Product,2:LogisticsRequestItem}
     */
    private function requestWithItem(string $status, int $quantity, int $productStock = 0): array
    {
        $request = $this->request($status);
        $product = $this->product(['stock' => $productStock]);
        $item = LogisticsRequestItem::query()->create([
            'logistics_request_id' => $request->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'unit_price' => 1,
            'line_total' => $quantity,
        ]);

        return [$request, $product, $item];
    }

    private function request(string $status): LogisticsRequest
    {
        return LogisticsRequest::query()->create([
            'requester_name_snapshot' => 'Equipa B4',
            'status' => $status,
            'total_amount' => 0,
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Material B4 '.(string) str()->uuid(),
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
            'ativo' => true,
            'stock' => 0,
            'stock_reservado' => 0,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function movement(Product $product, string $type, int $quantity, ?string $requestId, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reference_type' => 'logistics_request',
            'reference_id' => $requestId,
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'products' => Product::query()->orderBy('id')->get()->toArray(),
            'stock_movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'logistics_requests' => LogisticsRequest::query()->orderBy('id')->get()->toArray(),
            'logistics_request_items' => LogisticsRequestItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
