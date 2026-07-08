<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\Financeiro\MemberCostCenterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberCostCenterResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_cost_centers_are_used_for_member_show_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Membro Centro Canonico',
            'estado' => 'ativo',
        ]);

        $firstCenter = $this->createCostCenter('CC-RESOLVE-01', 'Centro Resolver 01');
        $secondCenter = $this->createCostCenter('CC-RESOLVE-02', 'Centro Resolver 02');

        $this->attachCostCenters($member, [
            [$firstCenter->id, 2.5],
            [$secondCenter->id, 1.5],
        ]);

        $resolver = app(MemberCostCenterResolver::class);
        $resolved = $resolver->resolveForUser($member->fresh());

        $this->assertTrue($resolver->hasCanonicalCostCenters($member->fresh()));
        $this->assertFalse($resolver->hasLegacyFallback($member->fresh()));
        $this->assertSame('canonical', $resolved['source']);
        $this->assertSame([$firstCenter->id, $secondCenter->id], $resolved['centro_custo']);
        $this->assertSame(2, count($resolved['centro_custo_pesos']));

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $this->assertSame([$firstCenter->id, $secondCenter->id], $response->json('props.member.centro_custo'));
        $this->assertSame([
            ['id' => $firstCenter->id, 'peso' => 2.5],
            ['id' => $secondCenter->id, 'peso' => 1.5],
        ], $response->json('props.member.centro_custo_pesos'));
    }

    public function test_legacy_cost_centers_are_used_when_pivot_is_empty(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Membro Centro Legacy',
            'estado' => 'ativo',
        ]);

        $resolver = app(MemberCostCenterResolver::class);
        $resolved = $resolver->resolveForUser($member->fresh());

        $this->assertFalse($resolver->hasCanonicalCostCenters($member->fresh()));
        $this->assertFalse($resolver->hasLegacyFallback($member->fresh()));
        $this->assertSame('none', $resolved['source']);
        $this->assertSame([], $resolved['centro_custo']);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $this->assertSame([], $response->json('props.member.centro_custo'));
        $this->assertSame([], $response->json('props.member.centro_custo_pesos'));
    }

    public function test_divergence_between_canonical_and_legacy_cost_centers_is_detected(): void
    {
        $member = User::factory()->create([
            'nome_completo' => 'Membro Divergente',
            'estado' => 'ativo',
        ]);

        $center = $this->createCostCenter('CC-DIVERGENT-01', 'Centro Divergente 01');
        $this->attachCostCenters($member, [[$center->id, 2]]);

        $resolver = app(MemberCostCenterResolver::class);
        $divergence = $resolver->detectDivergence($member->fresh());

        $this->assertTrue($divergence['has_canonical_cost_centers']);
        $this->assertFalse($divergence['has_legacy_fallback']);
        $this->assertFalse($divergence['has_divergence']);
        $this->assertSame([$center->id], $divergence['canonical_ids']);
        $this->assertSame([], $divergence['legacy_ids']);
        $this->assertSame([], $divergence['weight_mismatches']);
        $this->assertSame([$center->id], $divergence['missing_in_legacy']);
        $this->assertSame([], $divergence['missing_in_canonical']);
    }

    private function createCostCenter(string $codigo, string $nome): CostCenter
    {
        return CostCenter::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
    }

    /**
     * @param list<array{0:string,1:float|int}> $entries
     */
    private function attachCostCenters(User $member, array $entries): void
    {
        $now = now();

        foreach ($entries as [$centerId, $peso]) {
            DB::table('centro_custo_user')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $member->id,
                'centro_custo_id' => $centerId,
                'peso' => $peso,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }
}