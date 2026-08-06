<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sexo = $this->faker->randomElement(['masculino', 'female']);
        $age = $this->faker->numberBetween(10, 50);
        $birthYear = now()->year - $age;
        
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Keep automatically generated members outside the legacy four-digit
            // range used by deterministic financial and reconciliation fixtures.
            'numero_socio' => $this->faker->unique()->numberBetween(10000, 99999),
            'nome_completo' => fake()->name(),
            'perfil' => $this->faker->randomElement(['user', 'atleta', 'admin']),
            'estado' => 'ativo',
            'data_nascimento' => $birthYear . '-' . $this->faker->numberBetween(1, 12) . '-' . $this->faker->numberBetween(1, 28),
            'menor' => $age < 18,
            'sexo' => $sexo,
            'tipo_membro' => [$this->faker->randomElement(['Atleta', 'Sócio', 'Treinador'])],
            'rgpd' => true,
            'consentimento' => true,
            'afiliacao' => $this->faker->boolean(70),
            'declaracao_de_transporte' => $age < 18,
            'ativo_desportivo' => $this->faker->boolean(60),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an athlete.
     */
    public function athlete(): static
    {
        return $this->state(fn (array $attributes) => [
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'afiliacao' => true,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'ativo_desportivo' => false,
        ]);
    }
}
