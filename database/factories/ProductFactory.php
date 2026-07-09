<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => $this->faker->word(),
            'descricao' => $this->faker->sentence(),
            'codigo' => $this->faker->unique()->ean8(),
            'categoria' => $this->faker->word(),
            'preco' => $this->faker->randomFloat(2, 1, 100),
            'stock' => $this->faker->numberBetween(0, 1000),
            'stock_minimo' => $this->faker->numberBetween(1, 10),
            'ativo' => true,
        ];
    }
}
