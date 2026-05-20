<?php

namespace Tests\Feature\Configuracoes;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_can_be_created_from_configuracoes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('configuracoes'))
            ->post(route('configuracoes.metodos-pagamento.store'), [
                'codigo' => 'mb-way',
                'nome' => 'MB Way',
                'descricao' => 'Pagamento instantaneo',
                'requer_linha_bancaria' => false,
                'ativo' => true,
                'ordem' => 6,
            ])
            ->assertRedirect(route('configuracoes'));

        $this->assertDatabaseHas('payment_methods', [
            'codigo' => 'mb-way',
            'nome' => 'MB Way',
            'requer_linha_bancaria' => false,
            'ativo' => true,
            'ordem' => 6,
        ]);
    }

    public function test_payment_method_can_be_updated_to_inactive_and_reordered(): void
    {
        $user = User::factory()->create();
        $paymentMethod = PaymentMethod::query()->create([
            'codigo' => 'referencia-mb',
            'nome' => 'Referencia MB',
            'descricao' => 'Pagamento por referencia',
            'requer_linha_bancaria' => false,
            'ativo' => true,
            'ordem' => 7,
        ]);

        $this->actingAs($user)
            ->put(route('configuracoes.metodos-pagamento.update', $paymentMethod), [
                'codigo' => 'referencia-mb',
                'nome' => 'Referencia Multibanco',
                'descricao' => 'Atualizado',
                'requer_linha_bancaria' => true,
                'ativo' => false,
                'ordem' => 3,
            ])
            ->assertRedirect(route('configuracoes'));

        $paymentMethod->refresh();

        $this->assertSame('Referencia Multibanco', $paymentMethod->nome);
        $this->assertTrue($paymentMethod->requer_linha_bancaria);
        $this->assertFalse($paymentMethod->ativo);
        $this->assertSame(3, $paymentMethod->ordem);
    }

    public function test_payment_method_can_be_deleted_from_configuracoes(): void
    {
        $user = User::factory()->create();
        $paymentMethod = PaymentMethod::query()->create([
            'codigo' => 'apagavel',
            'nome' => 'Apagavel',
            'requer_linha_bancaria' => false,
            'ativo' => true,
            'ordem' => 8,
        ]);

        $this->actingAs($user)
            ->delete(route('configuracoes.metodos-pagamento.destroy', $paymentMethod))
            ->assertRedirect(route('configuracoes'));

        $this->assertDatabaseMissing('payment_methods', [
            'id' => $paymentMethod->id,
        ]);
    }
}
