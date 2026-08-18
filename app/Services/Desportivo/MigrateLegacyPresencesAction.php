<?php

namespace App\Services\Desportivo;

use Illuminate\Support\Facades\Log;

/**
 * Compatibility shell for the retired legacy presences migration.
 *
 * The `presences` table has completed its cutover to `training_athletes` and
 * is physically retired. Keeping this service as a no-op avoids breaking old
 * operational tooling while guaranteeing that no runtime path can query or
 * recreate the legacy table.
 */
class MigrateLegacyPresencesAction
{
    /**
     * Return the historical report contract without touching the database.
     *
     * @param bool $dryRun Kept for backwards-compatible callers.
     * @return array<string, mixed>
     */
    public function execute(bool $dryRun = false): array
    {
        $startedAt = now();

        $report = [
            'dry_run' => $dryRun,
            'started_at' => $startedAt->toIso8601String(),
            'total_presences' => 0,
            'already_migrated' => 0,
            'migrated' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'skipped' => 0,
            'error_details' => [],
            'conflict_details' => [],
            'finished_at' => now()->toIso8601String(),
            'duration_seconds' => (int) abs($startedAt->diffInSeconds(now(), false)),
            'retired' => true,
        ];

        Log::info('Legacy presences migration is retired; no database work was performed');

        return $report;
    }

    /**
     * Generate the historical human-readable report format.
     *
     * @param array<string, mixed> $report
     */
    public function generateReportText(array $report): string
    {
        $text = "=================  RELATÓRIO DE MIGRAÇÃO - PRESENCES LEGACY ================\n\n";
        $text .= "Modo: ".($report['dry_run'] ? 'DRY RUN (simulação)' : 'PRODUCTION (real)')."\n";
        $text .= "Iniciado em: {$report['started_at']}\n";
        $text .= "Finalizado em: {$report['finished_at']}\n";
        $text .= "Duração: {$report['duration_seconds']} segundos\n\n";

        if (($report['retired'] ?? false) === true) {
            $text .= "Estado: MIGRAÇÃO ENCERRADA — presences foi retirada do schema ativo.\n\n";
        }

        $text .= "--- ESTATÍSTICAS ---\n";
        $text .= "Total de presences legacy: {$report['total_presences']}\n";
        $text .= "✅ Migrados com sucesso: {$report['migrated']}\n";
        $text .= "⚠️  Conflitos (manteve existente): {$report['conflicts']}\n";
        $text .= "❌ Erros: {$report['errors']}\n";
        $text .= "⏭️  Skipped: {$report['skipped']}\n\n";

        if (! empty($report['conflict_details'])) {
            $text .= "--- DETALHES DE CONFLITOS ---\n";
            foreach ($report['conflict_details'] as $idx => $conflict) {
                $text .= 'Conflito #'.($idx + 1).":\n";
                $text .= "  Presence ID: {$conflict['presence_id']}\n";
                $text .= "  Training Athlete ID: {$conflict['training_athlete_id']}\n";
                $text .= "  Ação: {$conflict['action']}\n\n";
            }
        }

        if (! empty($report['error_details'])) {
            $text .= "--- DETALHES DE ERROS ---\n";
            foreach ($report['error_details'] as $idx => $error) {
                $text .= 'Erro #'.($idx + 1).":\n";
                $text .= "  Presence ID: {$error['presence_id']}\n";
                $text .= "  Mensagem: {$error['error']}\n\n";
            }
        }

        $text .= "================================================================================\n";

        return $text;
    }
}
