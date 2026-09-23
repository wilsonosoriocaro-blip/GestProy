<?php

use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\Projects\Gantt\GanttBuilder;
use App\Services\Projects\Gantt\GanttItem;
use App\Services\Projects\Gantt\GanttItemFactory;
use App\Services\Projects\Gantt\GanttZoom;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Timeline of one project: the project span on top, then its tasks with
 * progress, delay and finish-to-start dependencies.
 */
new class extends Component {
    #[Locked]
    public Project $project;

    public string $zoom = 'week';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [ProjectTask::class, $project]);

        // Long projects read better by month from the start.
        $span = $project->start_date && $project->due_date ? $project->start_date->diffInDays($project->due_date) : 0;
        $this->zoom = $span > 120 ? GanttZoom::Month->value : GanttZoom::Week->value;
    }

    public function updatedZoom(): void
    {
        $this->zoom = (GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Week)->value;
    }

    #[On('task-saved')]
    public function refreshTimeline(): void
    {
        $this->project->refresh();
        unset($this->chart);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function chart(): array
    {
        $this->project->loadMissing('status');

        $tasks = $this->project->tasks()
            ->with(['status', 'assignee:id,name', 'dependencies:id'])
            ->orderBy('sort_order')
            ->orderByRaw('start_date asc NULLS LAST')
            ->get();

        $factory = app(GanttItemFactory::class);
        $projectRow = new GanttItem(
            key: 'project-'.$this->project->id,
            label: $this->project->name,
            sublabel: 'Proyecto completo',
            href: null,
            start: $this->project->start_date,
            end: $this->project->due_date,
            progress: $this->project->progress,
            health: $this->project->schedule()->health,
            isOpen: ! $this->project->status->kind->lifecycle()->isClosed(),
        );

        return app(GanttBuilder::class)->build(
            [$projectRow, ...$factory->fromTasks($tasks)],
            GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Week,
        );
    }
}; ?>

<section class="flex flex-col gap-4" aria-labelledby="timeline-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading id="timeline-heading" level="2" size="lg">Cronograma</flux:heading>
            <flux:text class="text-sm">Duración, avance y dependencias de las tareas</flux:text>
        </div>
        <x-projects.zoom-switch :zoom="$zoom" />
    </div>

    <div wire:loading.class="opacity-60" wire:target="zoom" class="transition-opacity">
        <x-projects.gantt :chart="$this->chart" id="project-{{ $project->id }}" label="Cronograma de {{ $project->name }}" />
    </div>
</section>
