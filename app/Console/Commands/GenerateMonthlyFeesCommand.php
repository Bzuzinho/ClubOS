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
        {--current-season : Gerar para a epoca atual}
        {--include-inactive : Incluir utilizadores nao ativos}';

    protected $description = 'Gera mensalidades para utilizadores elegiveis sem duplicar periodos';

    public function __construct(private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('current-season')) {
            $summary = $this->monthlyFeeGenerationService->generateCurrentSeason([
                'only_active' => !$this->option('include-inactive'),
            ]);
        } else {
            $start = Carbon::parse((string) ($this->option('start') ?: Carbon::today()->startOfMonth()->toDateString()))->startOfMonth();
            $end = Carbon::parse((string) ($this->option('end') ?: $start->copy()->addMonths(11)->toDateString()))->startOfMonth();

            $summary = $this->monthlyFeeGenerationService->generateForAllEligibleUsers($start, $end, [
                'only_active' => !$this->option('include-inactive'),
            ]);
        }

        $this->info(sprintf(
            'Mensalidades geradas: %d | utilizadores processados: %d | com novas mensalidades: %d | sem plano: %d | sem data de inicio: %d',
            $summary['created_count'] ?? 0,
            $summary['users_processed'] ?? 0,
            $summary['users_with_new_fees'] ?? 0,
            $summary['skipped_without_plan'] ?? 0,
            $summary['skipped_without_start'] ?? 0,
        ));

        return self::SUCCESS;
    }
}