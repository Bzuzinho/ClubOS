<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EquipmentLoanStockAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_loan_with_physical_exit_is_clean(): void
    {
        [$loan, $product] = $this->loan(status: 'active', quantity: 2);
        $this->movement($product, 'exit', 2, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_active_clean', 'info', $loan->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_returned_loan_with_exit_and_return_is_clean(): void
    {
        [$loan, $product] = $this->loan(status: 'returned', quantity: 2);
        $this->movement($product, 'exit', 2, $loan->id);
        $this->movement($product, 'return', 2, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_return_cycle_clean', 'info', $loan->id);
        $this->assertNotContains('equipment_loan_duplicate_stock_action', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_active_loan_without_exit_generates_missing_physical_exit(): void
    {
        [$loan] = $this->loan(status: 'active', quantity: 2);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_missing_physical_exit', 'critical', $loan->id);
        $this->assertSame(1, $payload['summary']['missing_physical_exit_count']);
    }

    public function test_returned_loan_without_return_generates_missing_return(): void
    {
        [$loan, $product] = $this->loan(status: 'returned', quantity: 2);
        $this->movement($product, 'exit', 2, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_missing_return', 'warning', $loan->id);
        $this->assertSame(1, $payload['summary']['missing_return_count']);
    }

    public function test_closed_loan_with_physical_out_is_reported(): void
    {
        [$loan, $product] = $this->loan(status: 'cancelled', quantity: 2);
        $this->movement($product, 'exit', 2, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_closed_with_physical_out', 'warning', $loan->id);
        $this->assertSame(1, $payload['summary']['closed_with_physical_out_count']);
    }

    public function test_duplicate_exits_are_reported_but_exit_return_cycle_is_not_duplicate(): void
    {
        [$duplicateLoan, $duplicateProduct] = $this->loan(status: 'active', quantity: 2);
        $this->movement($duplicateProduct, 'exit', 2, $duplicateLoan->id);
        $this->movement($duplicateProduct, 'exit', 2, $duplicateLoan->id);

        $duplicatePayload = $this->jsonPayload(['--loan' => $duplicateLoan->id]);
        $this->assertFinding($duplicatePayload, 'equipment_loan_duplicate_stock_action', 'warning', $duplicateLoan->id);

        [$cycleLoan, $cycleProduct] = $this->loan(status: 'returned', quantity: 2);
        $this->movement($cycleProduct, 'exit', 2, $cycleLoan->id);
        $this->movement($cycleProduct, 'return', 2, $cycleLoan->id);

        $cyclePayload = $this->jsonPayload(['--loan' => $cycleLoan->id]);
        $this->assertNotContains('equipment_loan_duplicate_stock_action', collect($cyclePayload['findings'])->pluck('code')->all());
    }

    public function test_quantity_mismatch_is_reported(): void
    {
        [$loan, $product] = $this->loan(status: 'active', quantity: 5);
        $this->movement($product, 'exit', 3, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_quantity_mismatch', 'warning', $loan->id);
        $this->assertSame(1, $payload['summary']['quantity_mismatch_count']);
    }

    public function test_invalid_product_and_invalid_quantity_are_reported(): void
    {
        $invalidProductLoan = EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'B5',
            'article_id' => null,
            'article_name_snapshot' => 'Produto removido',
            'quantity' => 1,
            'loan_date' => Carbon::today()->toDateString(),
            'status' => 'active',
        ]);

        [$invalidQuantityLoan] = $this->loan(status: 'active', quantity: 0);

        $invalidProductPayload = $this->jsonPayload(['--loan' => $invalidProductLoan->id]);
        $invalidQuantityPayload = $this->jsonPayload(['--loan' => $invalidQuantityLoan->id]);

        $this->assertFinding($invalidProductPayload, 'equipment_loan_invalid_product', 'critical', $invalidProductLoan->id);
        $this->assertFinding($invalidQuantityPayload, 'equipment_loan_invalid_quantity', 'warning', $invalidQuantityLoan->id);
    }

    public function test_empty_uuid_source_reference_is_reported_without_crashing(): void
    {
        $product = $this->product();

        DB::table('stock_movements')->insert([
            'id' => (string) str()->uuid(),
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 1,
            'reference_type' => 'equipment_loan',
            'reference_id' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'equipment_loan_invalid_source_reference', 'warning');
        $this->assertSame(1, $payload['summary']['invalid_source_reference_count']);
    }

    public function test_overdue_active_loan_is_reported_as_warning(): void
    {
        [$loan, $product] = $this->loan(status: 'active', quantity: 1, dueDate: Carbon::today()->subDay()->toDateString());
        $this->movement($product, 'exit', 1, $loan->id);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);

        $this->assertFinding($payload, 'equipment_loan_overdue_active', 'warning', $loan->id);
        $this->assertFinding($payload, 'equipment_loan_active_clean', 'info', $loan->id);
        $this->assertSame(1, $payload['summary']['overdue_active_loan_count']);
    }

    public function test_legacy_unlinked_movement_is_info_and_only_actionable_removes_it(): void
    {
        $product = $this->product();
        $this->movement($product, 'exit', 1, null, [
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Saída legacy de empréstimo de material',
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);
        $actionablePayload = $this->jsonPayload(['--material' => $product->id, '--only-actionable' => true]);

        $this->assertFinding($payload, 'equipment_loan_legacy_unlinked_movement', 'info');
        $this->assertNotContains('equipment_loan_legacy_unlinked_movement', collect($actionablePayload['findings'])->pluck('code')->all());
    }

    public function test_fail_flags_json_report_path_and_read_only_snapshot(): void
    {
        [$loan] = $this->loan(status: 'active', quantity: 2);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/equipment-loan-stock-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--loan' => $loan->id]);
        $warningExitCode = Artisan::call('inventory:audit-equipment-loan-stock', [
            '--loan' => $loan->id,
            '--fail-on-warning' => true,
        ]);
        $criticalExitCode = Artisan::call('inventory:audit-equipment-loan-stock', [
            '--loan' => $loan->id,
            '--fail-on-critical' => true,
        ]);
        $reportExitCode = Artisan::call('inventory:audit-equipment-loan-stock', [
            '--loan' => $loan->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('b5-equipment-loan-stock-audit-v1', $payload['version']);
        $this->assertSame(1, $warningExitCode);
        $this->assertSame(1, $criticalExitCode);
        $this->assertSame(0, $reportExitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b5-equipment-loan-stock-audit-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_existing_stock_audits_remain_without_actionable_findings_for_clean_active_loan(): void
    {
        [$loan, $product] = $this->loan(status: 'active', quantity: 2, productStock: 8);
        $this->movement($product, 'adjustment', 10, null, ['reference_type' => 'ledger_opening_snapshot', 'reference_id' => null]);
        $this->movement($product, 'exit', 2, $loan->id);

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
        $exitCode = Artisan::call('inventory:audit-equipment-loan-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $loanId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code
            && ($loanId === null || ($finding['loan_id'] ?? null) === $loanId));

        $this->assertIsArray($finding, 'Expected finding '.$code);
        $this->assertSame($severity, $finding['severity']);
    }

    /**
     * @return array{0:EquipmentLoan,1:Product}
     */
    private function loan(string $status, int $quantity, ?string $dueDate = null, int $productStock = 0): array
    {
        $product = $this->product(['stock' => $productStock]);
        $loan = EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'Equipa B5',
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'loan_date' => Carbon::today()->toDateString(),
            'due_date' => $dueDate ?? Carbon::today()->addWeek()->toDateString(),
            'return_date' => $status === 'returned' ? Carbon::today()->toDateString() : null,
            'status' => $status,
        ]);

        return [$loan, $product];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Material B5 '.(string) str()->uuid(),
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
    private function movement(Product $product, string $type, int $quantity, ?string $loanId, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reference_type' => 'equipment_loan',
            'reference_id' => $loanId,
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
            'equipment_loans' => EquipmentLoan::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
