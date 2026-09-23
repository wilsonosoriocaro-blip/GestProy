<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\AppliesStatusRules;
use App\Enums\ProgressMode;
use App\Enums\ProjectActivityEvent;
use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Services\Notifications\ProjectNotifier;
use App\Services\Projects\ProjectActivityLogger;
use App\Services\Projects\ProjectProgressCalculator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    use AppliesStatusRules;

    /** Fields with their own timeline event; the rest go to a generic "updated" entry. */
    private const TRACKED_SEPARATELY = ['status_id', 'owner_id', 'start_date', 'due_date', 'progress', 'updated_by'];

    public function __construct(
        private readonly ProjectProgressCalculator $progress,
        private readonly ProjectActivityLogger $activity,
        private readonly ProjectNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes (see ProjectForm).
     */
    public function handle(User $actor, Project $project, array $data): Project
    {
        return DB::transaction(function () use ($actor, $project, $data): Project {
            $project->fill($data);

            if ($project->progress_mode === ProgressMode::Tasks) {
                $project->progress = $this->progress->calculate($project);
            }

            $this->applyStatusRules($project);

            if (! $project->isDirty()) {
                return $project;
            }

            $project->updated_by = $actor->id;
            $changes = $this->changes($project);
            $project->save();

            $this->logChanges($project, $changes);
            $this->notify($actor, $project, $changes);

            return $project;
        });
    }

    /**
     * @param  array<string, array{mixed, mixed}>  $changes
     */
    private function notify(User $actor, Project $project, array $changes): void
    {
        if (isset($changes['owner_id'])) {
            $this->notifier->projectOwnerAssigned($project->load('owner'), $actor);
        }

        if (isset($changes['status_id']) && $project->load('status')->status->kind === ProjectStatusKind::AtRisk
            && ProjectStatus::query()->find((int) $changes['status_id'][0])?->kind !== ProjectStatusKind::AtRisk) {
            $this->notifier->projectAtRisk($project, $actor);
        }
    }

    /**
     * Old and new values of every dirty attribute, dates as Y-m-d strings.
     *
     * @return array<string, array{mixed, mixed}>
     */
    private function changes(Project $project): array
    {
        $changes = [];

        foreach (array_keys($project->getDirty()) as $attribute) {
            $changes[$attribute] = [
                $this->normalize($project->getOriginal($attribute)),
                $this->normalize($project->getAttribute($attribute)),
            ];
        }

        return $changes;
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->toDateString(),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }

    /**
     * @param  array<string, array{mixed, mixed}>  $changes
     */
    private function logChanges(Project $project, array $changes): void
    {
        if (isset($changes['status_id'])) {
            [$from, $to] = $changes['status_id'];
            $names = ProjectStatus::query()->whereKey([$from, $to])->pluck('name', 'id');

            $this->activity->log($project, ProjectActivityEvent::StatusChanged,
                "Estado: {$names[$from]} → {$names[$to]}", $project,
                ['status_id' => $from, 'status' => $names[$from]],
                ['status_id' => $to, 'status' => $names[$to]],
            );
        }

        if (isset($changes['owner_id'])) {
            [$from, $to] = $changes['owner_id'];
            $names = User::query()->whereKey([$from, $to])->pluck('name', 'id');

            $this->activity->log($project, ProjectActivityEvent::OwnerChanged,
                "Responsable: {$names[$from]} → {$names[$to]}", $project,
                ['owner_id' => $from, 'owner' => $names[$from]],
                ['owner_id' => $to, 'owner' => $names[$to]],
            );
        }

        $dates = array_intersect_key($changes, array_flip(['start_date', 'due_date']));

        if ($dates !== []) {
            $this->activity->log($project, ProjectActivityEvent::DatesChanged, 'Cambio de fechas', $project,
                array_map(fn (array $pair) => $pair[0], $dates),
                array_map(fn (array $pair) => $pair[1], $dates),
            );
        }

        if (isset($changes['progress'])) {
            [$from, $to] = $changes['progress'];

            $this->activity->log($project, ProjectActivityEvent::ProgressChanged, "Avance: {$from}% → {$to}%", $project,
                ['progress' => $from], ['progress' => $to],
            );
        }

        $other = array_diff_key($changes, array_flip(self::TRACKED_SEPARATELY));

        if ($other !== []) {
            $this->activity->log($project, ProjectActivityEvent::ProjectUpdated, 'Información del proyecto actualizada', $project,
                array_map(fn (array $pair) => $pair[0], $other),
                array_map(fn (array $pair) => $pair[1], $other),
            );
        }
    }
}
