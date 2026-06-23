<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class EquipasController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Desportivo/Teams/Index', [
            'teams' => Team::with(['coach', 'members'])
                ->latest()
                ->paginate(15),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Desportivo/Teams/Create', [
            'treinadores' => $this->trainerOptions(),
        ]);
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        Team::create($request->validated());

        return redirect()->route('teams.index')
            ->with('success', 'Equipa criada com sucesso!');
    }

    public function show(Team $team): Response
    {
        return Inertia::render('Desportivo/Teams/Show', [
            'team' => $team->load(['coach', 'members.user', 'trainingSessions']),
        ]);
    }

    public function edit(Team $team): Response
    {
        return Inertia::render('Desportivo/Teams/Edit', [
            'team' => $team,
            'treinadores' => $this->trainerOptions(),
        ]);
    }

    private function trainerOptions()
    {
        return User::with('dadosPessoais:id,user_id,nome_completo')
            ->whereJsonContains('tipo_membro', 'treinador')
            ->where('status', 'ativo')
            ->get(['id', 'name', 'nome_completo'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'nome_completo' => trim((string) ($user->dadosPessoais?->nome_completo ?: $user->nome_completo ?: $user->name)) ?: 'Utilizador',
            ])
            ->values();
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $team->update($request->validated());

        return redirect()->route('teams.index')
            ->with('success', 'Equipa atualizada com sucesso!');
    }

    public function destroy(Team $team): RedirectResponse
    {
        $team->delete();

        return redirect()->route('teams.index')
            ->with('success', 'Equipa eliminada com sucesso!');
    }
}
