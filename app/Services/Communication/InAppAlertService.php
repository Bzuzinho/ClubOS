<?php

namespace App\Services\Communication;

use App\Models\InAppAlert;
use App\Models\NotificationPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class InAppAlertService
{
    public function createAlerts(array $payload, Collection $recipients): int
    {
        if (! $this->inAppAlertsEnabled()) {
            return 0;
        }

        $created = 0;
        $affectedUserIds = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['user_id'])) {
                continue;
            }

            InAppAlert::create([
                'campaign_id' => $payload['campaign_id'] ?? null,
                'delivery_id' => $payload['delivery_id'] ?? null,
                'user_id' => $recipient['user_id'],
                'title' => $payload['title'],
                'message' => $payload['message'],
                'link' => $payload['link'] ?? null,
                'type' => $payload['type'] ?? 'info',
                'visible_from' => $payload['visible_from'] ?? now(),
                'visible_until' => $payload['visible_until'] ?? null,
            ]);

            $affectedUserIds[] = (string) $recipient['user_id'];
            $created++;
        }

        $this->forgetSharedAlertCaches($affectedUserIds);

        return $created;
    }

    public function markAsRead(string $alertId, string $userId): void
    {
        InAppAlert::where('id', $alertId)
            ->where('user_id', $userId)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $this->forgetSharedAlertCache($userId);
    }

    public function markAsUnread(string $alertId, string $userId): void
    {
        InAppAlert::where('id', $alertId)
            ->where('user_id', $userId)
            ->update([
                'is_read' => false,
                'read_at' => null,
            ]);

        $this->forgetSharedAlertCache($userId);
    }

    public function markAllAsRead(string $userId): void
    {
        InAppAlert::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $this->forgetSharedAlertCache($userId);
    }

    public function unreadCount(string $userId): int
    {
        return InAppAlert::where('user_id', $userId)
            ->where('is_read', false)
            ->where(function ($query) {
                $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('visible_until')->orWhere('visible_until', '>=', now());
            })
            ->count();
    }

    public function userFeed(string $userId, int $limit = 15): Collection
    {
        return InAppAlert::where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNull('visible_from')->orWhere('visible_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('visible_until')->orWhere('visible_until', '>=', now());
            })
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function deleteForUser(string $alertId, string $userId): void
    {
        InAppAlert::where('id', $alertId)
            ->where('user_id', $userId)
            ->delete();

        $this->forgetSharedAlertCache($userId);
    }

    private function inAppAlertsEnabled(): bool
    {
        if (! Schema::hasTable('notification_preferences')) {
            return true;
        }

        $preferences = NotificationPreference::query()->first();
        if (! $preferences) {
            return true;
        }

        $attributes = $preferences->getAttributes();
        if (! array_key_exists('alertas_aplicacao', $attributes) || $attributes['alertas_aplicacao'] === null) {
            return true;
        }

        return filter_var(
            $attributes['alertas_aplicacao'],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) !== false;
    }

    private function forgetSharedAlertCaches(array $userIds): void
    {
        collect($userIds)
            ->filter()
            ->unique()
            ->each(fn (string $userId) => $this->forgetSharedAlertCache($userId));
    }

    private function forgetSharedAlertCache(string $userId): void
    {
        Cache::forget('shared:communication_alerts:' . $userId);
    }
}
