<?php

use App\Models\BankStatement;
use App\Services\Financeiro\FinancialSettlementService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $statementId, $invoiceId, $movementId] = $argv;

try {
    $payment = app(FinancialSettlementService::class)->settleMixedAllocations(
        BankStatement::query()->findOrFail($statementId),
        [
            ['invoice_id' => $invoiceId, 'amount' => 25.00],
            ['movement_id' => $movementId, 'amount' => 15.00],
        ],
        [
            'method' => 'transferencia',
            'source' => 'bank_reconciliation_suggestion',
            'description' => 'PostgreSQL concurrent settlement test',
        ],
    );

    echo json_encode([
        'status' => 'confirmed',
        'payment_id' => $payment->id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'rejected',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
