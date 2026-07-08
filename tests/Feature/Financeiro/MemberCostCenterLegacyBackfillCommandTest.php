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

    public function test_command_reports_cleanup_completed_after_fc2(): void
    {
        $center = CostCenter::query()->create([
            'codigo' => 'CC-FC2-POST',
            'nome' => 'Centro FC2',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $user = User::factory()->create(['estado' => 'ativo']);

        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'centro_custo_id' => $center->id,
            'peso' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        $this->assertSame(1, (int) ($payload['summary']['classifications']['cleanup_completed'] ?? 0));
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
    }

    public function test_apply_mode_is_safe_no_op_after_cleanup(): void
    {
        User::factory()->create(['estado' => 'ativo']);

        $exitCode = Artisan::call('finance:backfill-member-cost-centers', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        $this->assertSame(0, (int) ($payload['migration']['migrated_count'] ?? -1));
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
    }
}
