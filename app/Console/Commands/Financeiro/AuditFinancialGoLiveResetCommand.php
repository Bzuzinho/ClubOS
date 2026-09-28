<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AuditFinancialGoLiveResetCommand extends Command
{
    protected $signature = 'finance:audit-go-live-reset
        {--json : Devolve o relatório em JSON}
        {--report-path= : Caminho para guardar o relatório JSON}
        {--fail-on-data : Falha se existir qualquer facto financeiro residual}';

    protected $description = 'Audita, sem escrever dados, o baseline financeiro após o reset de go-live';

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

    private const LEGACY_FINANCIAL_KV_KEYS = [
        'club-movimentos',
        'club-movimento-itens',
        'club-movimento-items',
    ];

    public function handle(): int
    {
        $tableCounts = [];

        foreach (self::TRANSACTION_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $tableCounts[$table] = DB::table($table)->count();
            }
        }

        $referenceCounts = $this->referenceCounts();
        $legacyKvCount = Schema::hasTable('key_value_store')
            ? DB::table('key_value_store')->whereIn('key', self::LEGACY_FINANCIAL_KV_KEYS)->count()
            : 0;
        $manualBalanceCount = Schema::hasTable('dados_financeiros')
            && Schema::hasColumn('dados_financeiros', 'conta_corrente_manual')
                ? DB::table('dados_financeiros')->where('conta_corrente_manual', '<>', 0)->count()
                : 0;

        $transactionRowCount = array_sum($tableCounts);
        $linkedReferenceCount = array_sum($referenceCounts);
        $monthlyFeeGenerationEnabledCount = Schema::hasTable('club_settings')
            && Schema::hasColumn('club_settings', 'monthly_fee_generation_enabled')
                ? DB::table('club_settings')->where('monthly_fee_generation_enabled', true)->count()
                : 0;
        $monthlyFeeAutoActivateEnabledCount = Schema::hasTable('club_settings')
            && Schema::hasColumn('club_settings', 'monthly_fee_auto_activate_due')
                ? DB::table('club_settings')->where('monthly_fee_auto_activate_due', true)->count()
                : 0;

        $ready = $transactionRowCount === 0
            && $linkedReferenceCount === 0
            && $legacyKvCount === 0
            && $manualBalanceCount === 0
            && $monthlyFeeGenerationEnabledCount === 0
            && $monthlyFeeAutoActivateEnabledCount === 0;

        $payload = [
            'version' => 'financial-go-live-reset-v1',
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'ready' => $ready,
                'transaction_row_count' => $transactionRowCount,
                'linked_reference_count' => $linkedReferenceCount,
                'legacy_financial_kv_count' => $legacyKvCount,
                'non_zero_manual_balance_count' => $manualBalanceCount,
                'monthly_fee_generation_enabled_count' => $monthlyFeeGenerationEnabledCount,
                'monthly_fee_auto_activate_enabled_count' => $monthlyFeeAutoActivateEnabledCount,
                'users_count' => Schema::hasTable('users') ? DB::table('users')->count() : 0,
                'athlete_sports_data_count' => Schema::hasTable('athlete_sports_data') ? DB::table('athlete_sports_data')->count() : 0,
                'monthly_fee_plan_count' => Schema::hasTable('monthly_fees') ? DB::table('monthly_fees')->count() : 0,
                'member_financial_profile_count' => Schema::hasTable('dados_financeiros') ? DB::table('dados_financeiros')->count() : 0,
            ],
            'transaction_tables' => $tableCounts,
            'financial_references' => $referenceCounts,
        ];

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->info('Financial go-live reset audit (read-only)');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['ready', $ready ? 'true' : 'false'],
                    ['transaction_row_count', $transactionRowCount],
                    ['linked_reference_count', $linkedReferenceCount],
                    ['legacy_financial_kv_count', $legacyKvCount],
                    ['non_zero_manual_balance_count', $manualBalanceCount],
                    ['monthly_fee_generation_enabled_count', $monthlyFeeGenerationEnabledCount],
                    ['monthly_fee_auto_activate_enabled_count', $monthlyFeeAutoActivateEnabledCount],
                    ['users_count', $payload['summary']['users_count']],
                    ['athlete_sports_data_count', $payload['summary']['athlete_sports_data_count']],
                    ['monthly_fee_plan_count', $payload['summary']['monthly_fee_plan_count']],
                    ['member_financial_profile_count', $payload['summary']['member_financial_profile_count']],
                ]
            );
        }

        if ((bool) $this->option('fail-on-data') && ! $ready) {
            $this->error('O reset financeiro não está limpo.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,int>
     */
    private function referenceCounts(): array
    {
        $references = [
            'competition_registrations.fatura_id' => ['competition_registrations', 'fatura_id'],
            'competition_registrations.movimento_id' => ['competition_registrations', 'movimento_id'],
            'convocation_groups.movimento_id' => ['convocation_groups', 'movimento_id'],
            'competition_financial_obligations.invoice_id' => ['competition_financial_obligations', 'invoice_id'],
            'logistics_requests.financial_invoice_id' => ['logistics_requests', 'financial_invoice_id'],
            'supplier_purchases.financial_movement_id' => ['supplier_purchases', 'financial_movement_id'],
            'supplier_purchases.financial_entry_id' => ['supplier_purchases', 'financial_entry_id'],
            'loja_encomendas.fatura_id' => ['loja_encomendas', 'fatura_id'],
            'loja_encomenda_devolucoes.fatura_id' => ['loja_encomenda_devolucoes', 'fatura_id'],
            'loja_encomenda_devolucoes.fiscal_document_request_id' => ['loja_encomenda_devolucoes', 'fiscal_document_request_id'],
            'sponsorship_money_items.financial_movement_id' => ['sponsorship_money_items', 'financial_movement_id'],
        ];

        $counts = [];

        foreach ($references as $label => [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $counts[$label] = DB::table($table)->whereNotNull($column)->count();
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';

        if ($reportPath === '') {
            return;
        }

        $path = str_starts_with($reportPath, '/') ? $reportPath : base_path($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
