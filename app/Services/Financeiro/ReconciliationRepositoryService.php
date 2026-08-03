<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationRepository;
use App\Models\BankStatement;
use App\Models\Familia;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ReconciliationRepositoryService
{
    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
        private readonly BankDescriptionParser $descriptionParser,
    ) {
    }

    public function storeFromConfirmedReconciliation(BankStatement $bankStatement, Payment $payment, ?string $createdBy = null): ?BankReconciliationRepository
    {
        if (!Schema::hasTable('bank_reconciliation_repositories')) {
            return null;
        }

        $signatureData = $this->buildSignatureData($bankStatement);
        if ($signatureData['description_key'] === '') {
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

        if (!$familyId && $matchedUserIds !== []) {
            $commonFamilyIds = User::query()
                ->with('families:id,responsavel_user_id')
                ->whereIn('id', $matchedUserIds)
                ->get()
                ->map(fn (User $user): array => $user->families->pluck('id')->all())
                ->reduce(
                    fn (?array $commonIds, array $memberFamilyIds): array => $commonIds === null
                        ? $memberFamilyIds
                        : array_values(array_intersect($commonIds, $memberFamilyIds)),
                    null,
                ) ?? [];

            if (count($commonFamilyIds) === 1) {
                $familyId = (string) $commonFamilyIds[0];
            }
        }

        if (!$primaryUserId && $familyId) {
            $primaryUserId = Familia::query()
                ->whereKey($familyId)
                ->value('responsavel_user_id');
        }

        if (!$primaryUserId && count($matchedUserIds) === 1) {
            $primaryUserId = $matchedUserIds[0];
        }

        if (!$primaryUserId && !$familyId && $matchedUserIds === []) {
            return null;
        }

        $repositoryEntry = BankReconciliationRepository::query()
            ->where('family_id', $familyId)
            ->where('primary_user_id', $primaryUserId)
            ->get()
            ->first(fn (BankReconciliationRepository $entry): bool =>
                $this->entryMatchesDescription($entry, $signatureData)
            );

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
                'learned_description_key' => $signatureData['description_key'],
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
        if ($signatureData['description_key'] === '') {
            return collect();
        }

        $query = BankReconciliationRepository::query()
            ->with(['primaryUser', 'family'])
            ->when(
                $signatureData['conta'] !== '',
                fn ($accountQuery) => $accountQuery->where(function ($nestedAccountQuery) use ($signatureData): void {
                    $nestedAccountQuery
                        ->where('conta', $signatureData['conta'])
                        ->orWhereNull('conta');
                }),
            )
            ->orderByDesc('match_count')
            ->orderByDesc('last_reconciled_at')
            ->get();

        $matches = $query
            ->filter(fn (BankReconciliationRepository $entry): bool =>
                $this->entryMatchesDescription($entry, $signatureData)
            )
            ->values();

        $candidateUserIds = $matches
            ->flatMap(fn (BankReconciliationRepository $entry): array => array_merge(
                $entry->primary_user_id ? [(string) $entry->primary_user_id] : [],
                array_map('strval', (array) ($entry->matched_user_ids ?? [])),
            ))
            ->filter()
            ->unique()
            ->values();
        $validUserIds = User::query()
            ->whereIn('id', $candidateUserIds)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->flip();
        $candidateFamilyIds = $matches->pluck('family_id')->filter()->unique()->values();
        $validFamilyIds = Familia::query()
            ->whereIn('id', $candidateFamilyIds)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->flip();

        return $matches
            ->map(function (BankReconciliationRepository $entry) use ($validUserIds, $validFamilyIds): BankReconciliationRepository {
                $validMatchedUserIds = collect((array) ($entry->matched_user_ids ?? []))
                    ->map(fn ($id): string => (string) $id)
                    ->filter(fn (string $id): bool => $validUserIds->has($id))
                    ->unique()
                    ->values()
                    ->all();

                if ($entry->primary_user_id && !$validUserIds->has((string) $entry->primary_user_id)) {
                    $entry->setAttribute('primary_user_id', null);
                }
                if ($entry->family_id && !$validFamilyIds->has((string) $entry->family_id)) {
                    $entry->setAttribute('family_id', null);
                }

                $entry->setAttribute('matched_user_ids', $validMatchedUserIds);

                return $entry;
            })
            ->filter(fn (BankReconciliationRepository $entry): bool =>
                $entry->primary_user_id !== null
                || $entry->family_id !== null
                || (array) $entry->matched_user_ids !== []
            )
            ->values();
    }

    public function forgetFromUnreconciledPayment(BankStatement $bankStatement, Payment $payment): void
    {
        if (!Schema::hasTable('bank_reconciliation_repositories')) {
            return;
        }

        $signatureData = $this->buildSignatureData($bankStatement);

        BankReconciliationRepository::query()
            ->get()
            ->filter(fn (BankReconciliationRepository $entry): bool =>
                $this->entryMatchesDescription($entry, $signatureData)
                && (string) data_get($entry->metadata, 'last_bank_statement_id') === (string) $bankStatement->id
                && (string) data_get($entry->metadata, 'last_payment_id') === (string) $payment->id
            )
            ->each(function (BankReconciliationRepository $entry): void {
                if ((int) $entry->match_count <= 1) {
                    $entry->delete();

                    return;
                }

                $metadata = (array) ($entry->metadata ?? []);
                unset($metadata['last_bank_statement_id'], $metadata['last_payment_id']);

                $entry->forceFill([
                    'match_count' => max((int) $entry->match_count - 1, 1),
                    'metadata' => $metadata,
                ])->save();
            });
    }

    private function buildSignatureData(BankStatement $bankStatement): array
    {
        $conta = trim((string) ($bankStatement->conta ?? ''));
        $normalizedDescription = $this->normalizer->normalize($bankStatement->descricao);
        $normalizedReference = $this->normalizer->normalize($bankStatement->referencia);
        $descriptionKey = $this->descriptionKey((string) ($bankStatement->descricao ?? ''));
        $parts = array_values(array_filter([
            $conta,
            $descriptionKey,
        ], static fn (?string $value): bool => (string) $value !== ''));

        return [
            'signature' => $parts === [] ? '' : hash('sha256', implode('|', $parts)),
            'conta' => $conta,
            'normalized_description' => $normalizedDescription,
            'normalized_reference' => $normalizedReference,
            'description_key' => $descriptionKey,
        ];
    }

    private function entryMatchesDescription(BankReconciliationRepository $entry, array $signatureData): bool
    {
        $currentKey = (string) ($signatureData['description_key'] ?? '');
        if ($currentKey === '') {
            return false;
        }

        $storedKey = trim((string) data_get($entry->metadata, 'learned_description_key'));
        if ($storedKey === '') {
            $storedKey = $this->descriptionKey((string) ($entry->descricao ?? ''));
        }

        return $storedKey !== '' && $storedKey === $currentKey;
    }

    private function descriptionKey(string $description): string
    {
        $payerDescription = $this->descriptionParser->extractAliasAfterDe($description);

        return $this->normalizer->normalize($payerDescription ?: $description);
    }
}
