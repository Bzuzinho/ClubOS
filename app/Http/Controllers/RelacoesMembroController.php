<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Legacy tombstone for the pre-H2 generic member relationship API.
 *
 * Family and guardian mutations are canonical through
 * MemberFamilyRelationsController + FamilyRelationshipService. Keeping this
 * controller temporarily gives old callers an explicit 410 instead of
 * allowing a second source of truth to continue writing user_relationships.
 * The routes/controller can be removed physically after the H2.2 data audit
 * confirms that production user_relationships are fully projected.
 */
class RelacoesMembroController extends Controller
{
    public function index(User $member): JsonResponse
    {
        return $this->gone();
    }

    public function store(Request $request, User $member): JsonResponse
    {
        return $this->gone();
    }

    public function destroy(User $member, UserRelationship $relationship): JsonResponse
    {
        return $this->gone();
    }

    private function gone(): JsonResponse
    {
        return response()->json([
            'message' => 'Esta API de relações foi retirada. Utilize a gestão canónica de Família/EE.',
            'replacement' => 'membros.familia.*',
        ], 410);
    }
}
