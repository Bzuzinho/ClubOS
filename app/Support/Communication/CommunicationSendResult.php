<?php

declare(strict_types=1);

namespace App\Support\Communication;

final readonly class CommunicationSendResult
{
    private function __construct(
        public bool $success,
        public string $provider,
        public ?string $providerMessageId,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function success(string $provider, string $providerMessageId): self
    {
        return new self(true, $provider, $providerMessageId, null, null);
    }

    public static function failure(string $provider, string $errorCode, string $errorMessage): self
    {
        return new self(false, $provider, null, $errorCode, $errorMessage);
    }

    /** @return array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
