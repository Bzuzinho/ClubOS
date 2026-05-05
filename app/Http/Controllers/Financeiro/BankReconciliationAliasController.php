<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliationAlias;
use App\Services\Financeiro\BankAliasNormalizer;
use App\Services\Financeiro\ReconciliationAliasService;
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
        $aliases = BankReconciliationAlias::query()
            ->with(['user:id,nome_completo,numero_socio', 'family:id,nome', 'creator:id,nome_completo'])
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->string('user_id')->toString()))
            ->when($request->filled('family_id'), fn ($query) => $query->where('family_id', $request->string('family_id')->toString()))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('is_confirmed'), fn ($query) => $query->where('is_confirmed', filter_var($request->input('is_confirmed'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false))
            ->orderByDesc('is_confirmed')
            ->orderByDesc('confidence')
            ->orderBy('value')
            ->get();

        return response()->json(['aliases' => $aliases]);
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