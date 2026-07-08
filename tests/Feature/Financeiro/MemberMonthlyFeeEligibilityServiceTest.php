<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberMonthlyFeeEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_athlete_with_active_sports_is_required(): void
    {
        $user = $this->athlete('ativo', true);

        $result = $this->service()->evaluate($user);

        $this->assertTrue($result['should_have_monthly_fee']);
        $this->assertContains('active_sports_athlete', $result['reason_codes']);
        $this->assertContains('eligible_member_type', $result['reason_codes']);
    }

    public function test_active_athlete_with_inactive_sports_is_not_required(): void
    {
        $user = $this->athlete('ativo', false);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('inactive_sports_athlete', $result['reason_codes']);
    }

    public function test_inactive_athlete_is_not_required(): void
    {
        $user = $this->athlete('inativo', true);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('inactive_member', $result['reason_codes']);
    }

    public function test_active_admin_without_member_types_is_not_required(): void
    {
        $user = User::factory()->admin()->create([
            'estado' => 'ativo',
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ]);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('no_monthly_fee_eligible_member_type', $result['reason_codes']);
        $this->assertContains('missing_operational_type', $result['reason_codes']);
    }

    public function test_active_trainer_without_explicit_rule_is_not_required(): void
    {
        $user = $this->typedUser('treinador', 'Treinador', [
            'estado' => 'ativo',
            'ativo_desportivo' => false,
        ]);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('no_monthly_fee_eligible_member_type', $result['reason_codes']);
    }

    public function test_active_guardian_without_explicit_rule_is_not_required(): void
    {
        $user = $this->typedUser('encarregado_educacao', 'Encarregado de Educacao', [
            'estado' => 'ativo',
            'ativo_desportivo' => false,
        ]);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('no_monthly_fee_eligible_member_type', $result['reason_codes']);
    }

    public function test_active_user_without_functional_type_is_not_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ]);
        $user->userTypes()->sync([]);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('missing_operational_type', $result['reason_codes']);
    }

    public function test_configured_non_athlete_type_without_explicit_rule_is_not_required(): void
    {
        config()->set('clubos.financeiro.monthly_fee_eligible_member_types', ['socio']);

        $user = $this->typedUser('socio', 'Socio', [
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);

        $result = $this->service()->evaluate($user);

        $this->assertFalse($result['should_have_monthly_fee']);
        $this->assertContains('no_monthly_fee_eligible_member_type', $result['reason_codes']);
    }

    public function test_service_uses_member_type_resolver(): void
    {
        $resolver = $this->app->make(MemberTypeResolver::class);

        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $user->userTypes()->sync([]);

        $this->assertTrue($resolver->isAthlete($user));
        $this->assertTrue($this->service()->shouldHaveMonthlyFee($user));
    }

    private function service(): MemberMonthlyFeeEligibilityService
    {
        return $this->app->make(MemberMonthlyFeeEligibilityService::class);
    }

    private function athlete(string $estado, bool $ativoDesportivo): User
    {
        return $this->typedUser('atleta', 'Atleta', [
            'estado' => $estado,
            'ativo_desportivo' => $ativoDesportivo,
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function typedUser(string $codigo, string $nome, array $overrides = []): User
    {
        $type = UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);

        $user = User::factory()->create(array_merge([
            'estado' => 'ativo',
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ], $overrides));

        $user->userTypes()->sync([$type->id]);

        return $user->fresh(['userTypes']);
    }
}
