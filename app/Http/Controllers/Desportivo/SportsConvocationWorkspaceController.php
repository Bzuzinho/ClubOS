<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\ConvocationGroup;
use App\Models\ProvaTipo;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsConvocationWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsConvocationWorkspaceController extends Controller
{
    public function __construct(
        private readonly SportsConvocationWorkspaceService $workspace,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function index(): Response
    {
        $payload = $this->workspace->workspace();
        $payload['race_catalog'] = ProvaTipo::query()
            ->forClub($this->clubContext->id())
            ->ativo()
            ->ordenado()
            ->get(['id', 'codigo', 'nome', 'distancia', 'unidade', 'modalidade'])
            ->map(fn (ProvaTipo $race): array => [
                'id' => (string) $race->id,
                'code' => $race->codigo,
                'name' => (string) $race->nome,
                'distance' => $race->distancia,
                'unit' => $race->unidade,
                'modality' => $race->modalidade,
            ])
            ->values()
            ->all();

        return Inertia::render('Desportivo/ConvocationsWorkspace', $payload);
    }

    public function show(ConvocationGroup $convocationGroup): JsonResponse
    {
        return response()->json($this->workspace->detail($convocationGroup));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event_id' => ['required','uuid','exists:events,id'],
            'athletes' => ['required','array','min:1'],
            'athletes.*.user_id' => ['required','uuid','exists:users,id'],
            'athletes.*.race_ids' => ['nullable','array'],
            'athletes.*.race_ids.*' => ['uuid','exists:prova_tipos,id'],
            'athletes.*.relays' => ['nullable','integer','min:0'],
            'meeting_time' => ['nullable','date_format:H:i'],
            'meeting_location' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
            'cost_type' => ['required','string','max:30'],
            'value_per_race' => ['nullable','numeric','min:0'],
            'value_per_relay' => ['nullable','numeric','min:0'],
            'unit_registration_value' => ['nullable','numeric','min:0'],
            'cost_center_id' => ['nullable','uuid','exists:cost_centers,id'],
            'publish_now' => ['sometimes','boolean'],
        ]);
        $this->workspace->create($data, $request->user());
        return back();
    }

    public function update(Request $request, ConvocationGroup $convocationGroup): RedirectResponse
    {
        $data = $request->validate([
            'meeting_time' => ['nullable','date_format:H:i'],
            'meeting_location' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
            'cost_type' => ['nullable','string','max:30'],
            'value_per_race' => ['nullable','numeric','min:0'],
            'value_per_relay' => ['nullable','numeric','min:0'],
            'unit_registration_value' => ['nullable','numeric','min:0'],
            'cost_center_id' => ['nullable','uuid','exists:cost_centers,id'],
        ]);
        $this->workspace->update($convocationGroup, $data);
        return back();
    }

    public function publish(Request $request, ConvocationGroup $convocationGroup): JsonResponse
    {
        return response()->json($this->workspace->publish($convocationGroup, $request->user()));
    }
}
