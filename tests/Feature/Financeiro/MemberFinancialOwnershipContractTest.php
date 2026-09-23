<?php

namespace Tests\Feature\Financeiro;

use App\Models\KeyValueStore;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MemberFinancialOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_financial_legacy_history_requires_financial_tab_view_permission(): void
    {
        $user = User::factory()->create();
        KeyValueStore::setValue('club-movimentos', [['id' => 'legacy-movement']]);

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->withArgs(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'membros.ficha.financeiro'
                && $capability === 'view'
            )
            ->andReturnTrue();
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $this->actingAs($user)
            ->getJson('/api/kv/club-movimentos')
            ->assertOk()
            ->assertJsonPath('value.0.id', 'legacy-movement');
    }

    public function test_member_financial_legacy_history_is_not_exposed_without_financial_tab_permission(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->withArgs(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'membros.ficha.financeiro'
                && $capability === 'view'
            )
            ->andReturnFalse();
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $this->actingAs($user)
            ->getJson('/api/kv/club-movimentos')
            ->assertForbidden();
    }

    public function test_legacy_member_financial_keys_reject_mutations(): void
    {
        $user = User::factory()->create();

        foreach (['club-movimentos', 'club-movimento-itens', 'club-movimento-items'] as $key) {
            $this->actingAs($user)
                ->putJson("/api/kv/{$key}", ['value' => [['id' => 'should-not-write']]])
                ->assertForbidden();

            $this->actingAs($user)
                ->deleteJson("/api/kv/{$key}")
                ->assertForbidden();
        }

        $this->assertDatabaseCount('key_value_store', 0);
    }

    public function test_member_financial_tab_keeps_legacy_history_read_only(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/FinancialTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("const [movimentosKV] = useKV<any[]>('club-movimentos', []);", $source);
        $this->assertStringContainsString("const [movimentoItensKV] = useKV<any[]>('club-movimento-itens', []);", $source);
        $this->assertStringContainsString('Inscrições em Eventos — histórico legado', $source);
        $this->assertStringContainsString('Alterações financeiras são efetuadas apenas no módulo Financeiro.', $source);
        $this->assertStringNotContainsString('setMovimentosKV', $source);
        $this->assertStringNotContainsString('setMovimentoItensKV', $source);
        $this->assertStringNotContainsString('Editar Movimento', $source);
        $this->assertStringNotContainsString('handleSaveMovimento', $source);
    }
}
