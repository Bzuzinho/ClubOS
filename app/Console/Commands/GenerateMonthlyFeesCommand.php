<?php

namespace App\Console\Commands;

use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateMonthlyFeesCommand extends Command
{
    protected $signature = 'finance:generate-monthly-fees
        {--start= : Data inicial (YYYY-MM-DD)}
        {--end= : Data final (YYYY-MM-DD)}
        {--current-season : Opcao legada; usa o ciclo financeiro configurado}
        {--include-inactive : Incluir utilizadores nao ativos}';

    protected $description = 'Gera mensalidades pelo ciclo financeiro configurado sem duplicar periodos';

    public function __construct(private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $filters = [
            'only_active' => !$this->option('include-inactive'),
        ];

        if ($this->option('start') || $this->option('end')) {
            $start = Carbon::parse((string) ($this->option('start') ?: Carbon::today()->startOfMonth()->toDateString()))->startOfMonth();
            $end = Carbon::parse((string) ($this->option('end') ?: $start->copy()->toDateString()))->startOfMonth();

            $summary = $this->monthlyFeeGenerationService->generateForAllEligibleUsers($start, $end, $filters);
            $summary['activated_count'] = $this->monthlyFeeGenerationService->activateDueInvoices();
        } else {
            if ($this->option('current-season')) {
                $this->warn('A opcao --current-season esta obsoleta. Foi usado o ciclo financeiro configurado.');
            }

            $summary = $this->monthlyFeeGenerationService->runScheduledGeneration([
                'only_active' => !$this->option('include-inactive'),
            ]);
        }

        $this->info(sprintf(
            'Mensalidades geradas: %d | ativadas: %d | futuras ocultas: %d | ja existentes ignoradas: %d | utilizadores processados: %d | com novas mensalidades: %d | sem plano: %d | sem data de inicio: %d | erros: %d',
            $summary['created_count'] ?? 0,
            $summary['activated_count'] ?? 0,
            $summary['future_hidden_count'] ?? 0,
            $summary['skipped_existing_count'] ?? 0,
            $summary['users_processed'] ?? 0,
            $summary['users_with_new_fees'] ?? 0,
            $summary['skipped_without_plan'] ?? 0,
            $summary['skipped_without_start'] ?? 0,
            count($summary['errors'] ?? []),
        ));

        return self::SUCCESS;
    }
}