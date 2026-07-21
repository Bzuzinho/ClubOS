<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventario\EquipmentLoanStockResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EquipmentLoanStockResolutionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_full_safe_resolution_with_global_stock_ok_proposes_accept_legacy_exit_not_return(): void
    {
        [$product, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'full_safe_resolution',
        ]);

        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertTrue($payload['dry_run']);
        $this->assertSame('accept_legacy_exit', $action['action_type']);
        $this->assertTrue($action['safe_to_apply']);
        $this->assertSame('global_stock_already_matches_ledger_return_would_change_stock', $action['reason']);
        $this->assertSame(5, $payload['items'][0]['expected_after']['ledger_physical_stock']);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_dry_run_create_missing_return_is_unsafe_when_it_would_create_snapshot_mismatch(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'create_missing_return',
        ]);

        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertSame('create_missing_return', $action['action_type']);
        $this->assertFalse($action['safe_to_apply']);
        $this->assertStringContainsString('return_would_create_stock_snapshot_mismatch', $action['reason']);
        $this->assertSame(6, $payload['items'][0]['expected_after']['ledger_physical_stock']);
        $this->assertFalse($payload['items'][0]['expected_after']['matches_ledger']);
    }

    public function test_accept_legacy_exit_apply_marks_movement_reviewed_without_changing_stock_or_historical_signature(): void
    {
        [$product, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);
        $beforeSignature = [$movement->movement_type, (int) $movement->quantity];

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'accept_legacy_exit',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ]);

        $movement->refresh();

        $this->assertFalse($payload['dry_run']);
        $this->assertSame(1, $payload['summary']['movements_marked_reviewed_count']);
        $this->assertStringContainsString(EquipmentLoanStockResolutionService::REVIEW_NOTE_MARKER, (string) $movement->notes);
        $this->assertSame($beforeSignature, [$movement->movement_type, (int) $movement->quantity]);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(2, StockMovement::query()->where('article_id', $product->id)->count());
    }

    public function test_after_accept_legacy_exit_audit_has_no_actionable_finding_for_that_case(): void
    {
        [$product, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);

        $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'accept_legacy_exit',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ]);

        Artisan::call('inventory:audit-equipment-loan-stock', [
            '--material' => $product->id,
            '--json' => true,
        ]);
        $audit = json_decode(trim(Artisan::output()), true);

        $this->assertContains('equipment_loan_legacy_exit_reviewed', collect($audit['findings'])->pluck('code')->all());
        $this->assertSame(0, $audit['summary']['closed_with_physical_out_count']);
        $this->assertSame(0, $audit['summary']['actionable_count']);
        $this->assertSame(1, $audit['summary']['legacy_exit_reviewed_count']);
    }

    public function test_apply_without_confirmation_is_blocked(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);

        $exitCode = Artisan::call('inventory:resolve-equipment-loan-stock', [
            '--movement' => $movement->id,
            '--strategy' => 'accept_legacy_exit',
            '--apply' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('confirm_equipment_loan_resolution_required', $payload['block_reason']);
        $this->assertStringNotContainsString(EquipmentLoanStockResolutionService::REVIEW_NOTE_MARKER, (string) $movement->fresh()->notes);
    }

    public function test_accept_legacy_exit_apply_is_idempotent_and_does_not_duplicate_notes(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);
        $options = [
            '--movement' => $movement->id,
            '--strategy' => 'accept_legacy_exit',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ];

        $this->jsonPayload($options);
        $firstNotes = (string) $movement->fresh()->notes;
        $second = $this->jsonPayload($options);
        $secondNotes = (string) $movement->fresh()->notes;

        $this->assertSame($firstNotes, $secondNotes);
        $this->assertSame(0, $second['summary']['applied_count']);
        $this->assertSame(1, substr_count($secondNotes, EquipmentLoanStockResolutionService::REVIEW_NOTE_MARKER));
    }

    public function test_create_missing_return_is_allowed_when_it_resolves_exact_snapshot_mismatch(): void
    {
        [$product, $movement] = $this->missingLoanExitFixture(storedStock: 6, openingStock: 6);

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'create_missing_return',
        ]);

        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertTrue($action['safe_to_apply']);
        $this->assertSame(6, $payload['items'][0]['expected_after']['ledger_physical_stock']);
        $this->assertTrue($payload['items'][0]['expected_after']['matches_ledger']);
        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_create_missing_return_apply_creates_return_through_stock_ledger_when_safe(): void
    {
        [$product, $movement, $loanId] = $this->missingLoanExitFixture(storedStock: 6, openingStock: 6);

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'create_missing_return',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ]);

        $created = StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', 'return')
            ->where('reference_type', 'equipment_loan_return')
            ->where('reference_id', $loanId)
            ->first();

        $this->assertSame(1, $payload['summary']['stock_movements_created_count']);
        $this->assertNotNull($created);
        $this->assertSame(1, (int) $created->quantity);
        $this->assertStringContainsString('b5_2_missing_loan_return:', (string) $created->notes);
        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_historical_movement_is_not_deleted_or_mutated_when_return_is_created(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 6, openingStock: 6);
        $signature = [$movement->movement_type, (int) $movement->quantity, $movement->reference_type, $movement->reference_id];

        $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'create_missing_return',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ]);

        $movement->refresh();

        $this->assertSame($signature, [$movement->movement_type, (int) $movement->quantity, $movement->reference_type, $movement->reference_id]);
        $this->assertTrue(StockMovement::query()->whereKey($movement->id)->exists());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);
        $relativePath = 'storage/app/testing/equipment-loan-stock-resolution.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'full_safe_resolution',
        ]);

        $exitCode = Artisan::call('inventory:resolve-equipment-loan-stock', [
            '--movement' => $movement->id,
            '--strategy' => 'full_safe_resolution',
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('b5-2-equipment-loan-stock-resolution-v1', $payload['version']);
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b5-2-equipment-loan-stock-resolution-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_dry_run_is_read_only(): void
    {
        [, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);
        $before = $this->snapshot();

        $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'full_safe_resolution',
            '--dry-run' => true,
        ]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_stock_integrity_and_source_of_truth_remain_without_actionable_findings_after_acceptance(): void
    {
        [$product, $movement] = $this->missingLoanExitFixture(storedStock: 5, openingStock: 6);

        $this->jsonPayload([
            '--movement' => $movement->id,
            '--strategy' => 'accept_legacy_exit',
            '--apply' => true,
            '--confirm-equipment-loan-resolution' => true,
        ]);

        Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $product->id,
            '--only-actionable' => true,
            '--json' => true,
        ]);
        $integrity = json_decode(trim(Artisan::output()), true);

        Artisan::call('inventory:audit-stock-source-of-truth', [
            '--json' => true,
        ]);
        $sourceOfTruth = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $integrity['summary']['actionable_count']);
        $this->assertSame(0, $sourceOfTruth['summary']['actionable_count']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options): array
    {
        $exitCode = Artisan::call('inventory:resolve-equipment-loan-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array{0:Product,1:StockMovement,2:string}
     */
    private function missingLoanExitFixture(int $storedStock, int $openingStock): array
    {
        $product = $this->product([
            'stock' => $storedStock,
            'stock_reservado' => 0,
            'allow_loan' => false,
        ]);
        $loanId = (string) str()->uuid();
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'adjustment',
            'quantity' => $openingStock,
            'reference_type' => 'ledger_opening_snapshot',
            'reference_id' => null,
            'notes' => 'Snapshot inicial de teste',
            'created_at' => Carbon::parse('2026-03-25 14:00:00'),
        ]);
        $movement = StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 1,
            'reference_type' => 'equipment_loan',
            'reference_id' => $loanId,
            'notes' => 'Saida para emprestimo de material',
            'created_at' => Carbon::parse('2026-03-25 15:25:23'),
        ]);

        return [$product, $movement, $loanId];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'TShirt BSCN - Tamanho - S',
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
