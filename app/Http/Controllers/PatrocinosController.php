<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSponsorRequest;
use App\Http\Requests\StoreSponsorshipRequest;
use App\Http\Requests\UpdateSponsorRequest;
use App\Http\Requests\UpdateSponsorshipRequest;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorshipIntegration;
use App\Models\Supplier;
use App\Services\Patrocinios\SponsorshipIntegrationService;
use App\Services\Patrocinios\SponsorshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PatrocinosController extends Controller
{
    public function __construct(
        private SponsorshipService $sponsorshipService,
        private SponsorshipIntegrationService $sponsorshipIntegrationService
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['tab', 'search', 'type', 'status', 'cost_center_id', 'period_from', 'period_to']);
        $tab = $filters['tab'] ?? 'dashboard';

        $sponsorships = Sponsorship::query()
            ->with([
                'sponsor:id,nome,tipo,estado,email,contacto,website,valor_anual,data_inicio,data_fim',
                'supplier:id,nome',
                'costCenter:id,nome,codigo',
                'moneyItems.financialMovement:id,nome_manual,valor_total,estado_pagamento',
                'goodsItems.item:id,nome,codigo',
                'goodsItems.stockEntry:id,article_id,movement_type,quantity,created_at',
            ])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('codigo', 'like', "%{$search}%")
                        ->orWhere('sponsor_name', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['cost_center_id'] ?? null, fn ($query, $costCenterId) => $query->where('cost_center_id', $costCenterId))
            ->when($filters['period_from'] ?? null, fn ($query, $periodFrom) => $query->whereDate('start_date', '>=', $periodFrom))
            ->when($filters['period_to'] ?? null, fn ($query, $periodTo) => $query->whereDate('start_date', '<=', $periodTo))
            ->latest()
            ->get();

        $sponsorships->each(function (Sponsorship $sponsorship) {
            $sponsorship->setAttribute('integration_status', $this->sponsorshipIntegrationService->getConsolidatedStatus($sponsorship));
            $sponsorship->setAttribute('money_total', (float) $sponsorship->moneyItems->sum('amount'));
            $sponsorship->setAttribute('goods_total', (float) $sponsorship->goodsItems->sum('total_value'));
        });

        $integrations = SponsorshipIntegration::query()
            ->with('sponsorship:id,codigo,sponsor_name,title')
            ->latest()
            ->limit(250)
            ->get();

        return Inertia::render('Patrocinios/Index', [
            'tab' => $tab,
            'filters' => $filters,
            'dashboard' => $this->sponsorshipService->getDashboardSummary(),
            'sponsorships' => $sponsorships,
            'integrations' => $integrations,
            'lookups' => [
                'sponsors' => Sponsor::query()->orderBy('nome')->get(),
                'suppliers' => Supplier::query()->orderBy('nome')->get(['id', 'nome']),
                'costCenters' => CostCenter::query()->where('ativo', true)->orderBy('nome')->get(['id', 'codigo', 'nome']),
                'products' => Product::query()->where('ativo', true)->orderBy('nome')->get(['id', 'codigo', 'nome', 'categoria', 'area_armazenamento']),
            ],
        ]);
    }

    public function storeSponsor(StoreSponsorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('sponsors', 'public');
            $data['logo'] = Storage::url($path);
        }

        Sponsor::create($data);

        return redirect()->route('patrocinios.index', ['tab' => 'patrocinadores'])
            ->with('success', 'Patrocinador criado com sucesso!');
    }

    public function updateSponsor(UpdateSponsorRequest $request, Sponsor $sponsor): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('sponsors', 'public');
            $data['logo'] = Storage::url($path);
        }

        $sponsor->update($data);

        return redirect()->route('patrocinios.index', ['tab' => 'patrocinadores'])
            ->with('success', 'Patrocinador atualizado com sucesso!');
    }

    public function destroySponsor(Sponsor $sponsor): RedirectResponse
    {
        abort_if($sponsor->sponsorships()->exists(), 422, 'Não é possível eliminar um patrocinador com patrocínios associados.');

        $sponsor->delete();

        return redirect()->route('patrocinios.index', ['tab' => 'patrocinadores'])
            ->with('success', 'Patrocinador eliminado com sucesso!');
    }

    public function store(StoreSponsorshipRequest $request): RedirectResponse
    {
        $result = $this->sponsorshipService->create($request->validated(), $request->user());
        $summary = $result['integration'];

        return redirect()->route('patrocinios.index')->with('success', $this->buildResultMessage('Patrocínio criado com sucesso.', $summary));
    }

    public function show(Request $request, Sponsorship $patrocinio): JsonResponse|RedirectResponse
    {
        $patrocinio->load([
            'sponsor',
            'supplier:id,nome',
            'costCenter:id,nome,codigo',
            'moneyItems.financialMovement',
            'goodsItems.item:id,nome,codigo',
            'goodsItems.stockEntry',
            'integrations',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'sponsorship' => $patrocinio,
                'integration_status' => $this->sponsorshipIntegrationService->getConsolidatedStatus($patrocinio),
            ]);
        }

        return redirect()->route('patrocinios.index', ['selected' => $patrocinio->id]);
    }

    public function update(UpdateSponsorshipRequest $request, Sponsorship $patrocinio): RedirectResponse
    {
        $result = $this->sponsorshipService->update($patrocinio, $request->validated(), $request->user());
        $summary = $result['integration'];

        return redirect()->route('patrocinios.index')->with('success', $this->buildResultMessage('Patrocínio atualizado com sucesso.', $summary));
    }

    public function destroy(Sponsorship $patrocinio): RedirectResponse
    {
        $this->sponsorshipService->delete($patrocinio);

        return redirect()->route('patrocinios.index')->with('success', 'Patrocínio removido com sucesso.');
    }

    public function close(Sponsorship $patrocinio, Request $request): RedirectResponse
    {
        $this->sponsorshipService->changeStatus($patrocinio, 'fechado', $request->user());

        return redirect()->route('patrocinios.index')->with('success', 'Patrocínio fechado com sucesso.');
    }

    public function cancel(Sponsorship $patrocinio, Request $request): RedirectResponse
    {
        $this->sponsorshipService->changeStatus($patrocinio, 'cancelado', $request->user());

        return redirect()->route('patrocinios.index')->with('success', 'Patrocínio cancelado com sucesso.');
    }

    public function integrationsIndex(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->expectsJson()) {
            return redirect()->route('patrocinios.index', ['tab' => 'integracoes']);
        }

        $integrations = SponsorshipIntegration::query()
            ->with('sponsorship:id,codigo,sponsor_name,title')
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('integration_type'), fn ($query, $integrationType) => $query->where('integration_type', $integrationType))
            ->when($request->input('target_module'), fn ($query, $targetModule) => $query->where('target_module', $targetModule))
            ->latest()
            ->get();

        return response()->json(['integrations' => $integrations]);
    }

    public function retry(Sponsorship $patrocinio, Request $request): RedirectResponse
    {
        $summary = $this->sponsorshipIntegrationService->syncForSponsorship($patrocinio->fresh(['moneyItems', 'goodsItems']), $request->user());

        return redirect()->route('patrocinios.index', ['tab' => 'integracoes'])->with('success', $this->buildResultMessage('Nova tentativa de integração executada.', $summary));
    }

    private function buildResultMessage(string $prefix, array $summary): string
    {
        return trim($prefix.' '.$summary['generated'].' geradas, '.$summary['failed'].' falhadas, '.$summary['skipped'].' sem alterações.');
    }
}
