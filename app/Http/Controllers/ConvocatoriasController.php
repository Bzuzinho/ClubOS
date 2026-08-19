<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class ConvocatoriasController extends Controller
{
    public function index(): RedirectResponse
    {
        return $this->moved();
    }

    public function create(): RedirectResponse
    {
        return $this->moved();
    }

    public function store(): never
    {
        $this->retiredWrite();
    }

    public function show(string $convocatoria): RedirectResponse
    {
        return $this->moved();
    }

    public function edit(string $convocatoria): RedirectResponse
    {
        return $this->moved();
    }

    public function update(string $convocatoria): never
    {
        $this->retiredWrite();
    }

    public function destroy(string $convocatoria): never
    {
        $this->retiredWrite();
    }

    private function moved(): RedirectResponse
    {
        return redirect()->route('desportivo.convocatorias.index')
            ->with('warning', 'As convocatórias passaram a ser geridas na workspace canónica do Desportivo.');
    }

    private function retiredWrite(): never
    {
        abort(
            410,
            'O fluxo legacy de call_ups foi retirado. Utilize a workspace canónica de Convocatórias.'
        );
    }
}
