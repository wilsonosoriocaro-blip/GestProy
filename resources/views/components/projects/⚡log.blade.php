<?php

use App\Actions\Comments\ManageProjectComments;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Project log ("bitácora"): dated follow-up notes written by the team.
 * Highlighted entries are the milestones of the project story.
 */
new class extends Component {
    #[Locked]
    public Project $project;

    public string $body = '';

    public bool $highlighted = false;

    public string $taskId = '';

    public bool $onlyHighlighted = false;

    #[Locked]
    public int $limit = 15;

    #[Locked]
    public ?int $editingId = null;

    public string $editingBody = '';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [ProjectComment::class, $project]);
    }

    public function add(ManageProjectComments $comments): void
    {
        $this->authorize('create', [ProjectComment::class, $this->project]);

        $this->validate([
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'taskId' => ['nullable', 'integer', Rule::exists('project_tasks', 'id')->where('project_id', $this->project->id)->whereNull('deleted_at')],
        ], attributes: ['body' => 'comentario', 'taskId' => 'tarea']);

        $highlight = $this->highlighted && Auth::user()->can('highlight', [ProjectComment::class, $this->project]);
        $comments->add(Auth::user(), $this->project, $this->body, $highlight, $this->taskId === '' ? null : (int) $this->taskId);

        $this->reset('body', 'highlighted', 'taskId');
        unset($this->entries);
        Flux::toast(variant: 'success', text: 'Actualización registrada en la bitácora.');
    }

    public function startEdit(int $commentId): void
    {
        $comment = $this->findComment($commentId);
        $this->authorize('update', $comment);

        $this->editingId = $comment->id;
        $this->editingBody = $comment->body;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingBody');
        $this->resetValidation('editingBody');
    }

    public function saveEdit(ManageProjectComments $comments): void
    {
        $comment = $this->findComment((int) $this->editingId);
        $this->authorize('update', $comment);

        $this->validate(['editingBody' => ['required', 'string', 'min:3', 'max:5000']], attributes: ['editingBody' => 'comentario']);

        $comments->update($comment, $this->editingBody);
        $this->cancelEdit();
        unset($this->entries);
    }

    public function toggleHighlight(int $commentId, ManageProjectComments $comments): void
    {
        $this->authorize('highlight', [ProjectComment::class, $this->project]);

        $comments->toggleHighlight($this->findComment($commentId));
        unset($this->entries);
    }

    public function delete(int $commentId, ManageProjectComments $comments): void
    {
        $comment = $this->findComment($commentId);
        $this->authorize('delete', $comment);

        $comments->delete($comment);
        unset($this->entries);
        Flux::toast(text: 'Comentario eliminado.');
    }

    public function loadMore(): void
    {
        $this->limit += 15;
        unset($this->entries);
    }

    private function findComment(int $commentId): ProjectComment
    {
        return $this->project->comments()->findOrFail($commentId)->setRelation('project', $this->project);
    }

    /**
     * One extra row tells whether there is more to load.
     *
     * @return Collection<int, ProjectComment>
     */
    #[Computed]
    public function entries(): Collection
    {
        return $this->project->comments()
            ->with(['user:id,name', 'task:id,name'])
            ->when($this->onlyHighlighted, fn ($query) => $query->where('is_highlighted', true))
            ->latest()
            ->latest('id')
            ->limit($this->limit + 1)
            ->get()
            ->each->setRelation('project', $this->project);
    }

    /**
     * @return Collection<int, ProjectTask>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->project->tasks()->orderBy('sort_order')->get(['id', 'name']);
    }
}; ?>

<section class="flex flex-col gap-5" aria-labelledby="log-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading id="log-heading" level="2" size="lg">Bitácora</flux:heading>
            <flux:text class="text-sm">Seguimiento del proyecto contado por el equipo</flux:text>
        </div>
        <flux:checkbox wire:model.live="onlyHighlighted" label="Solo destacados" />
    </div>

    @can('create', [App\Models\ProjectComment::class, $project])
        <form wire:submit="add" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:textarea wire:model="body" rows="3" label="Nueva actualización"
                placeholder="Ej.: Se finalizó la integración con SAP y se inició la fase de pruebas con usuarios." />
            <div class="flex flex-wrap items-end gap-3">
                @if ($this->tasks->isNotEmpty())
                    <div class="w-64 max-w-full">
                        <flux:select wire:model="taskId" label="Relacionada con (opcional)">
                            <flux:select.option value="">Todo el proyecto</flux:select.option>
                            @foreach ($this->tasks as $task)
                                <flux:select.option :value="$task->id" wire:key="log-task-{{ $task->id }}">{{ $task->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
                @can('highlight', [App\Models\ProjectComment::class, $project])
                    <flux:checkbox wire:model="highlighted" label="Destacar como hito" class="mb-2.5" />
                @endcan
                <flux:button type="submit" variant="primary" icon="paper-airplane" class="ms-auto">Registrar</flux:button>
            </div>
        </form>
    @endcan

    @php
        $entries = $this->entries->take($limit);
    @endphp

    @if ($entries->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
            {{ $onlyHighlighted ? 'No hay actualizaciones destacadas.' : 'Todavía no hay actualizaciones en la bitácora.' }}
        </div>
    @else
        <ol class="flex flex-col gap-4">
            @foreach ($entries as $comment)
                <li wire:key="comment-{{ $comment->id }}" @class([
                    'rounded-xl border p-4',
                    'border-amber-300 bg-amber-50/60 dark:border-amber-500/50 dark:bg-amber-500/10' => $comment->is_highlighted,
                    'border-zinc-200 dark:border-zinc-700' => ! $comment->is_highlighted,
                ])>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <flux:avatar size="xs" :name="$comment->user?->name ?? '?'" :initials="$comment->user?->initials() ?? '?'" />
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $comment->user?->name ?? 'Usuario eliminado' }}</span>
                        <time class="text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $comment->created_at?->toIso8601String() }}">
                            {{ $comment->created_at?->translatedFormat('d M Y, h:i a') }}
                            @if ($comment->updated_at && $comment->created_at && $comment->updated_at->gt($comment->created_at->addMinute()))
                                · editado
                            @endif
                        </time>
                        @if ($comment->is_highlighted)
                            <flux:badge size="sm" color="amber" icon="star">Hito</flux:badge>
                        @endif
                        @if ($comment->task)
                            <flux:badge size="sm" icon="clipboard-document-check">{{ $comment->task->name }}</flux:badge>
                        @endif

                        <div class="ms-auto flex items-center gap-1">
                            @can('highlight', [App\Models\ProjectComment::class, $project])
                                <flux:button size="xs" variant="ghost" icon="star" :icon:variant="$comment->is_highlighted ? 'solid' : 'outline'" wire:click="toggleHighlight({{ $comment->id }})"
                                    :aria-label="$comment->is_highlighted ? 'Quitar destacado' : 'Destacar'" :tooltip="$comment->is_highlighted ? 'Quitar destacado' : 'Destacar'" />
                            @endcan
                            @can('update', $comment)
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEdit({{ $comment->id }})" aria-label="Editar" tooltip="Editar" />
                            @endcan
                            @can('delete', $comment)
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $comment->id }})"
                                    wire:confirm="¿Eliminar este comentario de la bitácora?" aria-label="Eliminar" tooltip="Eliminar" />
                            @endcan
                        </div>
                    </div>

                    @if ($editingId === $comment->id)
                        <form wire:submit="saveEdit" class="mt-3 flex flex-col gap-2">
                            <flux:textarea wire:model="editingBody" rows="3" aria-label="Editar comentario" />
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="cancelEdit">Cancelar</flux:button>
                                <flux:button size="sm" type="submit" variant="primary">Guardar</flux:button>
                            </div>
                        </form>
                    @else
                        <p class="mt-2 whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-200">{{ $comment->body }}</p>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($this->entries->count() > $limit)
            <flux:button variant="ghost" wire:click="loadMore" class="self-center">Ver más</flux:button>
        @endif
    @endif
</section>
