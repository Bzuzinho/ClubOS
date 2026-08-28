<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\User;
use App\Services\Family\FamilyJsonMirrorAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FamilyJsonMirrorCutoverAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_source_has_no_direct_json_mirror_usage(): void
    {
        $audit = app(FamilyJsonMirrorAuditor::class)->audit();

        $this->assertSame(
            [],
            $audit['source']['findings'] ?? null,
            json_encode($audit['source']['findings'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_json_mirror_pair_is_covered_when_user_guardian_exists(): void
    {
        $member = User::factory()->create();
        $guardian = User::factory()->create();

        $this->setMirror($member, 'encarregado_educacao', [$guardian->id]);
        $this->setMirror($guardian, 'educandos', [$member->id]);
        $this->insertGuardianPair($member, $guardian);

        $audit = app(FamilyJsonMirrorAuditor::class)->audit();
        $summary = $audit['summary'] ?? [];

        $this->assertSame(2, (int) ($summary['declared_json_links_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['unique_json_pairs_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['canonical_covered_pairs_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['uncovered_pairs_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['invalid_reference_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['self_reference_count'] ?? -1));
    }

    public function test_uncovered_json_pair_blocks_cleanup_gate(): void
    {
        $member = User::factory()->create();
        $guardian = User::factory()->create();
        $this->setMirror($member, 'encarregado_educacao', [$guardian->id]);

        $exitCode = Artisan::call('members:audit-family-json-mirrors', [
            '--json' => true,
            '--fail-on-finding' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['summary']['uncovered_pairs_count'] ?? -1));
        $this->assertFalse((bool) ($payload['summary']['ready_for_physical_cleanup'] ?? true));
    }

    public function test_family_json_mirrors_are_not_mass_assignable(): void
    {
        $fillable = (new User())->getFillable();

        $this->assertNotContains('encarregado_educacao', $fillable);
        $this->assertNotContains('educandos', $fillable);
    }

    private function setMirror(User $user, string $field, array $ids): void
    {
        DB::table('users')->where('id', $user->id)->update([
            $field => json_encode($ids, JSON_THROW_ON_ERROR),
        ]);
    }

    private function insertGuardianPair(User $member, User $guardian): void
    {
        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
