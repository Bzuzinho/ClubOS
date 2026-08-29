<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\User;
use App\Services\Family\FamilyRelationshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FamilyCanonicalBulkReplacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacing_guardians_updates_only_canonical_relation(): void
    {
        $member = User::factory()->athlete()->create();
        $firstGuardian = User::factory()->create();
        $secondGuardian = User::factory()->create();

        $service = app(FamilyRelationshipService::class);
        $service->associateGuardian($member, $firstGuardian);

        $service->replaceGuardiansForMember($member, [$secondGuardian->id]);

        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $firstGuardian->id,
        ]);
        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $secondGuardian->id,
        ]);
    }

    public function test_replacing_dependents_uses_same_canonical_guardian_relation(): void
    {
        $guardian = User::factory()->create();
        $firstDependent = User::factory()->athlete()->create();
        $secondDependent = User::factory()->athlete()->create();

        $service = app(FamilyRelationshipService::class);
        $service->associateGuardian($firstDependent, $guardian);

        $service->replaceDependentsForGuardian($guardian, [$secondDependent->id]);

        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $firstDependent->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $secondDependent->id,
            'guardian_id' => $guardian->id,
        ]);
    }

    public function test_bulk_replacement_is_idempotent_and_deduplicates_ids(): void
    {
        $member = User::factory()->athlete()->create();
        $guardian = User::factory()->create();

        $service = app(FamilyRelationshipService::class);
        $service->replaceGuardiansForMember($member, [$guardian->id, $guardian->id]);
        $service->replaceGuardiansForMember($member, [$guardian->id]);

        $this->assertSame(
            1,
            DB::table('user_guardian')
                ->where('user_id', $member->id)
                ->where('guardian_id', $guardian->id)
                ->count(),
        );
    }
}
