<?php

namespace Tests\Feature\Sports;

use App\Services\Desportivo\Queries\GetAthletePerformanceHistory;
use App\Services\Desportivo\Queries\GetCompetitionListSummary;
use App\Services\Desportivo\Queries\GetCompetitionResultsView;
use App\Services\Desportivo\Queries\GetTrainingDashboardSummary;
use App\Services\Desportivo\Queries\GetTrainingPoolDeckView;
use App\Support\LegacySportsGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySportsGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_lists_forbidden_tables(): void
    {
        $guard = app(LegacySportsGuard::class);

        $this->assertSame([
            'training_sessions',
            'presences',
            'event_results',
            'event_attendances',
            'teams',
            'team_members',
            'call_ups',
        ], $guard->forbiddenTables());
    }

    public function test_active_query_services_do_not_reference_legacy_tables(): void
    {
        $guard = app(LegacySportsGuard::class);

        $guard->assertServiceSourceIsLegacyFree(GetTrainingPoolDeckView::class);
        $guard->assertServiceSourceIsLegacyFree(GetTrainingDashboardSummary::class);
        $guard->assertServiceSourceIsLegacyFree(GetCompetitionListSummary::class);
        $guard->assertServiceSourceIsLegacyFree(GetCompetitionResultsView::class);
        $guard->assertServiceSourceIsLegacyFree(GetAthletePerformanceHistory::class);

        $this->assertTrue(true);
    }
}
