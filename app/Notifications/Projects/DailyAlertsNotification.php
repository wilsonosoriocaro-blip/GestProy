<?php

namespace App\Notifications\Projects;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * One message per person per business day with the work that needs
 * attention: overdue and due-soon tasks and projects.
 *
 * @phpstan-type Item array{label: string, detail: string, url: string}
 */
class DailyAlertsNotification extends ProjectNotification
{
    public const SECTIONS = [
        'tasks_overdue' => 'Tareas vencidas',
        'tasks_due_soon' => 'Tareas próximas a vencer',
        'projects_overdue' => 'Proyectos atrasados',
        'projects_due_soon' => 'Proyectos próximos a vencer',
    ];

    /**
     * @param  array<string, list<array{label: string, detail: string, url: string}>>  $sections
     */
    public function __construct(public readonly array $sections)
    {
        parent::__construct();
    }

    public function count(string $section): int
    {
        return count($this->sections[$section] ?? []);
    }

    public function title(): string
    {
        return 'Tus pendientes de hoy';
    }

    public function body(): string
    {
        $parts = [];

        foreach (self::SECTIONS as $key => $label) {
            if ($this->count($key) > 0) {
                $parts[] = mb_strtolower($label).': '.$this->count($key);
            }
        }

        return ucfirst(implode(' · ', $parts)).'.';
    }

    public function url(): string
    {
        return route('dashboard');
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function level(): string
    {
        return $this->count('tasks_overdue') + $this->count('projects_overdue') > 0 ? 'warning' : 'info';
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Built from scratch: lines added after the button would land below it.
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($notifiable instanceof User ? "Hola, {$notifiable->name}" : 'Hola')
            ->line('Esto necesita tu atención hoy:');

        if ($this->level() === 'warning') {
            $mail->error();
        }

        foreach (self::SECTIONS as $key => $label) {
            if ($this->count($key) === 0) {
                continue;
            }

            $mail->line("**{$label}**");

            foreach (array_slice($this->sections[$key], 0, 10) as $item) {
                $mail->line("• [{$item['label']}]({$item['url']}) — {$item['detail']}");
            }

            if ($this->count($key) > 10) {
                $mail->line('… y '.($this->count($key) - 10).' más.');
            }
        }

        return $mail->action('Ver en '.config('app.name'), $this->url());
    }
}
