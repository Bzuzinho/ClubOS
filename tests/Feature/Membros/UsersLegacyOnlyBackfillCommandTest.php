<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UsersLegacyOnlyBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_anything(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'estado_civil' => 'solteiro',
            'numero_irmaos' => 2,
            'dados_pessoais' => ['nome_completo' => 'Dry Run User'],
        ]);

        $before = $this->snapshotUserAndSports($user->id);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $this->snapshotUserAndSports($user->id));

        $payload = $this->decodeOutputJson();
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame(0, (int) ($payload['summary']['total_updated_count'] ?? -1));
    }

    public function test_commit_without_confirmation_fails(): void
    {
        $this->createUserWithPersonalPayload([
            'estado_civil' => 'casado',
            'dados_pessoais' => [],
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--commit' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Escrita bloqueada', Artisan::output());
    }

    public function test_commit_with_confirmation_writes_only_empty_targets(): void
    {
        $targetEmpty = $this->createUserWithPersonalPayload([
            'estado_civil' => 'uniao_de_facto',
            'dados_pessoais' => ['nome_completo' => 'Target Empty'],
        ]);

        $targetFilled = $this->createUserWithPersonalPayload([
            'estado_civil' => 'casado',
            'dados_pessoais' => ['nome_completo' => 'Target Filled', 'estado_civil' => 'casado'],
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $emptyPayload = $this->readPersonalPayload($targetEmpty->id);
        $filledPayload = $this->readPersonalPayload($targetFilled->id);

        $this->assertSame('uniao_de_facto', $emptyPayload['estado_civil'] ?? null);
        $this->assertSame('casado', $filledPayload['estado_civil'] ?? null);
    }

    public function test_numero_irmaos_backfill_normalizes_integer_when_safe(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'numero_irmaos' => '2',
            'dados_pessoais' => ['nome_completo' => 'Irmaos User'],
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'numero_irmaos',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $payload = $this->readPersonalPayload($user->id);
        $this->assertSame(2, $payload['numero_irmaos'] ?? null);
    }

    public function test_data_atestado_medico_backfills_when_safe_target_exists(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'data_atestado_medico' => '2025-05-10',
            'dados_pessoais' => [],
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $user->id,
            'data_atestado_medico' => null,
            'ativo' => true,
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'data_atestado_medico',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $sports = AthleteSportsData::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($sports);
        $this->assertSame('2025-05-10', $sports?->data_atestado_medico?->format('Y-m-d'));
    }

    public function test_data_atestado_medico_skips_when_target_is_missing(): void
    {
        $this->createUserWithPersonalPayload([
            'data_atestado_medico' => '2025-02-01',
            'dados_pessoais' => [],
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'data_atestado_medico',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodeOutputJson();
        $this->assertSame(1, (int) ($payload['summary']['total_skipped_missing_target_count'] ?? 0));
        $this->assertSame(0, (int) ($payload['summary']['total_updated_count'] ?? 0));
    }

    public function test_divergence_is_reported_and_not_overwritten(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'estado_civil' => 'solteiro',
            'dados_pessoais' => ['estado_civil' => 'casado'],
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeOutputJson();
        $this->assertSame(1, (int) ($payload['summary']['total_divergent_count'] ?? 0));
        $this->assertSame('casado', $this->readPersonalPayload($user->id)['estado_civil'] ?? null);
    }

    public function test_second_execution_is_idempotent(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'estado_civil' => 'viuvo',
            'dados_pessoais' => [],
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $first = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(0, $first);
        $payload = $this->decodeOutputJson();
        $this->assertSame(0, (int) ($payload['summary']['total_updated_count'] ?? -1));
        $this->assertSame('viuvo', $this->readPersonalPayload($user->id)['estado_civil'] ?? null);
    }

    public function test_field_option_limits_the_backfill_scope(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'estado_civil' => 'casado',
            'numero_irmaos' => 4,
            'dados_pessoais' => [],
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $payload = $this->readPersonalPayload($user->id);
        $this->assertSame('casado', $payload['estado_civil'] ?? null);
        $this->assertArrayNotHasKey('numero_irmaos', $payload);
    }

    public function test_invalid_field_fails(): void
    {
        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'campo_invalido',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Campo invalido para backfill', Artisan::output());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $this->createUserWithPersonalPayload([
            'estado_civil' => 'solteiro',
            'dados_pessoais' => [],
        ]);

        $relativePath = 'storage/app/audits/test-users-legacy-only-backfill.json';
        $absolutePath = base_path($relativePath);
        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
                '--json' => true,
                '--report-path' => $relativePath,
            ]);

            $this->assertSame(0, $exitCode);
            $payload = $this->decodeOutputJson();
            $this->assertSame('M4.17', $payload['version'] ?? null);
            $this->assertTrue(File::exists($absolutePath));
        } finally {
            File::delete($absolutePath);
        }
    }

    public function test_personal_payload_keys_are_preserved_and_users_legacy_columns_are_unchanged(): void
    {
        $user = $this->createUserWithPersonalPayload([
            'estado_civil' => 'casado',
            'numero_irmaos' => 3,
            'dados_pessoais' => [
                'nome_completo' => 'Payload Preserve',
                'contacto' => '910000000',
            ],
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $fresh = User::query()->findOrFail($user->id);
        $payload = $this->readPersonalPayload($user->id);

        $this->assertSame('Payload Preserve', $payload['nome_completo'] ?? null);
        $this->assertSame('910000000', $payload['contacto'] ?? null);
        $this->assertSame('casado', $payload['estado_civil'] ?? null);
        $this->assertSame('casado', $fresh->estado_civil);
        $this->assertSame(3, (int) $fresh->numero_irmaos);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function createUserWithPersonalPayload(array $attributes): User
    {
        $this->ensureUsersPersonalPayloadColumn();

        $payload = $attributes['dados_pessoais'] ?? [];
        unset($attributes['dados_pessoais']);

        $user = User::factory()->create(array_merge([
            'dados_pessoais' => json_encode($payload),
        ], $attributes));

        return $user;
    }

    private function ensureUsersPersonalPayloadColumn(): void
    {
        if (Schema::hasColumn('users', 'dados_pessoais')) {
            return;
        }

        Schema::table('users', function ($table): void {
            $table->json('dados_pessoais')->nullable();
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function readPersonalPayload(string $userId): array
    {
        $raw = User::query()->whereKey($userId)->value('dados_pessoais');

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotUserAndSports(string $userId): array
    {
        $user = User::query()->whereKey($userId)->first();

        return [
            'user' => $user?->getAttributes() ?? [],
            'athlete' => AthleteSportsData::query()->where('user_id', $userId)->first()?->getAttributes() ?? [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeOutputJson(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
