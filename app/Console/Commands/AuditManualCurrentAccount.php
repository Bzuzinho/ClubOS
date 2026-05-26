<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\ManualCurrentAccountAuditReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditManualCurrentAccount extends Command
{
    protected $signature = 'finance:audit-manual-current-account
        {--user= : Audita apenas um membro especifico}
        {--export= : Exporta o relatorio JSON para storage/app/...}';

    protected $description = 'Audita saldo manual legado em dados_financeiros.conta_corrente_manual sem alterar a base de dados';

    public function handle(ManualCurrentAccountAuditReportBuilder $reportBuilder): int
    {
        $report = $reportBuilder->build($this->option('user') ?: null);

        $this->info('Auditoria de conta_corrente_manual');
        $this->newLine();
        $this->renderSummary($report['summary']);
        $this->renderMembers($report['members']);
        $this->renderSemantics($report['semantics']);

        if ($export = $this->option('export')) {
            $this->exportReport($export, $report);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Membros afetados', $summary['affected_members']],
                ['Total positivo', number_format((float) $summary['positive_total'], 2, '.', '')],
                ['Total negativo', number_format((float) $summary['negative_total'], 2, '.', '')],
                ['Total liquido legado', number_format((float) $summary['net_legacy_total'], 2, '.', '')],
                ['Membros com movimentos manuais', $summary['members_with_manual_adjustments']],
                ['Membros com faturas/movimentos em aberto', $summary['members_with_open_financial_items']],
                ['Estado semantico', $summary['semantic_status']],
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     */
    private function renderMembers(array $members): void
    {
        $this->newLine();
        $this->line('Membros afetados');

        if ($members === []) {
            $this->line('  Nenhum membro com conta_corrente_manual diferente de zero.');

            return;
        }

        $this->table(
            ['User', 'Nome', 'Valor', 'Mov. manuais', 'Em aberto', 'Recomendacao'],
            array_map(fn (array $member) => [
                $member['user_id'],
                $member['name'],
                number_format((float) $member['value'], 2, '.', ''),
                $member['manual_adjustment_movement_count'],
                (($member['open_invoice_count'] ?? 0) + ($member['open_movement_count'] ?? 0)),
                $member['migration_recommendation'],
            ], $members)
        );
    }

    /**
     * @param  array<string, string>  $semantics
     */
    private function renderSemantics(array $semantics): void
    {
        $this->newLine();
        $this->line('Semantica e guarda de migracao');
        $this->line('  Positivo: ' . $semantics['positive_value_meaning']);
        $this->line('  Negativo: ' . $semantics['negative_value_meaning']);
        $this->line('  Guarda: ' . $semantics['migration_guard']);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exportReport(string $path, array $report): void
    {
        $normalizedPath = ltrim($path, '/');
        $absolutePath = str_starts_with($normalizedPath, 'storage/app/')
            ? base_path($normalizedPath)
            : storage_path('app/' . $normalizedPath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('Relatorio exportado para ' . $absolutePath);
    }
}