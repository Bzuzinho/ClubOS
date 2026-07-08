<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BackfillMemberMonthlyFeesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_cleanup_completed_after_fc2(): void
    {
        $plan = MonthlyFee::query()->create([
            'designacao' => 'Plano FC2 Command',
            'valor' => 30,
            'ativo' => true,
        ]);

        $user = User::factory()->create(['estado' => 'ativo']);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        $this->assertSame(1, (int) ($payload['summary']['counts']['cleanup_completed'] ?? 0));
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
    }

    public function test_apply_mode_is_safe_no_op_after_fc2_cleanup(): void
    {
        $user = User::factory()->create(['estado' => 'ativo']);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        $this->assertSame(0, (int) ($payload['migration']['migrated_count'] ?? -1));
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
        $this->assertSame((string) $user->id, (string) ($payload['cases'][0]['user_id'] ?? ''));
    }
}
