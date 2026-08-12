<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\CommunicationSegment;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\Communication\SegmentResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsAudienceCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_athlete_segment_uses_canonical_sports_participation_not_tipo_membro_json(): void
    {
        $canonicalAthlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => [],
        ]);
        $legacyOnlyAthlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ]);

        $modality = SportsModality::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'code' => 'NAT',
            'name' => 'Natação',
            'active' => true,
        ]);

        SportsAthleteParticipation::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'user_id' => $canonicalAthlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => null,
            'source' => 'test',
        ]);

        $segment = CommunicationSegment::query()->create([
            'name' => 'Atletas canónicos',
            'type' => 'dynamic',
            'rules_json' => ['source' => 'athletes'],
            'is_active' => true,
        ]);

        $recipientIds = app(SegmentResolverService::class)
            ->resolveRecipients($segment)
            ->pluck('user_id')
            ->all();

        $this->assertSame([$canonicalAthlete->id], $recipientIds);
        $this->assertNotContains($legacyOnlyAthlete->id, $recipientIds);
    }

    public function test_manual_athlete_role_filter_also_uses_canonical_participation(): void
    {
        $canonicalAthlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => [],
        ]);
        $legacyOnlyAthlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ]);

        $modality = SportsModality::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'code' => 'NAT2',
            'name' => 'Natação 2',
            'active' => true,
        ]);

        SportsAthleteParticipation::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'user_id' => $canonicalAthlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subDay()->toDateString(),
            'source' => 'test',
        ]);

        $segment = CommunicationSegment::query()->create([
            'name' => 'Manual atletas',
            'type' => 'manual',
            'rules_json' => ['user_types' => ['atleta']],
            'is_active' => true,
        ]);

        $recipientIds = app(SegmentResolverService::class)
            ->resolveRecipients($segment)
            ->pluck('user_id')
            ->all();

        $this->assertSame([$canonicalAthlete->id], $recipientIds);
        $this->assertNotContains($legacyOnlyAthlete->id, $recipientIds);
    }
}
