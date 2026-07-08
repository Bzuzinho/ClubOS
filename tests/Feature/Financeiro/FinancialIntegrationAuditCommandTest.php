<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Prova;
use App\Models\Sponsorship;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\SupplierPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinancialIntegrationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_outputs_valid_json_contract(): void
    {
        $this->seedCompetitionAmbiguousOriginCase();

        $exitCode = Artisan::call('finance:audit-integrations', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('xfin1-financial-integrations-audit-v1', $payload['version'] ?? null);
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('scope', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('modules', $payload);
        $this->assertArrayHasKey('findings', $payload);

        $finding = $payload['findings'][0] ?? null;
        $this->assertIsArray($finding);
        $this->assertArrayHasKey('severity', $finding);
        $this->assertArrayHasKey('code', $finding);
        $this->assertArrayHasKey('module', $finding);
        $this->assertArrayHasKey('source_type', $finding);
        $this->assertArrayHasKey('source_id', $finding);
        $this->assertArrayHasKey('financial_record_type', $finding);
        $this->assertArrayHasKey('financial_record_id', $finding);
        $this->assertArrayHasKey('reason', $finding);
        $this->assertArrayHasKey('metadata', $finding);
    }

    public function test_command_writes_report_path(): void
    {
        $this->seedCompetitionAmbiguousOriginCase();

        $relativePath = 'storage/app/audits/financial-integration-audit-command-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-integrations', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_command_filters_by_module(): void
    {
        $this->seedCompetitionAmbiguousOriginCase();
        $this->seedNegativeExpenseMovement();

        $exitCode = Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'competition_registrations',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $modules = collect($payload['modules'] ?? []);

        $this->assertSame(['competition_registrations'], $payload['scope']['modules'] ?? []);
        $this->assertSame(['competition_registrations'], $modules->pluck('module')->all());
    }

    public function test_fail_on_critical_returns_exit_code_one(): void
    {
        $this->seedSupplierPurchaseParallelCase();

        $exitCode = Artisan::call('finance:audit-integrations', [
            '--fail-on-critical' => true,
            '--module' => 'supplier_purchases',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_fail_on_warning_returns_exit_code_one(): void
    {
        $this->seedNegativeExpenseMovement();

        $exitCode = Artisan::call('finance:audit-integrations', [
            '--fail-on-warning' => true,
            '--module' => 'reporting',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_detects_registration_ambiguous_origin_and_parallel_entry(): void
    {
        [$registration, $invoice, $entry] = $this->seedCompetitionAmbiguousOriginCase();

        Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'competition_registrations',
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $findings = collect($payload['findings'] ?? []);

        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'competition_registration_non_specific_invoice_origin' && $finding['source_id'] === (string) $registration->id));
        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'competition_registration_parallel_invoice_and_entry' && $finding['financial_record_id'] === (string) $entry->id));
        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'registration_invoice_ambiguous_origin' && $finding['financial_record_id'] === (string) $invoice->id));
    }

    public function test_detects_supplier_purchase_parallel_and_orphan_reference(): void
    {
        $this->seedSupplierPurchaseParallelCase();
        $orphanPurchase = SupplierPurchase::query()->create([
            'supplier_name_snapshot' => 'Fornecedor Orfao',
            'invoice_reference' => 'SUP-ORPHAN',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 50,
        ]);

        DB::connection()->getPdo()->exec('PRAGMA defer_foreign_keys = ON');
        DB::connection()->getPdo()->exec(sprintf(
            "UPDATE supplier_purchases SET financial_movement_id = '%s' WHERE id = '%s'",
            (string) Str::uuid(),
            (string) $orphanPurchase->id,
        ));

        Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'supplier_purchases',
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $findings = collect($payload['findings'] ?? []);

        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'supplier_purchase_parallel_movement_and_entry'));
        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'supplier_purchase_orphan_financial_reference' && $finding['source_id'] === (string) $orphanPurchase->id));
    }

    public function test_reporting_module_no_longer_flags_paid_non_monthly_invoice_excluded(): void
    {
        $invoice = $this->seedPaidNonMonthlyInvoiceExcluded();
        $movement = $this->seedNegativeExpenseMovement();
        $snapshotInvoice = Invoice::query()->create([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 90,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'parcial',
            'tipo' => 'material',
            'oculta' => false,
        ]);

        Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'reporting',
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $findings = collect($payload['findings'] ?? []);

        $this->assertFalse($findings->contains(fn (array $finding): bool => $finding['code'] === 'paid_invoice_excluded_from_financial_reports' && $finding['source_id'] === (string) $invoice->id));
        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'negative_expense_movement_value' && $finding['source_id'] === (string) $movement->id));
        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'invoice_financial_snapshot_mismatch' && $finding['source_id'] === (string) $snapshotInvoice->id));
    }

    public function test_detects_logistics_request_paid_invoice_lifecycle_risk(): void
    {
        [$request, $invoice] = $this->seedLogisticsLifecycleRiskCase();

        Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'logistics_requests',
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $findings = collect($payload['findings'] ?? []);

        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'logistics_request_paid_invoice_lifecycle_risk' && $finding['source_id'] === (string) $request->id && $finding['financial_record_id'] === (string) $invoice->id));
    }

    public function test_detects_sponsorship_pending_integration_with_existing_movement(): void
    {
        $integration = $this->seedSponsorshipPendingIntegrationWithMovement();

        Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'sponsorships',
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $findings = collect($payload['findings'] ?? []);

        $this->assertTrue($findings->contains(fn (array $finding): bool => $finding['code'] === 'sponsorship_pending_integration_with_existing_movement' && $finding['source_id'] === (string) $integration->id));
    }

    /**
     * @return array{0:CompetitionRegistration,1:Invoice,2:FinancialEntry}
     */
    private function seedCompetitionAmbiguousOriginCase(): array
    {
        $user = User::factory()->create();
        $event = Event::query()->create([
            'titulo' => 'Prova XFIN',
            'descricao' => 'Evento de teste XFIN',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'taxa_inscricao' => 15,
            'estado' => 'agendado',
            'criado_por' => $user->id,
        ]);

        $competition = Competition::query()->create([
            'nome' => 'Competicao XFIN',
            'local' => 'Piscina Municipal',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'natacao',
            'evento_id' => $event->id,
        ]);

        $prova = Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'livres',
            'distancia_m' => 100,
            'genero' => 'misto',
            'ordem_prova' => 1,
        ]);

        $registration = CompetitionRegistration::query()->create([
            'prova_id' => $prova->id,
            'user_id' => $user->id,
            'estado' => 'pendente',
            'valor_inscricao' => 10,
            'fatura_id' => null,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 10,
            'valor_pago' => 0,
            'valor_em_aberto' => 10,
            'estado_pagamento' => 'pendente',
            'tipo' => 'inscricao',
            'origem_tipo' => 'evento',
            'origem_id' => $prova->id,
            'oculta' => false,
        ]);

        $registration->update(['fatura_id' => $invoice->id]);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Inscricao',
            'descricao' => 'Entrada paralela legacy',
            'valor' => 10,
            'valor_pago' => 0,
            'valor_em_aberto' => 10,
            'estado' => 'pendente',
            'user_id' => $user->id,
            'fatura_id' => $invoice->id,
            'origem_tipo' => 'evento',
            'origem_id' => $prova->id,
        ]);

        return [$registration->fresh(), $invoice, $entry];
    }

    private function seedSupplierPurchaseParallelCase(): SupplierPurchase
    {
        $movement = Movement::query()->create([
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -100,
            'estado_pagamento' => 'por_pagar',
            'tipo' => 'fornecedor',
            'origem_tipo' => 'stock',
            'origem_id' => 'purchase-xfin',
        ]);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Compras fornecedor',
            'descricao' => 'Compra XFIN',
            'valor' => 100,
            'origem_tipo' => 'stock',
            'origem_id' => 'purchase-xfin',
            'estado' => 'pendente',
        ]);

        return SupplierPurchase::query()->create([
            'supplier_name_snapshot' => 'Fornecedor XFIN',
            'invoice_reference' => 'SUP-001',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 100,
            'financial_movement_id' => $movement->id,
            'financial_entry_id' => $entry->id,
            'notes' => 'Caso paralelo',
        ]);
    }

    private function seedPaidNonMonthlyInvoiceExcluded(): Invoice
    {
        return Invoice::query()->create([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 25,
            'valor_pago' => 25,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'tipo' => 'material',
            'oculta' => false,
        ]);
    }

    private function seedNegativeExpenseMovement(): Movement
    {
        return Movement::query()->create([
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -55,
            'estado_pagamento' => 'pendente',
            'tipo' => 'fornecedor',
            'origem_tipo' => 'manual',
            'origem_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * @return array{0:LogisticsRequest,1:Invoice}
     */
    private function seedLogisticsLifecycleRiskCase(): array
    {
        $user = User::factory()->create();
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 40,
            'valor_pago' => 40,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'tipo' => 'material',
            'origem_tipo' => 'stock',
            'origem_id' => 'request-xfin',
            'oculta' => false,
        ]);

        Payment::query()->create([
            'id' => (string) Str::uuid(),
            'amount' => 40,
            'status' => 'confirmed',
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
        ]);

        $payment = Payment::query()->latest('created_at')->firstOrFail();

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 40,
        ]);

        $request = LogisticsRequest::query()->create([
            'requester_user_id' => $user->id,
            'requester_name_snapshot' => 'User XFIN',
            'status' => 'invoiced',
            'total_amount' => 40,
            'financial_invoice_id' => $invoice->id,
        ]);

        $invoice->update(['origem_id' => $request->id]);

        return [$request->fresh(), $invoice->fresh()];
    }

    private function seedSponsorshipPendingIntegrationWithMovement(): SponsorshipIntegration
    {
        $sponsorship = Sponsorship::query()->create([
            'codigo' => 'SP-XFIN',
            'sponsor_name' => 'Sponsor XFIN',
            'type' => 'money',
            'title' => 'Patrocinio XFIN',
            'periodicity' => 'pontual',
            'start_date' => now()->toDateString(),
            'status' => 'ativo',
        ]);

        $movement = Movement::query()->create([
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 100,
            'estado_pagamento' => 'pendente',
            'tipo' => 'patrocinio',
            'origem_tipo' => 'patrocinio',
            'origem_id' => $sponsorship->id,
        ]);

        SponsorshipMoneyItem::query()->create([
            'sponsorship_id' => $sponsorship->id,
            'description' => 'Tranche 1',
            'amount' => 100,
            'expected_date' => now()->toDateString(),
            'financial_movement_id' => $movement->id,
            'integration_status' => 'pending',
        ]);

        return SponsorshipIntegration::query()->create([
            'sponsorship_id' => $sponsorship->id,
            'integration_type' => 'financial',
            'source_type' => 'money_item',
            'source_id' => (string) Str::uuid(),
            'target_module' => 'financeiro',
            'target_table' => 'movements',
            'target_record_id' => $movement->id,
            'status' => 'pending',
        ]);
    }
}