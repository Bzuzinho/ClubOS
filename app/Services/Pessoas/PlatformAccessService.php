<?php

declare(strict_types=1);

namespace App\Services\Pessoas;

use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
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
        $activatedAt = $this->hasActivationColumn()
            ? $configuration?->platform_access_activated_at
            : null;
        $lastInvitationAt = $configuration?->ultimo_envio_acessos_at;
        $inviteLifetimeMinutes = (int) config('auth.passwords.member_access.expire', 4320);
        $revokedAt = $configuration?->platform_access_revoked_at;
        $pendingInvitation = ! $enabled
            && $lastInvitationAt !== null
            && ($revokedAt === null || $lastInvitationAt->gt($revokedAt));
        $inviteExpired = $pendingInvitation
            && $lastInvitationAt->lt(now()->subMinutes($inviteLifetimeMinutes));

        $state = match (true) {
            $enabled => 'active',
            $inviteExpired => 'expired',
            $pendingInvitation => 'invited',
            $revokedAt !== null => 'revoked',
            default => 'not_sent',
        };

        return [
            'source' => 'dados_configuracao.platform_access_enabled',
            'schema_ready' => $schemaReady,
            'platform_access_enabled' => $enabled,
            'platform_access_granted' => $enabled,
            'platform_access_granted_reason' => $enabled ? 'explicit_platform_access_enabled' : 'no_explicit_platform_access_enabled',
            'platform_access_granted_at' => $configuration?->platform_access_granted_at?->toISOString(),
            'platform_access_activated_at' => $activatedAt?->toISOString(),
            'last_invitation_sent_at' => $lastInvitationAt?->toISOString(),
            'invitation_expires_at' => $lastInvitationAt?->copy()->addMinutes($inviteLifetimeMinutes)->toISOString(),
            'state' => $state,
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

            Password::broker('member_access')->deleteToken($user);

            return $configuration->refresh();
        });
    }

    public function recordInvitationSent(User $user, ?User $actor = null): DadosConfiguracao
    {
        return DB::transaction(function () use ($user, $actor): DadosConfiguracao {
            $configuration = DadosConfiguracao::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($configuration === null) {
                $configuration = new DadosConfiguracao(['user_id' => $user->id]);
            }

            $alreadyActive = (bool) $configuration->platform_access_enabled
                && (! $this->hasActivationColumn()
                    || $configuration->platform_access_activated_at !== null
                    || $configuration->ultimo_envio_acessos_at === null);

            $configuration->forceFill([
                'platform_access_enabled' => $this->hasActivationColumn() ? $alreadyActive : true,
                'platform_access_granted_at' => now(),
                'platform_access_granted_by' => $actor?->id,
                'platform_access_revoked_at' => null,
                'platform_access_revoked_by' => null,
                'platform_access_notes' => 'Convite enviado; o acesso fica ativo depois de a pessoa criar a palavra-passe.',
                'ultimo_envio_acessos_at' => now(),
            ])->save();

            if ($this->hasActivationColumn() && ! $alreadyActive) {
                $configuration->forceFill(['platform_access_activated_at' => null])->save();
            }

            return $configuration->refresh();
        });
    }

    public function canActivatePlatformAccess(User $user): bool
    {
        if ($this->hasPlatformAccess($user)) {
            return true;
        }

        if (! $this->hasExplicitAccessColumn()) {
            return false;
        }

        $configuration = DadosConfiguracao::query()->where('user_id', $user->id)->first();
        $lastInvitationAt = $configuration?->ultimo_envio_acessos_at;
        $revokedAt = $configuration?->platform_access_revoked_at;

        if ($lastInvitationAt === null || ($revokedAt !== null && $revokedAt->gte($lastInvitationAt))) {
            return false;
        }

        $inviteLifetimeMinutes = (int) config('auth.passwords.member_access.expire', 4320);

        return $lastInvitationAt->gte(now()->subMinutes($inviteLifetimeMinutes));
    }

    public function activatePlatformAccess(User $user): DadosConfiguracao
    {
        return DB::transaction(function () use ($user): DadosConfiguracao {
            $configuration = DadosConfiguracao::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $configuration->platform_access_enabled = true;
            if ($this->hasActivationColumn()) {
                $configuration->platform_access_activated_at = now();
            }
            $configuration->platform_access_revoked_at = null;
            $configuration->platform_access_revoked_by = null;
            $configuration->save();

            return $configuration->refresh();
        });
    }

    private function hasExplicitAccessColumn(): bool
    {
        return Schema::hasTable('dados_configuracao')
            && Schema::hasColumn('dados_configuracao', 'platform_access_enabled');
    }

    private function hasActivationColumn(): bool
    {
        return $this->hasExplicitAccessColumn()
            && Schema::hasColumn('dados_configuracao', 'platform_access_activated_at');
    }
}
