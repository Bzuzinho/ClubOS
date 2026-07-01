<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MemberIdentityDisplayResolverTest extends TestCase
{
    use RefreshDatabase;

    private MemberIdentityDisplayResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(MemberIdentityDisplayResolver::class);
    }

    public function test_uses_canonical_personal_name_when_available(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Base Name',
            'nome_completo' => 'Legacy Display Name',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => '  Canonical Display Name  ',
        ]);

        $this->assertSame('Canonical Display Name', $this->resolver->displayName($user->fresh()));
    }

    public function test_falls_back_to_users_nome_completo_when_canonical_is_empty(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Base Name',
            'nome_completo' => 'Legacy Full Name',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => '   ',
        ]);

        $this->assertSame('Legacy Full Name', $this->resolver->displayName($user->fresh()));
    }

    public function test_falls_back_to_users_name_when_users_nome_completo_is_empty(): void
    {
        $user = User::factory()->create([
            'name' => 'Auth Name Fallback',
            'nome_completo' => '   ',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => null,
        ]);

        $this->assertSame('Auth Name Fallback', $this->resolver->displayName($user->fresh()));
    }

    public function test_falls_back_to_member_id_when_no_name_is_available(): void
    {
        $user = User::factory()->create([
            'name' => '',
            'nome_completo' => null,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => null,
        ]);

        $this->assertSame('Membro #' . $user->id, $this->resolver->displayName($user->fresh()));
    }

    public function test_resolver_is_read_only(): void
    {
        $user = User::factory()->create([
            'name' => 'Read Only Name',
            'nome_completo' => 'Read Only Legacy Name',
        ]);

        $this->assertDatabaseMissing('dados_pessoais', [
            'user_id' => $user->id,
        ]);

        $before = $user->only(['name', 'nome_completo']);
        $updatedAtBefore = optional($user->updated_at)->toDateTimeString();

        $resolved = $this->resolver->displayName($user);

        $this->assertSame('Read Only Legacy Name', $resolved);
        $this->assertDatabaseMissing('dados_pessoais', [
            'user_id' => $user->id,
        ]);

        $fresh = $user->fresh();
        $this->assertSame($before, $fresh->only(['name', 'nome_completo']));
        $this->assertSame($updatedAtBefore, optional($fresh->updated_at)->toDateTimeString());
    }

    public function test_scanner_reports_no_member_identity_display_findings_for_resolver_path(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $this->assertContains(
            'app/Services/Members/MemberIdentityDisplayResolver.php',
            $scanner->defaultAllowlist(),
        );

        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Services/Members/MemberIdentityDisplayResolver.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $identityFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_identity_display'
        );

        $this->assertCount(0, $identityFindings);
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
