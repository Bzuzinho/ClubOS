<?php

declare(strict_types=1);

namespace App\Console\Commands\Desportivo;

use App\Services\SportsFoundation\SportsLegacySchemaDataReadinessAuditor;
use Illuminate\Console\Command;

final class AuditSportsLegacySchemaDataReadinessCommand extends Command
{
    protected $signature = 'desportivo:audit-legacy-schema-data
        {--json= : Caminho para exportar o relatório JSON}
        {--fail-on-unreconciled : Devolve exit code 1 se existirem dados candidatos ainda por reconciliar}';

    protected $description = 'Audita em modo read-only a prontidão para futura limpeza física de schema/dados legacy do Desportivo.';

    public function handle(SportsLegacySchemaDataReadinessAuditor $auditor): int
    {
        $report = $auditor->audit();
        $summary = $report['summary'];

        $this->info('Desportivo Legacy Schema/Data Readiness');
        $this->line('Modo: READ-ONLY');
        $this->table(['Métrica', 'Valor'], collect($summary)->map(fn ($value, $key) => [(string) $key, (string) $value])->values()->all());

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Relatório exportado para: '.$path);
        }

        $hasUnreconciled = (int) ($summary['candidate_rows_requiring_review'] ?? 0) > 0
            || (int) ($summary['presence_unreconciled_count'] ?? 0) > 0;

        return $this->option('fail-on-unreconciled') && $hasUnreconciled ? self::FAILURE : self::SUCCESS;
    }
}
