<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SportsArchitectureBoundaryGuard
{
    /**
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
                    'use App\\Models\\PaymentAllocation;',
                    'use App\\Models\\FiscalDocumentRequest;',
                    'use App\\Models\\BankTransactionAllocation;',
                ],
                'allowed_files' => [],
            ],
            'sports_communication_persistence_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Models\\CommunicationCampaign;',
                    'use App\\Models\\CommunicationDelivery;',
                    'use App\\Models\\CommunicationTemplate;',
                    'use App\\Models\\CommunicationSegment;',
                    'use App\\Models\\InAppAlert;',
                    'use App\\Services\\Communication\\',
                ],
                'allowed_files' => [],
            ],
            'sports_logistics_persistence_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Models\\Product;',
                    'use App\\Models\\StockMovement;',
                    'use App\\Models\\LogisticsRequest;',
                    'use App\\Models\\LogisticsRequestItem;',
                    'use App\\Models\\EquipmentLoan;',
                    'use App\\Services\\Logistica\\',
                    'use App\\Services\\Inventario\\',
                    'use App\\Services\\Catalog\\CanonicalProductStockService;',
                ],
                'allowed_files' => [],
            ],
            'sports_legacy_runtime_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Models\\TrainingSession;',
                    'use App\\Models\\Team;',
                    'use App\\Models\\TeamMember;',
                    'use App\\Models\\CallUp;',
                    'use App\\Models\\EventAttendance;',
                ],
                'allowed_files' => [],
            ],
            'finance_competition_legacy_pointer_boundary' => [
                'scope' => 'app/Services/Financeiro/CompetitionFinancialObligationService.php',
                'needles' => [
                    'syncCompatibilityInvoicePointers',
                    'clearCompatibilityInvoicePointers',
                    'legacyEventFee',
                    'legacyCostCenterId',
                ],
                'allowed_files' => [],
            ],
            'communication_sports_legacy_audience_boundary' => [
                'scope' => 'app/Services/Communication/SegmentResolverService.php',
                'needles' => [
                    'use App\\Models\\TeamMember;',
                    'usersHaveAgeGroupColumn',
                ],
                'allowed_files' => [],
            ],
            'events_competition_master_boundary' => [
                'scope' => 'app/Services/Eventos',
                'needles' => [
                    'use App\\Models\\Competition;',
                ],
                'allowed_files' => [],
            ],
            'sports_member_read_coupling_boundary' => [
                'scope' => 'app/Services/Desportivo',
                'needles' => [
                    'use App\\Services\\Members\\MemberDataReadService;',
                    'use App\\Services\\Members\\MemberTypeResolver;',
                ],
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

        if (is_file($absoluteScope)) {
            return [str_replace('\\', '/', $scope) => (string) file_get_contents($absoluteScope)];
        }

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
