<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Models\Payment;
use App\Services\Financeiro\AccountCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CreateAccountCreditFromPaymentCommand extends Command
{
    protected $signature = 'finance:create-account-credit-from-payment
        {payment : Payment id}
        {--amount= : Valor do excedente a converter}
        {--dry-run : Simula a operacao sem alterar dados}
        {--apply : Executa a criacao}
        {--json : Escreve payload JSON}
        {--report-path= : Caminho para guardar o payload}';

    protected $description = 'Cria credito em conta a partir de excedente de pagamento, com dry-run por defeito';

    public function __construct(
        private readonly AccountCreditService $accountCreditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payment = Payment::query()->findOrFail((string) $this->argument('payment'));
        $amount = $this->option('amount') !== null ? (float) $this->option('amount') : null;
        $apply = (bool) $this->option('apply');

        $preview = $this->accountCreditService->previewCreateFromPaymentOverpayment($payment, $amount);
        $result = null;

        if ($apply) {
            $credit = $this->accountCreditService->createFromPaymentOverpayment($payment, $amount);
            $result = [
                'account_credit_id' => (string) $credit->id,
                'payment_id' => (string) $credit->payment_id,
                'amount' => round((float) $credit->amount, 2),
                'remaining_amount' => round((float) $credit->remaining_amount, 2),
                'status' => $credit->status,
                'source' => $credit->source,
            ];
        }

        $payload = [
            'command' => 'finance:create-account-credit-from-payment',
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

        $this->info($apply ? 'Account credit created' : 'Account credit dry-run');
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
