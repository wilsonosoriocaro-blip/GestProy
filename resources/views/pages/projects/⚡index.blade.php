<?php

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Queries\Projects\ProjectIndexQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Proyectos')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $progress = '';

    #[Url(except: '')]
    public string $dueFrom = '';

    #[Url(except: '')]
    public string $dueTo = '';

    #[Url(except: false)]
    public bool $overdue = false;

    #[Url(except: false)]
    public bool $atRisk = false;

    #[Url(except: false)]
    public bool $archived = false;

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    /**
     * Any filter change goes back to the first page.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['sort', 'direction'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, ProjectIndexQuery::SORTABLE)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'owner', 'priority', 'category', 'progress', 'dueFrom', 'dueTo', 'overdue', 'atRisk', 'archived']);
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->owner !== '' || $this->priority !== ''
            || $this->category !== '' || $this->progress !== '' || $this->dueFrom !== '' || $this->dueTo !== ''
            || $this->overdue || $this->atRisk || $this->archived;
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return app(ProjectIndexQuery::class)
            ->build(Auth::user(), [
                'search' => $this->search,
                'status' => $this->status,
                'owner' => $this->owner,
                'priority' => $this->priority,
                'category' => $this->category,
                'progress' => $this->progress,
                'due_from' => $this->dueFrom,
                'due_to' => $this->dueTo,
                'overdue' => $this->overdue,
                'at_risk' => $this->atRisk,
                'archived' => $this->archived,
                'sort' => $this->sort,
                'direction' => $this->direction,
            ])
            ->paginate(config()->integer('projects.per_page'));
    }

    /**
     * @return Collection<int, ProjectStatus>
     */
    #[Computed(persist: true)]
    public function statuses(): Collection
    {
        return ProjectStatus::query()->ordered()->get();
    }

    /**
     * @return Collection<int, ProjectPriority>
     */
    #[Computed(persist: true)]
    public function priorities(): Collection
    {
        return ProjectPriority::query()->ordered()->get();
    }

    /**
     * @return Collection<int, ProjectCategory>
     */
    #[Computed(persist: true)]
    public function categories(): Collection
    {
        return ProjectCategory::query()->ordered()->get();
    }

    /**
     * Only people who own at least one project.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()->whereHas('ownedProjects')->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Proyectos</flux:heading>
            <flux:subheading>Portafolio del área de Tecnología y Estrategias Digitales</flux:subheading>
        </div>

        @can('create', App\Models\Project::class)
            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>
                Nuevo proyecto
            </flux:button>
        @endcan
    </div>

    {{-- Filters: search always visible; the rest folds away on small screens. --}}
    <div class="flex flex-col gap-3" role="search" aria-label="Filtrar proyectos" x-data="{ open: false }">
        <div class="flex gap-3">
            <flux:input
                wire:model.live.debounce.400ms="search"
                icon="magnifying-glass"
                placeholder="Buscar por código, nombre, descripción o responsable"
                aria-label="Buscar"
                clearable
                class="flex-1"
            />
            <flux:button icon="funnel" class="lg:hidden" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="project-filters">
                Filtros
            </flux:button>
        </div>

        <div id="project-filters" class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8" x-bind:class="open ? '' : 'max-lg:hidden'">
            <flux:select wire:model.live="status" aria-label="Estado">
                <flux:select.option value="">Todos los estados</flux:select.option>
                @foreach ($this->statuses as $option)
                    <flux:select.option :value="$option->id" wire:key="status-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="owner" aria-label="Responsable">
                <flux:select.option value="">Todos los responsables</flux:select.option>
                @foreach ($this->owners as $option)
                    <flux:select.option :value="$option->id" wire:key="owner-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="priority" aria-label="Prioridad">
                <flux:select.option value="">Todas las prioridades</flux:select.option>
                @foreach ($this->priorities as $option)
                    <flux:select.option :value="$option->id" wire:key="priority-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="category" aria-label="Categoría">
                <flux:select.option value="">Todas las categorías</flux:select.option>
                @foreach ($this->categories as $option)
                    <flux:select.option :value="$option->id" wire:key="category-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="progress" aria-label="Nivel de avance">
                <flux:select.option value="">Cualquier avance</flux:select.option>
                <flux:select.option value="0-24">0 % a 24 %</flux:select.option>
                <flux:select.option value="25-49">25 % a 49 %</flux:select.option>
                <flux:select.option value="50-74">50 % a 74 %</flux:select.option>
                <flux:select.option value="75-99">75 % a 99 %</flux:select.option>
                <flux:select.option value="100">100 %</flux:select.option>
            </flux:select>

            <flux:input type="date" wire:model.live="dueFrom" label="Vence desde" />
            <flux:input type="date" wire:model.live="dueTo" label="Vence hasta" />
        </div>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <flux:checkbox wire:model.live="overdue" label="Solo atrasados" />
            <flux:checkbox wire:model.live="atRisk" label="En riesgo o atrasados" />
            <flux:checkbox wire:model.live="archived" label="Ver archivados" />

            @if ($this->hasFilters)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar filtros</flux:button>
            @endif

            <flux:text class="ms-auto text-sm" wire:loading.remove wire:target="search, status, owner, priority, category, progress, dueFrom, dueTo, overdue, atRisk, archived, sortBy, gotoPage, nextPage, previousPage">
                {{ $this->projects->total() }} {{ $this->projects->total() === 1 ? 'proyecto' : 'proyectos' }}
            </flux:text>
            <flux:text class="ms-auto text-sm" wire:loading wire:target="search, status, owner, priority, category, progress, dueFrom, dueTo, overdue, atRisk, archived, sortBy, gotoPage, nextPage, previousPage">
                Actualizando…
            </flux:text>
        </div>
    </div>

    {{-- List --}}
    @if ($this->projects->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:icon name="folder-open" class="mx-auto text-zinc-400" />
            <flux:heading class="mt-2">{{ $this->hasFilters ? 'Ningún proyecto coincide con los filtros' : 'Todavía no hay proyectos' }}</flux:heading>
            @if ($this->hasFilters)
                <flux:button class="mt-4" size="sm" wire:click="clearFilters">Limpiar filtros</flux:button>
            @endif
        </div>
    @else
        <div wire:loading.class="opacity-60" wire:target="search, status, owner, priority, category, progress, dueFrom, dueTo, overdue, atRisk, archived, sortBy, gotoPage, nextPage, previousPage" class="transition-opacity">
            {{-- Small screens: cards with the essentials. --}}
            <ul class="flex flex-col gap-3 md:hidden">
                @foreach ($this->projects as $project)
                    @php($schedule = $project->schedule())
                    <li wire:key="card-{{ $project->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $project->code }} · {{ $project->category->name }}</div>
                        <flux:link :href="route('projects.show', $project)" wire:navigate class="font-medium">{{ $project->name }}</flux:link>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <x-projects.status-badge :status="$project->status" />
                            <x-projects.priority-badge :priority="$project->priority" />
                        </div>
                        <x-projects.progress-bar class="mt-3" :value="$project->progress" :expected="$schedule->expectedProgress" />
                        <div class="mt-3 flex items-end justify-between gap-2">
                            <x-projects.health-badge :schedule="$schedule" />
                            <span class="text-end text-xs text-zinc-500 dark:text-zinc-400">{{ $project->owner->name }}<br>vence {{ $project->due_date?->translatedFormat('d M Y') ?? 'sin fecha' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 md:hidden">
                <flux:pagination :paginator="$this->projects" />
            </div>

            <flux:table :paginator="$this->projects" class="max-md:hidden">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sort === 'name'" :direction="$direction" wire:click="sortBy('name')">Proyecto</flux:table.column>
                    <flux:table.column>Responsable</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'priority'" :direction="$direction" wire:click="sortBy('priority')">Prioridad</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'due_date'" :direction="$direction" wire:click="sortBy('due_date')">Fechas</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'progress'" :direction="$direction" wire:click="sortBy('progress')">Avance</flux:table.column>
                    <flux:table.column>Cronograma</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->projects as $project)
                        @php($schedule = $project->schedule())
                        <flux:table.row :key="$project->id">
                            <flux:table.cell class="min-w-56 max-w-80 whitespace-normal">
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $project->code }}</div>
                                <flux:link :href="route('projects.show', $project)" wire:navigate class="line-clamp-2 font-medium">
                                    {{ $project->name }}
                                </flux:link>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $project->category->name }}
                                    @if ($project->isArchived())
                                        <flux:badge size="sm" icon="archive-box">Archivado</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">{{ $project->owner->name }}</flux:table.cell>
                            <flux:table.cell><x-projects.status-badge :status="$project->status" /></flux:table.cell>
                            <flux:table.cell><x-projects.priority-badge :priority="$project->priority" /></flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap text-xs">
                                <div>{{ $project->start_date?->translatedFormat('d M Y') ?? 'Sin inicio' }}</div>
                                <div class="text-zinc-500 dark:text-zinc-400">→ {{ $project->due_date?->translatedFormat('d M Y') ?? 'Sin fecha fin' }}</div>
                            </flux:table.cell>

                            <flux:table.cell class="min-w-32">
                                <x-projects.progress-bar :value="$project->progress" :expected="$schedule->expectedProgress" />
                            </flux:table.cell>

                            <flux:table.cell><x-projects.health-badge :schedule="$schedule" /></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
