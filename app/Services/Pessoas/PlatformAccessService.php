<?php

declare(strict_types=1);

namespace App\Services\Pessoas;

use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PlatformAccessService
{
    public function hasPlatformAccess(User $user): bool
    {
        if (! $this->hasExplicitAccessColumn()) {
            return false;
        }

        return DadosConfiguracao::query()
            ->where('user_id', $user->id)
            ->where('platform_access_enabled', true)
            ->exists();
    }

    /**
     * @return array<string,mixed>
     */
    public function explainPlatformAccess(User $user): array
    {
        $schemaReady = $this->hasExplicitAccessColumn();
        $configuration = $schemaReady
            ? DadosConfiguracao::query()->where('user_id', $user->id)->first()
            : null;
        $enabled = $configuration !== null && (bool) $configuration->platform_access_enabled;

        return [
            'source' => 'dados_configuracao.platform_access_enabled',
            'schema_ready' => $schemaReady,
            'platform_access_enabled' => $enabled,
            'platform_access_granted' => $enabled,
            'platform_access_granted_reason' => $enabled ? 'explicit_platform_access_enabled' : 'no_explicit_platform_access_enabled',
            'platform_access_granted_at' => $configuration?->platform_access_granted_at?->toISOString(),
            'platform_access_granted_by' => $configuration?->platform_access_granted_by,
            'platform_access_revoked_at' => $configuration?->platform_access_revoked_at?->toISOString(),
            'platform_access_revoked_by' => $configuration?->platform_access_revoked_by,
            'platform_access_notes' => $configuration?->platform_access_notes,
        ];
    }

    public function grantPlatformAccess(User $user, ?User $actor = null, ?string $notes = null): DadosConfiguracao
    {
        return DB::transaction(function () use ($user, $actor, $notes): DadosConfiguracao {
            $configuration = DadosConfiguracao::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($configuration === null) {
                $configuration = new DadosConfiguracao(['user_id' => $user->id]);
            }

            $configuration->platform_access_enabled = true;
            $configuration->platform_access_granted_at = now();
            $configuration->platform_access_granted_by = $actor?->id;
            $configuration->platform_access_revoked_at = null;
            $configuration->platform_access_revoked_by = null;
            $configuration->platform_access_notes = $notes;
            $configuration->save();

            return $configuration->refresh();
        });
    }

    public function revokePlatformAccess(User $user, ?User $actor = null, ?string $notes = null): DadosConfiguracao
    {
        return DB::transaction(function () use ($user, $actor, $notes): DadosConfiguracao {
            $configuration = DadosConfiguracao::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($configuration === null) {
                $configuration = new DadosConfiguracao(['user_id' => $user->id]);
            }

            $configuration->platform_access_enabled = false;
            $configuration->platform_access_revoked_at = now();
            $configuration->platform_access_revoked_by = $actor?->id;
            $configuration->platform_access_notes = $notes;
            $configuration->save();

            return $configuration->refresh();
        });
    }

    private function hasExplicitAccessColumn(): bool
    {
        return Schema::hasTable('dados_configuracao')
            && Schema::hasColumn('dados_configuracao', 'platform_access_enabled');
    }
}
