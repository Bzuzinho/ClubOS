<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\User;
use App\Services\Desportivo\SportsMemberStatusResolver;
use App\Services\Members\MemberTypeResolver;

final class MemberMonthlyFeeEligibilityService
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
        private readonly SportsMemberStatusResolver $sportsMemberStatusResolver,
    ) {
    }

    public function shouldHaveMonthlyFee(User $user): bool
    {
        return (bool) ($this->evaluate($user)['should_have_monthly_fee'] ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluate(User $user): array
    {
        $memberTypes = $this->memberTypeResolver->typesFor($user);
        $state = $this->normalizeNullableString($user->estado);
        $activeSports = $this->sportsMemberStatusResolver->sportsActivityActive($user);
        $isAthlete = $this->memberTypeResolver->isAthlete($user);
        $eligibleTypes = $this->eligibleMemberTypes();
        $athleteEligibilityEnabled = in_array('atleta', $eligibleTypes, true);

        $reasonCodes = [];
        $shouldHave = false;

        if ($isAthlete && $athleteEligibilityEnabled) {
            $reasonCodes[] = 'eligible_member_type';

            if ($state !== 'ativo') {
                $reasonCodes[] = 'inactive_member';
            } elseif (! $activeSports) {
                $reasonCodes[] = 'inactive_sports_athlete';
            } else {
                $reasonCodes[] = 'active_sports_athlete';
                $shouldHave = true;
            }
        } else {
            if ($state !== 'ativo') {
                $reasonCodes[] = 'inactive_member';
            }

            if ($memberTypes === []) {
                $reasonCodes[] = 'missing_operational_type';
            }

            $reasonCodes[] = 'no_monthly_fee_eligible_member_type';
        }

        return [
            'should_have_monthly_fee' => $shouldHave,
            'reason_codes' => $this->uniqueReasonCodes($reasonCodes),
            'member_types' => $memberTypes,
            'state' => $state,
            'active_sports' => $activeSports,
            'eligible_member_types' => $eligibleTypes,
        ];
    }

    /**
     * @return list<string>
     */
    private function eligibleMemberTypes(): array
    {
        $configured = config('clubos.financeiro.monthly_fee_eligible_member_types', ['atleta']);
        $types = is_array($configured) ? $configured : [$configured];

        return collect($types)
            ->map(fn (mixed $type): string => $this->memberTypeResolver->normalizeType((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function uniqueReasonCodes(array $codes): array
    {
        return array_values(array_unique(array_values(array_filter($codes, static fn (mixed $code): bool => is_string($code) && trim($code) !== ''))));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
