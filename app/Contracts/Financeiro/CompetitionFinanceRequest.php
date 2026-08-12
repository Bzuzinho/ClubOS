<?php

declare(strict_types=1);

namespace App\Contracts\Financeiro;

final readonly class CompetitionFinanceRequest
{
    /**
     * @param list<array{
     *   registration_id:string,
     *   state:string,
     *   amount_override:?float,
     *   age_group_id:?string,
     *   label:string
     * }> $registrations
     */
    public function __construct(
        public string $clubId,
        public string $competitionId,
        public string $athleteId,
        public string $competitionName,
        public string $competitionDate,
        public array $registrations,
        public ?float $legacyEventFee = null,
        public ?string $legacyCostCenterId = null,
        public ?string $legacyEventTitle = null,
        /** @var list<string> */
        public array $legacyInvoiceIds = [],
    ) {
    }
}
