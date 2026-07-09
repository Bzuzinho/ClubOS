<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class UsersLegacyFieldMapAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_configured_map_as_json(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-map', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('columns_by_category', $payload);
        $this->assertArrayHasKey('passed', $payload);
    }

    public function test_command_has_no_unknown_columns_for_current_schema(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-map', [
            '--json' => true,
            '--fail-on-unknown' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame([], $payload['unknown_columns']);
        $this->assertTrue($payload['passed']);
    }

    public function test_command_reports_no_missing_configured_columns_for_current_schema(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-map', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertArrayHasKey('missing_configured_columns', $payload);
        $this->assertIsArray($payload['missing_configured_columns']);
        $missing = $payload['missing_configured_columns'];

        $expectedRemoved = array_merge(
            config('member_user_legacy_fields.categories.removed_after_m5.fields', []),
            config('member_user_legacy_fields.categories.removed_after_fc2.fields', []),
        );
        sort($missing);
        sort($expectedRemoved);
        $this->assertSame($expectedRemoved, $missing);

        $this->assertSame([], $payload['unknown_columns']);
        $this->assertTrue($payload['passed']);
    }

    public function test_config_maps_every_category_to_fields_and_field_to_category_is_consistent(): void
    {
        $config = config('member_user_legacy_fields');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('categories', $config);
        $this->assertArrayHasKey('field_to_category', $config);

        foreach ($config['categories'] as $categoryName => $categoryDefinition) {
            $this->assertArrayHasKey('fields', $categoryDefinition);
            $this->assertIsArray($categoryDefinition['fields']);

            foreach ($categoryDefinition['fields'] as $field) {
                $this->assertSame($categoryName, $config['field_to_category'][$field] ?? null, sprintf('Field %s should map back to %s', $field, $categoryName));
            }
        }

        foreach ($config['field_to_category'] as $field => $categoryName) {
            $this->assertArrayHasKey($categoryName, $config['categories']);
            $this->assertContains($field, $config['categories'][$categoryName]['fields']);
        }
    }

    public function test_config_includes_known_legacy_personal_and_configuration_fields(): void
    {
        $config = config('member_user_legacy_fields');

        $this->assertIsArray($config);

        $this->assertContains('nome_completo', $config['categories']['member_personal_legacy']['fields']);
        $this->assertContains('rgpd', $config['categories']['member_configuration_legacy']['fields']);
        $this->assertContains('inscricao', $config['categories']['member_financial_legacy']['fields']);
        $this->assertContains('tipo_mensalidade', $config['categories']['removed_after_fc2']['fields']);
        $this->assertContains('name', $config['categories']['auth_operational_keep']['fields']);
        $this->assertContains('email', $config['categories']['auth_operational_keep']['fields']);
        $this->assertContains('password', $config['categories']['auth_operational_keep']['fields']);
        $this->assertContains('perfil', $config['categories']['auth_operational_keep']['fields']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArtisanJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
    }
}