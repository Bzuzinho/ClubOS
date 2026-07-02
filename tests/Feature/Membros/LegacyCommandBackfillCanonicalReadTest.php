<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Console\Commands\BackfillFinanceiroIntegracoes;
use App\Models\DadosPessoais;
use App\Models\Event;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

final class LegacyCommandBackfillCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_reports_no_legacy_command_findings_for_backfill_command_path(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Console/Commands/BackfillFinanceiroIntegracoes.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $legacyCommandFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'legacy_command_or_backfill'
        );

        $this->assertCount(0, $legacyCommandFindings);
    }

    public function test_backfill_command_path_is_not_in_scanner_allowlist(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $this->assertNotContains(
            'app/Console/Commands/BackfillFinanceiroIntegracoes.php',
            $scanner->defaultAllowlist(),
        );
    }

    public function test_backfill_command_source_uses_member_identity_display_resolver(): void
    {
        $commandSource = (string) File::get(base_path('app/Console/Commands/BackfillFinanceiroIntegracoes.php'));

        $this->assertStringContainsString(
            'MemberIdentityDisplayResolver',
            $commandSource,
        );
        $this->assertStringContainsString(
            'buildConvocationAthleteDescription',
            $commandSource,
        );
        $this->assertStringContainsString(
            'displayName($user)',
            $commandSource,
        );
        $this->assertStringNotContainsString(
            'user->nome_completo',
            $commandSource,
        );
    }

    public function test_convocation_description_uses_canonical_name_when_legacy_name_differs(): void
    {
        $user = User::factory()->create([
            'name' => 'Auth Name',
            'nome_completo' => 'Legacy Nome Completo',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Canonical Display Name',
        ]);

        $event = new Event([
            'titulo' => 'Open Regional',
        ]);

        $command = app(BackfillFinanceiroIntegracoes::class);
        $resolver = app(MemberIdentityDisplayResolver::class);

        $method = new ReflectionMethod($command, 'buildConvocationAthleteDescription');
        $method->setAccessible(true);

        /** @var string $description */
        $description = $method->invoke($command, $user->fresh(), $event, $resolver);

        $this->assertSame('Canonical Display Name - Open Regional', $description);
        $this->assertStringNotContainsString('Legacy Nome Completo', $description);
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
