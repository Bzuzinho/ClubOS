<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SportsArchitectureBoundaryGuard
{
    /**
     * Existing violations are explicit temporary debt. The guard prevents the
     * same boundary violation from spreading before the owning foundation phase
     * removes it. F3 closes the direct Desportivo -> Members service coupling:
     * Sports may consume member identity only through the neutral contract.
     *
     * @return array<string,array{scope:string,needles:list<string>,allowed_files:list<string>}>
     */
    public function rules(): array
    {
        return [
            'sports_finance_persistence_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Models\\Invoice;',
                    'use App\\Models\\InvoiceItem;',
                    'use App\\Models\\Movement;',
                    'use App\\Models\\FinancialEntry;',
                ],
                'allowed_files' => [
                    // F5 debt: replace direct invoice creation with a Financeiro contract.
                    'app/Services/Desportivo/CreateCompetitionRegistrationAction.php',
                ],
            ],
            'events_competition_master_boundary' => [
                'scope' => 'app/Services/Eventos',
                'needles' => [
                    'use App\\Models\\Competition;',
                ],
                'allowed_files' => [
                    // F4 debt: invert Event -> Competition into Competition -> Event projection.
                    'app/Services/Eventos/EventLifecycleService.php',
                ],
            ],
            'sports_member_read_coupling_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Services\\Members\\MemberDataReadService;',
                    'use App\\Services\\Members\\MemberTypeResolver;',
                ],
                // F3 closed this debt. Sports receives identity facts through
                // App\Contracts\Members\MemberSportsIdentityProvider only.
                'allowed_files' => [],
            ],
        ];
    }

    /**
     * @return list<array{rule:string,file:string,needle:string}>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->rules() as $ruleName => $rule) {
            foreach ($this->phpFiles($rule['scope']) as $relativePath => $source) {
                if (in_array($relativePath, $rule['allowed_files'], true)) {
                    continue;
                }

                foreach ($rule['needles'] as $needle) {
                    if (! str_contains($source, $needle)) {
                        continue;
                    }

                    $violations[] = [
                        'rule' => $ruleName,
                        'file' => $relativePath,
                        'needle' => $needle,
                    ];
                }
            }
        }

        return $violations;
    }

    /** @return array<string,string> */
    private function phpFiles(string $scope): array
    {
        $absoluteScope = base_path($scope);
        if (! is_dir($absoluteScope)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteScope));

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen(base_path()) + 1));
            $files[$relativePath] = (string) file_get_contents($absolutePath);
        }

        ksort($files);

        return $files;
    }
}
