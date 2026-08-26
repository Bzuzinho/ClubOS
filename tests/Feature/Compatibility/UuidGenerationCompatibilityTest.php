<?php

declare(strict_types=1);

namespace Tests\Feature\Compatibility;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UuidGenerationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_uuid_models_keep_uuid_v4_generation_contract(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $user->id
        );
    }
}
