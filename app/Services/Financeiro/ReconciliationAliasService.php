<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationAlias;
use App\Models\BankStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ReconciliationAliasService
{
    public const DISABLED_SOURCE_PREFIX = 'disabled::';

    private const GENERIC_ALIAS_TOKENS = [
        'A',
        'AO',
        'COM',
        'DA',
        'DE',
        'DO',
        'DOS',
        'DAS',
        'DOC',
        'DOCUMENTO',
        'ID',
        'IBAN',
        'MB',
        'MBWAY',
        'NIF',
        'PAGAMENTO',
        'REF',
        'REFERENCIA',
        'SEPA',
        'TRF',
        'TRANSFERENCIA',
    ];

    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
        private readonly BankDescriptionParser $descriptionParser,
    ) {
    }

    public function createAlias(array $data): BankReconciliationAlias
    {
        $normalizedValue = $this->normalizer->normalize($data['normalized_value'] ?? $data['value'] ?? null);
        $normalizedAlias = $data['normalized_alias'] ?? $this->descriptionParser->normalizeAlias($data['extracted_after_de'] ?? $data['value'] ?? null);

        $alias = BankReconciliationAlias::query()
            ->where('user_id', $data['user_id'] ?? null)
            ->where('family_id', $data['family_id'] ?? null)
            ->where('type', $data['type'])
            ->where(function ($query) use ($normalizedValue, $normalizedAlias): void {
                $query->where('normalized_value', $normalizedValue);

                if ($normalizedAlias !== '') {
                    $query->orWhere('normalized_alias', $normalizedAlias);
                }
            })
            ->first();

        if ($alias) {
            $updates = [
                'value' => $data['value'],
                'raw_description' => $data['raw_description'] ?? $alias->raw_description,
                'extracted_after_de' => $data['extracted_after_de'] ?? $alias->extracted_after_de,
                'normalized_value' => $normalizedValue,
                'normalized_alias' => $normalizedAlias !== '' ? $normalizedAlias : $alias->normalized_alias,
                'is_confirmed' => $data['is_confirmed'] ?? $alias->is_confirmed,
                'confidence' => $data['confidence'] ?? $alias->confidence,
                'confidence_score' => $data['confidence_score'] ?? $alias->confidence_score,
                'source' => $data['source'] ?? $alias->source,
                'last_matched_at' => $data['last_matched_at'] ?? $alias->last_matched_at,
                'last_used_at' => $data['last_used_at'] ?? $alias->last_used_at,
                'created_by' => $data['created_by'] ?? $alias->created_by,
            ];

            if (array_key_exists('match_count', $data)) {
                $updates['match_count'] = $data['match_count'];
            }

            if (array_key_exists('usage_count', $data)) {
                $updates['usage_count'] = $data['usage_count'];
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
            'raw_description' => $data['raw_description'] ?? null,
            'extracted_after_de' => $data['extracted_after_de'] ?? null,
            'normalized_value' => $normalizedValue,
            'normalized_alias' => $normalizedAlias,
            'is_confirmed' => $data['is_confirmed'] ?? false,
            'confidence' => $data['confidence'] ?? 50,
            'confidence_score' => $data['confidence_score'] ?? ($data['confidence'] ?? 50),
            'source' => $data['source'] ?? 'manual',
            'last_matched_at' => $data['last_matched_at'] ?? null,
            'last_used_at' => $data['last_used_at'] ?? null,
            'match_count' => $data['match_count'] ?? 0,
            'usage_count' => $data['usage_count'] ?? 0,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    public function suggestFromConfirmedReconciliation($bankStatement, $userId, $familyId = null, $createdBy = null): ?BankReconciliationAlias
    {
        if (!$bankStatement instanceof BankStatement || !$userId) {
            return null;
        }

        $candidate = $this->resolveSafeAliasCandidate($bankStatement->descricao, 'description_text')
            ?? $this->resolveSafeAliasCandidate($bankStatement->referencia, 'mb_reference');

        if ($candidate === null) {
            return null;
        }

        $resolvedFamilyId = $familyId ?: $this->resolveFamilyId($userId);

        return $this->createAlias([
            'user_id' => $userId,
            'family_id' => $resolvedFamilyId,
            'type' => $candidate['type'],
            'value' => $candidate['value'],
            'normalized_value' => $candidate['normalized'],
            'is_confirmed' => false,
            'confidence' => 50,
            'source' => 'learned_from_reconciliation',
            'created_by' => $createdBy,
        ]);
    }

    public function learnFromConfirmedReconciliation(BankStatement $bankStatement, ?string $userId, ?string $familyId = null, ?string $createdBy = null): array
    {
        if (!$userId) {
            return [];
        }

        $resolvedFamilyId = $familyId ?: $this->resolveFamilyId($userId);
        $candidates = [
            ['type' => 'description_text', 'value' => $bankStatement->descricao],
            ['type' => 'mb_reference', 'value' => $bankStatement->referencia],
        ];
        $learned = [];

        foreach ($candidates as $candidate) {
            $safeCandidate = $this->resolveSafeAliasCandidate($candidate['value'] ?? null, $candidate['type']);

            if ($safeCandidate === null) {
                continue;
            }

            $value = $safeCandidate['value'];
            $normalizedValue = $safeCandidate['normalized'];

            $alias = BankReconciliationAlias::query()
                ->where('user_id', $userId)
                ->where('family_id', $resolvedFamilyId)
                ->where('type', $candidate['type'])
                ->where('normalized_value', $normalizedValue)
                ->first();

            if ($alias) {
                $alias->fill([
                    'value' => $value,
                    'last_matched_at' => now(),
                    'last_used_at' => now(),
                    'match_count' => (int) $alias->match_count + 1,
                    'usage_count' => (int) ($alias->usage_count ?? 0) + 1,
                ]);
                $alias->save();
                $learned[] = $alias->refresh();

                continue;
            }

            $learned[] = $this->createAlias([
                'user_id' => $userId,
                'family_id' => $resolvedFamilyId,
                'type' => $candidate['type'],
                'value' => $value,
                'normalized_value' => $normalizedValue,
                'is_confirmed' => false,
                'confidence' => 50,
                'confidence_score' => 50,
                'source' => 'learned_from_reconciliation',
                'last_matched_at' => now(),
                'last_used_at' => now(),
                'match_count' => 1,
                'usage_count' => 1,
                'created_by' => $createdBy,
            ]);
        }

        return $learned;
    }

    public function findPossibleMatches(string $bankDescription, ?float $amount = null): Collection
    {
        $normalizedDescription = $this->normalizer->normalize($bankDescription);
        $normalizedAlias = $this->descriptionParser->normalizeAlias(
            $this->descriptionParser->extractAliasAfterDe($bankDescription)
        );

        if ($normalizedDescription === '' && $normalizedAlias === '') {
            return new Collection();
        }

        return BankReconciliationAlias::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('source')
                    ->orWhere('source', 'not like', self::DISABLED_SOURCE_PREFIX . '%');
            })
            ->with(['user', 'family'])
            ->get()
            ->filter(function (BankReconciliationAlias $alias) use ($normalizedDescription, $normalizedAlias) {
                $normalizedValue = trim((string) $alias->normalized_value);
                $storedAlias = trim((string) ($alias->normalized_alias ?? ''));

                if ($normalizedValue === '' && $storedAlias === '') {
                    return false;
                }

                return ($normalizedValue !== '' && (
                    str_contains($normalizedDescription, $normalizedValue)
                    || str_contains($normalizedValue, $normalizedDescription)
                ))
                    || ($normalizedAlias !== '' && $storedAlias !== '' && (
                        str_contains($normalizedAlias, $storedAlias)
                        || str_contains($storedAlias, $normalizedAlias)
                    ));
            })
            ->sortByDesc(function (BankReconciliationAlias $alias) {
                return ($alias->is_confirmed ? 1000 : 0)
                    + (int) round((float) ($alias->confidence_score ?? $alias->confidence ?? 0))
                    + (int) ($alias->usage_count ?? $alias->match_count ?? 0);
            })
            ->values();
    }

    public function isAliasActive(BankReconciliationAlias $alias): bool
    {
        $source = trim((string) ($alias->source ?? ''));

        return $source === '' || !str_starts_with($source, self::DISABLED_SOURCE_PREFIX);
    }

    public function deactivateAlias(BankReconciliationAlias $alias): BankReconciliationAlias
    {
        if (!$this->isAliasActive($alias)) {
            return $alias;
        }

        $currentSource = trim((string) ($alias->source ?? 'manual'));
        $encodedSource = self::DISABLED_SOURCE_PREFIX . ($currentSource !== '' ? $currentSource : 'manual');

        $alias->forceFill([
            'source' => mb_substr($encodedSource, 0, 50),
        ])->save();

        return $alias->refresh();
    }

    public function reactivateAlias(BankReconciliationAlias $alias): BankReconciliationAlias
    {
        if ($this->isAliasActive($alias)) {
            return $alias;
        }

        $source = trim((string) ($alias->source ?? ''));
        $restoredSource = trim((string) preg_replace('/^' . preg_quote(self::DISABLED_SOURCE_PREFIX, '/') . '/', '', $source));

        $alias->forceFill([
            'source' => $restoredSource !== '' ? mb_substr($restoredSource, 0, 50) : 'manual',
        ])->save();

        return $alias->refresh();
    }

    public function normalizeSourceForDisplay(BankReconciliationAlias $alias): ?string
    {
        $source = trim((string) ($alias->source ?? ''));

        if ($source === '') {
            return null;
        }

        if (str_starts_with($source, self::DISABLED_SOURCE_PREFIX)) {
            $source = trim((string) preg_replace('/^' . preg_quote(self::DISABLED_SOURCE_PREFIX, '/') . '/', '', $source));
        }

        return $source !== '' ? $source : null;
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

    private function resolveSafeAliasCandidate(mixed $value, string $type): ?array
    {
        $rawValue = trim((string) $value);

        if ($rawValue === '') {
            return null;
        }

        if ($type === 'description_text') {
            $extractedAlias = $this->descriptionParser->extractAliasAfterDe($rawValue);
            if ($extractedAlias !== null && $this->isSpecificAliasText($extractedAlias)) {
                return [
                    'type' => $type,
                    'value' => $extractedAlias,
                    'normalized' => $this->normalizer->normalize($extractedAlias),
                ];
            }

            if (!$this->isSpecificAliasText($rawValue)) {
                return null;
            }
        } elseif (!$this->isSpecificReferenceText($rawValue)) {
            return null;
        }

        $normalized = $this->normalizer->normalize($rawValue);

        if ($normalized === '') {
            return null;
        }

        return [
            'type' => $type,
            'value' => $rawValue,
            'normalized' => $normalized,
        ];
    }

    private function isSpecificAliasText(string $value): bool
    {
        $normalized = $this->normalizer->normalize($value);

        if ($normalized === '') {
            return false;
        }

        $tokens = collect(explode(' ', $normalized))
            ->filter(fn (string $token) => $token !== '' && !in_array($token, self::GENERIC_ALIAS_TOKENS, true))
            ->values();

        return $tokens->count() >= 2;
    }

    private function isSpecificReferenceText(string $value): bool
    {
        $normalized = $this->normalizer->normalize($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::GENERIC_ALIAS_TOKENS, true)) {
            return false;
        }

        return strlen(str_replace(' ', '', $normalized)) >= 6;
    }
}