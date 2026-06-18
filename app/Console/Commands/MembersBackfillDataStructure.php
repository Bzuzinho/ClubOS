<?php

namespace App\Console\Commands;

use App\Services\Members\MemberDataMigrationService;
use Illuminate\Console\Command;

class MembersBackfillDataStructure extends Command
{
    protected $signature = 'members:backfill-data-structure
        {--user-id= : Simula apenas um utilizador}
        {--limit= : Limita o numero de users analisados}
        {--json : Devolve o plano dry-run em JSON}
        {--commit : Opcao bloqueada nesta sprint}';

    protected $description = 'Simula backfill users -> dados_pessoais/dados_configuracao sem escrita (dry-run por defeito)';

    public function handle(MemberDataMigrationService $service): int
    {
        $commitRequested = (bool) $this->option('commit');

        if ($commitRequested) {
            $message = 'Backfill com escrita ainda esta bloqueado nesta sprint. Use apenas dry-run.';

            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'status' => 'blocked',
                    'message' => $message,
                    'mode' => 'commit_blocked',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return 2;
        }

        $report = $service->buildBackfillDryRunReport([
            'user_id' => $this->option('user-id'),
            'limit' => $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $summary = $report['summary'];

        $this->info('Backfill da estrutura de dados do membro (dry-run)');
        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', $summary['total_users']],
                ['would_create_dados_pessoais', $summary['would_create_dados_pessoais']],
                ['would_update_dados_pessoais', $summary['would_update_dados_pessoais']],
                ['would_create_dados_configuracao', $summary['would_create_dados_configuracao']],
                ['would_update_dados_configuracao', $summary['would_update_dados_configuracao']],
                ['missing_dados_pessoais', $summary['missing_dados_pessoais']],
                ['missing_dados_configuracao', $summary['missing_dados_configuracao']],
                ['conflicts_dados_pessoais', $summary['conflicts_dados_pessoais']],
                ['conflicts_dados_configuracao', $summary['conflicts_dados_configuracao']],
            ]
        );

        $this->newLine();
        $this->info('Dry-run concluido sem escrita em dados_pessoais/dados_configuracao.');

        return self::SUCCESS;
    }
}
