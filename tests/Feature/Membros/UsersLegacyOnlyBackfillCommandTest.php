<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UsersLegacyOnlyBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_anything(): void
    {
        $user = $this->createUserWithPersonalRow([
            'estado_civil' => 'solteiro',
            'numero_irmaos' => 2,
            'nome_completo' => 'Dry Run User',
        ], [
            'nome_completo' => 'Dry Run User',
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
        $this->createUserWithPersonalRow([
            'estado_civil' => 'casado',
            'nome_completo' => 'Commit Blocked User',
        ], [
            'nome_completo' => 'Commit Blocked User',
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--commit' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Escrita bloqueada', Artisan::output());
    }

    public function test_commit_with_confirmation_writes_only_empty_targets(): void
    {
        $targetEmpty = $this->createUserWithPersonalRow([
            'estado_civil' => 'uniao_de_facto',
            'nome_completo' => 'Target Empty',
        ], [
            'nome_completo' => 'Target Empty',
        ]);

        $targetFilled = $this->createUserWithPersonalRow([
            'estado_civil' => 'casado',
            'nome_completo' => 'Target Filled',
        ], [
            'nome_completo' => 'Target Filled',
            'estado_civil' => 'casado',
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $emptyRow = DadosPessoais::query()->where('user_id', $targetEmpty->id)->first();
        $filledRow = DadosPessoais::query()->where('user_id', $targetFilled->id)->first();

        $this->assertSame('uniao_de_facto', $emptyRow?->estado_civil);
        $this->assertSame('casado', $filledRow?->estado_civil);
    }

    public function test_numero_irmaos_backfill_normalizes_integer_when_safe(): void
    {
        $user = $this->createUserWithPersonalRow([
            'numero_irmaos' => '2',
            'nome_completo' => 'Irmaos User',
        ], [
            'nome_completo' => 'Irmaos User',
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'numero_irmaos',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $row = DadosPessoais::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, $row?->numero_irmaos);

        $fresh = User::query()->findOrFail($user->id);
        $this->assertSame(2, (int) $fresh->numero_irmaos);
        $this->assertSame('Irmaos User', $row?->nome_completo);
    }

    public function test_commit_is_allowed_with_personal_candidates_even_when_data_atestado_missing_target_is_skipped(): void
    {
        $estadoCivilUser = $this->createUserWithPersonalRow([
            'estado_civil' => 'casado',
            'nome_completo' => 'Estado Civil Candidate',
        ], [
            'nome_completo' => 'Estado Civil Candidate',
        ]);

        $numeroIrmaosUser = $this->createUserWithPersonalRow([
            'numero_irmaos' => 2,
            'nome_completo' => 'Numero Irmaos Candidate',
        ], [
            'nome_completo' => 'Numero Irmaos Candidate',
        ]);

        $medicalSkipUser = $this->createUserWithoutPersonalRow([
            'data_atestado_medico' => '2025-03-10',
            'nome_completo' => 'Medical Skip Candidate',
            'ativo_desportivo' => false,
        ]);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutputJson();
        $this->assertSame(0, (int) ($payload['summary']['total_divergent_count'] ?? -1));
        $this->assertSame(2, (int) ($payload['summary']['total_updated_count'] ?? -1));
        $this->assertSame(1, (int) ($payload['summary']['total_skipped_missing_target_count'] ?? -1));
        $this->assertTrue((bool) ($payload['summary']['commit_allowed'] ?? false));

        $this->assertSame('casado', DadosPessoais::query()->where('user_id', $estadoCivilUser->id)->value('estado_civil'));
        $this->assertSame(2, (int) DadosPessoais::query()->where('user_id', $numeroIrmaosUser->id)->value('numero_irmaos'));

        $sports = AthleteSportsData::query()->where('user_id', $medicalSkipUser->id)->first();
        $this->assertNull($sports);
    }

    public function test_data_atestado_medico_backfills_when_safe_target_exists(): void
    {
        $user = $this->createUserWithoutPersonalRow([
            'data_atestado_medico' => '2025-05-10',
            'nome_completo' => 'Sports User',
            'ativo_desportivo' => true,
        ]);

        AthleteSportsData::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
        $this->createUserWithoutPersonalRow([
            'data_atestado_medico' => '2025-02-01',
            'nome_completo' => 'Inactive User',
            'ativo_desportivo' => false,
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
        $this->assertFalse((bool) ($payload['summary']['commit_allowed'] ?? true));
    }

    public function test_divergence_is_reported_and_not_overwritten(): void
    {
        $user = $this->createUserWithPersonalRow([
            'estado_civil' => 'solteiro',
            'nome_completo' => 'Divergent User',
        ], [
            'nome_completo' => 'Divergent User',
            'estado_civil' => 'casado',
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
        $this->assertSame('casado', DadosPessoais::query()->where('user_id', $user->id)->value('estado_civil'));
    }

    public function test_second_execution_is_idempotent(): void
    {
        $user = $this->createUserWithPersonalRow([
            'estado_civil' => 'viuvo',
            'nome_completo' => 'Idempotent User',
        ], [
            'nome_completo' => 'Idempotent User',
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
        $this->assertSame('viuvo', DadosPessoais::query()->where('user_id', $user->id)->value('estado_civil'));
    }

    public function test_field_option_limits_the_backfill_scope(): void
    {
        $user = $this->createUserWithPersonalRow([
            'estado_civil' => 'casado',
            'numero_irmaos' => 4,
            'nome_completo' => 'Scoped User',
        ], [
            'nome_completo' => 'Scoped User',
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $payload = DadosPessoais::query()->where('user_id', $user->id)->first();
        $this->assertSame('casado', $payload?->estado_civil);
        $this->assertNull($payload?->numero_irmaos);
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
        $this->createUserWithPersonalRow([
            'estado_civil' => 'solteiro',
            'nome_completo' => 'Report User',
        ], [
            'nome_completo' => 'Report User',
        ]);

        $relativePath = 'storage/app/audits/test-users-legacy-only-backfill.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('members:backfill-users-legacy-only-fields', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = $this->decodeOutputJson();
        $this->assertSame('M4.17', $payload['version'] ?? null);
        $this->assertTrue(file_exists($absolutePath));
        unlink($absolutePath);
    }

    public function test_commit_creates_personal_row_when_missing_and_does_not_mutate_users_legacy_columns(): void
    {
        $user = $this->createUserWithoutPersonalRow([
            'estado_civil' => 'casado',
            'numero_irmaos' => 3,
            'nome_completo' => 'Payload Preserve',
        ]);

        Artisan::call('members:backfill-users-legacy-only-fields', [
            '--field' => 'estado_civil',
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $fresh = User::query()->findOrFail($user->id);
        $row = DadosPessoais::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('casado', $row?->estado_civil);
        $this->assertNull($row?->nome_completo);
        $this->assertSame('casado', $fresh->estado_civil);
        $this->assertSame(3, (int) $fresh->numero_irmaos);
    }

    public function test_migration_adds_estado_civil_and_numero_irmaos_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('dados_pessoais', 'estado_civil'));
        $this->assertTrue(Schema::hasColumn('dados_pessoais', 'numero_irmaos'));
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function createUserWithPersonalRow(array $userAttributes, array $personalAttributes = []): User
    {
        $userPayload = $this->filterUserAttributes($userAttributes);
        $personalRow = $personalAttributes === [] ? $this->filterPersonalAttributes($userAttributes) : $this->filterPersonalAttributes($personalAttributes);

        $user = User::factory()->create(array_merge([
            'ativo_desportivo' => $userAttributes['ativo_desportivo'] ?? false,
        ], $userPayload));

        $this->ensurePersonalRow($user->id, $personalRow);

        return $user;
    }

    private function createUserWithoutPersonalRow(array $attributes): User
    {
        return User::factory()->create(array_merge([
            'ativo_desportivo' => $attributes['ativo_desportivo'] ?? false,
        ], $this->filterUserAttributes($attributes)));
    }

    /**
     * @return array<string,mixed>
     */
    private function ensurePersonalRow(string $userId, array $attributes): void
    {
        $row = DadosPessoais::query()->firstOrNew(['user_id' => $userId]);
        if (!$row->exists) {
            $row->id = (string) \Illuminate\Support\Str::uuid();
            $row->user_id = $userId;
        }

        foreach ($this->filterPersonalAttributes($attributes) as $key => $value) {
            $row->{$key} = $value;
        }

        $row->save();
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function filterUserAttributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (str_starts_with((string) $key, 'target_row_')) {
                continue;
            }

            if ($key === 'data_atestado_medico' || Schema::hasColumn('users', (string) $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function filterPersonalAttributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (Schema::hasColumn('dados_pessoais', (string) $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
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
