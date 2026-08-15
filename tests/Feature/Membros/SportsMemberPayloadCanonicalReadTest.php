<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsMemberPayloadCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_reports_no_sports_member_payload_findings_for_athlete_controller_path(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $result = $scanner->scan([
            'app/Http/Controllers/Api/AthleteController.php',
        ], $scanner->defaultAllowlist());

        $sportsFindings = collect($result['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'sports_member_payload'
        );

        $this->assertCount(0, $sportsFindings);
    }

    public function test_default_allowlist_does_not_include_athlete_controller(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);
        $allowlist = $scanner->defaultAllowlist();

        $this->assertNotContains('app/Http/Controllers/Api/AthleteController.php', $allowlist);
    }

    public function test_athlete_controller_reads_canonical_name_and_birth_date(): void
    {
        $admin = User::factory()->admin()->create();

        $athlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Legacy Nome Atleta',
            'name' => 'Legacy Auth Name',
            'data_nascimento' => '1999-01-01',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $athlete->id,
            'nome_completo' => 'Nome Canonico Atleta',
            'data_nascimento' => '2011-07-19',
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $athlete->id,
            'data_atestado_medico' => '2026-03-10',
        ]);

        $indexResponse = $this->actingAs($admin)->getJson('/api/desportivo/athletes');
        $indexResponse->assertOk();

        $indexedAthlete = collect($indexResponse->json())->firstWhere('id', $athlete->id);
        $this->assertIsArray($indexedAthlete);
        $this->assertSame('Nome Canonico Atleta', $indexedAthlete['nome_completo']);

        $showResponse = $this->actingAs($admin)->getJson('/api/desportivo/athletes/' . $athlete->id);
        $showResponse->assertOk()
            ->assertJsonPath('nome_completo', 'Nome Canonico Atleta')
            ->assertJsonPath('data_nascimento', '2011-07-19');
    }
}
