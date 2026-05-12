<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->makePaymentAllocationInvoiceNullable();

        Schema::table('payment_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_allocations', 'financial_entry_id')) {
                $table->foreignUuid('financial_entry_id')->nullable()->after('invoice_id')
                    ->constrained('financial_entries')->nullOnDelete();
                $table->index('financial_entry_id');
            }
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('financial_entries', 'valor_pago')) {
                $table->decimal('valor_pago', 10, 2)->default(0)->after('valor');
            }
            if (!Schema::hasColumn('financial_entries', 'valor_em_aberto')) {
                $table->decimal('valor_em_aberto', 10, 2)->default(0)->after('valor_pago');
            }
            if (!Schema::hasColumn('financial_entries', 'estado')) {
                $table->string('estado', 20)->default('pendente')->after('valor_em_aberto');
            }
            if (!Schema::hasColumn('financial_entries', 'data_pagamento')) {
                $table->date('data_pagamento')->nullable()->after('estado');
            }
            if (!Schema::hasColumn('financial_entries', 'entidade_nome')) {
                $table->string('entidade_nome')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('financial_entries', 'documento_original')) {
                $table->string('documento_original')->nullable()->after('documento_ref');
            }
            if (!Schema::hasColumn('financial_entries', 'payment_id')) {
                $table->foreignUuid('payment_id')->nullable()->after('fatura_id')
                    ->constrained('payments')->nullOnDelete();
            }
            if (!Schema::hasColumn('financial_entries', 'bank_statement_id')) {
                $table->foreignUuid('bank_statement_id')->nullable()->after('payment_id')
                    ->constrained('bank_statements')->nullOnDelete();
            }
            if (!Schema::hasColumn('financial_entries', 'origem_modulo')) {
                $table->string('origem_modulo', 50)->nullable()->after('origem_id');
            }
            if (!Schema::hasColumn('financial_entries', 'fiscal_document_request_id')) {
                $table->foreignUuid('fiscal_document_request_id')->nullable()->after('comprovativo')
                    ->constrained('fiscal_document_requests')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('payment_allocations', 'financial_entry_id')) {
                $table->dropConstrainedForeignId('financial_entry_id');
            }
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            foreach (['fiscal_document_request_id', 'bank_statement_id', 'payment_id'] as $foreignColumn) {
                if (Schema::hasColumn('financial_entries', $foreignColumn)) {
                    $table->dropConstrainedForeignId($foreignColumn);
                }
            }

            foreach (['origem_modulo', 'documento_original', 'entidade_nome', 'data_pagamento', 'estado', 'valor_em_aberto', 'valor_pago'] as $column) {
                if (Schema::hasColumn('financial_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function makePaymentAllocationInvoiceNullable(): void
    {
        if (!Schema::hasTable('payment_allocations') || !Schema::hasColumn('payment_allocations', 'invoice_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE IF NOT EXISTS payment_allocations_tmp (
                id char(36) primary key,
                payment_id char(36) not null,
                invoice_id char(36) null,
                amount numeric not null,
                status varchar not null default "confirmed",
                allocated_at datetime null,
                created_by char(36) null,
                notes text null,
                metadata text null,
                created_at datetime null,
                updated_at datetime null,
                deleted_at datetime null,
                financial_entry_id char(36) null,
                foreign key(payment_id) references payments(id) on delete cascade,
                foreign key(invoice_id) references invoices(id) on delete set null,
                foreign key(created_by) references users(id) on delete set null,
                foreign key(financial_entry_id) references financial_entries(id) on delete set null
            )');
            DB::statement('INSERT INTO payment_allocations_tmp (id, payment_id, invoice_id, amount, status, allocated_at, created_by, notes, metadata, created_at, updated_at, deleted_at)
                SELECT id, payment_id, invoice_id, amount, status, allocated_at, created_by, notes, metadata, created_at, updated_at, deleted_at
                FROM payment_allocations');
            DB::statement('DROP TABLE payment_allocations');
            DB::statement('ALTER TABLE payment_allocations_tmp RENAME TO payment_allocations');
            DB::statement('CREATE INDEX IF NOT EXISTS payment_allocations_payment_id_index ON payment_allocations(payment_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS payment_allocations_invoice_id_index ON payment_allocations(invoice_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS payment_allocations_status_index ON payment_allocations(status)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payment_allocations ALTER COLUMN invoice_id DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payment_allocations MODIFY invoice_id CHAR(36) NULL');
        }
    }
};