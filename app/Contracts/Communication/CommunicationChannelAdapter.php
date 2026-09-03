<?php

declare(strict_types=1);

namespace App\Contracts\Communication;

use App\Support\Communication\CommunicationSendResult;

interface CommunicationChannelAdapter
{
    public function channel(): string;

    /** @param array<string,mixed> $recipient */
    public function send(
        array $recipient,
        ?string $subject,
        ?string $body,
        string $idempotencyKey,
    ): CommunicationSendResult;
}
