<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        $quantidade = $this->faker->numberBetween(1, 100);
        $preco_unitario = $this->faker->randomFloat(2, 1, 500);
        $total = $quantidade * $preco_unitario;

        return [
            'produto_id' => Product::factory(),
            'cliente_id' => User::factory(),
            'vendedor_id' => User::factory(),
            'quantidade' => $quantidade,
            'preco_unitario' => $preco_unitario,
            'total' => $total,
            'data' => $this->faker->dateTime(),
            'metodo_pagamento' => $this->faker->randomElement(['cash', 'card', 'transfer', 'check']),
        ];
    }
}
