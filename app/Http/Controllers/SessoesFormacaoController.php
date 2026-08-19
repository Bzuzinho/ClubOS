<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class SessoesFormacaoController extends Controller
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

    public function show(string $sessoes_formacao): RedirectResponse
    {
        return $this->moved();
    }

    public function edit(string $sessoes_formacao): RedirectResponse
    {
        return $this->moved();
    }

    public function update(string $sessoes_formacao): never
    {
        $this->retiredWrite();
    }

    public function destroy(string $sessoes_formacao): never
    {
        $this->retiredWrite();
    }

    private function moved(): RedirectResponse
    {
        return redirect()->route('desportivo.treinos')
            ->with('warning', 'As sessões de treino passaram a ser geridas na workspace canónica de Treinos.');
    }

    private function retiredWrite(): never
    {
        abort(
            410,
            'O fluxo legacy de training_sessions foi retirado. Utilize a workspace canónica de Treinos.'
        );
    }
}
