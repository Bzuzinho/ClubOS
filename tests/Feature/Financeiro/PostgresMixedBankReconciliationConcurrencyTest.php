<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresMixedBankReconciliationConcurrencyTest extends TestCase
{
    private ?BankStatement $statement = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a dedicated PostgreSQL test database.');
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS test_delay_mixed_payment_insert ON payments');
            DB::unprepared('DROP FUNCTION IF EXISTS test_delay_mixed_payment_insert()');

            if ($this->statement) {
                $paymentIds = Payment::query()
                    ->where('bank_statement_id', $this->statement->id)
                    ->pluck('id');

                DB::table('fiscal_document_requests')->where('bank_statement_id', $this->statement->id)->delete();
                DB::table('mapa_conciliacao')->where('extrato_id', $this->statement->id)->delete();
                PaymentAllocation::query()->whereIn('payment_id', $paymentIds)->delete();
                Payment::query()->whereIn('id', $paymentIds)->delete();
                FinancialEntry::query()->where('bank_statement_id', $this->statement->id)->delete();
                $this->statement->delete();
            }
        }

        parent::tearDown();
    }

    public function test_two_independent_connections_cannot_confirm_the_same_statement_twice(): void
    {
        $database = (string) config('database.connections.pgsql.database');
        $this->assertStringEndsWith('_test', $database, 'Concurrency test refuses a database not named as a test database.');

        $user = User::factory()->create();
        $costCenter = CostCenter::query()->create([
            'codigo' => 'PG-CONC-' . substr((string) now()->getTimestampMs(), -8),
            'nome' => 'PostgreSQL Concurrency Test',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => '2026-07-01',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-10',
            'mes' => '2026-07',
            'valor_total' => 25.00,
            'estado_pagamento' => 'pendente',
            'referencia_pagamento' => 'PG-CONCURRENT-INVOICE',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);
        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade julho',
            'quantidade' => 1,
            'valor_unitario' => 25.00,
            'imposto_percentual' => 0,
            'total_linha' => 25.00,
            'centro_custo_id' => $costCenter->id,
        ]);

        $movement = Movement::query()->create([
            'user_id' => $user->id,
            'classificacao' => 'receita',
            'data_emissao' => '2026-07-02',
            'data_vencimento' => '2026-07-12',
            'valor_total' => 15.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'outro',
            'observacoes' => 'PostgreSQL concurrent movement',
        ]);

        $this->statement = BankStatement::query()->create([
            'conta' => 'PT50-PG-CONCURRENT',
            'data_movimento' => '2026-07-05',
            'descricao' => 'PostgreSQL concurrent mixed settlement',
            'valor' => 40.00,
            'saldo' => 1000.00,
            'referencia' => 'PG-CONCURRENT-STATEMENT',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 40.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION test_delay_mixed_payment_insert()
            RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(1);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_delay_mixed_payment_insert
            AFTER INSERT ON payments
            FOR EACH ROW
            WHEN (NEW.description = 'PostgreSQL concurrent settlement test')
            EXECUTE FUNCTION test_delay_mixed_payment_insert()
        SQL);

        $command = [
            PHP_BINARY,
            base_path('tests/Support/run_mixed_bank_settlement.php'),
            $this->statement->id,
            $invoice->id,
            $movement->id,
        ];
        $environment = collect([
            'APP_ENV',
            'APP_KEY',
            'CACHE_STORE',
            'SESSION_DRIVER',
            'QUEUE_CONNECTION',
            'MAIL_MAILER',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ])->mapWithKeys(fn (string $key): array => [$key => (string) getenv($key)])->all();

        $first = new Process($command, base_path(), $environment, null, 20);
        $second = new Process($command, base_path(), $environment, null, 20);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $results = collect([$first, $second])->map(function (Process $process): array {
            $this->assertSame('', trim($process->getErrorOutput()));

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        });

        $this->assertSame(1, $results->where('status', 'confirmed')->count());
        $this->assertSame(1, $results->where('status', 'rejected')->count());

        $payment = Payment::query()
            ->where('bank_statement_id', $this->statement->id)
            ->where('status', Payment::STATUS_CONFIRMED)
            ->sole();
        $allocations = PaymentAllocation::query()->where('payment_id', $payment->id)->get();
        $entry = FinancialEntry::query()->where('origem_tipo', 'movement')->where('origem_id', $movement->id)->sole();

        $this->assertCount(2, $allocations);
        $this->assertSame(2, $allocations->unique(fn (PaymentAllocation $allocation): string => $allocation->invoice_id ?: $allocation->financial_entry_id)->count());
        $this->assertSame(25.00, (float) $allocations->where('invoice_id', $invoice->id)->sum('amount'));
        $this->assertSame(15.00, (float) $allocations->where('financial_entry_id', $entry->id)->sum('amount'));
        $this->assertLessThanOrEqual(25.00, (float) $invoice->fresh()->valor_pago);
        $this->assertLessThanOrEqual(15.00, (float) $entry->fresh()->valor_pago);
        $this->assertSame(40.00, (float) $payment->amount);
        $this->assertSame(40.00, (float) $payment->allocated_amount);
        $this->assertSame(0.00, (float) $payment->unallocated_amount);
        $this->assertSame(40.00, (float) $this->statement->fresh()->valor_conciliado);
        $this->assertSame(0.00, (float) $this->statement->fresh()->valor_por_conciliar);
        $this->assertSame('reconciled', $this->statement->fresh()->conciliacao_status);
    }
}
