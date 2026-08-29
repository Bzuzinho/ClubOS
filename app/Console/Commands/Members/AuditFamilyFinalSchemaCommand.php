<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AuditFamilyFinalSchemaCommand extends Command
{
    protected $signature = 'members:audit-family-final-schema
        {--json : Devolve o relatório em JSON}
        {--report-path= : Guarda o relatório JSON no caminho indicado}
        {--fail-on-finding : Falha se o schema canónico estiver incompleto ou existir estrutura legacy}';

    protected $description = 'Confirma o schema final Família/EE sem ler ou alterar dados pessoais';

    public function handle(): int
    {
        $canonical = [
            'user_guardian' => Schema::hasTable('user_guardian'),
            'familias' => Schema::hasTable('familias'),
            'familia_user' => Schema::hasTable('familia_user'),
        ];
        $legacy = [
            'user_relationships_table_present' => Schema::hasTable('user_relationships'),
            'users_encarregado_educacao_column_present' => Schema::hasColumn('users', 'encarregado_educacao'),
            'users_educandos_column_present' => Schema::hasColumn('users', 'educandos'),
        ];
        $ready = ! in_array(false, $canonical, true) && ! in_array(true, $legacy, true);

        $payload = [
            'version' => 'family-final-schema-v1',
            'read_only' => true,
            'summary' => [
                'canonical_tables_present_count' => count(array_filter($canonical)),
                'canonical_tables_required_count' => count($canonical),
                'legacy_structures_present_count' => count(array_filter($legacy)),
                'ready' => $ready,
            ],
            'canonical_structures' => $canonical,
            'legacy_structures' => $legacy,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $reportPath = trim((string) $this->option('report-path'));

        if ($reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->table(['Métrica', 'Valor'], [
                ['canonical_tables_present', sprintf('%d/%d', $payload['summary']['canonical_tables_present_count'], $payload['summary']['canonical_tables_required_count'])],
                ['legacy_structures_present', $payload['summary']['legacy_structures_present_count']],
                ['ready', $ready ? 'true' : 'false'],
            ]);
        }

        return (bool) $this->option('fail-on-finding') && ! $ready
            ? self::FAILURE
            : self::SUCCESS;
    }
}
