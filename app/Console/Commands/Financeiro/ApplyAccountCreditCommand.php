<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Services\Financeiro\AccountCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ApplyAccountCreditCommand extends Command
{
    protected $signature = 'finance:apply-account-credit
        {credit : AccountCredit id}
        {invoice : Invoice id}
        {--amount= : Valor a aplicar}
        {--dry-run : Simula a operacao sem alterar dados}
        {--apply : Executa a aplicacao}
        {--json : Escreve payload JSON}
        {--report-path= : Caminho para guardar o payload}';

    protected $description = 'Aplica credito em conta a uma fatura usando account_credit_usages, com dry-run por defeito';

    public function __construct(
        private readonly AccountCreditService $accountCreditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $credit = AccountCredit::query()->findOrFail((string) $this->argument('credit'));
        $invoice = Invoice::query()->findOrFail((string) $this->argument('invoice'));
        $amount = $this->option('amount') !== null
            ? (float) $this->option('amount')
            : round(min((float) $credit->remaining_amount, (float) ($invoice->valor_em_aberto ?? $invoice->valor_total)), 2);
        $apply = (bool) $this->option('apply');

        $preview = $this->accountCreditService->previewApplyToInvoice($credit, $invoice, $amount);
        $result = null;

        if ($apply) {
            $applied = $this->accountCreditService->applyToInvoice($credit, $invoice, $amount);
            $result = [
                'account_credit_id' => (string) $applied['account_credit']->id,
                'account_credit_usage_id' => (string) $applied['usage']->id,
                'invoice_id' => (string) $applied['invoice']->id,
                'financial_entry_id' => (string) $applied['financial_entry']->id,
                'amount' => round((float) $applied['usage']->amount, 2),
                'credit_remaining_amount' => round((float) $applied['account_credit']->remaining_amount, 2),
                'credit_status' => $applied['account_credit']->status,
                'invoice_valor_pago' => round((float) $applied['invoice']->valor_pago, 2),
                'invoice_valor_em_aberto' => round((float) $applied['invoice']->valor_em_aberto, 2),
                'invoice_estado_pagamento' => $applied['invoice']->estado_pagamento,
            ];
        }

        $payload = [
            'command' => 'finance:apply-account-credit',
            'mode' => $apply ? 'apply' : 'dry-run',
            'would_apply' => $preview,
            'applied' => $apply,
            'result' => $result,
        ];

        $this->writeReport($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($apply ? 'Account credit applied' : 'Account credit apply dry-run');
        $this->table(['Key', 'Value'], collect($payload['would_apply'])->map(fn (mixed $value, string $key): array => [$key, is_scalar($value) || $value === null ? (string) $value : json_encode($value)])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReport(array $payload): void
    {
        $path = $this->option('report-path');

        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $absolutePath = base_path($path);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
