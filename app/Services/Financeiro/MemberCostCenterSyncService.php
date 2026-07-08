<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MemberCostCenterSyncService
{
    /**
     * @param array<int, mixed> $centros
     * @return list<string>
     */
    public function sync(User $member, array $centros): array
    {
        $normalized = $this->normalize($centros);
        $now = now();

        DB::table('centro_custo_user')->where('user_id', $member->id)->delete();

        foreach ($normalized as $row) {
            DB::table('centro_custo_user')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $member->id,
                'centro_custo_id' => $row['id'],
                'peso' => $row['peso'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $member->unsetRelation('centrosCusto');

        return array_values(array_map(static fn (array $row): string => (string) $row['id'], $normalized));
    }

    /**
     * @param array<int, mixed> $centros
     * @return list<array{id:string,peso:float}>
     */
    public function normalize(array $centros): array
    {
        $syncData = [];

        foreach ($centros as $center) {
            if (is_array($center)) {
                $centerId = $center['id'] ?? null;
                $peso = isset($center['peso']) ? (float) $center['peso'] : 1.0;
            } else {
                $centerId = $center;
                $peso = 1.0;
            }

            if ($centerId) {
                $syncData[(string) $centerId] = [
                    'id' => (string) $centerId,
                    'peso' => $peso > 0 ? $peso : 1.0,
                ];
            }
        }

        return array_values($syncData);
    }
}