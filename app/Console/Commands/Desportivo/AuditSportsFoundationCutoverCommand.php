<?php

declare(strict_types=1);

namespace App\Console\Commands\Desportivo;

use App\Services\SportsFoundation\SportsFoundationCutoverAuditor;
use Illuminate\Console\Command;

final class AuditSportsFoundationCutoverCommand extends Command
{
    protected $signature = 'desportivo:audit-foundation-cutover
        {--json= : Caminho para exportar o relatório JSON}
        {--fail-on-blockers : Devolve exit code 1 se a fundação não estiver verde}';

    protected $description = 'Audita o cutover F7 do Desportivo, aliases legacy, boundaries e filas de revisão manual.';

    public function handle(SportsFoundationCutoverAuditor $auditor): int
    {
        $report = $auditor->audit();
        $summary = $report['summary'] ?? [];
        $blockers = $summary['blockers'] ?? [];

        $this->info('Desportivo Foundation Cutover — F7');
        $this->line('Estado: '.(($report['foundation_green'] ?? false) ? 'GREEN' : 'BLOCKED'));
        $this->newLine();

        $this->table(
            ['Check', 'Count'],
            collect($blockers)->map(fn ($count, $key): array => [(string) $key, (int) $count])->values()->all()
        );

        $this->newLine();
        $this->line('Ledger legacy: '.(int) ($summary['legacy_ledger_total'] ?? 0));
        $this->line('Obrigações financeiras em revisão manual: '.(int) ($summary['finance_manual_review_count'] ?? 0));
        $this->line('Proteção de writes legacy: '.(($summary['legacy_route_protection_enabled'] ?? false) ? 'ativa' : 'INATIVA'));

        if ($path = $this->option('json')) {
            file_put_contents(
                (string) $path,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $this->info('Relatório exportado para: '.$path);
        }

        if ($this->option('fail-on-blockers') && ! ($report['foundation_green'] ?? false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
