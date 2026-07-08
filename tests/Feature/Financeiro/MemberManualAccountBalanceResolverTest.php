<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\User;
use App\Services\Financeiro\MemberManualAccountBalanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MemberManualAccountBalanceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_value_is_used_when_present(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'conta_corrente_manual' => 45.25,
        ]);

        $resolved = app(MemberManualAccountBalanceResolver::class)->resolveForUser($user->fresh('dadosFinanceiros'));

        $this->assertSame(45.25, $resolved);
    }

    public function test_canonical_zero_is_valid_and_blocks_legacy_fallback(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'conta_corrente_manual' => 0,
        ]);

        Log::spy();

        $resolved = app(MemberManualAccountBalanceResolver::class)->resolveForUser($user->fresh('dadosFinanceiros'));

        $this->assertSame(0.0, $resolved);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_legacy_value_is_ignored_when_canonical_is_absent(): void
    {
        $user = User::factory()->create();

        $resolver = app(MemberManualAccountBalanceResolver::class);
        $diagnostic = $resolver->detectDivergence($user->fresh());

        $this->assertSame(0.0, (float) $diagnostic['resolved_manual_balance']);
        $this->assertFalse((bool) $diagnostic['uses_legacy_fallback']);
    }

    public function test_legacy_zero_is_normalized_as_valid_value(): void
    {
        $user = User::factory()->create();

        $diagnostic = app(MemberManualAccountBalanceResolver::class)->detectDivergence($user->fresh());

        $this->assertSame(0.0, (float) $diagnostic['resolved_manual_balance']);
        $this->assertFalse((bool) $diagnostic['has_legacy_fallback']);
        $this->assertFalse((bool) $diagnostic['uses_legacy_fallback']);
    }

    public function test_no_sources_return_zero_without_fallback(): void
    {
        $user = User::factory()->create();

        $diagnostic = app(MemberManualAccountBalanceResolver::class)->detectDivergence($user->fresh());

        $this->assertSame(0.0, (float) $diagnostic['resolved_manual_balance']);
        $this->assertFalse((bool) $diagnostic['has_canonical_manual_balance']);
        $this->assertFalse((bool) $diagnostic['has_legacy_fallback']);
        $this->assertFalse((bool) $diagnostic['uses_legacy_fallback']);
    }

    public function test_divergence_is_detected_when_both_sources_differ(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'conta_corrente_manual' => 15,
        ]);

        $diagnostic = app(MemberManualAccountBalanceResolver::class)->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertFalse((bool) $diagnostic['has_divergence']);
    }

    public function test_matching_values_are_not_divergent(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'conta_corrente_manual' => 10,
        ]);

        $diagnostic = app(MemberManualAccountBalanceResolver::class)->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertFalse((bool) $diagnostic['has_divergence']);
    }

    public function test_warning_is_not_emitted_for_canonical_source(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'conta_corrente_manual' => 25,
        ]);

        Log::spy();

        app(MemberManualAccountBalanceResolver::class)->resolveForUser($user->fresh('dadosFinanceiros'));

        Log::shouldNotHaveReceived('warning');
    }
}
