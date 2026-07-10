<?php

namespace Tests\Feature\Financeiro;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyTaxasRoutesShutdownTest extends TestCase
{
    public function test_legacy_taxas_membership_fee_routes_are_not_registered(): void
    {
        $routes = Route::getRoutes();

        foreach ([
            'taxas.index',
            'taxas.store',
            'taxas.update',
            'taxas.destroy',
            'taxas.gerar',
            'taxas.marcar-pago',
        ] as $routeName) {
            $this->assertNull(
                $routes->getByName($routeName),
                "Legacy route [{$routeName}] should not be registered."
            );
        }

        $this->assertNotNull($routes->getByName('financeiro.monthly-fees.generate'));
    }
}
