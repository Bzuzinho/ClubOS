<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\Familia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FamilyLegacyRelationshipAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_and_dependent_legacy_pair_is_covered_by_user_guardian(): void
    {
        $member = User::factory()->create();
        $guardian = User::factory()->create();

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertLegacyRelationship($member->id, $guardian->id, 'encarregado_educacao');
        $this->insertLegacyRelationship($guardian->id, $member->id, 'educando');

        $exitCode = Artisan::call('members:audit-family-legacy-relationships', [
            '--json' => true,
            '--fail-on-uncovered' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutput();
        $summary = $payload['summary'] ?? [];

        $this->assertSame(2, (int) ($summary['total_rows'] ?? -1));
        $this->assertSame(2, (int) ($summary['canonical_covered_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['uncovered_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['unknown_type_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['reciprocal_missing_count'] ?? -1));
        $this->assertTrue((bool) ($summary['ready_for_physical_cleanup'] ?? false));
        $this->assertTrue((bool) ($summary['passed'] ?? false));
    }

    public function test_familiar_legacy_relationship_is_covered_when_users_share_active_family(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $family = Familia::query()->create([
            'nome' => 'Família Auditada',
            'responsavel_user_id' => $first->id,
            'ativo' => true,
        ]);

        foreach ([$first, $second] as $member) {
            $family->members()->attach($member->id, [
                'id' => (string) Str::uuid(),
                'papel_na_familia' => $member->is($first) ? 'responsavel' : 'familiar',
                'pode_editar' => $member->is($first),
                'pode_ver_financeiro' => true,
                'pode_ver_desportivo' => true,
                'pode_ver_documentos' => true,
                'pode_ver_comunicacoes' => true,
            ]);
        }

        $this->insertLegacyRelationship($first->id, $second->id, 'familiar');

        $exitCode = Artisan::call('members:audit-family-legacy-relationships', [
            '--json' => true,
            '--fail-on-uncovered' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutput();
        $summary = $payload['summary'] ?? [];

        $this->assertSame(1, (int) ($summary['total_rows'] ?? -1));
        $this->assertSame(1, (int) ($summary['canonical_covered_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['uncovered_count'] ?? -1));
        $this->assertTrue((bool) ($summary['ready_for_physical_cleanup'] ?? false));
    }

    public function test_fail_on_uncovered_blocks_when_legacy_relationship_has_no_canonical_projection(): void
    {
        $member = User::factory()->create();
        $guardian = User::factory()->create();

        $this->insertLegacyRelationship($member->id, $guardian->id, 'encarregado_educacao');

        $exitCode = Artisan::call('members:audit-family-legacy-relationships', [
            '--json' => true,
            '--fail-on-uncovered' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeOutput();
        $summary = $payload['summary'] ?? [];
        $unresolved = $payload['unresolved'] ?? [];

        $this->assertSame(1, (int) ($summary['uncovered_count'] ?? -1));
        $this->assertFalse((bool) ($summary['ready_for_physical_cleanup'] ?? true));
        $this->assertFalse((bool) ($summary['passed'] ?? true));
        $this->assertSame('missing_canonical_projection', $unresolved[0]['reason'] ?? null);
    }

    public function test_unknown_legacy_type_is_reported_and_blocks_cleanup(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->insertLegacyRelationship($first->id, $second->id, 'irmao');

        $exitCode = Artisan::call('members:audit-family-legacy-relationships', [
            '--json' => true,
            '--fail-on-uncovered' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeOutput();
        $summary = $payload['summary'] ?? [];
        $unresolved = $payload['unresolved'] ?? [];

        $this->assertSame(1, (int) ($summary['unknown_type_count'] ?? -1));
        $this->assertFalse((bool) ($summary['ready_for_physical_cleanup'] ?? true));
        $this->assertSame('unsupported_type', $unresolved[0]['reason'] ?? null);
    }

    public function test_missing_legacy_reciprocal_is_informational_when_canonical_pair_is_complete(): void
    {
        $member = User::factory()->create();
        $guardian = User::factory()->create();

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertLegacyRelationship($member->id, $guardian->id, 'encarregado_educacao');

        $exitCode = Artisan::call('members:audit-family-legacy-relationships', [
            '--json' => true,
            '--fail-on-uncovered' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutput();
        $summary = $payload['summary'] ?? [];

        $this->assertSame(1, (int) ($summary['canonical_covered_count'] ?? -1));
        $this->assertSame(0, (int) ($summary['uncovered_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['reciprocal_missing_count'] ?? -1));
        $this->assertTrue((bool) ($summary['ready_for_physical_cleanup'] ?? false));
    }

    private function insertLegacyRelationship(string $userId, string $relatedUserId, string $type): void
    {
        DB::table('user_relationships')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'related_user_id' => $relatedUserId,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'O output do comando deve ser JSON válido.');

        return $decoded;
    }
}
