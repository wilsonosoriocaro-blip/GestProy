<?php

namespace App\Notifications\Projects;

use App\Models\User;
use App\Notifications\Channels\TeamsWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base of every projects-module notification: one message (title, body,
 * link, icon) rendered for each channel. Channels come from configuration,
 * minus email when the person turned it off. Queued and sent only after
 * the surrounding database transaction commits.
 */
abstract class ProjectNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    abstract public function title(): string;

    abstract public function body(): string;

    abstract public function url(): string;

    /** Heroicon shown in the bell. */
    public function icon(): string
    {
        return 'bell';
    }

    /** "info" or "warning": warnings are highlighted in the bell and email. */
    public function level(): string
    {
        return 'info';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        $channels = config()->array('projects.notifications.channels');

        if ($notifiable instanceof User && ! $notifiable->email_notifications) {
            $channels = array_diff($channels, ['mail']);
        }

        return array_values(array_map(fn ($channel) => $channel === 'teams' ? TeamsWebhookChannel::class : (string) $channel, $channels));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'icon' => $this->icon(),
            'level' => $this->level(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($notifiable instanceof User ? "Hola, {$notifiable->name}" : 'Hola')
            ->line($this->body())
            ->action('Ver en '.config('app.name'), $this->url());

        return $this->level() === 'warning' ? $mail->error() : $mail;
    }

    /**
     * Microsoft Teams message (incoming webhook, Adaptive Card).
     *
     * @return array<string, mixed>
     */
    public function toTeams(object $notifiable): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $this->title(), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true,
                            'color' => $this->level() === 'warning' ? 'Attention' : 'Default'],
                        ['type' => 'TextBlock', 'text' => $this->body(), 'wrap' => true],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Abrir', 'url' => $this->url()]],
                ],
            ]],
        ];
    }
}
