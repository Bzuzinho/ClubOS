<?php

namespace Tests\Feature\Api;

use App\Models\KeyValueStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenericKvFallbackRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_kv_keys_are_gone_for_read_write_and_delete(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/kv/arbitrary-runtime-key')
            ->assertStatus(410);

        $this->actingAs($user)
            ->putJson('/api/kv/arbitrary-runtime-key', ['value' => ['unsafe' => true]])
            ->assertStatus(410);

        $this->actingAs($user)
            ->deleteJson('/api/kv/arbitrary-runtime-key')
            ->assertStatus(410);

        $this->assertDatabaseMissing('key_value_store', [
            'key' => 'arbitrary-runtime-key',
        ]);
    }

    public function test_retiring_generic_api_does_not_delete_unknown_historical_rows(): void
    {
        $user = User::factory()->create();
        KeyValueStore::setValue('historical-unmapped-key', ['kept' => true]);

        $this->actingAs($user)
            ->getJson('/api/kv/historical-unmapped-key')
            ->assertStatus(410);

        $this->actingAs($user)
            ->deleteJson('/api/kv/historical-unmapped-key')
            ->assertStatus(410);

        $this->assertSame(
            ['kept' => true],
            KeyValueStore::getValue('historical-unmapped-key')
        );
    }
}
