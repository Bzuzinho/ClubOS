<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyOnlyBackfillPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_without_commit_passes_and_returns_json(): void
    {
        $user = $this->createFixtureUser();

        $payload = $this->runJsonCommand();

        $this->assertSame('M4.14', $payload['version'] ?? null);
        $this->assertSame('preflight', $payload['mode'] ?? null);
        $this->assertFalse((bool) ($payload['writable'] ?? true));
        $this->assertFalse((bool) ($payload['commit_allowed'] ?? true));
        $this->assertTrue((bool) ($payload['summary']['passed'] ?? false));
        $this->assertNull($payload['summary']['failure_reason'] ?? null);

        $this->assertLegacyUserUnchanged($user);
    }

    public function test_commit_without_confirmation_fails(): void
    {
        $this->createFixtureUser();

        $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', [
            '--commit' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Commit bloqueado nesta sprint', Artisan::output());
    }

    public function test_commit_with_confirmation_also_fails(): void
    {
        $this->createFixtureUser();

        $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', [
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('nao permite escrita', Artisan::output());
    }

    public function test_field_option_limits_analysis(): void
    {
        $this->createFixtureUser();

        $payload = $this->runJsonCommand(['--field' => 'data_atestado_medico']);

        $this->assertSame(1, (int) ($payload['summary']['fields_analyzed'] ?? 0));
        $this->assertCount(1, $payload['fields'] ?? []);
        $this->assertSame('data_atestado_medico', $payload['fields'][0]['field'] ?? null);
    }

    public function test_invalid_field_fails(): void
    {
        $this->createFixtureUser();

        $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', [
            '--field' => 'invalid_field',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Campo invalido para preflight', Artisan::output());
    }

    public function test_report_path_writes_json_report(): void
    {
        $this->createFixtureUser();

        $relativePath = 'storage/app/audits/m4-14-preflight-users-legacy-only.json';
        $absolutePath = base_path($relativePath);

        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', [
                '--json' => true,
                '--report-path' => $relativePath,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertTrue(File::exists($absolutePath));

            $decoded = json_decode((string) File::get($absolutePath), true);
            $this->assertIsArray($decoded);
            $this->assertSame('M4.14', $decoded['version'] ?? null);
        } finally {
            File::delete($absolutePath);
        }
    }

    public function test_diagnostics_identify_all_three_fields_and_blocks(): void
    {
        $this->createFixtureUser();

        $payload = $this->runJsonCommand();

        $fieldsByName = [];
        foreach ($payload['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['field'])) {
                $fieldsByName[$field['field']] = $field;
            }
        }

        $this->assertArrayHasKey('data_atestado_medico', $fieldsByName);
        $this->assertArrayHasKey('estado_civil', $fieldsByName);
        $this->assertArrayHasKey('numero_irmaos', $fieldsByName);
        $this->assertSame('canonical_target_requires_architecture_decision', $fieldsByName['data_atestado_medico']['canonical_target_status'] ?? null);
        $this->assertSame('canonical_target_defined_but_write_blocked', $fieldsByName['estado_civil']['canonical_target_status'] ?? null);
        $this->assertSame('canonical_target_missing_or_not_versioned', $fieldsByName['numero_irmaos']['canonical_target_status'] ?? null);
        $this->assertSame('route_to_sports_domain', $fieldsByName['data_atestado_medico']['decision'] ?? null);
        $this->assertSame('desportivo', $fieldsByName['data_atestado_medico']['owner_area'] ?? null);
        $this->assertSame('add_to_personal_payload_contract', $fieldsByName['estado_civil']['decision'] ?? null);
        $this->assertSame('add_to_personal_payload_contract_or_discard_as_historical', $fieldsByName['numero_irmaos']['decision'] ?? null);
        $this->assertSame('M4.16', $payload['decision_config_version'] ?? null);
        $this->assertSame(1, (int) ($payload['summary']['fields_with_missing_canonical_target'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['fields_with_defined_but_write_blocked_target'] ?? 0));
        $this->assertFalse((bool) ($fieldsByName['data_atestado_medico']['write_allowed'] ?? true));
    }

    public function test_preflight_includes_decision_reason_and_next_action_and_keeps_commit_blocked(): void
    {
        $this->createFixtureUser();

        $payload = $this->runJsonCommand();

        $this->assertFalse((bool) ($payload['commit_allowed'] ?? true));

        $fieldsByName = [];
        foreach ($payload['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['field'])) {
                $fieldsByName[$field['field']] = $field;
            }
        }

        $this->assertStringContainsString('dominio desportivo', (string) ($fieldsByName['data_atestado_medico']['reason'] ?? ''));
        $this->assertStringContainsString('Auditar athlete_sports_data', (string) ($fieldsByName['data_atestado_medico']['next_action'] ?? ''));
        $this->assertStringContainsString('Contrato canonico de dados_pessoais atualizado', (string) ($fieldsByName['estado_civil']['reason'] ?? ''));
        $this->assertStringContainsString('backfill controlado apenas de estado_civil', (string) ($fieldsByName['estado_civil']['next_action'] ?? ''));
        $this->assertStringContainsString('Validar utilidade funcional', (string) ($fieldsByName['numero_irmaos']['next_action'] ?? ''));
    }

    public function test_command_does_not_write_any_tables(): void
    {
        $user = $this->createFixtureUser();

        $beforeUser = $this->snapshotUser($user);
        $beforePessoais = DadosPessoais::query()->where('user_id', $user->id)->first()?->getAttributes() ?? [];
        $beforeConfiguracao = DadosConfiguracao::query()->where('user_id', $user->id)->first()?->getAttributes() ?? [];
        $beforeAthlete = AthleteSportsData::query()->where('user_id', $user->id)->first()?->getAttributes() ?? [];

        Artisan::call('members:preflight-users-legacy-only-backfill', [
            '--commit' => true,
            '--confirm' => 'BACKFILL_LEGACY_ONLY_FIELDS',
        ]);

        $this->assertSame($beforeUser, $this->snapshotUser($user));
        $this->assertSame($beforePessoais, DadosPessoais::query()->where('user_id', $user->id)->first()?->getAttributes() ?? []);
        $this->assertSame($beforeConfiguracao, DadosConfiguracao::query()->where('user_id', $user->id)->first()?->getAttributes() ?? []);
        $this->assertSame($beforeAthlete, AthleteSportsData::query()->where('user_id', $user->id)->first()?->getAttributes() ?? []);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runJsonCommand(array $options = []): array
    {
        $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function createFixtureUser(): User
    {
        $user = User::factory()->create([
            'name' => 'M4.14 Preflight User',
            'nome_completo' => 'M4.14 Preflight User',
            'data_atestado_medico' => '2024-05-01',
            'estado_civil' => 'solteiro',
            'numero_irmaos' => 2,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'M4.14 Preflight User',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $user->id,
            'data_atestado_medico' => '2024-05-02',
            'ativo' => true,
        ]);

        return $user;
    }

    private function snapshotUser(User $user): array
    {
        return User::query()->whereKey($user->id)->first()?->getAttributes() ?? [];
    }

    private function assertLegacyUserUnchanged(User $user): void
    {
        $fresh = User::query()->whereKey($user->id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame('2024-05-01', $fresh?->data_atestado_medico?->format('Y-m-d'));
        $this->assertSame('solteiro', $fresh?->estado_civil);
        $this->assertSame(2, (int) $fresh?->numero_irmaos);
    }
}