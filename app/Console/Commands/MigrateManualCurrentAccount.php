<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\ManualCurrentAccountAuditReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigrateManualCurrentAccount extends Command
{
    protected $signature = 'finance:migrate-manual-current-account
        {--dry-run : Executa apenas o plano de migracao (comportamento por omissao)}
        {--commit : Reservado para sprint futura; nao executa migracao real em F3.4}
        {--user= : Prepara a migracao apenas para um membro}
        {--export= : Exporta o plano JSON para storage/app/...}';

    protected $description = 'Prepara a migracao controlada de conta_corrente_manual para movimentos manuais, em dry-run por omissao';

    public function handle(ManualCurrentAccountAuditReportBuilder $reportBuilder): int
    {
        $commitRequested = (bool) $this->option('commit');
        $report = $reportBuilder->build($this->option('user') ?: null);
        $plan = [
            'mode' => $commitRequested ? 'commit_blocked' : 'dry-run',
            'generated_at' => $report['generated_at'],
            'filters' => $report['filters'],
            'summary' => $report['summary'],
            'semantics' => $report['semantics'],
            'planned_movements' => array_map(fn (array $member) => [
                'user_id' => $member['user_id'],
                'name' => $member['name'],
                'value' => $member['value'],
                'recommendation' => $member['migration_recommendation'],
                'preview' => $member['dry_run_migration_preview'],
            ], $report['members']),
        ];

        $this->info('Plano de migracao de conta_corrente_manual');
        $this->newLine();
        $this->renderSummary($plan['summary']);
        $this->renderPlannedMovements($plan['planned_movements']);
        $this->renderGuards($commitRequested);

        if ($export = $this->option('export')) {
            $this->exportPlan($export, $plan);
        }

        if ($commitRequested) {
            $this->newLine();
            $this->error('A opcao --commit permanece bloqueada em F3.4. E obrigatoria uma decisao manual sobre a semantica de valores positivos/negativos antes de qualquer migracao real.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Dry-run concluido sem qualquer alteracao na base de dados.');

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
                ['Membros com movimentos manuais', $summary['members_with_manual_adjustments']],
                ['Membros com itens em aberto', $summary['members_with_open_financial_items']],
                ['Estado semantico', $summary['semantic_status']],
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $plannedMovements
     */
    private function renderPlannedMovements(array $plannedMovements): void
    {
        $this->newLine();
        $this->line('Preview de movimentos planeados');

        if ($plannedMovements === []) {
            $this->line('  Nenhum membro elegivel para plano de migracao.');

            return;
        }

        $this->table(
            ['User', 'Nome', 'Valor original', 'Valor previsto', 'Estado previsto', 'Origem prevista'],
            array_map(function (array $row): array {
                $payload = $row['preview']['movement_payload'];

                return [
                    $row['user_id'],
                    $row['name'],
                    number_format((float) $row['value'], 2, '.', ''),
                    number_format((float) ($payload['valor_total'] ?? 0), 2, '.', ''),
                    $payload['estado_pagamento'] ?? 'pendente',
                    $payload['planned_origin_label'] ?? 'legacy_manual_current_account',
                ];
            }, $plannedMovements)
        );
    }

    private function renderGuards(bool $commitRequested): void
    {
        $this->newLine();
        $this->line('Guardas da migracao');
        $this->line('  - Nunca cria Payment automaticamente.');
        $this->line('  - Nunca cria PaymentAllocation automaticamente.');
        $this->line('  - Nunca cria FiscalDocumentRequest automaticamente.');
        $this->line('  - Nunca concilia banco automaticamente.');
        $this->line('  - Nunca marca como pago automaticamente.');
        $this->line('  - Origem planeada: legacy_manual_current_account.');
        $this->line('  - Estado planeado: pendente, com revisao humana obrigatoria antes de commit real.');
        $this->line('  - Modo atual: ' . ($commitRequested ? 'commit pedido mas bloqueado por desenho de sprint.' : 'dry-run.'));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function exportPlan(string $path, array $plan): void
    {
        $normalizedPath = ltrim($path, '/');
        $absolutePath = str_starts_with($normalizedPath, 'storage/app/')
            ? base_path($normalizedPath)
            : storage_path('app/' . $normalizedPath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('Plano exportado para ' . $absolutePath);
    }
}