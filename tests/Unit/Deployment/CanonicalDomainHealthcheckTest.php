<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class CanonicalDomainHealthcheckTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/clubos-domain-healthcheck-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory.'/bin', 0777, true);
        file_put_contents($this->temporaryDirectory.'/.env', "APP_URL=https://bscn.pt\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/bin/curl');
        @unlink($this->temporaryDirectory.'/.env');
        @rmdir($this->temporaryDirectory.'/bin');
        @rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_accepts_the_canonical_host_and_exact_www_redirect(): void
    {
        $this->installFakeCurl(<<<'BASH'
url="${*: -1}"
if [[ "$url" == "https://bscn.pt/" || "$url" == "https://bscn.pt/login" || "$url" == "https://bscn.pt/up" ]]; then printf '200'; exit 0; fi
if [[ "$url" == "https://www.bscn.pt/" ]]; then printf '301|https://bscn.pt/'; exit 0; fi
if [[ "$url" == "https://www.bscn.pt/login" ]]; then printf '301|https://bscn.pt/login'; exit 0; fi
if [[ "$url" == "https://www.bscn.pt/up" ]]; then printf '200'; exit 0; fi
exit 2
BASH);

        [$exitCode, $output] = $this->runHealthcheck();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('canonical / HTTP 200', $output);
        self::assertStringContainsString('canonical /login HTTP 200', $output);
        self::assertStringContainsString('canonical /up HTTP 200', $output);
        self::assertStringContainsString('alias www.bscn.pt/ HTTP 301 -> https://bscn.pt/', $output);
        self::assertStringContainsString('alias www.bscn.pt/login HTTP 301 -> https://bscn.pt/login', $output);
        self::assertStringContainsString('alias health www.bscn.pt/up HTTP 200', $output);
    }

    public function test_it_rejects_a_www_redirect_that_keeps_www(): void
    {
        $this->installFakeCurl(<<<'BASH'
url="${*: -1}"
if [[ "$url" == https://bscn.pt/* ]]; then printf '200'; exit 0; fi
if [[ "$url" == "https://www.bscn.pt/" ]]; then printf '301|https://www.bscn.pt/'; exit 0; fi
exit 2
BASH);

        [$exitCode, $output] = $this->runHealthcheck();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('deve devolver 301 para https://bscn.pt/', $output);
    }

    public function test_it_rejects_www_as_the_application_url(): void
    {
        file_put_contents($this->temporaryDirectory.'/.env', "APP_URL=https://www.bscn.pt\n");
        $this->installFakeCurl('exit 99');

        [$exitCode, $output] = $this->runHealthcheck();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('APP_URL não pode usar o alias www', $output);
    }

    private function installFakeCurl(string $body): void
    {
        $script = "#!/usr/bin/env bash\nset -e\n{$body}\n";
        file_put_contents($this->temporaryDirectory.'/bin/curl', $script);
        chmod($this->temporaryDirectory.'/bin/curl', 0755);
    }

    /** @return array{int, string} */
    private function runHealthcheck(): array
    {
        $root = dirname(__DIR__, 3);
        $command = 'PATH='.escapeshellarg($this->temporaryDirectory.'/bin:'.getenv('PATH'))
            .' bash '.escapeshellarg($root.'/bin/remote-healthcheck.sh')
            .' '.escapeshellarg($this->temporaryDirectory).' 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
    }
}
