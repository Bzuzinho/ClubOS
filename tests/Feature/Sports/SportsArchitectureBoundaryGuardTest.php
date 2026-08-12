<?php

namespace Tests\Feature\Sports;

use App\Support\SportsArchitectureBoundaryGuard;
use Tests\TestCase;

class SportsArchitectureBoundaryGuardTest extends TestCase
{
    public function test_new_sports_code_cannot_expand_known_cross_module_boundary_violations(): void
    {
        $guard = app(SportsArchitectureBoundaryGuard::class);

        $this->assertSame([], $guard->violations(), $this->failureMessage($guard));
    }

    public function test_foundation_boundaries_are_closed_for_completed_phases(): void
    {
        $rules = app(SportsArchitectureBoundaryGuard::class)->rules();

        foreach ([
            'sports_finance_persistence_boundary',
            'sports_communication_persistence_boundary',
            'sports_logistics_persistence_boundary',
            'sports_legacy_runtime_boundary',
            'finance_competition_legacy_pointer_boundary',
            'communication_sports_legacy_audience_boundary',
            'events_competition_master_boundary',
            'sports_member_read_coupling_boundary',
        ] as $rule) {
            $this->assertSame([], $rules[$rule]['allowed_files'], "Boundary {$rule} is not closed.");
        }

        $this->assertContains(
            'use App\\Models\\PaymentAllocation;',
            $rules['sports_finance_persistence_boundary']['needles']
        );
        $this->assertContains(
            'use App\\Models\\EventAttendance;',
            $rules['sports_legacy_runtime_boundary']['needles']
        );
        $this->assertContains(
            'use App\\Models\\TeamMember;',
            $rules['communication_sports_legacy_audience_boundary']['needles']
        );
    }

    private function failureMessage(SportsArchitectureBoundaryGuard $guard): string
    {
        return "New Desportivo architecture boundary violations were introduced:\n".
            collect($guard->violations())
                ->map(fn (array $violation): string => sprintf(
                    '- [%s] %s contains %s',
                    $violation['rule'],
                    $violation['file'],
                    $violation['needle']
                ))
                ->implode("\n");
    }
}
