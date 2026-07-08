<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberCostCenterLegacyBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_only_member_is_classified_as_ready_for_backfill(): void
    {
        $center = $this->createCostCenter('CC-READY', 'Ready Center');
        $user = $this->createMemberWithLegacyCenters([
            ['id' => $center->id, 'peso' => 2],
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeJsonOutput();
        $member = $this->findMember($payload, (string) $user->id);

        $this->assertSame('ready_for_backfill', $member['classification'] ?? null);
        $this->assertSame($center->id, $member['canonical_payload_candidate'][0]['id'] ?? null);
    }

    public function test_member_with_existing_pivot_is_not_overwritten(): void
    {
        $center = $this->createCostCenter('CC-CAN', 'Canonical Center');
        $user = $this->createMemberWithLegacyCenters([
            ['id' => $center->id, 'peso' => 3],
        ]);

        $this->attachPivot($user->id, $center->id, 3.0);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('centro_custo_user')->where('user_id', $user->id)->count());
        $this->assertSame(3.0, (float) DB::table('centro_custo_user')->where('user_id', $user->id)->value('peso'));

        $payload = $this->decodeJsonOutput();
        $member = $this->findMember($payload, (string) $user->id);
        $this->assertSame('already_canonical', $member['classification'] ?? null);
    }

    public function test_divergence_blocks_apply_backfill(): void
    {
        $canonical = $this->createCostCenter('CC-DIV-CAN', 'Canonical');
        $legacy = $this->createCostCenter('CC-DIV-LEG', 'Legacy');

        $user = $this->createMemberWithLegacyCenters([
            ['id' => $legacy->id, 'peso' => 1],
        ]);
        $this->attachPivot($user->id, $canonical->id, 1.0);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeJsonOutput();
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
        $this->assertSame(1, (int) ($payload['summary']['divergent_count'] ?? 0));
    }

    public function test_missing_legacy_cost_center_blocks_apply(): void
    {
        $this->createMemberWithLegacyCenters([
            ['id' => (string) Str::uuid(), 'peso' => 1],
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeJsonOutput();
        $this->assertSame(1, (int) ($payload['summary']['invalid_legacy_count'] ?? 0));
    }

    public function test_dry_run_does_not_write_to_pivot(): void
    {
        $center = $this->createCostCenter('CC-DRY', 'Dry Center');
        $user = $this->createMemberWithLegacyCenters([
            ['id' => $center->id, 'peso' => 1],
        ]);

        $this->assertSame(0, DB::table('centro_custo_user')->where('user_id', $user->id)->count());

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', ['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('centro_custo_user')->where('user_id', $user->id)->count());
    }

    public function test_apply_creates_canonical_pivot_rows(): void
    {
        $first = $this->createCostCenter('CC-APPLY-1', 'Apply One');
        $second = $this->createCostCenter('CC-APPLY-2', 'Apply Two');

        $user = $this->createMemberWithLegacyCenters([
            ['id' => $first->id, 'peso' => 2],
            ['id' => $second->id, 'peso' => 1],
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, DB::table('centro_custo_user')->where('user_id', $user->id)->count());
    }

    public function test_apply_uses_sync_service_normalization_path(): void
    {
        $first = $this->createCostCenter('CC-NORM-1', 'Norm One');
        $second = $this->createCostCenter('CC-NORM-2', 'Norm Two');

        $user = $this->createMemberWithLegacyCenters([
            ['id' => $first->id, 'peso' => 1],
            ['id' => $first->id, 'peso' => 4],
            ['id' => $second->id, 'peso' => 2],
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $rows = DB::table('centro_custo_user')
            ->where('user_id', $user->id)
            ->orderBy('centro_custo_id')
            ->get(['centro_custo_id', 'peso'])
            ->map(static fn ($row): array => [
                'id' => (string) $row->centro_custo_id,
                'peso' => (float) $row->peso,
            ])
            ->all();

        $this->assertSame([
            ['id' => $first->id, 'peso' => 4.0],
            ['id' => $second->id, 'peso' => 2.0],
        ], $rows);
    }

    public function test_apply_keeps_users_legacy_column_unchanged(): void
    {
        $center = $this->createCostCenter('CC-LEGACY-KEEP', 'Legacy Keep');
        $legacyPayload = [
            ['id' => $center->id, 'peso' => 3],
        ];

        $user = $this->createMemberWithLegacyCenters($legacyPayload);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $fresh = User::query()->findOrFail($user->id);
        $this->assertSame($legacyPayload, $fresh->centro_custo);
    }

    public function test_second_apply_execution_is_idempotent(): void
    {
        $center = $this->createCostCenter('CC-IDEMP', 'Idempotent');
        $user = $this->createMemberWithLegacyCenters([
            ['id' => $center->id, 'peso' => 1],
        ]);

        $firstExit = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $secondExit);

        $this->assertSame(1, DB::table('centro_custo_user')->where('user_id', $user->id)->count());

        $payload = $this->decodeJsonOutput();
        $this->assertSame(0, (int) ($payload['migration']['migrated_count'] ?? -1));
        $this->assertSame(1, (int) ($payload['summary']['already_canonical_count'] ?? 0));
    }

    public function test_json_output_is_valid(): void
    {
        $exitCode = Artisan::call('finance:backfill-member-cost-centers', ['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($this->decodeJsonOutput());
    }

    public function test_report_path_writes_json_file(): void
    {
        $relativePath = 'storage/app/audits/member-cost-centers-test-report.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_user_option_limits_execution_scope(): void
    {
        $firstCenter = $this->createCostCenter('CC-USER-1', 'User One');
        $secondCenter = $this->createCostCenter('CC-USER-2', 'User Two');

        $firstUser = $this->createMemberWithLegacyCenters([
            ['id' => $firstCenter->id, 'peso' => 1],
        ]);
        $secondUser = $this->createMemberWithLegacyCenters([
            ['id' => $secondCenter->id, 'peso' => 1],
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--user' => (string) $firstUser->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('centro_custo_user')->where('user_id', $firstUser->id)->count());
        $this->assertSame(0, DB::table('centro_custo_user')->where('user_id', $secondUser->id)->count());
    }

    private function createCostCenter(string $code, string $name): CostCenter
    {
        return CostCenter::query()->create([
            'codigo' => $code,
            'nome' => $name,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
    }

    /**
     * @param list<array{id:string,peso:int|float}> $legacyCenters
     */
    private function createMemberWithLegacyCenters(array $legacyCenters): User
    {
        return User::factory()->create([
            'estado' => 'ativo',
            'centro_custo' => $legacyCenters,
            'numero_socio' => (string) random_int(100000, 999999),
        ]);
    }

    private function attachPivot(string $userId, string $costCenterId, float $peso): void
    {
        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'centro_custo_id' => $costCenterId,
            'peso' => $peso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function findMember(array $payload, string $userId): array
    {
        $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];

        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }

            if ((string) ($member['id'] ?? '') === $userId) {
                return $member;
            }
        }

        $this->fail('Expected member not found in payload');
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
