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

    public function test_known_foundation_debt_is_explicit_and_phase_scoped(): void
    {
        $rules = app(SportsArchitectureBoundaryGuard::class)->rules();

        $this->assertSame(
            ['app/Services/Desportivo/CreateCompetitionRegistrationAction.php'],
            $rules['sports_finance_persistence_boundary']['allowed_files']
        );
        $this->assertSame(
            ['app/Services/Eventos/EventLifecycleService.php'],
            $rules['events_competition_master_boundary']['allowed_files']
        );
        $this->assertSame([], $rules['sports_member_read_coupling_boundary']['allowed_files']);
        $this->assertContains(
            'use App\\Services\\Members\\MemberTypeResolver;',
            $rules['sports_member_read_coupling_boundary']['needles']
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
