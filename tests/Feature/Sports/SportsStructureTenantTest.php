<?php

namespace Tests\Feature\Sports;

use App\Services\Desportivo\SportsStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsStructureTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_f2_structure_uses_the_canonical_sports_club_context(): void
    {
        config(['sports.club_id' => 'club-secondary']);

        $service = app(SportsStructureService::class);
        $modality = $service->createModality([
            'code' => 'swimming-secondary',
            'name' => 'Natação Secundária',
        ]);

        $this->assertSame('club-secondary', $service->clubId());
        $this->assertSame('club-secondary', $modality->club_id);
        $this->assertDatabaseHas('sports_modalities', [
            'club_id' => 'club-secondary',
            'code' => 'swimming-secondary',
        ]);
    }
}
