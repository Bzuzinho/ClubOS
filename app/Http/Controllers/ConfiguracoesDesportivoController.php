<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class ConfiguracoesDesportivoController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('desportivo.configuracao.index');
    }

    public function storeAthleteStatus(): RedirectResponse { return $this->moved(); }
    public function updateAthleteStatus(): RedirectResponse { return $this->moved(); }
    public function destroyAthleteStatus(): RedirectResponse { return $this->moved(); }
    public function storeTrainingType(): RedirectResponse { return $this->moved(); }
    public function updateTrainingType(): RedirectResponse { return $this->moved(); }
    public function destroyTrainingType(): RedirectResponse { return $this->moved(); }
    public function storeTrainingZone(): RedirectResponse { return $this->moved(); }
    public function updateTrainingZone(): RedirectResponse { return $this->moved(); }
    public function destroyTrainingZone(): RedirectResponse { return $this->moved(); }
    public function storeAbsenceReason(): RedirectResponse { return $this->moved(); }
    public function updateAbsenceReason(): RedirectResponse { return $this->moved(); }
    public function destroyAbsenceReason(): RedirectResponse { return $this->moved(); }
    public function storeInjuryReason(): RedirectResponse { return $this->moved(); }
    public function updateInjuryReason(): RedirectResponse { return $this->moved(); }
    public function destroyInjuryReason(): RedirectResponse { return $this->moved(); }
    public function storePoolType(): RedirectResponse { return $this->moved(); }
    public function updatePoolType(): RedirectResponse { return $this->moved(); }
    public function destroyPoolType(): RedirectResponse { return $this->moved(); }

    private function moved(): RedirectResponse
    {
        return redirect()->route('desportivo.configuracao.index')
            ->with('warning', 'A Configuração Desportiva passou a ser gerida dentro do módulo Desportivo.');
    }
}
