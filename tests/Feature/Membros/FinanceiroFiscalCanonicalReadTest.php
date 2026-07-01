<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\ReceiptImportBatch;
use App\Models\ReceiptImportItem;
use App\Models\User;
use App\Services\Financeiro\FinanceReportService;
use App\Services\Financeiro\ReceiptMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class FinanceiroFiscalCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_reports_no_member_fiscal_finance_findings_for_financeiro_controller(): void
    {
        $payload = $this->runAuditForPath('app/Http/Controllers/FinanceiroController.php');

        $fiscalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_fiscal_finance'
        );

        $this->assertCount(0, $fiscalFindings);
    }

    public function test_scanner_reports_no_member_fiscal_finance_findings_for_bank_reconciliation_suggestion_service(): void
    {
        $payload = $this->runAuditForPath('app/Services/Financeiro/BankReconciliationSuggestionService.php');

        $fiscalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_fiscal_finance'
        );

        $this->assertCount(0, $fiscalFindings);
    }

    public function test_scanner_reports_no_member_fiscal_finance_findings_for_finance_report_service(): void
    {
        $payload = $this->runAuditForPath('app/Services/Financeiro/FinanceReportService.php');

        $fiscalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_fiscal_finance'
        );

        $this->assertCount(0, $fiscalFindings);
    }

    public function test_scanner_reports_no_member_fiscal_finance_findings_for_receipt_matching_service(): void
    {
        $payload = $this->runAuditForPath('app/Services/Financeiro/ReceiptMatchingService.php');

        $fiscalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_fiscal_finance'
        );

        $this->assertCount(0, $fiscalFindings);
    }

    public function test_scanner_ignores_supplier_nif_morada_context_for_manual_expense_service(): void
    {
        $payload = $this->runAuditForPath('app/Services/Financeiro/ManualExpenseService.php');

        $fiscalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_fiscal_finance'
        );

        $this->assertCount(0, $fiscalFindings);
    }

    public function test_receipt_matching_prefers_canonical_member_name_in_candidates_label(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Legacy Receipt Name',
            'name' => 'Legacy Base Name',
            'numero_socio' => 'S-1001',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Canonical Receipt Name',
        ]);

        $batch = ReceiptImportBatch::query()->create([
            'status' => ReceiptImportBatch::STATUS_PENDING_REVIEW,
            'source_type' => 'upload',
            'source_path' => 'receipts/test.zip',
            'created_by' => $user->id,
        ]);

        $item = ReceiptImportItem::query()->create([
            'batch_id' => $batch->id,
            'status' => ReceiptImportItem::STATUS_PENDING_REVIEW,
            'file_name' => 'receipt-1.pdf',
            'storage_path' => 'receipts/receipt-1.pdf',
            'file_hash' => str_repeat('a', 64),
            'extracted_name' => 'Canonical Receipt Name',
            'extracted_member_number' => 'S-1001',
        ]);

        /** @var ReceiptMatchingService $service */
        $service = app(ReceiptMatchingService::class);
        $matched = $service->matchItem($item->fresh());

        $userCandidate = collect(data_get($matched->match_candidates, 'users', []))
            ->firstWhere('id', $user->id);

        $this->assertIsArray($userCandidate);
        $this->assertSame('Canonical Receipt Name', $userCandidate['label']);
    }

    public function test_finance_report_athletes_uses_canonical_name_when_personal_data_differs_from_legacy(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Legacy Athlete Name',
            'name' => 'Legacy Athlete Base',
            'tipo_membro' => ['atleta'],
            'escalao' => [],
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Canonical Athlete Name',
        ]);

        /** @var FinanceReportService $service */
        $service = app(FinanceReportService::class);
        $report = $service->reports(['user_id' => $user->id]);

        $athleteItem = collect(data_get($report, 'reports.athletes.items', []))
            ->firstWhere('id', $user->id);

        $this->assertIsArray($athleteItem);
        $this->assertSame('Canonical Athlete Name', $athleteItem['nome']);
    }

    public function test_scanner_does_not_flag_supplier_property_reads_as_user_fiscal_reads(): void
    {
        $scanner = app(\App\Services\Members\UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/FornecedorContext.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nif = \$supplier->nif;\n\$morada = \$supplier->morada;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame([], $result['findings']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runAuditForPath(string $path): array
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => [$path],
        ]);

        $this->assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
    }
}
