<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Communication\CommunicationProviderWebhookController;
use App\Http\Controllers\Api\TiposUtilizadorController;
use App\Http\Controllers\Api\EscaloesController;
use App\Http\Controllers\Api\TiposEventoController;
use App\Http\Controllers\Api\CentrosCustoController;
use App\Http\Controllers\Api\ClubSettingController;
use App\Http\Controllers\Api\KeyValueController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\ProvasController;
use App\Http\Controllers\Api\ResultsController;
use App\Http\Controllers\Api\EventAttendancesController;
use App\Http\Controllers\Api\EventResultsController;
use App\Http\Controllers\Api\ProvaTiposController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\TrainingAttendanceController;
use App\Http\Controllers\Api\AthleteController;
use App\Http\Controllers\Api\CompetitionController;
use App\Http\Controllers\Api\CompetitionResultController;
use App\Http\Controllers\Api\CompetitionRegistrationController;
use App\Http\Controllers\Api\TeamResultController;
use App\Http\Controllers\Api\UserTypeAccessControlController;
use App\Http\Controllers\AdminLojaController;
use App\Http\Controllers\AdminLojaEncomendaController;
use App\Http\Controllers\AdminLojaHeroController;
use App\Http\Controllers\AdminLojaProdutoController;
use App\Http\Controllers\LojaCarrinhoController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\LojaEncomendaController;
use App\Http\Controllers\LojaProdutoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/communication/{provider}', CommunicationProviderWebhookController::class)
    ->whereIn('provider', ['email', 'sms', 'push'])
    ->middleware(['throttle:120,1', 'communication.webhook.signature'])
    ->name('api.communication.webhooks.provider');

