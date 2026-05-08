<?php

namespace App\Console\Commands;

use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Console\Command;

class ActivateDueMonthlyFeesCommand extends Command
{
    protected $signature = 'finance:activate-due-monthly-fees';

    protected $description = 'Ativa mensalidades ocultas cujo vencimento ja foi atingido';

    public function __construct(private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $activated = $this->monthlyFeeGenerationService->activateDueInvoices();

        $this->info(sprintf('Mensalidades ativadas: %d', $activated));

        return self::SUCCESS;
    }
}