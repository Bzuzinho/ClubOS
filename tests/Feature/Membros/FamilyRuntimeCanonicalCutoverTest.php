<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePermissionAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FamilyRuntimeCanonicalCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_member_record_does_not_reconcile_stale_json_into_canonical_guardian_relation(): void
    {
        $member = User::factory()->athlete()->create();
        $canonicalGuardian = User::factory()->create();
        $staleLegacyGuardian = User::factory()->create();

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $canonicalGuardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $member->id)->update([
            'encarregado_educacao' => json_encode([$staleLegacyGuardian->id], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->actingAs($member)
            ->withoutMiddleware([EnsureModuleAccess::class, EnsurePermissionAccess::class])
            ->get(route('membros.show', $member));

        $response->assertOk();

        $this->assertDatabaseHas('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $canonicalGuardian->id,
        ]);
        $this->assertDatabaseMissing('user_guardian', [
            'user_id' => $member->id,
            'guardian_id' => $staleLegacyGuardian->id,
        ]);
    }
}