Route::middleware(['auth'])->group(function () {
    // Current user
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Key-Value Store endpoints
    Route::get('/kv/{key}', [KeyValueController::class, 'show']);
    Route::put('/kv/{key}', [KeyValueController::class, 'update']);
    Route::delete('/kv/{key}', [KeyValueController::class, 'destroy']);

    // Resource APIs
    Route::apiResource('users', UsersController::class)
        ->middleware('module.access:membros')
        ->middlewareFor(['index', 'show'], 'permission.access:membros.ficha,view')
        ->middlewareFor(['store', 'update'], 'permission.access:membros.ficha,edit')
        ->middlewareFor(['destroy'], 'permission.access:membros.ficha,delete');
    Route::apiResource('events', EventsController::class)
        ->middleware('module.access:eventos')
        ->middlewareFor(['index', 'show'], 'permission.access:eventos.calendario,view')
        ->middlewareFor(['store', 'update'], 'permission.access:eventos.calendario,edit')
        ->middlewareFor(['destroy'], 'permission.access:eventos.calendario,delete');
    Route::apiResource('provas', ProvasController::class)
        ->middleware('module.access:desportivo')
        ->middlewareFor(['index', 'show'], 'permission.access:desportivo.competicoes,view')
        ->middlewareFor(['store', 'update'], 'permission.access:desportivo.competicoes,edit')
        ->middlewareFor(['destroy'], 'permission.access:desportivo.competicoes,delete');
    Route::apiResource('results', ResultsController::class)
        ->middleware('module.access:desportivo')
        ->middlewareFor(['index', 'show'], 'permission.access:desportivo.resultados,view')
        ->middlewareFor(['store', 'update'], 'permission.access:desportivo.resultados,edit')
        ->middlewareFor(['destroy'], 'permission.access:desportivo.resultados,delete');
    Route::apiResource('event-attendances', EventAttendancesController::class)
        ->middleware('module.access:eventos')
        ->middlewareFor(['index', 'show'], 'permission.access:eventos.resultados,view')
        ->middlewareFor(['store', 'update'], 'permission.access:eventos.resultados,edit')
        ->middlewareFor(['destroy'], 'permission.access:eventos.resultados,delete');
    Route::apiResource('event-results', EventResultsController::class)
        ->middleware('module.access:eventos')
        ->middlewareFor(['index', 'show'], 'permission.access:eventos.resultados,view')
        ->middlewareFor(['store', 'update'], 'permission.access:eventos.resultados,edit')
        ->middlewareFor(['destroy'], 'permission.access:eventos.resultados,delete');
    Route::get('event-results-stats', [EventResultsController::class, 'stats'])
        ->middleware(['module.access:eventos', 'permission.access:eventos.resultados,view']);
    Route::get('prova-tipos', [ProvaTiposController::class, 'index']);

    // Settings APIs
    Route::apiResource('user-types', TiposUtilizadorController::class);
    Route::apiResource('age-groups', EscaloesController::class);
    Route::apiResource('event-types', TiposEventoController::class)
        ->middleware('module.access:eventos')
        ->middlewareFor(['index', 'show'], 'permission.access:eventos.calendario,view')
        ->middlewareFor(['store', 'update'], 'permission.access:eventos.calendario,edit')
        ->middlewareFor(['destroy'], 'permission.access:eventos.calendario,delete');
    Route::apiResource('cost-centers', CentrosCustoController::class);
    Route::apiResource('club-settings', ClubSettingController::class);

    Route::prefix('access-control')->group(function () {
        Route::get('catalog', [UserTypeAccessControlController::class, 'catalog'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,view']);
        Route::get('user-types', [UserTypeAccessControlController::class, 'userTypes'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,view']);
        Route::get('user-types/{userType}', [UserTypeAccessControlController::class, 'show'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,view']);
        Route::put('user-types/{userType}/menu-modules', [UserTypeAccessControlController::class, 'updateMenuModules'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,edit']);
        Route::put('user-types/{userType}/landing-page', [UserTypeAccessControlController::class, 'updateLandingPage'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,edit']);
        Route::put('user-types/{userType}/permissions', [UserTypeAccessControlController::class, 'updatePermissions'])
            ->middleware(['module.access:configuracoes', 'permission.access:configuracoes.permissoes,edit']);
    });

    // Desportivo Module APIs
    Route::prefix('desportivo')
        ->middleware('module.access:desportivo')
        ->group(function () {
            // Athletes
            Route::apiResource('athletes', AthleteController::class, ['only' => ['index', 'show']])
                ->middlewareFor(['index', 'show'], 'permission.access:desportivo.dashboard,view');

            // Trainings
            Route::apiResource('trainings', TrainingController::class)
                ->middlewareFor(['index', 'show'], 'permission.access:desportivo.treinos,view')
                ->middlewareFor(['store', 'update'], 'permission.access:desportivo.treinos,edit')
                ->middlewareFor(['destroy'], 'permission.access:desportivo.treinos,delete');

            // Training Attendance (Cais)
            Route::prefix('trainings/{trainingId}/attendance')->group(function () {
                Route::get('/', [TrainingAttendanceController::class, 'index'])
                    ->middleware('permission.access:desportivo.presencas,view');
                Route::put('{athleteId}', [TrainingAttendanceController::class, 'update'])
                    ->middleware('permission.access:desportivo.presencas,edit');
                Route::post('mark-all', [TrainingAttendanceController::class, 'markAllPresent'])
                    ->middleware('permission.access:desportivo.presencas,edit');
                Route::post('clear-all', [TrainingAttendanceController::class, 'clearAll'])
                    ->middleware('permission.access:desportivo.presencas,edit');
            });

            // Competitions
            Route::apiResource('competitions', CompetitionController::class)
                ->middlewareFor(['index', 'show'], 'permission.access:desportivo.competicoes,view')
                ->middlewareFor(['store', 'update'], 'permission.access:desportivo.competicoes,edit')
                ->middlewareFor(['destroy'], 'permission.access:desportivo.competicoes,delete');

            // Competition Results
            Route::apiResource('competition-results', CompetitionResultController::class)
                ->middlewareFor(['index', 'show'], 'permission.access:desportivo.resultados,view')
                ->middlewareFor(['store', 'update'], 'permission.access:desportivo.resultados,edit')
                ->middlewareFor(['destroy'], 'permission.access:desportivo.resultados,delete');
            Route::apiResource('competition-registrations', CompetitionRegistrationController::class)
                ->only(['index', 'store', 'destroy'])
                ->middlewareFor(['index'], 'permission.access:desportivo.competicoes,view')
                ->middlewareFor(['store'], 'permission.access:desportivo.competicoes,edit')
                ->middlewareFor(['destroy'], 'permission.access:desportivo.competicoes,delete');
            Route::apiResource('team-results', TeamResultController::class)
                ->only(['index', 'store', 'destroy'])
                ->middlewareFor(['index'], 'permission.access:desportivo.resultados,view')
                ->middlewareFor(['store'], 'permission.access:desportivo.resultados,edit')
                ->middlewareFor(['destroy'], 'permission.access:desportivo.resultados,delete');
        });

    Route::prefix('loja')->group(function () {
        Route::get('hero', [LojaController::class, 'hero']);
        Route::get('categorias', [LojaController::class, 'categorias']);
        Route::get('produtos', [LojaController::class, 'produtos']);
        Route::get('produtos/{produto}', [LojaProdutoController::class, 'show']);
        Route::get('carrinho', [LojaCarrinhoController::class, 'show']);
        Route::post('carrinho/itens', [LojaCarrinhoController::class, 'store']);
        Route::patch('carrinho/itens/{item}', [LojaCarrinhoController::class, 'update']);
        Route::delete('carrinho/itens/{item}', [LojaCarrinhoController::class, 'destroy']);
        Route::post('carrinho/submeter', [LojaCarrinhoController::class, 'submeter']);
        Route::get('encomendas', [LojaEncomendaController::class, 'index']);
        Route::get('encomendas/{encomenda}', [LojaEncomendaController::class, 'show']);
    });

    Route::prefix('admin/loja')->middleware('module.access:loja')->group(function () {
        Route::get('dashboard', [AdminLojaController::class, 'index']);

        Route::get('hero', [AdminLojaHeroController::class, 'index']);
        Route::post('hero', [AdminLojaHeroController::class, 'store']);
        Route::patch('hero/{item}', [AdminLojaHeroController::class, 'update']);
        Route::delete('hero/{item}', [AdminLojaHeroController::class, 'destroy']);
        Route::patch('hero/{item}/toggle', [AdminLojaHeroController::class, 'toggle']);
        Route::post('hero/reordenar', [AdminLojaHeroController::class, 'reordenar']);

        Route::get('produtos', [AdminLojaProdutoController::class, 'index']);
        Route::post('produtos', [AdminLojaProdutoController::class, 'store']);
        Route::get('produtos/{produto}', [AdminLojaProdutoController::class, 'show']);
        Route::patch('produtos/{produto}', [AdminLojaProdutoController::class, 'update']);
        Route::delete('produtos/{produto}', [AdminLojaProdutoController::class, 'destroy']);

        Route::get('encomendas', [AdminLojaEncomendaController::class, 'index']);
        Route::get('encomendas/{encomenda}', [AdminLojaEncomendaController::class, 'show']);
        Route::patch('encomendas/{encomenda}/estado', [AdminLojaEncomendaController::class, 'updateEstado']);
        Route::post('encomendas/{encomenda}/devolucao', [AdminLojaEncomendaController::class, 'processDevolucao']);
    });
});
