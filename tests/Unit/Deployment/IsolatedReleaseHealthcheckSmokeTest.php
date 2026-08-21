<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class IsolatedReleaseHealthcheckSmokeTest extends TestCase
{
    public function test_release_healthcheck_command_returns_http_200_for_up(): void
    {
        $root = dirname(__DIR__, 3);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, sprintf('Could not reserve local port: %s (%d)', $error, $errno));

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);

        $separator = strrpos($address, ':');
        self::assertNotFalse($separator);
        $port = (int) substr($address, $separator + 1);
        self::assertGreaterThan(0, $port);

        $logPath = sys_get_temp_dir().'/clubos-release-healthcheck-'.getmypid().'-'.$port.'.log';
        $process = proc_open(
            [
                PHP_BINARY,
                '-S',
                '127.0.0.1:'.$port,
                '-t',
                'public',
                'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $logPath, 'a'],
                2 => ['file', $logPath, 'a'],
            ],
            $pipes,
            $root,
        );

        self::assertIsResource($process, 'Could not start isolated PHP server');
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = '';

        try {
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $output = [];
                $exitCode = 0;
                exec(
                    'curl --silent --output /dev/null --write-out '.escapeshellarg('%{http_code}')
                    .' --max-time 2 '.escapeshellarg('http://127.0.0.1:'.$port.'/up').' 2>/dev/null',
                    $output,
                    $exitCode,
                );
                $status = trim(implode("\n", $output));

                if ($exitCode === 0 && $status === '200') {
                    break;
                }

                $processStatus = proc_get_status($process);
                if (! $processStatus['running']) {
                    break;
                }

                usleep(200000);
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        @unlink($logPath);

        self::assertSame('200', $status, "Isolated release /up did not return HTTP 200.\n{$log}");
    }
}
