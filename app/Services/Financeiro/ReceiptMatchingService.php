<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\ReceiptImportItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReceiptMatchingService
{
    public function matchItem(ReceiptImportItem $item): ReceiptImportItem
    {
        if (in_array($item->status, [ReceiptImportItem::STATUS_DUPLICATE, ReceiptImportItem::STATUS_FAILED], true)) {
            return $item;
        }

        $userCandidates = $this->findUserCandidates($item);
        $selectedUser = $this->selectBestCandidate($userCandidates);
        $invoiceCandidates = $selectedUser
            ? $this->findInvoiceCandidates($item, $selectedUser['model'])
            : collect();
        $selectedInvoice = $this->selectBestCandidate($invoiceCandidates);

        $status = ReceiptImportItem::STATUS_PENDING_REVIEW;
        if ($selectedUser === null) {
            $status = ReceiptImportItem::STATUS_NEEDS_USER;
        } elseif ($selectedInvoice === null) {
            $status = ReceiptImportItem::STATUS_NEEDS_INVOICE;
        } else {
            $status = ReceiptImportItem::STATUS_MATCHED;
        }

        $item->fill([
            'user_id' => $selectedUser['model']->id ?? null,
            'invoice_id' => $selectedInvoice['model']->id ?? null,
            'status' => $status,
            'confidence_score' => $selectedInvoice['score'] ?? $selectedUser['score'] ?? 0,
            'match_candidates' => [
                'users' => $userCandidates->map(fn (array $candidate) => $this->serializeCandidate($candidate))->all(),
                'invoices' => $invoiceCandidates->map(fn (array $candidate) => $this->serializeCandidate($candidate))->all(),
            ],
        ]);
        $item->save();

        return $item->refresh(['user', 'invoice']);
    }

    public function rematchItem(ReceiptImportItem $item, array $overrides = []): ReceiptImportItem
    {
        $item->fill(array_intersect_key($overrides, array_flip([
            'user_id',
            'invoice_id',
            'bank_statement_id',
            'numero_recibo',
            'recibo_emitido_em',
        ])));
        $item->save();

        if (!empty($overrides['user_id']) && !empty($overrides['invoice_id'])) {
            $item->update([
                'status' => ReceiptImportItem::STATUS_MATCHED,
            ]);

            return $item->refresh(['user', 'invoice']);
        }

        return $this->matchItem($item->fresh());
    }

    private function findUserCandidates(ReceiptImportItem $item): Collection
    {
        $candidates = collect();

        if ($item->extracted_nif) {
            User::query()
                ->where('nif', $item->extracted_nif)
                ->get()
                ->each(function (User $user) use ($candidates): void {
                    $candidates->push(['model' => $user, 'score' => 100, 'reason' => 'nif_exato']);
                });
        }

        if ($item->extracted_member_number) {
            User::query()
                ->where('numero_socio', $item->extracted_member_number)
                ->get()
                ->each(function (User $user) use ($candidates): void {
                    $candidates->push(['model' => $user, 'score' => 92, 'reason' => 'numero_socio_exato']);
                });
        }

        if ($item->extracted_email) {
            User::query()
                ->where(function ($query) use ($item): void {
                    $query
                        ->where('email', $item->extracted_email)
                        ->orWhere('email_utilizador', $item->extracted_email);
                })
                ->get()
                ->each(function (User $user) use ($candidates): void {
                    $candidates->push(['model' => $user, 'score' => 84, 'reason' => 'email_exato']);
                });
        }

        $normalizedName = $this->normalizeName($item->extracted_name);
        if ($normalizedName !== '') {
            User::query()
                ->whereNotNull('nome_completo')
                ->get()
                ->each(function (User $user) use ($normalizedName, $candidates): void {
                    $candidateName = $this->normalizeName($user->nome_completo ?? $user->name ?? null);
                    if ($candidateName === '') {
                        return;
                    }

                    if ($candidateName === $normalizedName) {
                        $candidates->push(['model' => $user, 'score' => 72, 'reason' => 'nome_exato']);

                        return;
                    }

                    if (str_contains($candidateName, $normalizedName) || str_contains($normalizedName, $candidateName)) {
                        $candidates->push(['model' => $user, 'score' => 58, 'reason' => 'nome_aproximado']);
                    }
                });
        }

        return $candidates
            ->groupBy(fn (array $candidate) => $candidate['model']->id)
            ->map(function (Collection $group): array {
                $model = $group->first()['model'];
                $score = (int) min(100, $group->max('score') + max($group->count() - 1, 0) * 4);

                return [
                    'model' => $model,
                    'score' => $score,
                    'reason' => $group->pluck('reason')->implode(','),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    private function findInvoiceCandidates(ReceiptImportItem $item, User $user): Collection
    {
        $value = $item->valor !== null ? round((float) $item->valor, 2) : null;
        $periodMonth = $this->resolvePeriodMonth($item);

        return Invoice::query()
            ->where('user_id', $user->id)
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->get()
            ->map(function (Invoice $invoice) use ($value, $periodMonth): array {
                $score = 0;
                $reasons = [];
                $invoiceTotal = round((float) $invoice->valor_total, 2);
                $invoiceOpen = round((float) ($invoice->valor_em_aberto ?? $invoice->valor_total), 2);

                if ($value !== null && abs($invoiceOpen - $value) <= 0.01) {
                    $score += 55;
                    $reasons[] = 'valor_em_aberto';
                } elseif ($value !== null && abs($invoiceTotal - $value) <= 0.01) {
                    $score += 40;
                    $reasons[] = 'valor_total';
                }

                if ($periodMonth !== null) {
                    $invoiceMonth = $invoice->mes
                        ? Carbon::parse($invoice->mes.'-01')->format('Y-m')
                        : optional($invoice->data_fatura)?->format('Y-m');

                    if ($invoiceMonth === $periodMonth) {
                        $score += 30;
                        $reasons[] = 'mes_periodo';
                    }
                }

                if ($invoice->estado_pagamento === 'parcial') {
                    $score += 5;
                }

                return [
                    'model' => $invoice,
                    'score' => $score,
                    'reason' => implode(',', $reasons),
                ];
            })
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->values();
    }

    private function selectBestCandidate(Collection $candidates): ?array
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates->first();
        $second = $candidates->skip(1)->first();

        if ($best['score'] < 55) {
            return null;
        }

        if ($second !== null && ($best['score'] - $second['score']) < 10) {
            return null;
        }

        return $best;
    }

    private function serializeCandidate(array $candidate): array
    {
        $model = $candidate['model'];

        return [
            'id' => $model->id,
            'score' => $candidate['score'],
            'reason' => $candidate['reason'],
            'label' => $model instanceof User
                ? ($model->nome_completo ?? $model->name ?? $model->id)
                : (($model->tipo ?? 'fatura').' '.($model->mes ?? $model->id)),
        ];
    }

    private function resolvePeriodMonth(ReceiptImportItem $item): ?string
    {
        if ($item->extracted_period_start) {
            return $item->extracted_period_start->format('Y-m');
        }

        if ($item->recibo_emitido_em) {
            return $item->recibo_emitido_em->format('Y-m');
        }

        $label = trim((string) ($item->extracted_period_label ?? ''));
        if ($label === '') {
            return null;
        }

        if (preg_match('/(20\d{2})[-\/](0[1-9]|1[0-2])/', $label, $matches)) {
            return $matches[1].'-'.$matches[2];
        }

        return null;
    }

    private function normalizeName(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return trim($normalized);
    }
}