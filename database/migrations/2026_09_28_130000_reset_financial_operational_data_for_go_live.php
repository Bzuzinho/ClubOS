<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const TRANSACTION_TABLES = [
        'payment_reversals',
        'account_credit_usages',
        'account_credits',
        'bank_transaction_allocations',
        'payment_allocations',
        'mapa_conciliacao',
        'bank_reconciliation_suggestions',
        'bank_reconciliation_aliases',
        'bank_reconciliation_repositories',
        'fiscal_document_requests',
        'convocation_movement_items',
        'convocation_movements',
        'membership_fees',
        'transactions',
        'receipt_import_items',
        'receipt_import_batches',
        'invoice_items',
        'movement_documents',
        'movement_items',
        'financial_entries',
        'payments',
        'invoices',
        'movements',
        'bank_statements',
    ];

    private const PRESERVED_TABLES = [
        'users',
        'athlete_sports_data',
        'familias',
        'user_guardian',
        'monthly_fees',
        'dados_financeiros',
        'cost_centers',
        'suppliers',
        'financial_categories',
        'invoice_types',
        'payment_methods',
        'movement_document_requirements',
        'competition_finance_policies',
        'competitions',
        'events',
        'convocation_groups',
        'loja_encomendas',
        'logistics_requests',
        'supplier_purchases',
        'sponsorships',
    ];

    private const LEGACY_FINANCIAL_KV_KEYS = [
        'club-movimentos',
        'club-movimento-itens',
        'club-movimento-items',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $preservedBefore = $this->snapshotCounts(self::PRESERVED_TABLES);

            $this->clearOperationalFinancialReferences();
            $this->resetMemberFinancialBalances();
            $this->disableAutomaticMonthlyFeeGeneration();
            $this->resetFinancialIntegrations();
            $this->clearLegacyFinancialKv();

            foreach (self::TRANSACTION_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            $this->assertTransactionalTablesAreEmpty();
            $this->assertOperationalFinancialReferencesAreEmpty();
            $this->assertPreservedCountsUnchanged($preservedBefore);
        });

        foreach ([
            'financeiro:index',
            'financeiro:fiscal_requests',
            'financeiro:faturas',
            'financeiro:fatura_itens',
            'financeiro:movimentos',
            'financeiro:movimento_itens',
            'financeiro:lancamentos',
            'financeiro:extratos',
            'financeiro:conciliacoes',
            'financeiro:mensalidades',
            'dashboard:stats',
        ] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Intencionalmente irreversível por migration.
        // A recuperação desta operação é feita exclusivamente pelo backup
        // PostgreSQL/off-site obrigatório criado antes do deploy.
    }

    private function clearOperationalFinancialReferences(): void
    {
        $this->updateIfColumnsExist('competition_registrations', [
            'fatura_id' => null,
            'movimento_id' => null,
        ]);

        $this->updateIfColumnsExist('convocation_groups', [
            'movimento_id' => null,
        ]);

        $this->updateIfColumnsExist('competition_financial_obligations', [
            'invoice_id' => null,
            'status' => 'pending_sync',
            'synchronized_at' => null,
        ]);

        $this->updateIfColumnsExist('store_orders', [
            'financial_invoice_id' => null,
        ]);

        $this->updateIfColumnsExist('logistics_requests', [
            'financial_invoice_id' => null,
        ]);

        $this->updateIfColumnsExist('supplier_purchases', [
            'financial_movement_id' => null,
            'financial_entry_id' => null,
        ]);

        $this->updateIfColumnsExist('loja_encomendas', [
            'fatura_id' => null,
        ]);

        $this->updateIfColumnsExist('loja_encomenda_devolucoes', [
            'fatura_id' => null,
            'fiscal_document_request_id' => null,
            'reversao_financeira_por' => null,
            'reversao_financeira_em' => null,
        ]);
    }

    private function resetMemberFinancialBalances(): void
    {
        if (! Schema::hasTable('dados_financeiros') || ! Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')) {
            return;
        }

        DB::table('dados_financeiros')->update([
            'conta_corrente_manual' => 0,
            'updated_at' => now(),
        ]);
    }

    private function disableAutomaticMonthlyFeeGeneration(): void
    {
        if (! Schema::hasTable('club_settings')) {
            return;
        }

        $payload = [];

        if (Schema::hasColumn('club_settings', 'monthly_fee_generation_enabled')) {
            $payload['monthly_fee_generation_enabled'] = false;
        }

        if (Schema::hasColumn('club_settings', 'monthly_fee_auto_activate_due')) {
            $payload['monthly_fee_auto_activate_due'] = false;
        }

        if ($payload !== []) {
            $payload['updated_at'] = now();
            DB::table('club_settings')->update($payload);
        }
    }

    private function resetFinancialIntegrations(): void
    {
        if (Schema::hasTable('sponsorship_money_items')) {
            $payload = [];

            if (Schema::hasColumn('sponsorship_money_items', 'financial_movement_id')) {
                $payload['financial_movement_id'] = null;
            }

            if (Schema::hasColumn('sponsorship_money_items', 'integration_status')) {
                $payload['integration_status'] = 'pending';
            }

            if (Schema::hasColumn('sponsorship_money_items', 'integration_message')) {
                $payload['integration_message'] = null;
            }

            if ($payload !== []) {
                $payload['updated_at'] = now();
                DB::table('sponsorship_money_items')->update($payload);
            }
        }

        if (Schema::hasTable('sponsorship_integrations')
            && Schema::hasColumn('sponsorship_integrations', 'integration_type')) {
            DB::table('sponsorship_integrations')
                ->where('integration_type', 'financial')
                ->delete();
        }
    }

    private function clearLegacyFinancialKv(): void
    {
        if (! Schema::hasTable('key_value_store')) {
            return;
        }

        DB::table('key_value_store')
            ->whereIn('key', self::LEGACY_FINANCIAL_KV_KEYS)
            ->delete();
    }

    /**
     * @param array<string,mixed> $values
     */
    private function updateIfColumnsExist(string $table, array $values): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $payload = [];

        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = $value;
            }
        }

        if ($payload !== []) {
            if (Schema::hasColumn($table, 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table($table)->update($payload);
        }
    }

    /**
     * @param array<int,string> $tables
     * @return array<string,int>
     */
    private function snapshotCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    private function assertTransactionalTablesAreEmpty(): void
    {
        foreach (self::TRANSACTION_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count !== 0) {
                throw new RuntimeException("Financial reset incomplete: {$table} still has {$count} row(s).");
            }
        }

        if (Schema::hasTable('key_value_store')) {
            $count = DB::table('key_value_store')
                ->whereIn('key', self::LEGACY_FINANCIAL_KV_KEYS)
                ->count();

            if ($count !== 0) {
                throw new RuntimeException("Financial reset incomplete: {$count} legacy financial KV row(s) remain.");
            }
        }

        if (Schema::hasTable('dados_financeiros') && Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')) {
            $count = DB::table('dados_financeiros')
                ->where('conta_corrente_manual', '<>', 0)
                ->count();

            if ($count !== 0) {
                throw new RuntimeException("Financial reset incomplete: {$count} manual current-account balance(s) remain.");
            }
        }
    }

    private function assertOperationalFinancialReferencesAreEmpty(): void
    {
        $references = [
            'competition_registrations' => ['fatura_id', 'movimento_id'],
            'convocation_groups' => ['movimento_id'],
            'competition_financial_obligations' => ['invoice_id'],
            'store_orders' => ['financial_invoice_id'],
            'logistics_requests' => ['financial_invoice_id'],
            'supplier_purchases' => ['financial_movement_id', 'financial_entry_id'],
            'loja_encomendas' => ['fatura_id'],
            'loja_encomenda_devolucoes' => ['fatura_id', 'fiscal_document_request_id'],
            'sponsorship_money_items' => ['financial_movement_id'],
        ];

        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $count = DB::table($table)->whereNotNull($column)->count();

                if ($count !== 0) {
                    throw new RuntimeException("Financial reset incomplete: {$table}.{$column} still has {$count} linked row(s).");
                }
            }
        }
    }

    /**
     * @param array<string,int> $before
     */
    private function assertPreservedCountsUnchanged(array $before): void
    {
        foreach ($before as $table => $countBefore) {
            $countAfter = DB::table($table)->count();

            if ($countBefore !== $countAfter) {
                throw new RuntimeException(
                    "Financial reset safety violation: preserved table {$table} changed from {$countBefore} to {$countAfter} row(s)."
                );
            }
        }
    }
};
