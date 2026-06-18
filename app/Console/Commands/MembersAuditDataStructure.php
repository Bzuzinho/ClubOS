<?php

namespace App\Console\Commands;

use App\Services\Members\MemberDataMigrationService;
use Illuminate\Console\Command;

class MembersAuditDataStructure extends Command
{
    protected $signature = 'members:audit-data-structure
        {--user-id= : Audita apenas um utilizador}
        {--limit= : Limita o numero de users analisados}
        {--json : Devolve o relatorio em JSON}';

    protected $description = 'Audita o estado de migracao users -> dados_pessoais/dados_configuracao sem escrever dados';

    public function handle(MemberDataMigrationService $service): int
    {
        $report = $service->buildAuditReport([
            'user_id' => $this->option('user-id'),
            'limit' => $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $summary = $report['summary'];

        $this->info('Auditoria da estrutura de dados do membro (dry-run)');
        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users', $summary['total_users']],
                ['users_with_personal_payload', $summary['users_with_personal_payload']],
                ['users_with_configuration_payload', $summary['users_with_configuration_payload']],
                ['users_with_dados_pessoais', $summary['users_with_dados_pessoais']],
                ['users_with_dados_configuracao', $summary['users_with_dados_configuracao']],
                ['missing_dados_pessoais', $summary['missing_dados_pessoais']],
                ['missing_dados_configuracao', $summary['missing_dados_configuracao']],
                ['conflicts_dados_pessoais', $summary['conflicts_dados_pessoais']],
                ['conflicts_dados_configuracao', $summary['conflicts_dados_configuracao']],
                ['absent_source_fields', $summary['absent_source_fields']],
                ['suspicious_values', $summary['suspicious_values']],
            ]
        );

        $duplicatesCount =
            count($summary['possible_duplications']['nif'])
            + count($summary['possible_duplications']['documento_identificacao'])
            + count($summary['possible_duplications']['email_secundario'])
            + count($summary['possible_duplications']['afiliacao_numero']);

        $this->newLine();
        $this->line('Possiveis duplicacoes encontradas: ' . $duplicatesCount);

        return self::SUCCESS;
    }
}
