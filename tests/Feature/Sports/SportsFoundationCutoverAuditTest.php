<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\SportsLegacyCutoverLedger;
use App\Models\Team;
use App\Models\User;
use App\Services\SportsFoundation\SportsFoundationCutoverAuditor;
use App\Services\SportsFoundation\SportsLegacyCutoverLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SportsFoundationCutoverAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sports_get_routes_redirect_and_mutations_are_closed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/sessoes-formacao')
            ->assertRedirect('/desportivo/treinos');

        $this->actingAs($user)
            ->get('/equipas')
            ->assertRedirect('/desportivo/estrutura');

        $this->actingAs($user)
            ->get('/convocatorias')
            ->assertRedirect('/desportivo/convocatorias');

        $this->actingAs($user)
            ->post('/sessoes-formacao', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->post('/equipas', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->post('/convocatorias', [])
            ->assertStatus(410);
    }

    public function test_unmapped_legacy_rows_are_classified_for_manual_review_never_guessed(): void
    {
        $team = Team::query()->create([
            'nome' => 'Equipa Legacy Sem Mapping',
            'ativo' => true,
        ]);

        app(SportsLegacyCutoverLedgerService::class)->refresh();

        $this->assertDatabaseHas('sports_legacy_cutover_ledger', [
            'source_type' => 'team',
            'source_id' => $team->id,
            'status' => 'manual_review',
            'reason' => 'legacy_team_requires_explicit_group_mapping',
            'target_id' => null,
        ]);
    }

    public function test_foundation_audit_is_green_when_runtime_boundaries_and_aliases_are_consistent(): void
    {
        $report = app(SportsFoundationCutoverAuditor::class)->audit();

        $this->assertTrue($report['foundation_green'], json_encode($report, JSON_PRETTY_PRINT));
        $this->assertSame(0, $report['summary']['blockers']['architecture_boundary_violations']);
        $this->assertSame(0, $report['summary']['blockers']['runtime_source_violations']);
        $this->assertSame(0, $report['summary']['blockers']['active_legacy_write_endpoints']);
        $this->assertSame(0, $report['summary']['blockers']['alias_mismatches']);
        $this->assertSame(0, $report['summary']['blockers']['unclassified_legacy_rows']);
        $this->assertSame(0, SportsLegacyCutoverLedger::query()->count());

        $this->assertSame(0, Artisan::call('desportivo:audit-foundation-cutover', [
            '--fail-on-blockers' => true,
        ]));
    }
}
