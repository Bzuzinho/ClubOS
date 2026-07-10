<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use App\Services\Financeiro\MemberMonthlyFeeLifecycleService;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(
        private readonly MemberDataWriteService $memberDataWriteService,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
        private readonly MemberMonthlyFeeLifecycleService $memberMonthlyFeeLifecycleService,
    ) {
    }

    /**
     * GET /api/users
     */
    public function index(): JsonResponse
    {
        $users = User::with(['userTypes', 'ageGroup'])
            ->orderBy('nome_completo')
            ->get();
        
        return response()->json($users);
    }

    /**
     * POST /api/users
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome_completo' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'email_utilizador' => 'nullable|email',
            'data_nascimento' => 'required|date',
            'tipo_membro' => 'nullable|array',
            'estado' => 'required|in:ativo,inativo,suspenso',
            'perfil' => 'nullable|in:admin,atleta,encarregado,treinador,socio',
            'numero_socio' => 'nullable|string|max:255',
            'menor' => 'nullable|boolean',
            'ativo_desportivo' => 'nullable|boolean',
            'escalao' => 'nullable|array',
            'sexo' => 'nullable|in:M,F',
            'morada' => 'nullable|string',
            'contacto' => 'nullable|string',
            'telemovel' => 'nullable|string',
            'nif' => 'nullable|string',
            'password' => 'nullable|string|min:8',
        ]);

        if (isset($validated['password']) && $validated['password'] !== null && $validated['password'] !== '') {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            // Keep API create compatible with users.password NOT NULL.
            $validated['password'] = Hash::make(Str::random(32));
        }

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create($this->legacyUserPayloadForApiWrite($validated, true));

            $this->memberDataWriteService->persistFromMemberRequest($user, $validated, (string) $user->id);

            return $user;
        });

        $user->refresh()->load(['userTypes', 'ageGroup', 'dadosPessoais', 'dadosConfiguracao']);
        
        return response()->json($user, 201);
    }

    /**
     * GET /api/users/{id}
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with(['userTypes', 'ageGroup', 'dadosPessoais', 'dadosConfiguracao'])->findOrFail($id);
        return response()->json($user);
    }

    /**
     * PUT /api/users/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->with(['userTypes', 'dadosFinanceiros'])->findOrFail($id);
        $previouslyEligibleForMonthlyFee = $this->memberMonthlyFeeEligibilityService
            ->shouldHaveMonthlyFee($user);
        
        $validated = $request->validate([
            'nome_completo' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'email_utilizador' => 'sometimes|nullable|email',
            'data_nascimento' => 'sometimes|date',
            'tipo_membro' => 'sometimes|array',
            'estado' => 'sometimes|in:ativo,inativo,suspenso',
            'perfil' => 'sometimes|in:admin,atleta,encarregado,treinador,socio',
            'numero_socio' => 'sometimes|nullable|string|max:255',
            'menor' => 'sometimes|nullable|boolean',
            'ativo_desportivo' => 'sometimes|nullable|boolean',
            'escalao' => 'sometimes|nullable|array',
            'sexo' => 'sometimes|in:M,F',
            'morada' => 'sometimes|string',
            'contacto' => 'sometimes|string',
            'telemovel' => 'sometimes|string',
            'nif' => 'sometimes|string',
            'password' => 'sometimes|string|min:8',
        ]);

        if (array_key_exists('password', $validated)) {
            if ($validated['password'] !== null && $validated['password'] !== '') {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }
        }

        $user = DB::transaction(function () use ($user, $validated, $previouslyEligibleForMonthlyFee): User {
            $user->update($this->legacyUserPayloadForApiWrite($validated, false));

            $freshUser = User::query()
                ->with(['userTypes', 'dadosFinanceiros'])
                ->whereKey($user->id)
                ->firstOrFail();
            $this->memberDataWriteService->persistFromMemberRequest($freshUser, $validated, (string) $user->id);

            $freshUser = User::query()
                ->with(['userTypes', 'dadosFinanceiros'])
                ->whereKey($user->id)
                ->firstOrFail();

            $currentlyEligibleForMonthlyFee = $this->memberMonthlyFeeEligibilityService
                ->shouldHaveMonthlyFee($freshUser);

            $this->memberMonthlyFeeLifecycleService->reconcileEligibilityTransition(
                $freshUser,
                $previouslyEligibleForMonthlyFee,
                $currentlyEligibleForMonthlyFee,
            );

            return $freshUser;
        });

        $user->refresh()->load(['userTypes', 'ageGroup', 'dadosPessoais', 'dadosConfiguracao']);
        
        return response()->json($user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function legacyUserPayloadForApiWrite(array $data, bool $isCreate = false): array
    {
        $name = null;

        if (array_key_exists('name', $data)) {
            $name = $data['name'];
        } elseif (array_key_exists('nome_completo', $data)) {
            $name = $data['nome_completo'];
        } elseif ($isCreate) {
            $name = 'Membro';
        }

        $payload = [
            'name' => $name,
            'email' => $data['email'] ?? null,
            'email_utilizador' => $data['email_utilizador'] ?? null,
            'password' => $data['password'] ?? null,
            'estado' => $data['estado'] ?? null,
            'perfil' => $data['perfil'] ?? null,
            'tipo_membro' => $data['tipo_membro'] ?? null,
            'numero_socio' => $data['numero_socio'] ?? null,
            'menor' => $data['menor'] ?? null,
            'ativo_desportivo' => $data['ativo_desportivo'] ?? null,
            'escalao' => $data['escalao'] ?? null,
        ];

        return array_filter($payload, static fn ($value) => $value !== null);
    }

    /**
     * DELETE /api/users/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->delete();
        
        return response()->json(['message' => 'User deleted successfully']);
    }
}
