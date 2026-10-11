<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class ResetOperationalDataCommand extends Command
{
    protected $signature = 'finance:reset-operational-data
        {--execute : Executa efetivamente o reset; sem esta opção é apenas dry-run}
        {--acknowledge= : Confirmação textual obrigatória para execução}
        {--json : Devolve o relatório em JSON}
        {--report-path= : Guarda o relatório JSON no caminho indicado}';

    protected $description = 'Reset one-shot de dados financeiros/fiscais/bancários e dados operacionais fictícios, preservando membros e configuração base';

    public function __construct(private readonly OperationalDataResetService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $ack = trim((string) $this->option('acknowledge'));

        if ($execute && $ack !== OperationalDataResetService::ACKNOWLEDGEMENT) {
            $this->error('Confirmação inválida. O reset não foi executado.');

            return self::FAILURE;
        }

        try {
            $payload = $execute ? $this->service->execute() : $this->service->preview();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $reportPath = trim((string) $this->option('report-path'));
        if ($reportPath !== '') {
            $path = str_starts_with($reportPath, '/') ? $reportPath : base_path($reportPath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->table(['Campo', 'Valor'], [
                ['reset_id', (string) ($payload['reset_id'] ?? '')],
                ['executed', ($payload['executed'] ?? false) ? 'true' : 'false'],
                ['already_executed', ($payload['already_executed'] ?? false) ? 'true' : 'false'],
                ['marker', $this->service->markerPath()],
            ]);
        }

        return self::SUCCESS;
    }
}
