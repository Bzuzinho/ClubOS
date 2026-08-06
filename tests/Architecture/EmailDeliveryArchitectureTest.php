<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

class EmailDeliveryArchitectureTest extends TestCase
{
    public function test_production_code_only_sends_email_from_authorized_services(): void
    {
        $authorizedFiles = [
            app_path('Services/Communication/CommunicationDeliveryService.php'),
            app_path('Services/Website/PublicFormWorkflowService.php'),
        ];

        $violations = [];
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())),
            '/\.php$/i'
        );

        $patterns = [
            '/\bMail::(?:to|cc|bcc|send|queue|later|raw|html)\s*\(/',
            '/\bNotification::send(?:Now)?\s*\(/',
            '/->notify(?:Now)?\s*\(/',
            '/\bapp\s*\(\s*[\'\"]mailer[\'\"]\s*\)/',
            '/\bresolve\s*\(\s*[\'\"]mailer[\'\"]\s*\)/',
        ];

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (in_array($path, $authorizedFiles, true)) {
                continue;
            }

            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($violations)),
            "Foram detetados envios de email fora dos servicos autorizados:\n- " . implode("\n- ", array_unique($violations))
        );
    }
}
