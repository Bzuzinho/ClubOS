<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

require __DIR__.'/web_public.php';

Route::middleware(['auth', 'verified'])->group(function () {
    require __DIR__.'/web_dashboard.php';

    require __DIR__.'/web_website.php';

    require __DIR__.'/web_portal.php';

    require __DIR__.'/web_finance_fiscal_request_index.php';

    require __DIR__.'/web_members.php';

    require __DIR__.'/web_events.php';

    require __DIR__.'/web_sports.php';

    require __DIR__.'/web_finance.php';

    require __DIR__.'/web_logistics.php';

    require __DIR__.'/compat/web_finance_delete.php';

    require __DIR__.'/web_store_admin.php';

    require __DIR__.'/web_sponsorships.php';

    require __DIR__.'/web_communication.php';

    require __DIR__.'/web_marketing.php';
    
    require __DIR__.'/web_settings.php';

    require __DIR__.'/web_sports_resources.php';

    require __DIR__.'/web_finance_complementary.php';
});

require __DIR__.'/web_compatibility.php';

require __DIR__.'/auth.php';

require __DIR__.'/web_public_fallback.php';
