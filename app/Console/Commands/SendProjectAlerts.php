<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Projects\DailyAlertsNotification;
use App\Services\Notifications\DailyAlertsBuilder;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('projects:send-alerts {--date= : Fecha de referencia (Y-m-d), por defecto hoy} {--force : Enviar aunque no sea día hábil}')]
#[Description('Envía a cada persona el resumen diario de tareas y proyectos vencidos o próximos a vencer')]
class SendProjectAlerts extends Command
{
    public function handle(DailyAlertsBuilder $builder, BusinessCalendar $calendar): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay()
            : CarbonImmutable::today();

        if (! $calendar->isBusinessDay($today) && ! $this->option('force')) {
            $this->info("{$today->toDateString()} no es día hábil: no se envían alertas.");

            return self::SUCCESS;
        }

        $digests = $builder->build($today);
        $users = User::query()->active()->whereKey(array_keys($digests))->get()->keyBy('id');
        $sent = 0;

        foreach ($digests as $userId => $sections) {
            $user = $users->get($userId);

            if ($user === null) {
                continue;
            }

            // The unique (user, date) row makes a second run on the same day a no-op.
            $claimed = DB::table('project_alert_digests')->insertOrIgnore([
                'user_id' => $userId,
                'digest_date' => $today->toDateString(),
                'items' => array_sum(array_map('count', $sections)),
            ]);

            if ($claimed === 0) {
                continue;
            }

            $user->notify(new DailyAlertsNotification($sections));
            $sent++;
        }

        $this->info("Resúmenes enviados: {$sent}.");

        return self::SUCCESS;
    }
}
