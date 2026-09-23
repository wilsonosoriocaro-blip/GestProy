<?php

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\DeleteProject;
use App\Data\ScheduleSnapshot;
use App\Enums\ProjectStatusKind;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Project $project;

    #[Url(except: 'tasks')]
    public string $tab = 'tasks';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project->load(['status', 'priority', 'category', 'owner', 'creator', 'updater']);
    }

    #[Computed]
    public function schedule(): ScheduleSnapshot
    {
        return $this->project->schedule();
    }

    /**
     * Declared at risk, or the schedule says so.
     */
    #[Computed]
    public function riskReason(): ?string
    {
        return match (true) {
            $this->project->status->kind === ProjectStatusKind::AtRisk => 'Declarado en riesgo por el equipo',
            $this->schedule->needsAttention() => $this->schedule->health->label(),
            default => null,
        };
    }

    /**
     * Tabs with their icon and, where useful, a counter.
     *
     * @return array<string, array{string, string, int|null}>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            'tasks' => ['Tareas', 'clipboard-document-list', $this->project->tasks()->count()],
            'timeline' => ['Cronograma', 'calendar-days', null],
            'log' => ['Bitácora', 'chat-bubble-left-ellipsis', $this->project->comments()->count()],
            'history' => ['Historial', 'clock', null],
            'details' => ['Detalles y equipo', 'information-circle', null],
        ];
    }

    public function updatedTab(): void
    {
        if (! array_key_exists($this->tab, $this->tabs)) {
            $this->tab = 'tasks';
        }
    }

    /**
     * Task changes may move the calculated progress: reload the summary.
     */
    #[On('task-saved')]
    public function refreshProject(): void
    {
        $this->project->refresh()->load(['status', 'priority', 'category', 'owner', 'creator', 'updater']);
        unset($this->schedule, $this->riskReason, $this->tabs);
    }

    public function archive(ArchiveProject $action): void
    {
        $this->authorize('archive', $this->project);
        $action->archive(Auth::user(), $this->project);

        Flux::modal('confirm-archive')->close();
        Flux::toast(variant: 'success', text: 'Proyecto archivado.');
    }

    public function restore(ArchiveProject $action): void
    {
        $this->authorize('restore', $this->project);
        $action->restore(Auth::user(), $this->project);

        Flux::toast(variant: 'success', text: 'Proyecto restaurado.');
    }

    public function delete(DeleteProject $action): void
    {
        $this->authorize('delete', $this->project);
        $action->handle(Auth::user(), $this->project);

        Flux::toast(variant: 'success', text: "Proyecto {$this->project->code} eliminado.");
        $this->redirectRoute('projects.index', navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->project->name);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Proyectos</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $project->code }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <flux:heading size="xl" level="1" class="break-words">{{ $project->name }}</flux:heading>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $project->code }}</span>
                <x-projects.status-badge :status="$project->status" />
                <x-projects.priority-badge :priority="$project->priority" />
                @if ($project->isArchived())
                    <flux:badge size="sm" icon="archive-box">Archivado el {{ $project->archived_at?->translatedFormat('d M Y') }}</flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('update', $project)
                <flux:button icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>Editar</flux:button>
            @endcan

            @can('restore', $project)
                <flux:button icon="arrow-uturn-left" wire:click="restore">Restaurar</flux:button>
            @endcan

            @if (Auth::user()->can('archive', $project) || Auth::user()->can('delete', $project))
                <flux:dropdown align="end">
                    <flux:button icon="ellipsis-horizontal" aria-label="Más acciones" />
                    <flux:menu>
                        @can('archive', $project)
                            <flux:modal.trigger name="confirm-archive">
                                <flux:menu.item icon="archive-box">Archivar</flux:menu.item>
                            </flux:modal.trigger>
                        @endcan
                        @can('delete', $project)
                            <flux:modal.trigger name="confirm-delete">
                                <flux:menu.item icon="trash" variant="danger">Eliminar</flux:menu.item>
                            </flux:modal.trigger>
                        @endcan
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>
    </div>

    {{-- Executive summary --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-projects.stat label="Avance" :value="$project->progress.'%'" icon="chart-bar"
            :hint="$project->progress_mode->label()">
            <x-projects.progress-bar class="mt-3" :value="$project->progress" :expected="$this->schedule->expectedProgress" />
        </x-projects.stat>

        <x-projects.stat label="Tiempo transcurrido" icon="clock"
            :value="$this->schedule->elapsedDays !== null ? $this->schedule->elapsedDays.' de '.$this->schedule->totalDays : '—'"
            :hint="$this->schedule->timeConsumed !== null ? 'días hábiles · '.$this->schedule->timeConsumed.'% del plazo' : 'Sin fecha de inicio o de fin'" />

        @if ($this->schedule->overdueDays > 0)
            <x-projects.stat label="Retraso" icon="exclamation-triangle" :value="$this->schedule->overdueDays"
                :hint="$this->schedule->health === App\Enums\ScheduleHealth::CompletedLate ? 'días hábiles tarde al finalizar' : 'días hábiles después del vencimiento'" />
        @else
            <x-projects.stat label="Tiempo restante" icon="calendar-days"
                :value="$this->schedule->remainingDays ?? '—'"
                :hint="$this->schedule->remainingDays !== null ? 'días hábiles hasta el '.$project->due_date?->translatedFormat('d M Y') : 'Sin fecha de finalización'" />
        @endif

        <x-projects.stat label="Estado del cronograma" icon="signal">
            <x-projects.health-badge class="mt-2" :schedule="$this->schedule" />
            <div class="mt-3 text-xs">
                @if ($this->riskReason)
                    <span class="inline-flex items-center gap-1 font-medium text-red-700 dark:text-red-400">
                        <flux:icon name="shield-exclamation" variant="micro" /> Riesgo: {{ $this->riskReason }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="shield-check" variant="micro" /> Sin alertas de riesgo
                    </span>
                @endif
            </div>
        </x-projects.stat>
    </div>

    {{-- Sections: only the open tab mounts its components --}}
    <div class="flex flex-col gap-6">
        <div role="tablist" aria-label="Secciones del proyecto" class="-mb-px flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($this->tabs as $key => [$label, $icon, $count])
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                    @class([
                        'inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition',
                        'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $tab === $key,
                        'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $tab !== $key,
                    ])>
                    <flux:icon :name="$icon" variant="micro" />
                    {{ $label }}
                    @if ($count !== null)
                        <span class="rounded-full bg-zinc-100 px-1.5 text-xs tabular-nums text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">{{ $count }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div role="tabpanel" id="panel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" wire:loading.class="opacity-60" wire:target="tab">
            @if ($tab === 'tasks')
                <livewire:projects.tasks :project="$project" :key="'tasks-'.$project->id" />
                <livewire:projects.task-form :project="$project" :key="'task-form-'.$project->id" />
            @elseif ($tab === 'timeline')
                <livewire:projects.timeline :project="$project" :key="'timeline-'.$project->id" />
            @elseif ($tab === 'log')
                <livewire:projects.log :project="$project" :key="'log-'.$project->id" />
            @elseif ($tab === 'history')
                <livewire:projects.history :project="$project" :key="'history-'.$project->id" />
            @else
                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="flex flex-col gap-6 lg:col-span-2">
                        @foreach (['description' => 'Descripción', 'objective' => 'Objetivo', 'scope' => 'Alcance', 'notes' => 'Observaciones'] as $field => $label)
                            @if (filled($project->{$field}))
                                <div>
                                    <flux:heading level="2" size="lg">{{ $label }}</flux:heading>
                                    <flux:text class="mt-2 whitespace-pre-line">{{ $project->{$field} }}</flux:text>
                                </div>
                            @endif
                        @endforeach

                        @if (blank($project->description) && blank($project->objective) && blank($project->scope) && blank($project->notes))
                            <flux:text>Sin descripción, objetivo ni alcance registrados.</flux:text>
                        @endif
                    </div>

                {{-- Facts --}}
                <aside class="flex flex-col gap-6">
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                        <dt class="text-zinc-500 dark:text-zinc-400">Responsable</dt>
                        <dd class="font-medium">{{ $project->owner->name }}</dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Categoría</dt>
                        <dd>{{ $project->category->name }}</dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Inicio</dt>
                        <dd>{{ $project->start_date?->translatedFormat('d M Y') ?? 'Sin definir' }}</dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Fin estimado</dt>
                        <dd>{{ $project->due_date?->translatedFormat('d M Y') ?? 'Sin definir' }}</dd>

                        @if ($project->completed_at)
                            <dt class="text-zinc-500 dark:text-zinc-400">Fin real</dt>
                            <dd>{{ $project->completed_at->translatedFormat('d M Y') }}</dd>
                        @endif

                        @if ($project->budget !== null)
                            <dt class="text-zinc-500 dark:text-zinc-400">Presupuesto</dt>
                            <dd class="tabular-nums">{{ Number::currency((float) $project->budget, in: 'COP', locale: 'es_CO') }}</dd>
                        @endif

                        <dt class="text-zinc-500 dark:text-zinc-400">Creado</dt>
                        <dd>{{ $project->created_at?->translatedFormat('d M Y') }}@if ($project->creator) · {{ $project->creator->name }}@endif</dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Actualizado</dt>
                        <dd>{{ $project->updated_at?->translatedFormat('d M Y, h:i a') }}@if ($project->updater) · {{ $project->updater->name }}@endif</dd>
                    </dl>

                    <livewire:projects.team :project="$project" />
                </aside>
                </div>
            @endif
        </div>
    </div>

    {{-- Confirmations --}}
    @can('archive', $project)
        <flux:modal name="confirm-archive" class="max-w-md">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">¿Archivar el proyecto?</flux:heading>
                <flux:text>{{ $project->code }} saldrá del listado principal y quedará en solo lectura. Se puede restaurar en cualquier momento.</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="archive">Archivar</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('delete', $project)
        <flux:modal name="confirm-delete" class="max-w-md">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">¿Eliminar el proyecto?</flux:heading>
                <flux:text>{{ $project->code }} · {{ $project->name }} dejará de estar disponible junto con sus tareas. Un administrador puede recuperarlo desde la base de datos.</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="delete">Eliminar</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
