<?php

return [
    'automations' => [
        'monthly_fee_scheduler' => env('CLUBOS_MONTHLY_FEE_SCHEDULER', false),
        'release_invoice_communications_schedule' => env('CLUBOS_RELEASE_INVOICE_COMMUNICATIONS_SCHEDULE', false),
    ],
];