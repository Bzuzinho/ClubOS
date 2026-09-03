<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Services\Communication\Adapters\EmailChannelAdapter;
use App\Services\Communication\Adapters\FacebookChannelAdapter;
use App\Services\Communication\Adapters\InstagramChannelAdapter;
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
        FacebookChannelAdapter $facebook,
        InstagramChannelAdapter $instagram,
    ) {
        $this->adapters = [
            $email->channel() => $email,
            $sms->channel() => $sms,
            $push->channel() => $push,
            $facebook->channel() => $facebook,
            $instagram->channel() => $instagram,
        ];
    }

    public function resolve(string $channel): ?CommunicationChannelAdapter
    {
        return $this->adapters[$channel] ?? null;
    }
}
