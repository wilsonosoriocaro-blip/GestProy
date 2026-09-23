<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Services\Projects\ScheduleCalculator;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BusinessCalendar::class, fn (): BusinessCalendar => new BusinessCalendar(
            array_values(array_map(fn (mixed $day): int => (int) $day, config()->array('business_calendar.weekend_days'))),
            array_values(array_map(fn (mixed $date): string => (string) $date, config()->array('business_calendar.extra_non_working_days'))),
        ));

        $this->app->singleton(ScheduleCalculator::class, fn ($app): ScheduleCalculator => new ScheduleCalculator(
            $app->make(BusinessCalendar::class),
            config()->integer('business_calendar.due_soon_business_days'),
            config()->integer('business_calendar.behind_schedule_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Short, stable aliases for polymorphic columns (activity log subjects).
        Relation::morphMap([
            'project' => Project::class,
            'project_task' => ProjectTask::class,
            'project_comment' => ProjectComment::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
