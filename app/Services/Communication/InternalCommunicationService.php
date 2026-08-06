<?php

namespace App\Services\Communication;

use App\Models\InAppAlert;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InternalCommunicationService
{
    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly InAppAlertService $inAppAlertService,
    ) {
    }

    public function send(User $sender, array $payload): InternalMessage
    {
        return DB::transaction(function () use ($sender, $payload) {
            $recipientIds = collect($payload['recipient_ids'] ?? [])
                ->filter(fn ($id) => is_string($id) && $id !== '' && $id !== $sender->id)
                ->unique()
                ->values();

            $message = InternalMessage::create([
                'sender_id' => $sender->id,
                'parent_id' => $payload['parent_id'] ?? null,
                'subject' => trim((string) $payload['subject']),
                'message' => trim((string) $payload['message']),
                'type' => $payload['type'] ?? 'info',
            ]);

            $recipients = User::query()
                ->whereIn('id', $recipientIds)
                ->get(['id', 'name']);

            foreach ($recipients as $recipient) {
                $alert = $this->inAppAlertService->createAlert([
                    'title' => $message->subject,
                    'message' => $this->excerpt($message->message),
                    'link' => route('membros.show', [
                        'member' => $recipient->id,
                        'tab' => 'communications',
                        'folder' => 'received',
                        'message' => $message->id,
                    ]),
                    'type' => $message->type,
                    'visible_from' => now(),
                ], (string) $recipient->id);

                InternalMessageRecipient::create([
                    'internal_message_id' => $message->id,
                    'recipient_id' => $recipient->id,
                    'in_app_alert_id' => $alert?->id,
                    'is_read' => false,
                ]);
            }

            return $message->load([
                'sender.dadosPessoais',
                'parent.sender.dadosPessoais',
                'recipients.recipient.dadosPessoais',
            ]);
        });
    }

    public function markAsRead(InternalMessageRecipient $recipient): void
    {
        $recipient->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();

        if ($recipient->in_app_alert_id) {
            InAppAlert::where('id', $recipient->in_app_alert_id)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    public function markAsUnread(InternalMessageRecipient $recipient): void
    {
        $recipient->forceFill([
            'is_read' => false,
            'read_at' => null,
        ])->save();

        if ($recipient->in_app_alert_id) {
            InAppAlert::where('id', $recipient->in_app_alert_id)->update([
                'is_read' => false,
                'read_at' => null,
            ]);

            return;
        }

        $recipient->loadMissing('message');

        $alert = $this->inAppAlertService->createAlert([
            'title' => $recipient->message->subject,
            'message' => $this->excerpt($recipient->message->message),
            'link' => route('membros.show', [
                'member' => $recipient->recipient_id,
                'tab' => 'communications',
                'folder' => 'received',
                'message' => $recipient->internal_message_id,
            ]),
            'type' => $recipient->message->type,
            'visible_from' => now(),
        ], (string) $recipient->recipient_id);

        if ($alert) {
            $recipient->forceFill([
                'in_app_alert_id' => $alert->id,
            ])->save();
        }
    }

    public function markAllReceivedAsRead(string $userId): void
    {
        $recipients = InternalMessageRecipient::query()
            ->where('recipient_id', $userId)
            ->whereNull('deleted_at')
            ->where('is_read', false)
            ->get(['id', 'in_app_alert_id']);

        if ($recipients->isEmpty()) {
            return;
        }

        $timestamp = now();

        InternalMessageRecipient::query()
            ->whereIn('id', $recipients->pluck('id'))
            ->update([
                'is_read' => true,
                'read_at' => $timestamp,
            ]);

        $alertIds = $recipients
            ->pluck('in_app_alert_id')
            ->filter()
            ->values();

        if ($alertIds->isEmpty()) {
            return;
        }

        InAppAlert::query()
            ->whereIn('id', $alertIds)
            ->update([
                'is_read' => true,
                'read_at' => $timestamp,
            ]);
    }

    public function deleteReceived(InternalMessageRecipient $recipient): void
    {
        $recipient->forceFill([
            'deleted_at' => now(),
        ])->save();

        if ($recipient->in_app_alert_id) {
            InAppAlert::where('id', $recipient->in_app_alert_id)->delete();

            $recipient->forceFill([
                'in_app_alert_id' => null,
            ])->save();
        }
    }

    public function deleteSent(InternalMessage $message): void
    {
        $message->forceFill([
            'sender_deleted_at' => now(),
        ])->save();
    }

    public function receivedFeed(string $userId): Collection
    {
        $internalRecipients = InternalMessageRecipient::query()
            ->with(['message.sender.dadosPessoais', 'message.parent.sender.dadosPessoais'])
            ->where('recipient_id', $userId)
            ->whereNull('deleted_at')
            ->latest()
            ->get();

        $internalFeed = $internalRecipients
            ->map(function (InternalMessageRecipient $recipient) {
                $message = $recipient->message;
                $sender = $message->sender;
                $parent = $message->parent;

                return [
                    'recipient_entry_id' => $recipient->id,
                    'message_id' => $message->id,
                    'folder' => 'received',
                    'subject' => $message->subject,
                    'message' => $message->message,
                    'type' => $message->type,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                    'is_read' => $recipient->is_read,
                    'read_at' => optional($recipient->read_at)?->toIso8601String(),
                    'sender' => [
                        'id' => $sender?->id,
                        'name' => $this->displayName($sender),
                        'email' => $sender?->email,
                    ],
                    'source' => 'internal',
                    'alert_id' => $recipient->in_app_alert_id,
                    'link' => null,
                    'reply_to' => $parent ? [
                        'id' => $parent->id,
                        'subject' => $parent->subject,
                        'sender_name' => $this->displayName($parent->sender),
                    ] : null,
                ];
            });

        $linkedAlertIds = $internalRecipients
            ->pluck('in_app_alert_id')
            ->filter()
            ->values();

        $standaloneAlerts = InAppAlert::query()
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('visible_until')->orWhere('visible_until', '>=', now());
            })
            ->when($linkedAlertIds->isNotEmpty(), function ($query) use ($linkedAlertIds) {
                $query->whereNotIn('id', $linkedAlertIds);
            })
            ->latest()
            ->get();

        $alertFeed = $standaloneAlerts->map(function (InAppAlert $alert) {
            return [
                'recipient_entry_id' => null,
                'message_id' => 'alert-' . $alert->id,
                'folder' => 'received',
                'subject' => $alert->title,
                'message' => $alert->message,
                'type' => $alert->type,
                'created_at' => optional($alert->created_at)?->toIso8601String(),
                'is_read' => (bool) $alert->is_read,
                'read_at' => optional($alert->read_at)?->toIso8601String(),
                'sender' => [
                    'id' => null,
                    'name' => 'Sistema',
                    'email' => null,
                ],
                'source' => 'alert',
                'alert_id' => $alert->id,
                'link' => $alert->link,
                'reply_to' => null,
            ];
        });

        return $internalFeed
            ->concat($alertFeed)
            ->sortByDesc('created_at')
            ->values();
    }

    public function sentFeed(string $userId): Collection
    {
        return InternalMessage::query()
            ->with(['recipients.recipient.dadosPessoais', 'parent.sender.dadosPessoais'])
            ->where('sender_id', $userId)
            ->whereNull('sender_deleted_at')
            ->latest()
            ->get()
            ->map(function (InternalMessage $message) {
                $parent = $message->parent;

                return [
                    'message_id' => $message->id,
                    'folder' => 'sent',
                    'subject' => $message->subject,
                    'message' => $message->message,
                    'type' => $message->type,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                    'recipient_count' => $message->recipients->count(),
                    'recipients' => $message->recipients->map(function (InternalMessageRecipient $recipient) {
                        return [
                            'id' => $recipient->recipient_id,
                            'name' => $this->displayName($recipient->recipient),
                            'email' => $recipient->recipient?->email,
                            'is_read' => $recipient->is_read,
                            'read_at' => optional($recipient->read_at)?->toIso8601String(),
                            'deleted_at' => optional($recipient->deleted_at)?->toIso8601String(),
                        ];
                    })->values(),
                    'reply_to' => $parent ? [
                        'id' => $parent->id,
                        'subject' => $parent->subject,
                        'sender_name' => $this->displayName($parent->sender),
                    ] : null,
                ];
            })
            ->values();
    }

    private function excerpt(string $message): string
    {
        return mb_strimwidth(trim($message), 0, 160, '...');
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'Utilizador removido';
        }

        $user->loadMissing('dadosPessoais');

        return $this->memberIdentityDisplayResolver->displayName($user);
    }
}
