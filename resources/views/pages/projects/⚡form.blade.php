<?php

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\UpdateProject;
use App\Enums\ProgressMode;
use App\Enums\ProjectStatusKind;
use App\Livewire\Forms\ProjectForm;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Create and edit share this page: without a project it creates one.
 */
new class extends Component {
    public ProjectForm $form;

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('update', $project);
            $this->form->setProject($project);

            return;
        }

        $this->authorize('create', Project::class);
        $this->form->setDefaults(Auth::id());
    }

    public function save(CreateProject $create, UpdateProject $update): void
    {
        $user = Auth::user();

        if ($this->form->project) {
            $this->authorize('update', $this->form->project);
            $project = $update->handle($user, $this->form->project, $this->form->payload());
            Flux::toast(variant: 'success', text: 'Proyecto actualizado.');
        } else {
            $this->authorize('create', Project::class);
            $project = $create->handle($user, $this->form->payload());
            Flux::toast(variant: 'success', text: "Proyecto {$project->code} creado.");
        }

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    #[Computed]
    public function isManualProgress(): bool
    {
        return $this->form->progress_mode === ProgressMode::Manual->value;
    }

    #[Computed]
    public function isCompleted(): bool
    {
        return $this->statuses->firstWhere('id', $this->form->status_id)?->kind === ProjectStatusKind::Completed;
    }

    /**
     * Active entries, plus the current one when it was deactivated later.
     *
     * @return Collection<int, ProjectStatus>
     */
    #[Computed(persist: true)]
    public function statuses(): Collection
    {
        return ProjectStatus::query()->ordered()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->form->project?->status_id))
            ->get();
    }

    /**
     * @return Collection<int, ProjectPriority>
     */
    #[Computed(persist: true)]
    public function priorities(): Collection
    {
        return ProjectPriority::query()->ordered()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->form->project?->priority_id))
            ->get();
    }

    /**
     * @return Collection<int, ProjectCategory>
     */
    #[Computed(persist: true)]
    public function categories(): Collection
    {
        return ProjectCategory::query()->ordered()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->form->project?->category_id))
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->form->project ? 'Editar proyecto' : 'Nuevo proyecto');
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Proyectos</flux:breadcrumbs.item>
            @if ($form->project)
                <flux:breadcrumbs.item :href="route('projects.show', $form->project)" wire:navigate>{{ $form->project->code }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item>Nuevo</flux:breadcrumbs.item>
            @endif
        </flux:breadcrumbs>

        <flux:heading size="xl" level="1">{{ $form->project ? 'Editar proyecto' : 'Nuevo proyecto' }}</flux:heading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-8">
        <fieldset class="grid gap-4 sm:grid-cols-3">
            <legend class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Información general</legend>

            <flux:input wire:model="form.code" label="Código" :placeholder="$form->project ? '' : 'Automático'" :description="$form->project ? null : 'Vacío para generarlo'" class="uppercase" />
            <div class="sm:col-span-2"><flux:input wire:model="form.name" label="Nombre" required /></div>

            <div class="sm:col-span-3"><flux:textarea wire:model="form.description" label="Descripción" rows="3" /></div>
            <div class="sm:col-span-3"><flux:textarea wire:model="form.objective" label="Objetivo" rows="2" /></div>
            <div class="sm:col-span-3"><flux:textarea wire:model="form.scope" label="Alcance" rows="2" /></div>
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Clasificación y responsable</legend>

            <flux:select wire:model="form.category_id" label="Categoría" placeholder="Seleccione…" required>
                @foreach ($this->categories as $option)
                    <flux:select.option :value="$option->id" wire:key="category-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.owner_id" label="Responsable" placeholder="Seleccione…" required>
                @foreach ($this->users as $option)
                    <flux:select.option :value="$option->id" wire:key="user-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="form.status_id" label="Estado" required>
                @foreach ($this->statuses as $option)
                    <flux:select.option :value="$option->id" wire:key="status-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.priority_id" label="Prioridad" required>
                @foreach ($this->priorities as $option)
                    <flux:select.option :value="$option->id" wire:key="priority-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-3">
            <legend class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Fechas</legend>

            <flux:input type="date" wire:model="form.start_date" label="Inicio" />
            <flux:input type="date" wire:model="form.due_date" label="Finalización estimada" />

            @if ($this->isCompleted)
                <flux:input type="date" wire:model="form.completed_at" label="Finalización real" description="Vacío para usar la fecha de hoy" />
            @endif
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Avance y presupuesto</legend>

            <div class="sm:col-span-2">
                <flux:radio.group wire:model.live="form.progress_mode" label="¿Cómo se mide el avance?">
                    <flux:radio value="tasks" label="Calculado por tareas" description="Promedio ponderado de las tareas del proyecto. Recomendado." />
                    <flux:radio value="manual" label="Manual" description="Lo actualiza el responsable." />
                </flux:radio.group>
            </div>

            @if ($this->isManualProgress)
                <flux:input type="number" wire:model="form.progress" label="Avance (%)" min="0" max="100" step="1" />
            @endif

            <flux:input type="number" wire:model="form.budget" label="Presupuesto en COP (opcional)" min="0" step="0.01" />
        </fieldset>

        <flux:textarea wire:model="form.notes" label="Observaciones" rows="3" />

        <div class="flex items-center justify-end gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:button :href="$form->project ? route('projects.show', $form->project) : route('projects.index')" wire:navigate variant="ghost">
                Cancelar
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ $form->project ? 'Guardar cambios' : 'Crear proyecto' }}
            </flux:button>
        </div>
    </form>
</section>
