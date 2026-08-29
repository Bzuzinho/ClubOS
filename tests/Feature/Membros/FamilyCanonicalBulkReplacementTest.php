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

    public function test_replacing_guardians_updates_only_canonical_relation_and_preserves_json_mirrors(): void
    {
        $member = User::factory()->athlete()->create();
        $firstGuardian = User::factory()->create();
        $secondGuardian = User::factory()->create();

        DB::table('users')->where('id', $member->id)->update([
            'encarregado_educacao' => json_encode([$firstGuardian->id]),
        ]);
        DB::table('users')->where('id', $firstGuardian->id)->update([
            'educandos' => json_encode([$member->id]),
        ]);

        $service = app(FamilyRelationshipService::class);
        $service->associateGuardian($member, $firstGuardian);

        $memberMirrorBefore = DB::table('users')->where('id', $member->id)->value('encarregado_educacao');
        $firstGuardianMirrorBefore = DB::table('users')->where('id', $firstGuardian->id)->value('educandos');
        $secondGuardianMirrorBefore = DB::table('users')->where('id', $secondGuardian->id)->value('educandos');

        $service->replaceGuardiansForMember($member, [$secondGuardian->id]);

        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $firstGuardian->id,
        ]);
        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $secondGuardian->id,
        ]);

        $this->assertSame(
            $memberMirrorBefore,
            DB::table('users')->where('id', $member->id)->value('encarregado_educacao'),
        );
        $this->assertSame(
            $firstGuardianMirrorBefore,
            DB::table('users')->where('id', $firstGuardian->id)->value('educandos'),
        );
        $this->assertSame(
            $secondGuardianMirrorBefore,
            DB::table('users')->where('id', $secondGuardian->id)->value('educandos'),
        );
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
