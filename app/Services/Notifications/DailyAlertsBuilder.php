<?php

namespace App\Services\Notifications;

use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Queries\Projects\TaskSignals;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the daily digest per person, with the same definitions as the
 * dashboard and task alerts (TaskSignals): open tasks overdue or due soon
 * go to their assignee (to the project owner when unassigned); open
 * projects overdue or due soon go to their owner. Archived projects and
 * deactivated people are left out.
 */
class DailyAlertsBuilder
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @return array<int, array<string, list<array{label: string, detail: string, url: string}>>> Sections per user id.
     */
    public function build(CarbonImmutable $today): array
    {
        $signals = TaskSignals::make($today);
        $digests = [];

        foreach ([TaskSignals::OVERDUE => 'tasks_overdue', TaskSignals::DUE_SOON => 'tasks_due_soon'] as $signal => $section) {
            $query = ProjectTask::query()
                ->whereHas('project', fn (Builder $q) => $q->notArchived())
                ->with(['project:id,code,name,owner_id', 'assignee:id,deactivated_at']);
            $signals->apply($query, $signal);

            foreach ($query->orderBy('due_date')->get() as $task) {
                $recipient = $task->assignee !== null && $task->assignee->isActive() ? $task->assignee->id : $task->project->owner_id;

                $digests[$recipient][$section][] = [
                    'label' => $task->name,
                    'detail' => $task->project->code.' · '.$this->dueText($task->due_date, $today),
                    'url' => route('projects.show', $task->project_id),
                ];
            }
        }

        $openKinds = [ProjectStatusKind::Planned->value, ProjectStatusKind::Active->value, ProjectStatusKind::Paused->value, ProjectStatusKind::AtRisk->value];
        $dueSoonLimit = $this->calendar->addBusinessDays($today, max(0, config()->integer('business_calendar.due_soon_business_days') - 1));

        $projects = Project::query()
            ->notArchived()
            ->whereHas('status', fn (Builder $q) => $q->whereIn('kind', $openKinds))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $dueSoonLimit)
            ->orderBy('due_date')
            ->get(['id', 'code', 'name', 'owner_id', 'due_date']);

        foreach ($projects as $project) {
            $section = $project->due_date?->lt($today) ? 'projects_overdue' : 'projects_due_soon';

            $digests[$project->owner_id][$section][] = [
                'label' => "{$project->code} · {$project->name}",
                'detail' => $this->dueText($project->due_date, $today),
                'url' => route('projects.show', $project),
            ];
        }

        return $digests;
    }

    private function dueText(?CarbonImmutable $due, CarbonImmutable $today): string
    {
        if ($due === null) {
            return 'sin fecha';
        }

        if ($due->lt($today)) {
            $late = $this->calendar->countBetween($due->addDay(), $today);

            return "venció el {$due->translatedFormat('d M')} ({$late} ".($late === 1 ? 'día hábil' : 'días hábiles').' de retraso)';
        }

        return $due->isSameDay($today) ? 'vence hoy' : "vence el {$due->translatedFormat('d M')}";
    }
}
