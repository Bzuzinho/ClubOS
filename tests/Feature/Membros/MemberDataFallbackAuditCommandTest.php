<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MemberDataFallbackAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_and_does_not_create_canonical_rows(): void
    {
        $user = User::factory()->create([
            'name' => 'Fallback Readonly User',
            'nome_completo' => 'Fallback Readonly User',
            'nif' => '245678901',
            'morada' => 'Rua Readonly 100',
            'contacto' => '910000001',
            'rgpd' => true,
        ]);

        $before = $this->snapshotLegacyUser($user);

        $this->assertSame(0, Artisan::call('members:audit-data-fallback', [
            '--json' => true,
        ]));

        $after = $this->snapshotLegacyUser($user->fresh());

        $this->assertDatabaseMissing('dados_pessoais', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('dados_configuracao', ['user_id' => $user->id]);
        $this->assertSame($before, $after);
    }

    public function test_command_reports_fallback_usage_when_canonical_value_is_missing(): void
    {
        $user = User::factory()->create([
            'name' => 'Fallback Missing Canonical',
            'nome_completo' => 'Fallback Missing Canonical',
            'nif' => '245678902',
            'morada' => 'Rua Fallback 10',
            'contacto' => '910000002',
        ]);

        $report = $this->runJsonCommand();

        $this->assertGreaterThan(0, $report['summary']['personal_fallback_values']);
        $this->assertGreaterThan(0, $report['fields']['personal']['nif']['fallback_count']);
        $this->assertGreaterThan(0, $report['fields']['personal']['morada']['fallback_count']);
        $this->assertGreaterThan(0, $report['fields']['personal']['contacto']['fallback_count']);

        $auditedUser = collect($report['users'])->firstWhere('id', (string) $user->id);
        $this->assertIsArray($auditedUser);
        $this->assertContains('nif', $auditedUser['personal_fallback_fields']);
        $this->assertContains('morada', $auditedUser['personal_fallback_fields']);
        $this->assertContains('contacto', $auditedUser['personal_fallback_fields']);
    }

    public function test_command_prefers_canonical_values_and_does_not_count_fallback(): void
    {
        $user = User::factory()->create([
            'name' => 'Canonical Preferred',
            'nome_completo' => 'Canonical Preferred',
            'nif' => '245678903',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nif' => '999999999',
        ]);

        $report = $this->runJsonCommand([
            '--user-id' => (string) $user->id,
            '--only' => 'personal',
        ]);

        $this->assertSame(1, $report['summary']['total_users_analyzed']);
        $this->assertSame(1, $report['fields']['personal']['nif']['canonical_count']);
        $this->assertSame(0, $report['fields']['personal']['nif']['fallback_count']);
    }

    public function test_command_treats_boolean_false_as_canonical_value(): void
    {
        $user = User::factory()->create([
            'name' => 'Boolean False Canonical',
            'nome_completo' => 'Boolean False Canonical',
            'rgpd' => true,
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
        ]);

        $report = $this->runJsonCommand([
            '--user-id' => (string) $user->id,
            '--only' => 'configuration',
        ]);

        $this->assertSame(1, $report['fields']['configuration']['consentimento_rgpd']['canonical_count']);
        $this->assertSame(0, $report['fields']['configuration']['consentimento_rgpd']['fallback_count']);
    }

    public function test_command_supports_user_id_filter(): void
    {
        $target = User::factory()->create([
            'name' => 'Target Audit User',
            'nome_completo' => 'Target Audit User',
            'nif' => '245678904',
        ]);

        User::factory()->create([
            'name' => 'Other Audit User',
            'nome_completo' => 'Other Audit User',
            'nif' => '245678905',
        ]);

        $report = $this->runJsonCommand([
            '--user-id' => (string) $target->id,
        ]);

        $this->assertSame(1, $report['summary']['total_users_analyzed']);
        $this->assertCount(1, $report['users']);
        $this->assertSame((string) $target->id, $report['users'][0]['id']);
    }

    public function test_command_writes_report_path(): void
    {
        User::factory()->create([
            'name' => 'Report Path User',
            'nome_completo' => 'Report Path User',
            'nif' => '245678906',
        ]);

        $relativePath = 'storage/app/member-fallback-test/report.json';
        $absolutePath = base_path($relativePath);

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }

        $this->assertSame(0, Artisan::call('members:audit-data-fallback', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]));

        $this->assertFileExists($absolutePath);
        $decoded = json_decode((string) File::get($absolutePath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('fields', $decoded);
        $this->assertArrayHasKey('users', $decoded);

        File::delete($absolutePath);
        File::deleteDirectory(dirname($absolutePath));
    }

    public function test_command_supports_only_personal_and_configuration(): void
    {
        User::factory()->create([
            'name' => 'Only Scope User',
            'nome_completo' => 'Only Scope User',
            'nif' => '245678907',
            'morada' => 'Rua Only Scope',
            'rgpd' => true,
        ]);

        $personalOnly = $this->runJsonCommand([
            '--only' => 'personal',
        ]);

        $this->assertGreaterThan(0, $personalOnly['summary']['personal_fallback_values']);
        $this->assertSame(0, $personalOnly['summary']['configuration_canonical_values']);
        $this->assertSame(0, $personalOnly['summary']['configuration_fallback_values']);
        $this->assertSame(0, $personalOnly['summary']['configuration_empty_values']);
        $this->assertSame(0, $personalOnly['summary']['users_with_any_configuration_fallback']);

        $configurationOnly = $this->runJsonCommand([
            '--only' => 'configuration',
        ]);

        $this->assertGreaterThan(0, $configurationOnly['summary']['configuration_fallback_values']);
        $this->assertSame(0, $configurationOnly['summary']['personal_canonical_values']);
        $this->assertSame(0, $configurationOnly['summary']['personal_fallback_values']);
        $this->assertSame(0, $configurationOnly['summary']['personal_empty_values']);
        $this->assertSame(0, $configurationOnly['summary']['users_with_any_personal_fallback']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runJsonCommand(array $options = []): array
    {
        $result = Artisan::call('members:audit-data-fallback', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $result);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotLegacyUser(User $user): array
    {
        $raw = $user->getRawOriginal();

        return [
            'name' => $raw['name'] ?? null,
            'nome_completo' => $raw['nome_completo'] ?? null,
            'nif' => $raw['nif'] ?? null,
            'morada' => $raw['morada'] ?? null,
            'contacto' => $raw['contacto'] ?? null,
            'rgpd' => array_key_exists('rgpd', $raw) && $raw['rgpd'] !== null ? (int) $raw['rgpd'] : null,
            'updated_at' => $raw['updated_at'] ?? null,
        ];
    }
}
