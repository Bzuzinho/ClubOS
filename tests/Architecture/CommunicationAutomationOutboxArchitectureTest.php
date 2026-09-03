<?php

namespace Tests\Architecture;

use Tests\TestCase;

class CommunicationAutomationOutboxArchitectureTest extends TestCase
{
    public function test_automatic_producers_use_the_persistent_outbox_entrypoint(): void
    {
        $producerFiles = [
            app_path('Services/Communication/CommunicationAutomationService.php'),
            app_path('Services/Communication/SportsCommunicationGatewayService.php'),
        ];

        foreach ($producerFiles as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringContainsString('->queueIndividualCommunication(', $source, $path);
            $this->assertStringNotContainsString('->sendIndividualCommunication(', $source, $path);
        }
    }
}
