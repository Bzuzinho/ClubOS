<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Contracts\Financeiro\CompetitionFinanceGateway;
use App\Contracts\Financeiro\CompetitionFinanceRequest;
use App\Models\CompetitionFinancePolicy;
use App\Models\CompetitionFinancialObligation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompetitionFinancialObligationService implements CompetitionFinanceGateway
{
    private const CHARGEABLE_STATES = [
        'inscrito',
        'confirmado',
        'confirmed',
        'final',
        'finalizado',
    ];

    public function __construct(
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly InvoiceFinancialGuardService $invoiceFinancialGuard,
    ) {
    }

    public function ensureDefaultPolicy(string $clubId, string $competitionId): void
    {
        DB::table('competition_finance_policies')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'club_id' => $clubId,
            'competition_id' => $competitionId,
            'payer_mode' => 'club',
            'charge_mode' => 'none',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function synchronize(CompetitionFinanceRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            // F7 cutover: every competition must have a canonical policy. The
            // default is club-pays/none; runtime no longer infers finance from Event.
            $this->ensureDefaultPolicy($request->clubId, $request->competitionId);

            $policy = CompetitionFinancePolicy::query()
                ->where('club_id', $request->clubId)
                ->where('competition_id', $request->competitionId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $policyData = $this->policyData($policy);
            $obligation = $this->lockOrCreateObligation($request);
            $activeRegistrations = collect($request->registrations)
                ->filter(fn (array $row): bool => in_array($row['state'], self::CHARGEABLE_STATES, true))
                ->values();

            $registrationIds = $activeRegistrations
                ->pluck('registration_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($obligation->status === 'manual_review') {
                $previousIds = collect($obligation->calculation_snapshot['registration_ids'] ?? [])
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values()
                    ->all();
                $currentIds = collect($registrationIds)->sort()->values()->all();

                if ($previousIds !== $currentIds) {
                    throw ValidationException::withMessages([
                        'competition_registration' => 'A obrigação financeira desta competição requer revisão manual antes de alterar as inscrições.',
                    ]);
                }

                return;
            }

            [$amount, $calculationMeta, $manualReviewReason] = $this->calculateAmount(
                $policyData,
                $activeRegistrations->all(),
                $obligation
            );

            if ($manualReviewReason !== null) {
                $obligation->fill([
                    'status' => 'manual_review',
                    'calculated_amount' => $amount,
                    'calculation_snapshot' => [
                        'registration_ids' => $registrationIds,
                        'policy' => $policyData,
                        'calculation' => $calculationMeta,
                    ],
                    'manual_review_reason' => $manualReviewReason,
                    'synchronized_at' => now(),
                ])->save();

                return;
            }

            $costCenterId = $this->resolveCostCenterId($policyData, $request->athleteId);
            $invoice = $obligation->invoice_id
                ? Invoice::query()->lockForUpdate()->find($obligation->invoice_id)
                : null;

            if ($amount <= 0.009 || $registrationIds === []) {
                if ($invoice) {
                    $this->assertInvoiceMutable($invoice);
                    $invoice->items()->delete();
                    $invoice->delete();
                }

                $obligation->fill([
                    'invoice_id' => null,
                    'status' => $registrationIds === [] ? 'cancelled' : 'no_charge',
                    'calculated_amount' => 0,
                    'calculation_snapshot' => [
                        'registration_ids' => $registrationIds,
                        'policy' => $policyData,
                        'calculation' => $calculationMeta,
                    ],
                    'manual_review_reason' => null,
                    'synchronized_at' => now(),
                ])->save();

                return;
            }

            $anchorRegistrationId = (string) ($registrationIds[0] ?? '');
            $description = 'Inscricao em prova - '.$request->competitionName;

            if (! $invoice) {
                $emissionDate = now();
                $dueDate = $this->addBusinessDays($emissionDate->copy(), 8);
                $invoice = Invoice::query()->create([
                    'user_id' => $request->athleteId,
                    'data_fatura' => $emissionDate->toDateString(),
                    'mes' => $emissionDate->format('Y-m'),
                    'data_emissao' => $emissionDate->toDateString(),
                    'data_vencimento' => $dueDate->toDateString(),
                    'valor_total' => $amount,
                    'valor_pago' => 0,
                    'valor_em_aberto' => $amount,
                    'oculta' => false,
                    'estado_pagamento' => 'pendente',
                    'centro_custo_id' => $costCenterId,
                    'tipo' => 'inscricao',
                    'origem_tipo' => 'competition_registration',
                    'origem_id' => $anchorRegistrationId,
                    'observacoes' => $description,
                ]);

                InvoiceItem::query()->create([
                    'fatura_id' => $invoice->id,
                    'descricao' => $description,
                    'valor_unitario' => $amount,
                    'quantidade' => 1,
                    'imposto_percentual' => 0,
                    'total_linha' => $amount,
                    'centro_custo_id' => $costCenterId,
                ]);
            } else {
                $needsMutation = $this->invoiceNeedsMutation(
                    $invoice,
                    $amount,
                    $costCenterId,
                    $anchorRegistrationId,
                    $description
                );

                if ($needsMutation) {
                    $this->assertInvoiceMutable($invoice);

                    $invoice->fill([
                        'user_id' => $request->athleteId,
                        'valor_total' => $amount,
                        'valor_pago' => 0,
                        'valor_em_aberto' => $amount,
                        'estado_pagamento' => $this->openStatusFor($invoice),
                        'data_pagamento' => null,
                        'metodo_pagamento' => null,
                        'centro_custo_id' => $costCenterId,
                        'tipo' => 'inscricao',
                        'origem_tipo' => 'competition_registration',
                        'origem_id' => $anchorRegistrationId,
                        'observacoes' => $description,
                    ])->save();

                    $invoice->items()->delete();
                    InvoiceItem::query()->create([
                        'fatura_id' => $invoice->id,
                        'descricao' => $description,
                        'valor_unitario' => $amount,
                        'quantidade' => 1,
                        'imposto_percentual' => 0,
                        'total_linha' => $amount,
                        'centro_custo_id' => $costCenterId,
                    ]);
                }
            }

            $obligation->fill([
                'invoice_id' => $invoice->id,
                'status' => 'active',
                'calculated_amount' => $amount,
                'calculation_snapshot' => [
                    'registration_ids' => $registrationIds,
                    'policy' => $policyData,
                    'calculation' => $calculationMeta,
                    'invoice_origin' => [
                        'type' => 'competition_registration',
                        'id' => $anchorRegistrationId,
                        'canonical_obligation_id' => (string) $obligation->id,
                    ],
                ],
                'manual_review_reason' => null,
                'synchronized_at' => now(),
            ])->save();
        });
    }

    /** @return array<string,mixed> */
    private function policyData(CompetitionFinancePolicy $policy): array
    {
        return [
            'source' => 'canonical',
            'payer_mode' => (string) $policy->payer_mode,
            'charge_mode' => (string) $policy->charge_mode,
            'fixed_amount' => $policy->fixed_amount !== null ? (float) $policy->fixed_amount : 0.0,
            'per_race_amount' => $policy->per_race_amount !== null ? (float) $policy->per_race_amount : 0.0,
            'age_group_rates' => is_array($policy->age_group_rates) ? $policy->age_group_rates : [],
            'cost_center_id' => $policy->cost_center_id ? (string) $policy->cost_center_id : null,
        ];
    }

    private function lockOrCreateObligation(CompetitionFinanceRequest $request): CompetitionFinancialObligation
    {
        DB::table('competition_financial_obligations')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'club_id' => $request->clubId,
            'competition_id' => $request->competitionId,
            'user_id' => $request->athleteId,
            'status' => 'pending_sync',
            'calculated_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CompetitionFinancialObligation::query()
            ->where('club_id', $request->clubId)
            ->where('competition_id', $request->competitionId)
            ->where('user_id', $request->athleteId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $registrations
     * @return array{0:float,1:array<string,mixed>,2:?string}
     */
    private function calculateAmount(
        array $policy,
        array $registrations,
        CompetitionFinancialObligation $obligation
    ): array {
        if (($policy['payer_mode'] ?? 'club') !== 'athlete' || $registrations === []) {
            return [0.0, ['mode' => 'none', 'registration_count' => count($registrations)], null];
        }

        $mode = (string) ($policy['charge_mode'] ?? 'none');
        $fixed = $this->normalizeAmount((float) ($policy['fixed_amount'] ?? 0));
        $perRaceDefault = $this->normalizeAmount((float) ($policy['per_race_amount'] ?? 0));
        $perRaceTotal = collect($registrations)->sum(function (array $row) use ($perRaceDefault): float {
            $override = $row['amount_override'] ?? null;

            return $override !== null
                ? $this->normalizeAmount((float) $override)
                : $perRaceDefault;
        });

        return match ($mode) {
            'fixed' => [$fixed, ['mode' => $mode, 'fixed_amount' => $fixed], null],
            'per_race' => [
                $this->normalizeAmount((float) $perRaceTotal),
                ['mode' => $mode, 'registration_count' => count($registrations), 'per_race_total' => $perRaceTotal],
                null,
            ],
            'mixed' => [
                $this->normalizeAmount($fixed + (float) $perRaceTotal),
                ['mode' => $mode, 'fixed_amount' => $fixed, 'per_race_total' => $perRaceTotal],
                null,
            ],
            'manual' => [
                $this->normalizeAmount((float) ($obligation->manual_amount ?? 0)),
                ['mode' => $mode, 'manual_amount' => (float) ($obligation->manual_amount ?? 0)],
                null,
            ],
            'age_group' => $this->calculateAgeGroupAmount($policy, $registrations),
            default => [0.0, ['mode' => 'none'], null],
        };
    }

    /**
     * @param array<string,mixed> $policy
     * @param list<array<string,mixed>> $registrations
     * @return array{0:float,1:array<string,mixed>,2:?string}
     */
    private function calculateAgeGroupAmount(array $policy, array $registrations): array
    {
        $ageGroupIds = collect($registrations)
            ->pluck('age_group_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($ageGroupIds->count() !== 1) {
            return [
                0.0,
                ['mode' => 'age_group', 'age_group_ids' => $ageGroupIds->all()],
                'age_group_rate_requires_single_resolved_group',
            ];
        }

        $ageGroupId = (string) $ageGroupIds->first();
        $rates = is_array($policy['age_group_rates'] ?? null) ? $policy['age_group_rates'] : [];
        if (! array_key_exists($ageGroupId, $rates)) {
            return [
                0.0,
                ['mode' => 'age_group', 'age_group_id' => $ageGroupId],
                'age_group_rate_missing',
            ];
        }

        $amount = $this->normalizeAmount((float) $rates[$ageGroupId]);

        return [$amount, ['mode' => 'age_group', 'age_group_id' => $ageGroupId, 'rate' => $amount], null];
    }

    /** @param array<string,mixed> $policy */
    private function resolveCostCenterId(array $policy, string $athleteId): ?string
    {
        if (filled($policy['cost_center_id'] ?? null)) {
            return (string) $policy['cost_center_id'];
        }

        $user = User::query()->find($athleteId);
        if (! $user) {
            return null;
        }

        $rows = collect($this->memberCostCenterResolver->resolveForUser($user)['centro_custo_pesos'] ?? []);
        if ($rows->count() === 1) {
            return (string) $rows->first()['id'];
        }
        if ($rows->isEmpty()) {
            return null;
        }

        $maxWeight = (float) $rows->max('peso');
        $top = $rows->filter(fn (array $row): bool => abs((float) $row['peso'] - $maxWeight) < 0.0001)->values();

        return $top->count() === 1 ? (string) $top->first()['id'] : null;
    }

    private function invoiceNeedsMutation(
        Invoice $invoice,
        float $amount,
        ?string $costCenterId,
        string $anchorRegistrationId,
        string $description
    ): bool {
        $item = $invoice->items()->first();

        return abs((float) $invoice->valor_total - $amount) > 0.009
            || (string) $invoice->centro_custo_id !== (string) $costCenterId
            || (string) $invoice->origem_tipo !== 'competition_registration'
            || (string) $invoice->origem_id !== $anchorRegistrationId
            || (string) $invoice->observacoes !== $description
            || $invoice->items()->count() !== 1
            || ! $item
            || abs((float) $item->total_linha - $amount) > 0.009
            || (string) $item->centro_custo_id !== (string) $costCenterId;
    }

    private function assertInvoiceMutable(Invoice $invoice): void
    {
        if ((string) $invoice->estado_pagamento === 'pago_parcial'
            || $this->invoiceFinancialGuard->hasFinancialOrFiscalTrail($invoice)) {
            throw ValidationException::withMessages([
                'competition_registration' => 'Nao e possivel alterar a inscricao: a obrigação financeira associada já entrou num lifecycle fechado.',
            ]);
        }
    }

    private function openStatusFor(Invoice $invoice): string
    {
        return $invoice->data_vencimento && $invoice->data_vencimento->isBefore(now()->startOfDay())
            ? 'vencido'
            : 'pendente';
    }

    private function normalizeAmount(float $amount): float
    {
        return round(max(0, $amount), 2);
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $added = 0;
        while ($added < $days) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }
}
