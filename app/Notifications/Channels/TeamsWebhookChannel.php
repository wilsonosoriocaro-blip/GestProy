<?php

namespace App\Notifications\Channels;

use App\Notifications\Projects\ProjectNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a notification to a Microsoft Teams incoming webhook. Team-wide:
 * it is used through Notification::route('teams', $url). Other external
 * channels (WhatsApp, SMS…) follow the same shape: a class with send().
 */
class TeamsWebhookChannel
{
    public function send(object $notifiable, ProjectNotification $notification): void
    {
        $url = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor(self::class, $notification) ?? $notifiable->routeNotificationFor('teams', $notification)
            : null;

        if (! is_string($url) || $url === '') {
            return;
        }

        $response = Http::timeout(10)->post($url, $notification->toTeams($notifiable));

        if ($response->failed()) {
            Log::warning('Teams webhook failed', ['status' => $response->status(), 'notification' => $notification::class]);
        }
    }
}
