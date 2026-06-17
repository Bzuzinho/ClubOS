<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliationAlias;
use App\Services\Financeiro\BankAliasNormalizer;
use App\Services\Financeiro\ReconciliationAliasService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationAliasController extends Controller
{
    public function __construct(
        private readonly ReconciliationAliasService $aliasService,
        private readonly BankAliasNormalizer $normalizer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'type' => ['nullable', Rule::in(BankReconciliationAlias::TYPES)],
            'is_confirmed' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'target_type' => ['nullable', Rule::in(['user', 'family'])],
            'source' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 20);

        /** @var LengthAwarePaginator $paginator */
        $paginator = BankReconciliationAlias::query()
            ->with(['user:id,nome_completo,numero_socio', 'family:id,nome', 'creator:id,nome_completo'])
            ->when(!empty($data['user_id']), fn ($query) => $query->where('user_id', $data['user_id']))
            ->when(!empty($data['family_id']), fn ($query) => $query->where('family_id', $data['family_id']))
            ->when(!empty($data['type']), fn ($query) => $query->where('type', $data['type']))
            ->when(array_key_exists('is_confirmed', $data), fn ($query) => $query->where('is_confirmed', (bool) $data['is_confirmed']))
            ->when(array_key_exists('active', $data) && (bool) $data['active'] === true, function ($query): void {
                $query
                    ->where(function ($nested): void {
                        $nested
                            ->whereNull('source')
                            ->orWhere('source', 'not like', ReconciliationAliasService::DISABLED_SOURCE_PREFIX . '%');
                    });
            })
            ->when(array_key_exists('active', $data) && (bool) $data['active'] === false, function ($query): void {
                $query->where('source', 'like', ReconciliationAliasService::DISABLED_SOURCE_PREFIX . '%');
            })
            ->when(!empty($data['target_type']) && $data['target_type'] === 'user', fn ($query) => $query->whereNotNull('user_id'))
            ->when(!empty($data['target_type']) && $data['target_type'] === 'family', fn ($query) => $query->whereNotNull('family_id'))
            ->when(!empty($data['source']), fn ($query) => $query->where('source', 'like', '%' . $data['source'] . '%'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('value', 'like', '%' . $search . '%')
                        ->orWhere('normalized_value', 'like', '%' . strtoupper($search) . '%')
                        ->orWhere('raw_description', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function ($userQuery) use ($search): void {
                            $userQuery
                                ->where('nome_completo', 'like', '%' . $search . '%')
                                ->orWhere('numero_socio', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('family', function ($familyQuery) use ($search): void {
                            $familyQuery->where('nome', 'like', '%' . $search . '%');
                        });
                });
            })
            ->orderByDesc('is_confirmed')
            ->orderByDesc('last_used_at')
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $mapped = collect($paginator->items())
            ->map(function (BankReconciliationAlias $alias): array {
                $targetType = $alias->user_id ? 'user' : ($alias->family_id ? 'family' : null);
                $targetName = $alias->user?->nome_completo ?: $alias->family?->nome;

                return [
                    'id' => $alias->id,
                    'normalized_value' => $alias->normalized_value,
                    'original_value' => $alias->value,
                    'description' => $alias->raw_description,
                    'target_type' => $targetType,
                    'target_id' => $alias->user_id ?: $alias->family_id,
                    'target_name' => $targetName,
                    'user' => $alias->user ? [
                        'id' => $alias->user->id,
                        'nome_completo' => $alias->user->nome_completo,
                        'numero_socio' => $alias->user->numero_socio,
                    ] : null,
                    'family' => $alias->family ? [
                        'id' => $alias->family->id,
                        'nome' => $alias->family->nome,
                    ] : null,
                    'confidence' => (int) ($alias->confidence_score ?? $alias->confidence ?? 0),
                    'source' => $this->aliasService->normalizeSourceForDisplay($alias),
                    'usage_count' => (int) ($alias->usage_count ?? $alias->match_count ?? 0),
                    'last_used_at' => optional($alias->last_used_at)->toIso8601String(),
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                    'active' => $this->aliasService->isAliasActive($alias),
                    'is_confirmed' => (bool) $alias->is_confirmed,
                    'created_at' => optional($alias->created_at)->toIso8601String(),
                    'updated_at' => optional($alias->updated_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'aliases' => $mapped,
            'data' => $mapped,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $alias = $this->aliasService->createAlias([
            'user_id' => $data['user_id'] ?? null,
            'family_id' => $data['family_id'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'normalized_value' => $this->normalizer->normalize($data['value']),
            'is_confirmed' => $data['is_confirmed'] ?? false,
            'confidence' => $data['confidence'] ?? 50,
            'source' => $data['source'] ?? 'manual',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['alias' => $alias->load(['user', 'family', 'creator'])], 201);
    }

    public function update(Request $request, BankReconciliationAlias $alias): JsonResponse
    {
        $data = $request->validate($this->rules($alias));
        $targetUserId = $data['user_id'] ?? $alias->user_id;
        $targetFamilyId = $data['family_id'] ?? $alias->family_id;
        $targetType = $data['type'] ?? $alias->type;
        $targetValue = $data['value'] ?? $alias->value;
        $targetNormalizedValue = $this->normalizer->normalize($targetValue);
        $isConfirmed = $data['is_confirmed'] ?? $alias->is_confirmed;
        $confidence = $data['confidence'] ?? $alias->confidence;

        if ($isConfirmed) {
            $confidence = max(80, (int) $confidence);
        }

        $duplicate = BankReconciliationAlias::query()
            ->whereKeyNot($alias->getKey())
            ->where('user_id', $targetUserId)
            ->where('family_id', $targetFamilyId)
            ->where('type', $targetType)
            ->where('normalized_value', $targetNormalizedValue)
            ->first();

        if ($duplicate) {
            $duplicate->update([
                'value' => $targetValue,
                'is_confirmed' => $isConfirmed,
                'confidence' => $confidence,
                'source' => $data['source'] ?? $duplicate->source,
                'last_matched_at' => $data['last_matched_at'] ?? $duplicate->last_matched_at,
                'match_count' => $data['match_count'] ?? $duplicate->match_count,
            ]);

            $alias->delete();

            return response()->json(['alias' => $duplicate->load(['user', 'family', 'creator'])]);
        }

        $alias->update([
            'user_id' => $targetUserId,
            'family_id' => $targetFamilyId,
            'type' => $targetType,
            'value' => $targetValue,
            'normalized_value' => $targetNormalizedValue,
            'is_confirmed' => $isConfirmed,
            'confidence' => $confidence,
            'source' => $data['source'] ?? $alias->source,
            'last_matched_at' => $data['last_matched_at'] ?? $alias->last_matched_at,
            'match_count' => $data['match_count'] ?? $alias->match_count,
        ]);

        return response()->json(['alias' => $alias->load(['user', 'family', 'creator'])]);
    }

    public function destroy(BankReconciliationAlias $alias): JsonResponse
    {
        $alias->delete();

        return response()->json(['success' => true]);
    }

    public function deactivate(BankReconciliationAlias $alias): JsonResponse
    {
        $alias = $this->aliasService->deactivateAlias($alias);

        return response()->json([
            'alias' => [
                'id' => $alias->id,
                'active' => $this->aliasService->isAliasActive($alias),
            ],
        ]);
    }

    public function reactivate(BankReconciliationAlias $alias): JsonResponse
    {
        $alias = $this->aliasService->reactivateAlias($alias);

        return response()->json([
            'alias' => [
                'id' => $alias->id,
                'active' => $this->aliasService->isAliasActive($alias),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?BankReconciliationAlias $alias = null): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'type' => ['required', Rule::in(BankReconciliationAlias::TYPES)],
            'value' => ['required', 'string', 'max:255'],
            'confidence' => ['nullable', 'integer', 'between:0,100'],
            'is_confirmed' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'max:50'],
            'last_matched_at' => ['nullable', 'date'],
            'match_count' => ['nullable', 'integer', 'min:0'],
        ];
    }
}