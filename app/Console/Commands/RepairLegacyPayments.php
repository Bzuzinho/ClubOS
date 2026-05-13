<?php

namespace App\Console\Commands;

use App\Services\Financeiro\LegacyConsistencyService;
use Illuminate\Console\Command;

class RepairLegacyPayments extends Command
{
    protected $signature = 'financeiro:repair-legacy-payments
        {--dry-run : Simula as alteracoes sem escrever na base de dados}
        {--commit : Aplica as alteracoes elegiveis na base de dados}
        {--cancel-manual-orphans : Permite cancelar pagamentos manuais orfaos elegiveis}';

    protected $description = 'Repara de forma segura payments legacy orfaos ou com tracking incoerente';

    public function handle(LegacyConsistencyService $service): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = (bool) $this->option('dry-run');
        $cancelManualOrphans = (bool) $this->option('cancel-manual-orphans');

        if ($commit && $dryRun) {
            $this->error('Use apenas um modo: --dry-run ou --commit.');

            return self::FAILURE;
        }

        $report = $service->repairPayments($commit, $cancelManualOrphans);

        $this->info($commit
            ? 'Reparacao legacy de payments em modo commit.'
            : 'Reparacao legacy de payments em modo dry-run. Nenhuma alteracao foi aplicada.');
        $this->newLine();

        $this->table(
            ['Planeados', 'Elegiveis', 'Ignorados', 'Aplicados', 'Cancelados', 'Rebalanceados'],
            [[
                $report['summary']['planned'],
                $report['summary']['eligible'],
                $report['summary']['skipped'],
                $report['summary']['committed'],
                $report['summary']['cancelled'],
                $report['summary']['rebalanced'],
            ]]
        );

        $this->newLine();
        $this->line('Plano de reparacao por payment');

        if ($report['payments'] === []) {
            $this->line('  Nenhuma alteracao necessaria.');

            return self::SUCCESS;
        }

        $this->table([
            'Payment', 'Source', 'Elegivel', 'Aplicada', 'Mudancas', 'Bloqueios',
        ], array_map(function (array $row): array {
            return [
                $row['payment_id'],
                $row['source'],
                $row['eligible'] ? 'sim' : 'nao',
                ($row['executed'] ?? false) ? 'sim' : 'nao',
                implode(', ', array_keys($row['changes'])),
                implode(' | ', $row['blocked_reasons']),
            ];
        }, $report['payments']));

        return self::SUCCESS;
    }
}