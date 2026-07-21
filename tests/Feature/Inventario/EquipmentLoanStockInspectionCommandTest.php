<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EquipmentLoanStockInspectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_active_loan_with_exit_is_clean(): void
    {
        [$loan, $product] = $this->loan('active', 1, productStock: 4);
        $this->movement($product, 'adjustment', 5, null, ['reference_type' => 'ledger_opening_snapshot']);
        $movement = $this->movement($product, 'exit', 1, $loan->id);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertItem($payload, 'equipment_loan_inspection_clean', 'info', false);
        $this->assertTrue($payload['items'][0]['loan_record_found']);
        $this->assertTrue($payload['items'][0]['global_stock_state']['matches_ledger']);
    }

    public function test_existing_returned_loan_with_exit_and_return_is_clean(): void
    {
        [$loan, $product] = $this->loan('returned', 1, productStock: 0);
        $movement = $this->movement($product, 'exit', 1, $loan->id);
        $this->movement($product, 'return', 1, $loan->id);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertItem($payload, 'equipment_loan_inspection_clean', 'info', false);
        $this->assertSame(0, $payload['items'][0]['impact_by_source']['equipment_loan']['physical_net']);
    }

    public function test_missing_loan_record_with_uncompensated_exit_is_warning_actionable(): void
    {
        $product = $this->product(['stock' => -1]);
        $loanId = (string) str()->uuid();
        $movement = $this->movement($product, 'exit', 1, $loanId);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertItem($payload, 'equipment_loan_missing_record_with_uncompensated_exit', 'warning', true);
        $this->assertFalse($payload['items'][0]['loan_record_found']);
        $this->assertSame(1, $payload['summary']['uncompensated_exit_count']);
    }

    public function test_missing_loan_record_with_nearby_compensation_is_info_not_actionable(): void
    {
        $product = $this->product(['stock' => 0]);
        $loanId = (string) str()->uuid();
        $movement = $this->movement($product, 'exit', 1, $loanId);
        $this->movement($product, 'return', 1, $loanId, ['created_at' => Carbon::parse($movement->created_at)->addMinutes(3)]);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertItem($payload, 'equipment_loan_missing_record_but_globally_compensated', 'info', false);
        $this->assertSame(1, $payload['summary']['globally_compensated_count']);
    }

    public function test_missing_loan_record_with_global_stock_clean_is_not_critical(): void
    {
        $product = $this->product(['stock' => -1]);
        $movement = $this->movement($product, 'exit', 1, (string) str()->uuid());

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertTrue($payload['items'][0]['global_stock_state']['matches_ledger']);
    }

    public function test_finds_nearby_movements_by_source_material_and_created_by(): void
    {
        $userId = (string) User::factory()->create()->id;
        $product = $this->product(['stock' => 0]);
        $loanId = (string) str()->uuid();
        $movement = $this->movement($product, 'exit', 1, $loanId, ['created_by' => $userId]);
        $this->movement($product, 'entry', 1, (string) str()->uuid(), [
            'reference_type' => 'audit_orphan_resolution',
            'created_by' => $userId,
            'created_at' => Carbon::parse($movement->created_at)->addMinutes(2),
        ]);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertNotEmpty($payload['items'][0]['nearby_context']);
        $this->assertSame(0, $payload['items'][0]['impact_by_source']['total_material_period']['physical_net']);
    }

    public function test_detects_return_recorded_under_different_source(): void
    {
        $product = $this->product(['stock' => 0]);
        $movement = $this->movement($product, 'exit', 1, (string) str()->uuid());
        $this->movement($product, 'return', 1, (string) str()->uuid(), [
            'reference_type' => 'audit_orphan_resolution',
            'created_at' => Carbon::parse($movement->created_at)->addMinutes(4),
        ]);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);

        $this->assertItem($payload, 'equipment_loan_return_recorded_under_different_source', 'info', false);
    }

    public function test_json_report_path_only_actionable_and_read_only(): void
    {
        [$loan, $product] = $this->loan('returned', 1);
        $movement = $this->movement($product, 'exit', 1, $loan->id);
        $this->movement($product, 'return', 1, $loan->id);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/equipment-loan-stock-inspection.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--movement' => $movement->id]);
        $actionablePayload = $this->jsonPayload(['--movement' => $movement->id, '--only-actionable' => true]);
        $reportExitCode = Artisan::call('inventory:inspect-equipment-loan-stock', [
            '--movement' => $movement->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('b5-1-equipment-loan-stock-inspection-v1', $payload['version']);
        $this->assertSame([], $actionablePayload['items']);
        $this->assertSame(0, $reportExitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b5-1-equipment-loan-stock-inspection-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_equipment_loan_audit_is_same_before_and_after_inspection(): void
    {
        $product = $this->product(['stock' => -1]);
        $movement = $this->movement($product, 'exit', 1, (string) str()->uuid());

        Artisan::call('inventory:audit-equipment-loan-stock', ['--material' => $product->id, '--json' => true]);
        $beforeAudit = json_decode(trim(Artisan::output()), true);

        $this->jsonPayload(['--movement' => $movement->id]);

        Artisan::call('inventory:audit-equipment-loan-stock', ['--material' => $product->id, '--json' => true]);
        $afterAudit = json_decode(trim(Artisan::output()), true);

        $this->assertSame($beforeAudit['summary'], $afterAudit['summary']);
        $this->assertSame($beforeAudit['findings'], $afterAudit['findings']);
    }

    public function test_stock_integrity_remains_clean_for_globally_consistent_case(): void
    {
        $product = $this->product(['stock' => 4]);
        $this->movement($product, 'adjustment', 5, null, ['reference_type' => 'ledger_opening_snapshot']);
        $this->movement($product, 'exit', 1, (string) str()->uuid());

        Artisan::call('inventory:audit-stock-integrity', ['--material' => $product->id, '--json' => true]);
        $integrity = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $integrity['summary']['critical_count']);
        $this->assertSame(0, $integrity['summary']['warning_count']);
        $this->assertSame(0, $integrity['summary']['actionable_count']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:inspect-equipment-loan-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertItem(array $payload, string $classification, string $severity, bool $actionable): void
    {
        $this->assertNotEmpty($payload['items']);
        $this->assertSame($classification, $payload['items'][0]['classification']);
        $this->assertSame($severity, $payload['items'][0]['severity']);
        $this->assertSame($actionable, $payload['items'][0]['actionable']);
    }

    /**
     * @return array{0:EquipmentLoan,1:Product}
     */
    private function loan(string $status, int $quantity, int $productStock = 0): array
    {
        $product = $this->product(['stock' => $productStock]);
        $loan = EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'Equipa B5.1',
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'loan_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addWeek()->toDateString(),
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
            'nome' => 'Material B5.1 '.(string) str()->uuid(),
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
            'created_at' => now(),
            'updated_at' => now(),
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
