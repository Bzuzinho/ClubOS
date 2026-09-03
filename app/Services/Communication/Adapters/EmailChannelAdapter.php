<?php

declare(strict_types=1);

namespace App\Services\Communication\Adapters;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Support\Communication\CommunicationSendResult;
use Illuminate\Support\Facades\Mail;

final class EmailChannelAdapter implements CommunicationChannelAdapter
{
    public function channel(): string
    {
        return 'email';
    }

    public function send(array $recipient, ?string $subject, ?string $body, string $idempotencyKey): CommunicationSendResult
    {
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '') {
            return CommunicationSendResult::failure('mail', 'missing_email', 'Destinatário sem email válido.');
        }

        $messageId = $idempotencyKey.'@clubos.local';
        $sentMessage = Mail::raw($body ?: '', function ($message) use ($email, $subject, $messageId): void {
            $message->to($email)
                ->subject($subject ?: 'Comunicação ClubOS');

            $headers = $message->getSymfonyMessage()->getHeaders();
            if (! $headers->has('Message-ID')) {
                $headers->addIdHeader('Message-ID', $messageId);
            }
        });

        return CommunicationSendResult::success(
            'mail:'.(string) config('mail.default', 'smtp'),
            $sentMessage?->getMessageId() ?: $messageId,
        );
    }
}
