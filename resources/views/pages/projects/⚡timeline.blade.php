<?php

use App\Enums\ProjectStatusKind;
use App\Enums\ScheduleCompliance;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\Projects\Gantt\GanttBuilder;
use App\Services\Projects\Gantt\GanttColor;
use App\Services\Projects\Gantt\GanttItemFactory;
use App\Services\Projects\Gantt\GanttItem;
use App\Services\Projects\Gantt\GanttZoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portfolio timeline: one bar per project the user can see. When a person
 * is selected and "Mostrar tareas" is on, this becomes that person's
 * workload: their own project(s), plus the tasks assigned to them in
 * every other project, each foreign project drawn in its own color so
 * a task's origin is obvious without opening it. When one project is
 * picked, it shows that project and all its tasks, each bar colored by
 * whether the task is keeping its dates.
 */
new #[Title('Cronograma de proyectos')] class extends Component {
    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: false)]
    public bool $includeClosed = false;

    #[Url(except: false)]
    public bool $showTasks = false;

    #[Url(except: 'month')]
    public string $zoom = 'month';

    #[Url(except: '')]
    public string $project = '';

    public function updatedZoom(): void
    {
        $this->zoom = (GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Month)->value;
    }

    /**
     * Base filters shared by the person's own projects and, when a person
     * is selected, the search for their tasks in other people's projects.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    private function scoped(Builder $query): Builder
    {
        return $query
            ->visibleTo(Auth::user())
            ->notArchived()
            ->when($this->category !== '', fn (Builder $q) => $q->where('category_id', (int) $this->category))
            ->unless($this->includeClosed, fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->whereNotIn('kind', [
                ProjectStatusKind::Completed->value,
                ProjectStatusKind::Cancelled->value,
            ])));
    }

    /**
     * The project picked in the project filter, when the user may see it.
     */
    #[Computed]
    public function selectedProject(): ?Project
    {
        if ($this->project === '') {
            return null;
        }

        // Picking a project overrides category, person and "include closed":
        // only visibility and archiving still apply.
        return Project::query()
            ->visibleTo(Auth::user())
            ->notArchived()
            ->with(['status', 'owner:id,name', 'tasks' => fn ($t) => $t
                ->with(['status', 'assignee:id,name', 'dependencies:id'])
                ->orderBy('sort_order')
                ->orderByRaw('start_date asc NULLS LAST')])
            ->find((int) $this->project);
    }

    /**
     * Single-project view: the project on top, then every task colored by
     * whether it is keeping its dates.
     *
     * @return list<GanttItem>
     */
    private function projectItems(Project $project): array
    {
        $factory = app(GanttItemFactory::class);

        return [
            ...$factory->fromProjects(collect([$project])),
            ...$factory->fromTasks($project->tasks, withCompliance: true),
        ];
    }

    /**
     * Task count per compliance group for the selected project.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function compliance(): array
    {
        $counts = array_fill_keys(array_map(fn (ScheduleCompliance $c) => $c->value, ScheduleCompliance::cases()), 0);

        foreach ($this->selectedProject?->tasks ?? [] as $task) {
            $counts[ScheduleCompliance::fromHealth($task->schedule()->health)->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function chart(): array
    {
        $zoom = GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Month;

        if ($this->selectedProject) {
            return app(GanttBuilder::class)->build($this->projectItems($this->selectedProject), $zoom);
        }

        $factory = app(GanttItemFactory::class);
        $ownerId = $this->owner !== '' ? (int) $this->owner : null;

        $ownedProjects = $this->scoped(Project::query())
            ->when($ownerId !== null, fn (Builder $q) => $q->where('owner_id', $ownerId))
            ->with(['status', 'owner:id,name'])
            ->when($this->showTasks, fn (Builder $q) => $q->with(['tasks' => fn ($t) => $t
                ->when($ownerId !== null, fn ($t) => $t->where('assignee_id', $ownerId))
                ->with(['status', 'assignee:id,name', 'dependencies:id'])
                ->orderBy('sort_order')]))
            ->orderByRaw('start_date asc NULLS LAST')
            ->orderBy('due_date')
            ->limit(200)
            ->get();

        $projectItems = $factory->fromProjects($ownedProjects);

        if (! $this->showTasks) {
            return app(GanttBuilder::class)->build($projectItems, $zoom);
        }

        // Each project's row followed by the bars of its own tasks, so the
        // portfolio timeline reads as grouped sections rather than one flat list.
        $items = [];

        foreach ($ownedProjects as $i => $project) {
            $items[] = $projectItems[$i];
            array_push($items, ...$factory->fromTasks($project->tasks));
        }

        if ($ownerId === null) {
            return app(GanttBuilder::class)->build($items, $zoom);
        }

        // A person's full workload also covers tasks assigned to them in
        // projects someone else owns. One color per foreign project so a
        // task's origin reads at a glance; their own project(s) above stay
        // the default blue.
        $otherProjects = $this->scoped(Project::query())
            ->whereKeyNot($ownedProjects->pluck('id'))
            ->whereHas('tasks', fn (Builder $q) => $q->where('assignee_id', $ownerId))
            ->with(['status', 'owner:id,name'])
            ->with(['tasks' => fn ($t) => $t->where('assignee_id', $ownerId)
                ->with(['status', 'assignee:id,name', 'dependencies:id'])
                ->orderBy('sort_order')])
            ->orderByRaw('start_date asc NULLS LAST')
            ->orderBy('due_date')
            ->limit(50)
            ->get();

        foreach ($otherProjects as $i => $project) {
            $color = GanttColor::cycle($i);
            $items[] = $factory->fromProjects(collect([$project]), color: $color)[0];
            array_push($items, ...$factory->fromTasks($project->tasks, color: $color));
        }

        return app(GanttBuilder::class)->build($items, $zoom);
    }

    /**
     * Projects for the project filter: what the user can see, narrowed by
     * the category and "include closed" filters.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectOptions(): Collection
    {
        return $this->scoped(Project::query())->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * @return Collection<int, ProjectCategory>
     */
    #[Computed(persist: true)]
    public function categories(): Collection
    {
        return ProjectCategory::query()->ordered()->get(['id', 'name']);
    }

    /**
     * Anyone with a project to their name or a task assigned to them, so
     * the picker works for the workload view and not only project owners.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()
            ->where(fn ($q) => $q
                ->whereHas('ownedProjects', fn ($p) => $p->visibleTo(Auth::user()))
                ->orWhereHas('assignedTasks.project', fn ($p) => $p->visibleTo(Auth::user())))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Cronograma de proyectos</flux:heading>
            <flux:subheading>Fechas, avance y retrasos del portafolio en una sola línea de tiempo</flux:subheading>
        </div>
        <x-projects.zoom-switch :zoom="$zoom" />
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="w-52">
            <flux:select wire:model.live="category" aria-label="Categoría">
                <flux:select.option value="">Todas las categorías</flux:select.option>
                @foreach ($this->categories as $option)
                    <flux:select.option :value="$option->id" wire:key="cat-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-52">
            <flux:select wire:model.live="owner" aria-label="Persona">
                <flux:select.option value="">Todas las personas</flux:select.option>
                @foreach ($this->owners as $option)
                    <flux:select.option :value="$option->id" wire:key="own-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-64">
            <flux:select wire:model.live="project" aria-label="Proyecto">
                <flux:select.option value="">Todos los proyectos</flux:select.option>
                @foreach ($this->projectOptions as $option)
                    <flux:select.option :value="$option->id" wire:key="prj-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:checkbox wire:model.live="includeClosed" label="Incluir finalizados y cancelados" />
        <flux:checkbox wire:model.live="showTasks" label="Mostrar tareas de los proyectos" />
        <flux:text class="ms-auto text-sm" wire:loading wire:target="category, owner, includeClosed, showTasks, zoom, project">Actualizando…</flux:text>
    </div>

    @if ($this->selectedProject)
        <section aria-label="Cumplimiento de las tareas de {{ $this->selectedProject->name }}" class="flex flex-col gap-2">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (ScheduleCompliance::cases() as $group)
                    <div wire:key="compliance-{{ $group->value }}" class="flex items-center gap-3 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <span @class([
                            'size-2.5 shrink-0 rounded-sm',
                            'bg-[#0ca30c]' => $group === ScheduleCompliance::Meeting,
                            'bg-[#fab219]' => $group === ScheduleCompliance::AtRisk,
                            'bg-[#d03b3b]' => $group === ScheduleCompliance::Failing,
                            'bg-zinc-400' => $group === ScheduleCompliance::Neutral,
                        ])></span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1 text-sm font-medium text-zinc-800 dark:text-white">
                                <flux:icon :name="$group->icon()" variant="micro" />{{ $group->label() }}
                            </div>
                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $group->description() }}</div>
                        </div>
                        <span class="ms-auto text-xl font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $this->compliance[$group->value] }}</span>
                    </div>
                @endforeach
            </div>
            <flux:text class="text-xs">Mostrando todas las tareas de {{ $this->selectedProject->code }}. Con un proyecto elegido no aplican los filtros de categoría, persona ni finalizados.</flux:text>
        </section>
    @endif

    @if ($owner !== '' && $showTasks && ! $this->selectedProject)
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>
                Cronograma de {{ $this->owners->firstWhere('id', (int) $owner)?->name }}: su propio proyecto arriba, en azul, y debajo las tareas que tiene asignadas en otros proyectos, cada uno con un color distinto.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div wire:loading.class="opacity-60" wire:target="category, owner, includeClosed, showTasks, zoom, project" class="transition-opacity">
        <x-projects.gantt :chart="$this->chart" id="portfolio" label="Cronograma del portafolio" />
    </div>
</section>
