<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class E2eBrowserTestSeeder extends Seeder
{
    private const PASSWORD = 'ClubOS-E2E-2026!';

    /**
     * @var list<string>
     */
    private const PROJECTS = [
        'chromium-desktop',
        'firefox-desktop',
        'webkit-desktop',
        'chromium-mobile',
        'webkit-mobile',
    ];

    public function run(PlatformAccessService $platformAccessService): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2eBrowserTestSeeder may only run in the testing environment.');
        }

        foreach (self::PROJECTS as $project) {
            $email = self::emailForProject($project);
            $attributes = [
                'name' => sprintf('Browser QA %s', $project),
                'nome_completo' => sprintf('Browser QA %s', $project),
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
                'perfil' => 'admin',
                'tipo_membro' => ['Admin'],
                'estado' => 'ativo',
                'data_nascimento' => '1990-01-01',
                'menor' => false,
                'afiliacao' => false,
                'declaracao_de_transporte' => false,
                'ativo_desportivo' => false,
                'rgpd' => true,
                'consentimento' => true,
            ];

            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                $user = User::factory()->admin()->create($attributes);
            } else {
                $user->forceFill($attributes)->save();
            }

            $platformAccessService->grantPlatformAccess(
                $user,
                notes: 'Deterministic browser QA fixture.',
            );
        }
    }

    public static function emailForProject(string $project): string
    {
        return sprintf('e2e.%s@clubos.test', $project);
    }
}
