<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberFiscalDataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MemberFiscalDataResolverTest extends TestCase
{
    use RefreshDatabase;

    private MemberFiscalDataResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(MemberFiscalDataResolver::class);
    }

    public function test_uses_dados_pessoais_as_primary_fiscal_source(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Name',
            'nome_completo' => 'Legacy Fiscal Name',
            'nif' => '100000001',
            'morada' => 'Rua Legacy 10',
            'codigo_postal' => '1000-001',
            'localidade' => 'Lisboa Legacy',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => '  Canonical Name  ',
            'nif' => ' 200000002 ',
            'morada' => ' Avenida Canonica 22 ',
            'codigo_postal' => ' 2000-002 ',
            'localidade' => ' Porto Canonico ',
        ]);

        $resolved = $this->resolver->resolve($user->fresh());

        $this->assertSame('Canonical Name', $resolved['nome']);
        $this->assertSame('200000002', $resolved['nif']);
        $this->assertSame('Avenida Canonica 22', $resolved['morada']);
        $this->assertSame('2000-002', $resolved['codigo_postal']);
        $this->assertSame('Porto Canonico', $resolved['localidade']);
    }

    public function test_falls_back_to_users_legacy_fiscal_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Name',
            'nome_completo' => 'Legacy Fiscal Name',
            'nif' => '300000003',
            'morada' => 'Rua Legacy 30',
            'codigo_postal' => '3000-003',
            'localidade' => 'Coimbra Legacy',
        ]);

        $this->assertNull($user->dadosPessoais);

        $resolved = $this->resolver->resolve($user);

        $this->assertSame('Legacy Fiscal Name', $resolved['nome']);
        $this->assertSame('300000003', $resolved['nif']);
        $this->assertSame('Rua Legacy 30', $resolved['morada']);
        $this->assertSame('3000-003', $resolved['codigo_postal']);
        $this->assertSame('Coimbra Legacy', $resolved['localidade']);
    }

    public function test_falls_back_per_field_when_canonical_field_is_null_or_empty(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Name',
            'nome_completo' => 'Legacy Fiscal Name',
            'nif' => '400000004',
            'morada' => 'Rua Legacy 40',
            'codigo_postal' => '4000-004',
            'localidade' => 'Braga Legacy',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Canonical Fiscal Name',
            'nif' => '',
            'morada' => null,
            'codigo_postal' => '  4400-111  ',
            'localidade' => '   ',
        ]);

        $resolved = $this->resolver->resolve($user->fresh());

        $this->assertSame('Canonical Fiscal Name', $resolved['nome']);
        $this->assertSame('400000004', $resolved['nif']);
        $this->assertSame('Rua Legacy 40', $resolved['morada']);
        $this->assertSame('4400-111', $resolved['codigo_postal']);
        $this->assertSame('Braga Legacy', $resolved['localidade']);
    }

    public function test_uses_users_name_as_final_name_fallback(): void
    {
        $user = User::factory()->create([
            'name' => 'Final Name Fallback',
            'nome_completo' => '   ',
            'nif' => null,
            'morada' => null,
            'codigo_postal' => null,
            'localidade' => null,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => null,
        ]);

        $resolved = $this->resolver->resolve($user->fresh());

        $this->assertSame('Final Name Fallback', $resolved['nome']);
        $this->assertNull($resolved['nif']);
        $this->assertNull($resolved['morada']);
        $this->assertNull($resolved['codigo_postal']);
        $this->assertNull($resolved['localidade']);
    }

    public function test_resolver_is_read_only(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Read Only Legacy',
            'nif' => '500000005',
            'morada' => 'Rua Read Only 50',
            'codigo_postal' => '5000-005',
            'localidade' => 'Aveiro',
        ]);

        $this->assertDatabaseMissing('dados_pessoais', [
            'user_id' => $user->id,
        ]);

        $before = $user->only([
            'name',
            'nome_completo',
            'nif',
            'morada',
            'codigo_postal',
            'localidade',
        ]);
        $updatedAtBefore = optional($user->updated_at)->toDateTimeString();

        $this->resolver->resolve($user);

        $this->assertDatabaseMissing('dados_pessoais', [
            'user_id' => $user->id,
        ]);

        $freshUser = $user->fresh();
        $after = $freshUser->only([
            'name',
            'nome_completo',
            'nif',
            'morada',
            'codigo_postal',
            'localidade',
        ]);

        $this->assertSame($before, $after);
        $this->assertSame($updatedAtBefore, optional($freshUser->updated_at)->toDateTimeString());
    }

    public function test_users_legacy_read_scanner_reports_no_personal_profile_findings_for_member_fiscal_data_resolver_path(): void
    {
        $this->assertNotContains(
            'app/Services/Members/MemberFiscalDataResolver.php',
            app(\App\Services\Members\UsersLegacyReadScanner::class)->defaultAllowlist(),
        );

        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Services/Members/MemberFiscalDataResolver.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $personalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_personal_profile'
        );

        $this->assertCount(0, $personalFindings);
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
