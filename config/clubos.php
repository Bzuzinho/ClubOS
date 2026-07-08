<?php

return [
    'automations' => [
        'monthly_fee_scheduler' => env('CLUBOS_MONTHLY_FEE_SCHEDULER', false),
        'release_invoice_communications_schedule' => env('CLUBOS_RELEASE_INVOICE_COMMUNICATIONS_SCHEDULE', false),
    ],
    'financeiro' => [
        'monthly_fee_eligible_member_types' => array_values(array_filter(array_map(
            static fn (string $type): string => trim($type),
            explode(',', (string) env('CLUBOS_MONTHLY_FEE_ELIGIBLE_MEMBER_TYPES', 'atleta')),
        ), static fn (string $type): bool => $type !== '')),
    ],
];