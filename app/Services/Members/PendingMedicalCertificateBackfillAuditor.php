<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AthleteSportsData;
use App\Models\User;

final class PendingMedicalCertificateBackfillAuditor
{
    public const VERSION = 'D1-2026-07-03';

    /** @var list<string> */
    public const PENDING_USER_IDS = [
        'a19b2c5e-a890-4e19-8dc9-fe0a78525405',
        'a19b2c63-3aea-44d1-8f8b-dfcebbf5f88f',
        'a19b2c65-57de-4362-a986-0730d40d97ec',
        'a19b2c66-81d5-4291-b1a3-ba171ba47eb2',
        'a19b2c76-f19f-48a1-9953-af6e5aad2223',
    ];

    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $users = User::query()
            ->whereIn('id', self::PENDING_USER_IDS)
            ->select([
                'id',
                'name',
                'email',
                'perfil',
                'tipo_membro',
                'ativo_desportivo',
                'escalao',
                'estado',
                'data_atestado_medico',
            ])
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (User $user): string => (string) $user->getKey());

        $sportsRowsByUser = AthleteSportsData::query()
            ->whereIn('user_id', self::PENDING_USER_IDS)
            ->select(['id', 'user_id', 'data_atestado_medico', 'ativo', 'escalao_id'])
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (AthleteSportsData $row): string => (string) $row->getAttribute('user_id'));

        $cases = [];
        foreach (self::PENDING_USER_IDS as $userId) {
            $user = $users->get($userId);
            if (!$user instanceof User) {
                $cases[] = [
                    'user_id' => $userId,
                    'found' => false,
                    'classification' => 'not_found',
                    'bucket' => 'manual_review_required',
                ];

                continue;
            }

            $legacyRawDate = $user->getRawOriginal('data_atestado_medico');
            $legacyDate = $this->normalizeIsoDate($legacyRawDate);
            $legacyDateValid = $legacyDate !== null;

            $sportsRows = $sportsRowsByUser->get($userId);
            $targetRowsCount = $sportsRows?->count() ?? 0;
            $sportsRow = $sportsRows?->first();

            $targetDate = $sportsRow instanceof AthleteSportsData
                ? $this->normalizeIsoDate($sportsRow->getRawOriginal('data_atestado_medico'))
                : null;

            $isSportsMember = $this->isSportsMember($user);
            $classification = $this->classify(
                $legacyDateValid,
                $legacyDate,
                $targetRowsCount,
                $targetDate,
                $isSportsMember,
            );

            $cases[] = [
                'user_id' => $userId,
                'found' => true,
                'classification' => $classification,
                'bucket' => $this->bucketFor($classification),
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'perfil' => $user->perfil,
                    'tipo_membro' => $this->normalizeStringArray($user->tipo_membro),
                    'ativo_desportivo' => (bool) $user->ativo_desportivo,
                    'escalao' => $this->normalizeStringArray($user->escalao),
                    'estado' => $user->estado,
                    'users_data_atestado_medico' => is_scalar($legacyRawDate) ? (string) $legacyRawDate : null,
                ],
                'target' => [
                    'athlete_sports_data_exists' => $targetRowsCount > 0,
                    'athlete_sports_data_rows_count' => $targetRowsCount,
                    'athlete_sports_data_id' => $sportsRow instanceof AthleteSportsData ? (string) $sportsRow->getKey() : null,
                    'athlete_sports_data_data_atestado_medico' => $sportsRow instanceof AthleteSportsData
                        ? $sportsRow->getRawOriginal('data_atestado_medico')
                        : null,
                ],
                'validation' => [
                    'legacy_date_valid' => $legacyDateValid,
                    'legacy_date_normalized' => $legacyDate,
                    'target_date_normalized' => $targetDate,
                    'is_sports_member' => $isSportsMember,
                ],
            ];
        }

        return [
            'version' => self::VERSION,
            'scope' => [
                'pending_user_ids' => self::PENDING_USER_IDS,
                'pending_user_ids_count' => count(self::PENDING_USER_IDS),
            ],
            'summary' => [
                'users_found_count' => count(array_filter($cases, static fn (array $row): bool => (bool) ($row['found'] ?? false))),
                'users_missing_count' => count(array_filter($cases, static fn (array $row): bool => !(bool) ($row['found'] ?? false))),
                'by_classification' => $this->countBy($cases, 'classification'),
                'by_bucket' => $this->countBy($cases, 'bucket'),
            ],
            'cases' => $cases,
        ];
    }

    private function classify(
        bool $legacyDateValid,
        ?string $legacyDate,
        int $targetRowsCount,
        ?string $targetDate,
        bool $isSportsMember,
    ): string {
        if (!$legacyDateValid) {
            return 'invalid_legacy_date';
        }

        if ($targetRowsCount > 1) {
            return 'divergent';
        }

        if ($targetRowsCount === 1) {
            if ($targetDate === null) {
                return 'ready_for_backfill';
            }

            if ($legacyDate === $targetDate) {
                return 'already_matching';
            }

            return 'divergent';
        }

        if (!$isSportsMember) {
            return 'not_sports_member';
        }

        return 'missing_sports_target';
    }

    private function bucketFor(string $classification): string
    {
        return match ($classification) {
            'ready_for_backfill' => 'migravel_automaticamente',
            'invalid_legacy_date' => 'requer_correcao_manual_data',
            'not_sports_member' => 'nao_aplicavel_nao_atleta_ou_nao_ativo_desportivo',
            default => 'manual_review_required',
        };
    }

    private function isSportsMember(User $user): bool
    {
        $perfil = strtolower(trim((string) $user->perfil));
        $types = array_map('strtolower', $this->normalizeStringArray($user->tipo_membro));
        $isAthlete = $perfil === 'atleta' || in_array('atleta', $types, true);

        return $isAthlete && (bool) $user->ativo_desportivo;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, array_filter($value, static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')));
        }

        return [];
    }

    private function normalizeIsoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $year = (int) $value->format('Y');

            return $this->isAllowedYear($year)
                ? $value->format('Y-m-d')
                : null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date);
            if ($parsed instanceof \DateTimeImmutable) {
                $year = (int) $parsed->format('Y');
                if ($this->isAllowedYear($year)) {
                    return $parsed->format('Y-m-d');
                }

                return null;
            }
        }

        return null;
    }

    private function isAllowedYear(int $year): bool
    {
        return $year >= 1900 && $year <= 2100;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = is_string($row[$key] ?? null) ? (string) $row[$key] : 'unknown';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
