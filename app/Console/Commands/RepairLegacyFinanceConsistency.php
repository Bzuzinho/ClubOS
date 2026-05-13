<?php

namespace App\Console\Commands;

use App\Services\Financeiro\LegacyConsistencyService;
use Illuminate\Console\Command;

class RepairLegacyFinanceConsistency extends Command
{
    protected $signature = 'financeiro:repair-legacy-consistency
        {--dry-run : Simula as alteracoes sem escrever na base de dados}
        {--commit : Aplica as alteracoes elegiveis na base de dados}';

    protected $description = 'Repara de forma segura incoerencias legacy entre invoices e pedidos fiscais';

    public function handle(LegacyConsistencyService $service): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = (bool) $this->option('dry-run');

        if ($commit && $dryRun) {
            $this->error('Use apenas um modo: --dry-run ou --commit.');

            return self::FAILURE;
        }

        $report = $service->repair($commit);

        $this->info($commit
            ? 'Reparacao legacy em modo commit.'
            : 'Reparacao legacy em modo dry-run. Nenhuma alteracao foi aplicada.');
        $this->newLine();

        $this->table(
            ['Planeadas', 'Elegiveis', 'Ignoradas', 'Aplicadas'],
            [[
                $report['summary']['planned'],
                $report['summary']['eligible'],
                $report['summary']['skipped'],
                $report['summary']['committed'],
            ]]
        );

        $this->newLine();
        $this->line('Plano de reparacao por invoice');

        if ($report['invoices'] === []) {
            $this->line('  Nenhuma alteracao necessaria.');
        } else {
            $this->table([
                'Invoice', 'Atual', 'Esperado', 'Elegivel', 'Aplicada', 'Mudancas', 'Bloqueios',
            ], array_map(function (array $row): array {
                return [
                    $row['invoice_id'],
                    $row['current_status'],
                    $row['expected_status'],
                    $row['eligible'] ? 'sim' : 'nao',
                    ($row['executed'] ?? false) ? 'sim' : 'nao',
                    implode(', ', array_keys($row['changes'])),
                    implode(' | ', $row['blocked_reasons']),
                ];
            }, $report['invoices']));
        }

        $this->newLine();
        $this->line('Pagamentos nao alocados ou com tracking inconsistente sao apenas auditados nesta fase.');
        $this->line(sprintf(
            'Audit findings: sem creditos=%d, sem allocations confirmadas=%d',
            $report['audit']['summary']['payments_without_account_credit'],
            $report['audit']['summary']['payments_without_confirmed_allocations'],
        ));

        return self::SUCCESS;
    }
}