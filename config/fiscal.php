<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Productive fiscal operation mode
    |--------------------------------------------------------------------------
    |
    | ClubOS currently records documents issued manually in Wintouch. Automated
    | provider calls are fail-closed unless a controlled release explicitly
    | selects provider_api and registers a FiscalDocumentProviderAdapter.
    |
    */
    'operation_mode' => env('FISCAL_OPERATION_MODE', 'manual_wintouch'),
    'provider' => env('FISCAL_PROVIDER', 'wintouch'),
];
