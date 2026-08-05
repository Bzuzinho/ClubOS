<?php

namespace App\Console\Commands;

use App\Services\Communication\CommunicationAutomationService;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Console\Command;

class ActivateDueMonthlyFeesCommand extends Command
{
    protected $signature = 'finance:activate-due-monthly-fees {--force : Ignorar a configuracao de ativacao automatica}';

    protected $description = 'Ativa mensalidades ocultas cujo periodo ja ficou visivel e envia as respetivas notificacoes';

    public function __construct(
        private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService,
        private readonly CommunicationAutomationService $communicationAutomationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $activated = $this->monthlyFeeGenerationService->activateDueInvoices(null, [
            'force' => (bool) $this->option('force'),
            'respect_auto_activation_setting' => true,
        ]);

        $released = $this->communicationAutomationService->releaseVisibleInvoiceCommunications();

        $this->info(sprintf('Mensalidades ativadas: %d', $activated));
        $this->info(sprintf('Notificacoes de mensalidades libertadas: %d', $released));

        return self::SUCCESS;
    }
}
