<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\MarketingCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampanhasMarketingController extends Controller
{
    public function index(Request $request): Response
    {
        $query = MarketingCampaign::query();

        if ($request->filled('type')) {
            $query->ofType($request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->ofStatus($request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $query->search($request->string('search')->toString());
        }

        $campaigns = $query->latest()->paginate(15)->withQueryString();

        $stats = [
            'total_campaigns' => MarketingCampaign::count(),
            'active_campaigns' => MarketingCampaign::active()->count(),
            'budget_total' => (float) (MarketingCampaign::sum('budget') ?? 0),
            'planned_campaigns' => MarketingCampaign::ofStatus('planned')->count(),
            'completed_campaigns' => MarketingCampaign::completed()->count(),
        ];

        return Inertia::render('CampanhasMarketing/Index', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'filters' => [
                'type' => $request->input('type'),
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('campanhas-marketing.index');
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        MarketingCampaign::create($request->validated());

        return redirect()->route('campanhas-marketing.index')
            ->with('success', 'Campanha criada com sucesso!');
    }

    public function show(MarketingCampaign $campanhas_marketing): RedirectResponse
    {
        return redirect()->route('campanhas-marketing.index');
    }

    public function edit(MarketingCampaign $campanhas_marketing): RedirectResponse
    {
        return redirect()->route('campanhas-marketing.index');
    }

    public function update(UpdateCampaignRequest $request, MarketingCampaign $campanhas_marketing): RedirectResponse
    {
        $campanhas_marketing->update($request->validated());

        return redirect()->route('campanhas-marketing.index')
            ->with('success', 'Campanha atualizada com sucesso!');
    }

    public function destroy(MarketingCampaign $campanhas_marketing): RedirectResponse
    {
        $campanhas_marketing->delete();

        return redirect()->route('campanhas-marketing.index')
            ->with('success', 'Campanha eliminada com sucesso!');
    }
}
