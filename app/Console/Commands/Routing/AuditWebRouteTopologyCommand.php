<?php

declare(strict_types=1);

namespace App\Console\Commands\Routing;

use App\Services\Routing\RouteTopologyAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditWebRouteTopologyCommand extends Command
{
    protected $signature = 'routes:audit-web-topology
        {--json : Devolve o relatório em JSON}
        {--report-path= : Guarda o relatório JSON no caminho indicado}
        {--fail-on-contract-drift : Falha se a topologia divergir do baseline H2.5a}';

    protected $description = 'Audita, sem alterar rotas, a topologia web, duplicados e consumidores de redirects legacy';

    public function handle(RouteTopologyAuditService $audit): int
    {
        $payload = $audit->report();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $reportPath = trim((string) $this->option('report-path'));

        if ($reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $summary = $payload['summary'];
            $this->table(['Métrica', 'Valor'], [
                ['web_routes', $summary['web_route_count']],
                ['duplicate_method_uri_groups', $summary['duplicate_signature_group_count']],
                ['legacy_redirects', $summary['legacy_redirect_count']],
                ['legacy_redirect_consumers', $summary['legacy_redirect_consumer_count']],
                ['modular_route_files', $summary['modular_route_file_count']],
                ['web.php lines', $summary['web_source_line_count']],
                ['fallback_is_last', $summary['fallback_is_last'] ? 'true' : 'false'],
                ['contract_matches_baseline', $summary['contract_matches_baseline'] ? 'true' : 'false'],
            ]);
        }

        return (bool) $this->option('fail-on-contract-drift')
            && ! (bool) $payload['summary']['contract_matches_baseline']
                ? self::FAILURE
                : self::SUCCESS;
    }
}
