<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_finance_policies')) {
            Schema::create('competition_finance_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('competition_id')->index();
                $table->string('payer_mode', 24)->default('club')->index();
                $table->string('charge_mode', 24)->default('none')->index();
                $table->decimal('fixed_amount', 10, 2)->nullable();
                $table->decimal('per_race_amount', 10, 2)->nullable();
                $table->json('age_group_rates')->nullable();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->boolean('active')->default(true)->index();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['club_id', 'competition_id'], 'competition_finance_policy_scope_unique');
            });
        }

        if (! Schema::hasTable('competition_financial_obligations')) {
            Schema::create('competition_financial_obligations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('competition_id')->index();
                $table->uuid('user_id')->index();
                $table->uuid('invoice_id')->nullable()->index();
                $table->string('status', 32)->default('pending_sync')->index();
                $table->decimal('calculated_amount', 10, 2)->default(0);
                $table->decimal('manual_amount', 10, 2)->nullable();
                $table->json('calculation_snapshot')->nullable();
                $table->text('manual_review_reason')->nullable();
                $table->timestamp('synchronized_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['club_id', 'competition_id', 'user_id'],
                    'competition_obligation_scope_unique'
                );
            });
        }

        $this->backfillPolicies();
        $this->backfillObligations();
    }

    private function backfillPolicies(): void
    {
        if (! Schema::hasTable('competitions')) {
            return;
        }

        $fallbackClub = trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';

        foreach (DB::table('competitions')->orderBy('id')->get() as $competition) {
            $clubId = filled($competition->club_id ?? null) ? (string) $competition->club_id : $fallbackClub;
            $event = ! empty($competition->evento_id) && Schema::hasTable('events')
                ? DB::table('events')->where('id', $competition->evento_id)->first()
                : null;

            $legacyFee = $event?->taxa_inscricao !== null ? max(0, round((float) $event->taxa_inscricao, 2)) : 0.0;
            $hasExplicitCharge = DB::table('competition_registrations as cr')
                ->join('provas as p', 'p.id', '=', 'cr.prova_id')
                ->where('p.competicao_id', $competition->id)
                ->whereNotNull('cr.valor_inscricao')
                ->where('cr.valor_inscricao', '>', 0)
                ->exists();
            $hasLegacyInvoice = DB::table('competition_registrations as cr')
                ->join('provas as p', 'p.id', '=', 'cr.prova_id')
                ->where('p.competicao_id', $competition->id)
                ->whereNotNull('cr.fatura_id')
                ->exists();

            $athleteCharged = $legacyFee > 0.009 || $hasExplicitCharge || $hasLegacyInvoice;
            $chargeMode = $legacyFee > 0.009 || $hasExplicitCharge
                ? 'per_race'
                : ($hasLegacyInvoice ? 'manual' : 'none');

            DB::table('competition_finance_policies')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'club_id' => $clubId,
                'competition_id' => (string) $competition->id,
                'payer_mode' => $athleteCharged ? 'athlete' : 'club',
                'charge_mode' => $chargeMode,
                'fixed_amount' => null,
                'per_race_amount' => $legacyFee > 0.009 ? $legacyFee : null,
                'age_group_rates' => null,
                'cost_center_id' => $event?->centro_custo_id ?: null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillObligations(): void
    {
        if (! Schema::hasTable('competition_registrations') || ! Schema::hasTable('provas')) {
            return;
        }

        $fallbackClub = trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';
        $scopes = DB::table('competition_registrations as cr')
            ->join('provas as p', 'p.id', '=', 'cr.prova_id')
            ->join('competitions as c', 'c.id', '=', 'p.competicao_id')
            ->select(['c.club_id', 'p.competicao_id', 'cr.user_id'])
            ->distinct()
            ->orderBy('p.competicao_id')
            ->orderBy('cr.user_id')
            ->get();

        foreach ($scopes as $scope) {
            $rows = DB::table('competition_registrations as cr')
                ->join('provas as p', 'p.id', '=', 'cr.prova_id')
                ->where('p.competicao_id', $scope->competicao_id)
                ->where('cr.user_id', $scope->user_id)
                ->select(['cr.id', 'cr.fatura_id', 'cr.valor_inscricao'])
                ->orderBy('cr.created_at')
                ->orderBy('cr.id')
                ->get();

            $invoiceIds = $rows->pluck('fatura_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
            $status = match (true) {
                $invoiceIds->count() > 1 => 'manual_review',
                $invoiceIds->count() === 1 => 'legacy_linked',
                default => 'pending_sync',
            };
            $invoiceId = $invoiceIds->count() === 1 ? (string) $invoiceIds->first() : null;
            $manualAmount = null;

            $policy = DB::table('competition_finance_policies')
                ->where('competition_id', $scope->competicao_id)
                ->first();
            if ($policy?->charge_mode === 'manual' && $invoiceId) {
                $manualAmount = DB::table('invoices')->where('id', $invoiceId)->value('valor_total');
            }

            DB::table('competition_financial_obligations')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'club_id' => filled($scope->club_id) ? (string) $scope->club_id : $fallbackClub,
                'competition_id' => (string) $scope->competicao_id,
                'user_id' => (string) $scope->user_id,
                'invoice_id' => $invoiceId,
                'status' => $status,
                'calculated_amount' => round((float) $rows->sum(fn ($row) => (float) ($row->valor_inscricao ?? 0)), 2),
                'manual_amount' => $manualAmount,
                'calculation_snapshot' => json_encode([
                    'registration_ids' => $rows->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'legacy_invoice_ids' => $invoiceIds->all(),
                    'source' => 'f5_backfill',
                ], JSON_THROW_ON_ERROR),
                'manual_review_reason' => $invoiceIds->count() > 1
                    ? 'multiple_legacy_registration_invoices'
                    : null,
                'synchronized_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_financial_obligations');
        Schema::dropIfExists('competition_finance_policies');
    }
};
