<?php

namespace Tests\Feature\Sports;

use App\Models\KeyValueStore;
use App\Models\ProvaTipo;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaceCatalogOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_race_catalog_api_returns_only_active_unarchived_rows_from_current_club_in_canonical_order(): void
    {
        $user = User::factory()->admin()->create();
        $clubId = app(SportsClubContext::class)->id();

        $second = ProvaTipo::query()->create([
            'club_id' => $clubId,
            'codigo' => 'bscn-200-livre',
            'nome' => '200 Livre',
            'distancia' => 200,
            'unidade' => 'm',
            'modalidade' => 'Natacao',
            'ativo' => true,
            'ordem' => 20,
        ]);

        $first = ProvaTipo::query()->create([
            'club_id' => $clubId,
            'codigo' => 'bscn-100-livre',
            'nome' => '100 Livre',
            'distancia' => 100,
            'unidade' => 'm',
            'modalidade' => 'Natacao',
            'ativo' => true,
            'ordem' => 10,
        ]);

        ProvaTipo::query()->create([
            'club_id' => $clubId,
            'codigo' => 'bscn-inativa',
            'nome' => 'Inativa',
            'distancia' => 50,
            'unidade' => 'm',
            'modalidade' => 'Natacao',
            'ativo' => false,
            'ordem' => 1,
        ]);

        ProvaTipo::query()->create([
            'club_id' => $clubId,
            'codigo' => 'bscn-arquivada',
            'nome' => 'Arquivada',
            'distancia' => 25,
            'unidade' => 'm',
            'modalidade' => 'Natacao',
            'ativo' => true,
            'ordem' => 1,
            'archived_at' => now(),
        ]);

        ProvaTipo::query()->create([
            'club_id' => 'outro-clube',
            'codigo' => 'outro-50-livre',
            'nome' => 'Outro clube',
            'distancia' => 50,
            'unidade' => 'm',
            'modalidade' => 'Natacao',
            'ativo' => true,
            'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/prova-tipos');

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame([$first->id, $second->id], collect($response->json())->pluck('id')->all());
        $this->assertSame(['bscn-100-livre', 'bscn-200-livre'], collect($response->json())->pluck('codigo')->all());
    }

    public function test_shadow_race_catalog_kv_key_is_retired_without_deleting_historical_data(): void
    {
        $user = User::factory()->admin()->create();

        KeyValueStore::setValue('club-prova-tipos', [
            ['id' => 'legacy-race', 'name' => 'Legacy'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/kv/club-prova-tipos')
            ->assertStatus(410);

        $this->actingAs($user)
            ->putJson('/api/kv/club-prova-tipos', ['value' => []])
            ->assertStatus(410);

        $this->actingAs($user)
            ->deleteJson('/api/kv/club-prova-tipos')
            ->assertStatus(410);

        $this->assertDatabaseHas('key_value_store', [
            'key' => 'club-prova-tipos',
            'scope' => 'global',
        ]);
    }

    public function test_member_convocation_tab_reads_race_catalog_from_canonical_api_without_shadow_kv(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/ConvocatoriasTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("axios.get('/api/prova-tipos')", $source);
        $this->assertStringContainsString('const [provas, setProvas] = useState<Prova[]>([]);', $source);
        $this->assertStringNotContainsString('club-prova-tipos', $source);
        $this->assertStringNotContainsString('setCachedProvas', $source);
        $this->assertStringNotContainsString('cachedProvas', $source);
    }
}
