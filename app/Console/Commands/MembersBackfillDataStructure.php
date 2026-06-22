<?php

namespace App\Console\Commands;

use App\Services\Members\MemberDataMigrationService;
use Illuminate\Console\Command;

class MembersBackfillDataStructure extends Command
{
    protected $signature = 'members:backfill-data-structure
        {--user-id= : Simula apenas um utilizador}
        {--limit= : Limita o numero de users analisados}
        {--chunk=100 : Tamanho do lote de processamento}
        {--json : Devolve o plano dry-run em JSON}
        {--commit : Permite escrita real apenas com guardas explicitas}
        {--unlock-write : Guarda de seguranca para escrita real}
        {--confirm= : Confirmacao obrigatoria para escrita real}
        {--production-ack= : Confirmacao adicional obrigatoria para escrita em producao}
        {--allow-updates : Opcao reservada (bloqueada nesta sprint)}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Simula backfill users -> dados_pessoais/dados_configuracao sem escrita (dry-run por defeito)';

    public function handle(MemberDataMigrationService $service): int
    {
        $commitRequested = (bool) $this->option('commit');
        $unlockWrite = (bool) $this->option('unlock-write');
        $confirmToken = is_string($this->option('confirm')) ? trim((string) $this->option('confirm')) : '';
        $productionAck = is_string($this->option('production-ack')) ? trim((string) $this->option('production-ack')) : '';
        $allowUpdates = (bool) $this->option('allow-updates');
        $isJson = (bool) $this->option('json');

        if ($allowUpdates) {
            $message = 'Atualização de registos existentes ainda não está permitida nesta sprint.';

            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'blocked',
                    'message' => $message,
                    'mode' => 'updates_blocked',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return 2;
        }

        if ($commitRequested && !$this->isWritableEnvironment($productionAck)) {
            $message = app()->environment('production')
                ? 'Backfill real em producao requer --production-ack=PRODUCTION_MEMBER_BACKFILL.'
                : 'Backfill real so e permitido em ambiente local/desenvolvimento/codespaces.';

            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'blocked',
                    'message' => $message,
                    'mode' => 'environment_blocked',
                    'environment' => app()->environment(),
                    'required_production_ack' => app()->environment('production')
                        ? '--production-ack=PRODUCTION_MEMBER_BACKFILL'
                        : null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return 2;
        }

        if ($commitRequested && (!$unlockWrite || $confirmToken !== 'BACKFILL_MEMBER_DATA')) {
            $message = 'Escrita bloqueada: use todas as flags obrigatorias (--commit --unlock-write --confirm=BACKFILL_MEMBER_DATA).';

            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'blocked',
                    'message' => $message,
                    'mode' => 'write_guard_blocked',
                    'required_flags' => [
                        '--commit',
                        '--unlock-write',
                        '--confirm=BACKFILL_MEMBER_DATA',
                    ],
                    'required_production_ack' => app()->environment('production')
                        ? '--production-ack=PRODUCTION_MEMBER_BACKFILL'
                        : null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return 2;
        }

        $filters = [
            'user_id' => $this->option('user-id'),
            'limit' => $this->option('limit'),
            'chunk' => $this->option('chunk'),
            'allow_updates' => $allowUpdates,
        ];

        if ($commitRequested) {
            $report = $service->executeBackfillCommit($filters);
            $report['committed'] = true;
            $report['dry_run'] = false;
        } else {
            $report = $service->buildBackfillDryRunReport($filters);
            $report['committed'] = false;
            $report['dry_run'] = true;
        }

        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';

        if ($reportPath !== '') {
            $savedTo = $service->writeBackfillReportFile($report, $reportPath);
            $report['report_path'] = $savedTo;
        }

        if ($isJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $summary = $report['summary'];

        $this->info(sprintf(
            'Backfill da estrutura de dados do membro (%s)',
            $report['dry_run'] ? 'dry-run' : 'commit'
        ));

        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', $summary['total_users']],
                ['created_dados_pessoais', $summary['created_dados_pessoais']],
                ['created_dados_configuracao', $summary['created_dados_configuracao']],
                ['skipped_existing_dados_pessoais', $summary['skipped_existing_dados_pessoais']],
                ['skipped_existing_dados_configuracao', $summary['skipped_existing_dados_configuracao']],
                ['skipped_empty_payload_dados_pessoais', $summary['skipped_empty_payload_dados_pessoais']],
                ['skipped_empty_payload_dados_configuracao', $summary['skipped_empty_payload_dados_configuracao']],
                ['conflicts_dados_pessoais', $summary['conflicts_dados_pessoais']],
                ['conflicts_dados_configuracao', $summary['conflicts_dados_configuracao']],
                ['errors', $summary['errors']],
                ['dry_run', $summary['dry_run'] ? 'true' : 'false'],
                ['committed', $summary['committed'] ? 'true' : 'false'],
            ]
        );

        if ($reportPath !== '') {
            $this->newLine();
            $this->line('Relatorio JSON gravado em: ' . ($report['report_path'] ?? $reportPath));
        }

        $this->newLine();

        if ($report['dry_run']) {
            $this->info('Dry-run concluido sem escrita em dados_pessoais/dados_configuracao.');
        } else {
            $this->info('Backfill com escrita concluido (apenas criacao de registos em falta).');
        }

        return self::SUCCESS;
    }

    private function isWritableEnvironment(string $productionAck = ''): bool
    {
        if (app()->environment(['local', 'development', 'testing', 'codespaces'])) {
            return true;
        }

        return app()->environment('production')
            && $productionAck === 'PRODUCTION_MEMBER_BACKFILL';
    }
}
