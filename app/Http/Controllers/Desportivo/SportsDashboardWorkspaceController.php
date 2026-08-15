<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Services\Desportivo\SportsDashboardWorkspaceService;
use Inertia\Inertia;
use Inertia\Response;

final class SportsDashboardWorkspaceController extends Controller
{
    public function __construct(private readonly SportsDashboardWorkspaceService $workspace)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/DashboardWorkspace', $this->workspace->workspace());
    }
}
