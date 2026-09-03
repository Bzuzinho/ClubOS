<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Services\Communication\Adapters\EmailChannelAdapter;
use App\Services\Communication\Adapters\PushChannelAdapter;
use App\Services\Communication\Adapters\SmsChannelAdapter;

final class CommunicationChannelAdapterRegistry
{
    /** @var array<string,CommunicationChannelAdapter> */
    private array $adapters;

    public function __construct(
        EmailChannelAdapter $email,
        SmsChannelAdapter $sms,
        PushChannelAdapter $push,
    ) {
        $this->adapters = [
            $email->channel() => $email,
            $sms->channel() => $sms,
            $push->channel() => $push,
        ];
    }

    public function resolve(string $channel): ?CommunicationChannelAdapter
    {
        return $this->adapters[$channel] ?? null;
    }
}
