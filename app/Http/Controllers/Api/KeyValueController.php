<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeyValueStore;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\KeyValue\EventosKeyValueService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KeyValueController extends Controller
{
    private const RETIRED_SHADOW_CATALOG_KEYS = [
        'club-prova-tipos',
        'club-eventos-tipos',
    ];

    private const READ_ONLY_CONVOCATION_KV_KEYS = [
        'club-convocatorias',
        'club-convocatorias-grupo',
        'club-convocatorias-atleta',
        'movimentos-convocatoria',
    ];

    private const READ_ONLY_LEGACY_FINANCIAL_KEYS = [
        'club-movimentos',
        'club-movimento-itens',
        'club-movimento-items',
    ];

    private const MEMBER_DISCIPLINE_KV_KEYS = [
        'club-discipline-status',
        'club-discipline-records',
    ];

    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
        private readonly EventosKeyValueService $eventosSync,
    ) {
    }

    /**
     * GET /api/kv/{key}
     * 
     * Fetch value by key (supports ?scope=user)
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $scope = $request->get('scope', 'global');
        $userId = $scope === 'user' ? auth()->id() : null;

        abort_if(
            $this->isRetiredShadowCatalogKey($key),
            410,
            'Este catálogo KV foi descontinuado. Utilize a fonte canónica correspondente.'
        );

        if ($this->isReadOnlyLegacyFinancialKey($key)) {
            $this->authorizeLegacyFinancialRead($request);
        }

        if ($this->isMemberDisciplineKvKey($key)) {
            $this->authorizeMemberDiscipline($request, 'view');
        }

        if ($this->eventosSync->supports($key)) {
            $this->authorizeEventosKey($request, $key, 'view');

            return response()->json([
                'key' => $key,
                'value' => $this->eventosSync->get($key, $userId),
                'scope' => $scope,
            ]);
        }

        $value = KeyValueStore::getValue($key, $userId);

        return response()->json([
            'key' => $key,
            'value' => $value,
            'scope' => $scope,
        ]);
    }

    /**
     * PUT /api/kv/{key}
     * 
     * Set/update value by key
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'present',
            'scope' => 'sometimes|in:global,user',
        ]);

        $scope = $validated['scope'] ?? 'global';
        $userId = $scope === 'user' ? auth()->id() : null;

        abort_if(
            $this->isRetiredShadowCatalogKey($key),
            410,
            'Este catálogo KV foi descontinuado. Utilize a fonte canónica correspondente.'
        );

        abort_if(
            $this->isReadOnlyConvocationKvKey($key),
            410,
            'A escrita KV de convocatórias foi descontinuada. Utilize Desportivo > Convocatórias.'
        );

        abort_if(
            $this->isReadOnlyLegacyFinancialKey($key),
            403,
            'Este histórico financeiro legado é apenas de leitura. Utilize o módulo Financeiro para alterações.'
        );

        if ($this->isMemberDisciplineKvKey($key)) {
            $this->authorizeMemberDiscipline($request, 'edit');
        }

        if ($this->eventosSync->supports($key)) {
            $this->authorizeEventosKey($request, $key, 'edit');
            $this->eventosSync->set($key, $validated['value'], $userId);

            return response()->json([
                'message' => 'Value saved successfully',
                'key' => $key,
            ]);
        }

        KeyValueStore::setValue($key, $validated['value'], $userId);

        return response()->json([
            'message' => 'Value saved successfully',
            'key' => $key,
        ]);
    }

    /**
     * DELETE /api/kv/{key}
     * 
     * Delete value by key
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $scope = $request->get('scope', 'global');
        $userId = $scope === 'user' ? auth()->id() : null;

        abort_if(
            $this->isRetiredShadowCatalogKey($key),
            410,
            'Este catálogo KV foi descontinuado. Utilize a fonte canónica correspondente.'
        );

        abort_if(
            $this->isReadOnlyConvocationKvKey($key),
            410,
            'A escrita KV de convocatórias foi descontinuada. Utilize Desportivo > Convocatórias.'
        );

        abort_if(
            $this->isReadOnlyLegacyFinancialKey($key),
            403,
            'Este histórico financeiro legado é apenas de leitura. Utilize o módulo Financeiro para alterações.'
        );

        if ($this->isMemberDisciplineKvKey($key)) {
            $this->authorizeMemberDiscipline($request, 'delete');
        }

        if ($this->eventosSync->supports($key)) {
            $this->authorizeEventosKey($request, $key, 'delete');
            $this->eventosSync->delete($key, $userId);

            return response()->json([
                'message' => 'Value deleted successfully',
                'key' => $key,
            ]);
        }

        KeyValueStore::deleteValue($key, $userId);

        return response()->json([
            'message' => 'Value deleted successfully',
            'key' => $key,
        ]);
    }

    private function isRetiredShadowCatalogKey(string $key): bool
    {
        return in_array($key, self::RETIRED_SHADOW_CATALOG_KEYS, true);
    }

    private function isReadOnlyConvocationKvKey(string $key): bool
    {
        return in_array($key, self::READ_ONLY_CONVOCATION_KV_KEYS, true);
    }

    private function isReadOnlyLegacyFinancialKey(string $key): bool
    {
        return in_array($key, self::READ_ONLY_LEGACY_FINANCIAL_KEYS, true);
    }

    private function isMemberDisciplineKvKey(string $key): bool
    {
        return in_array($key, self::MEMBER_DISCIPLINE_KV_KEYS, true);
    }

    private function authorizeLegacyFinancialRead(Request $request): void
    {
        abort_unless(
            $this->accessControlService->canAccessPermission(
                $request->user(),
                'membros.ficha.financeiro',
                'view'
            ),
            403,
            'Sem permissão para consultar o histórico financeiro da ficha do membro.'
        );
    }

    private function authorizeMemberDiscipline(Request $request, string $capability): void
    {
        abort_unless(
            $this->accessControlService->canAccessPermission(
                $request->user(),
                'membros.ficha.desportivo.disciplina',
                $capability
            ),
            403,
            'Sem permissão para executar esta ação sobre a disciplina do membro.'
        );
    }

    private function authorizeEventosKey(Request $request, string $key, string $capability): void
    {
        $permissionKeys = match ($key) {
            'club-events' => $capability === 'view'
                ? [
                    'eventos.calendario',
                    'desportivo.competicoes',
                    'membros.ficha.dashboard',
                    'membros.ficha.desportivo.resultados',
                ]
                : ['eventos.calendario'],
            'club-convocatorias', 'club-convocatorias-grupo', 'club-convocatorias-atleta', 'movimentos-convocatoria' => $capability === 'view'
                ? [
                    'eventos.convocatorias',
                    'desportivo.competicoes',
                    'membros.ficha.desportivo.convocatorias',
                ]
                : ['eventos.convocatorias'],
            'club-presencas' => $capability === 'view'
                ? [
                    'eventos.resultados',
                    'desportivo.presencas',
                    'membros.ficha.dashboard',
                    'membros.ficha.desportivo.presencas',
                ]
                : ['eventos.resultados'],
            'club-resultados' => $capability === 'view'
                ? [
                    'eventos.resultados',
                    'desportivo.resultados',
                    'membros.ficha.desportivo.resultados',
                ]
                : ['eventos.resultados'],
            'club-resultados-provas' => $capability === 'view'
                ? [
                    'eventos.resultados',
                    'desportivo.resultados',
                    'membros.ficha.dashboard',
                    'membros.ficha.desportivo.resultados',
                ]
                : ['eventos.resultados'],
            default => [],
        };

        abort_unless(
            collect($permissionKeys)->contains(
                fn (string $permissionKey) => $this->accessControlService
                    ->canAccessPermission($request->user(), $permissionKey, $capability)
            ),
            403,
            'Sem permissão para executar esta ação sobre dados de Eventos.'
        );
    }
}
