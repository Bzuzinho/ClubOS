<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Services\Desportivo\SportsConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SportsConfigurationController extends Controller
{
    public function __construct(private readonly SportsConfigurationService $service) {}

    public function index(): Response
    {
        return Inertia::render('Desportivo/Configuracao/Index', $this->service->pagePayload());
    }

    public function store(Request $request, string $catalog): RedirectResponse
    {
        $data = $request->validate($this->rules($catalog));
        $this->service->create($catalog, $data, $request->user());
        return back()->with('success', 'Configuração desportiva criada com sucesso.');
    }

    public function update(Request $request, string $catalog, string $id): RedirectResponse
    {
        $data = $request->validate($this->rules($catalog, true));
        $this->service->update($catalog, $id, $data, $request->user());
        return back()->with('success', 'Configuração desportiva atualizada com sucesso.');
    }

    public function destroy(Request $request, string $catalog, string $id): RedirectResponse
    {
        $result = $this->service->remove($catalog, $id, $request->user());
        return back()->with('success', $result['action'] === 'archived'
            ? 'A configuração já tem histórico e foi arquivada.'
            : 'Configuração desportiva eliminada.');
    }

    /** @return array<string,mixed> */
    private function rules(string $catalog, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $common = [
            'codigo' => [$required, 'string', 'max:96'],
            'nome' => [$required, 'string', 'max:120'],
            'ativo' => ['sometimes', 'boolean'],
            'ordem' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];

        return match ($catalog) {
            'athlete_statuses' => [
                ...$common, 'nome_en' => ['nullable', 'string', 'max:120'], 'descricao' => ['nullable', 'string', 'max:2000'],
                'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'counts_as_present' => ['sometimes', 'boolean'],
                'requires_reason' => ['sometimes', 'boolean'], 'allows_training' => ['sometimes', 'boolean'], 'allows_competition' => ['sometimes', 'boolean'],
            ],
            'training_types' => [
                ...$common, 'nome_en' => ['nullable', 'string', 'max:120'], 'descricao' => ['nullable', 'string', 'max:2000'],
                'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'is_recovery' => ['sometimes', 'boolean'], 'is_high_intensity' => ['sometimes', 'boolean'],
            ],
            'training_zones' => [
                ...$common, 'descricao' => ['nullable', 'string', 'max:2000'], 'percentagem_min' => ['nullable', 'integer', 'min:0', 'max:200'],
                'percentagem_max' => ['nullable', 'integer', 'min:0', 'max:200', 'gte:percentagem_min'], 'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_recovery' => ['sometimes', 'boolean'], 'is_high_intensity' => ['sometimes', 'boolean'],
            ],
            'absence_reasons' => [
                ...$common, 'nome_en' => ['nullable', 'string', 'max:120'], 'descricao' => ['nullable', 'string', 'max:2000'],
                'requer_justificacao' => ['sometimes', 'boolean'], 'health_related' => ['sometimes', 'boolean'],
            ],
            'pool_types' => [...$common, 'comprimento_m' => ['nullable', 'integer', 'min:1', 'max:10000'], 'is_open_water' => ['sometimes', 'boolean']],
            'race_types' => [...$common, 'distancia' => [$required, 'integer', 'min:1', 'max:100000'], 'unidade' => [$required, Rule::in(['m', 'km'])], 'modalidade' => [$required, 'string', 'max:80']],
            'limitation_types' => [
                ...$common, 'descricao' => ['nullable', 'string', 'max:2000'], 'instrucao_padrao' => ['nullable', 'string', 'max:3000'],
                'allows_training' => ['sometimes', 'boolean'], 'allows_competition' => ['sometimes', 'boolean'], 'requires_end_date' => ['sometimes', 'boolean'],
            ],
            'cais_metrics' => [
                ...$common,
                'input_type' => [$required, Rule::in(['text', 'number', 'choice'])],
                'unit' => ['nullable', 'string', 'max:32'],
                'options' => ['nullable', 'string', 'max:3000'],
                'quick_action' => ['sometimes', 'boolean'],
            ],
            default => throw \Illuminate\Validation\ValidationException::withMessages(['catalog' => 'Catálogo de configuração desportiva inválido.']),
        };
    }
}
