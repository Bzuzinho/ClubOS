<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Desportivo\SportsDashboardWorkspaceController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\MembrosController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BenchmarkModulesPerformance extends Command
{
    protected $signature = 'perf:modules {--runs=3 : Number of warm runs after cold run}';

    protected $description = 'Benchmark module controller response build time (cold and warm cache)';

    public function handle(): int
    {
        $user = User::query()->first();

        if (!$user) {
            $this->error('No users found. Cannot benchmark authenticated modules.');
            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));

        $this->info('Benchmarking modules with user: ' . ($user->email_utilizador ?: $user->email ?: $user->id));
        $this->line('Running 1 cold request + ' . $runs . ' warm requests per module.');

        $targets = [
            'dashboard' => function () {
                app(DashboardController::class)->index();
            },
            'membros' => function () use ($user) {
                $request = Request::create('/membros', 'GET');
                $request->setUserResolver(fn () => $user);
                app(MembrosController::class)->index($request);
            },
            'eventos' => function () {
                app(EventosController::class)->index();
            },
            'financeiro' => function () {
                app(FinanceiroController::class)->index();
            },
            'desportivo' => function () {
                app(SportsDashboardWorkspaceController::class)->index();
            },
        ];

        $rows = [];

        foreach ($targets as $name => $runner) {
            Cache::clear();

            $coldMs = $this->measure($runner);
            $warmMs = [];

            for ($i = 0; $i < $runs; $i++) {
                $warmMs[] = $this->measure($runner);
            }

            $warmAvg = array_sum($warmMs) / count($warmMs);
            $speedup = $warmAvg > 0 ? $coldMs / $warmAvg : 0;

            $rows[] = [
                $name,
                number_format($coldMs, 2),
                number_format($warmAvg, 2),
                number_format($speedup, 1) . 'x',
            ];
        }

        $this->newLine();
        $this->table(['module', 'cold_ms', 'warm_avg_ms', 'speedup'], $rows);

        return self::SUCCESS;
    }

    private function measure(callable $fn): float
    {
        $start = microtime(true);
        $fn();

        return (microtime(true) - $start) * 1000;
    }
}
