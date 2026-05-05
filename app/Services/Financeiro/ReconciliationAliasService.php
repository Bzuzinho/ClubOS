<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationAlias;
use App\Models\BankStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ReconciliationAliasService
{
    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
    ) {
    }

    public function createAlias(array $data): BankReconciliationAlias
    {
        $normalizedValue = $this->normalizer->normalize($data['normalized_value'] ?? $data['value'] ?? null);

        $alias = BankReconciliationAlias::query()
            ->where('user_id', $data['user_id'] ?? null)
            ->where('family_id', $data['family_id'] ?? null)
            ->where('type', $data['type'])
            ->where('normalized_value', $normalizedValue)
            ->first();

        if ($alias) {
            $updates = [
                'value' => $data['value'],
                'normalized_value' => $normalizedValue,
                'is_confirmed' => $data['is_confirmed'] ?? $alias->is_confirmed,
                'confidence' => $data['confidence'] ?? $alias->confidence,
                'source' => $data['source'] ?? $alias->source,
                'last_matched_at' => $data['last_matched_at'] ?? $alias->last_matched_at,
                'created_by' => $data['created_by'] ?? $alias->created_by,
            ];

            if (array_key_exists('match_count', $data)) {
                $updates['match_count'] = $data['match_count'];
            }

            $alias->fill($updates);
            $alias->save();

            return $alias;
        }

        return BankReconciliationAlias::create([
            'user_id' => $data['user_id'] ?? null,
            'family_id' => $data['family_id'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'normalized_value' => $normalizedValue,
            'is_confirmed' => $data['is_confirmed'] ?? false,
            'confidence' => $data['confidence'] ?? 50,
            'source' => $data['source'] ?? 'manual',
            'last_matched_at' => $data['last_matched_at'] ?? null,
            'match_count' => $data['match_count'] ?? 0,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    public function suggestFromConfirmedReconciliation($bankStatement, $userId, $familyId = null, $createdBy = null): ?BankReconciliationAlias
    {
        if (!$bankStatement instanceof BankStatement || !$userId) {
            return null;
        }

        $value = $this->resolveStatementAliasValue($bankStatement);
        $normalizedValue = $this->normalizer->normalize($value);

        if ($normalizedValue === '') {
            return null;
        }

        $resolvedFamilyId = $familyId ?: $this->resolveFamilyId($userId);

        return $this->createAlias([
            'user_id' => $userId,
            'family_id' => $resolvedFamilyId,
            'type' => 'description_text',
            'value' => $value,
            'normalized_value' => $normalizedValue,
            'is_confirmed' => false,
            'confidence' => 50,
            'source' => 'learned_from_reconciliation',
            'created_by' => $createdBy,
        ]);
    }

    public function findPossibleMatches(string $bankDescription, ?float $amount = null): Collection
    {
        $normalizedDescription = $this->normalizer->normalize($bankDescription);

        if ($normalizedDescription === '') {
            return new Collection();
        }

        return BankReconciliationAlias::query()
            ->with(['user', 'family'])
            ->get()
            ->filter(function (BankReconciliationAlias $alias) use ($normalizedDescription) {
                if ($alias->normalized_value === '') {
                    return false;
                }

                return str_contains($normalizedDescription, $alias->normalized_value)
                    || str_contains($alias->normalized_value, $normalizedDescription);
            })
            ->sortByDesc(function (BankReconciliationAlias $alias) {
                return ($alias->is_confirmed ? 1000 : 0) + $alias->confidence + $alias->match_count;
            })
            ->values();
    }

    private function resolveStatementAliasValue(BankStatement $bankStatement): string
    {
        return trim((string) ($bankStatement->descricao ?: $bankStatement->referencia ?: ''));
    }

    private function resolveFamilyId(string $userId): ?string
    {
        $user = User::query()->with('families:id')->find($userId);

        return $user?->families->first()?->id;
    }
}