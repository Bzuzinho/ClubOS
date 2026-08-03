<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Services\Members\UsersLegacyReadScanner;
use Tests\TestCase;

final class Pr84LegacyReadDiagnosticsTest extends TestCase
{
    public function test_prints_current_legacy_read_findings(): void
    {
        $result = app(UsersLegacyReadScanner::class)->scan();

        fwrite(STDERR, "\nPR84_LEGACY_FINDINGS=" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->assertTrue(true);
    }
}
