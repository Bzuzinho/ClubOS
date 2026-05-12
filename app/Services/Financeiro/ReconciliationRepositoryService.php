<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationRepository;
use App\Models\BankStatement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ReconciliationRepositoryService
{
    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
    ) {
    }

    public function storeFromConfirmedReconciliation(BankStatement $bankStatement, Payment $payment, ?string $createdBy = null): ?BankReconciliationRepository
    {
        if (!Schema::hasTable('bank_reconciliation_repositories')) {
            return null;
        }

        $signatureData = $this->buildSignatureData($bankStatement);
        if ($signatureData['signature'] === '') {
            return null;
        }

        $payment = $payment->fresh(['allocations.invoice:id,user_id', 'user.families:id']);
        $matchedUserIds = $payment?->allocations
            ?->pluck('invoice.user_id')
            ->filter()
            ->map(fn ($userId) => (string) $userId)
            ->unique()
            ->values()
            ->all() ?? [];

        if ($matchedUserIds === [] && $payment?->user_id) {
            $matchedUserIds = [(string) $payment->user_id];
        }

        $familyId = $payment?->family_id ?: $payment?->user?->families?->first()?->id;
        $primaryUserId = $payment?->user_id;

        if (!$primaryUserId && count($matchedUserIds) === 1) {
            $primaryUserId = $matchedUserIds[0];
        }

        if (!$primaryUserId && !$familyId && $matchedUserIds === []) {
            return null;
        }

        $repositoryEntry = BankReconciliationRepository::query()
            ->where('signature', $signatureData['signature'])
            ->where('family_id', $familyId)
            ->where('primary_user_id', $primaryUserId)
            ->first();

        $repositoryEntry ??= new BankReconciliationRepository();

        $mergedUserIds = collect(array_merge(
            (array) ($repositoryEntry->matched_user_ids ?? []),
            $matchedUserIds,
            $primaryUserId ? [(string) $primaryUserId] : [],
        ))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $repositoryEntry->fill([
            'signature' => $signatureData['signature'],
            'conta' => $signatureData['conta'],
            'descricao' => $bankStatement->descricao,
            'referencia' => $bankStatement->referencia,
            'normalized_description' => $signatureData['normalized_description'],
            'normalized_reference' => $signatureData['normalized_reference'],
            'primary_user_id' => $primaryUserId,
            'family_id' => $familyId,
            'matched_user_ids' => $mergedUserIds,
            'match_count' => (int) ($repositoryEntry->match_count ?? 0) + 1,
            'last_reconciled_at' => now(),
            'created_by' => $repositoryEntry->created_by ?? $createdBy,
            'metadata' => array_merge((array) ($repositoryEntry->metadata ?? []), [
                'last_bank_statement_id' => $bankStatement->id,
                'last_payment_id' => $payment?->id,
            ]),
        ]);
        $repositoryEntry->save();

        return $repositoryEntry->refresh(['primaryUser', 'family']);
    }

    public function findMatches(BankStatement $bankStatement): Collection
    {
        if (!Schema::hasTable('bank_reconciliation_repositories')) {
            return collect();
        }

        $signatureData = $this->buildSignatureData($bankStatement);
        if ($signatureData['signature'] === '') {
            return collect();
        }

        return BankReconciliationRepository::query()
            ->with(['primaryUser', 'family'])
            ->where('signature', $signatureData['signature'])
            ->orderByDesc('match_count')
            ->orderByDesc('last_reconciled_at')
            ->get();
    }

    private function buildSignatureData(BankStatement $bankStatement): array
    {
        $conta = trim((string) ($bankStatement->conta ?? ''));
        $normalizedDescription = $this->normalizer->normalize($bankStatement->descricao);
        $normalizedReference = $this->normalizer->normalize($bankStatement->referencia);
        $parts = array_values(array_filter([
            $conta,
            $normalizedDescription,
            $normalizedReference,
        ], static fn (?string $value): bool => (string) $value !== ''));

        return [
            'signature' => $parts === [] ? '' : hash('sha256', implode('|', $parts)),
            'conta' => $conta,
            'normalized_description' => $normalizedDescription,
            'normalized_reference' => $normalizedReference,
        ];
    }
}