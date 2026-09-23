<?php

use App\Enums\Permission;
use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\User;
use App\Services\Projects\ActivityChangeFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Full history of a project: every change with who, when and, for audit
 * readers, from which IP; each entry expands to its before/after values.
 */
new class extends Component {
    use WithPagination;

    #[Locked]
    public Project $project;

    public string $group = '';

    public string $user = '';

    public string $from = '';

    public string $to = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
    }

    public function updated(): void
    {
        $this->resetPage('history');
    }

    public function clearFilters(): void
    {
        $this->reset('group', 'user', 'from', 'to');
        $this->resetPage('history');
    }

    #[On('task-saved')]
    public function refreshHistory(): void
    {
        unset($this->entries, $this->changes);
    }

    /**
     * @return LengthAwarePaginator<int, ProjectActivityLog>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $events = ProjectActivityEvent::groups()[$this->group]['events'] ?? null;

        return ProjectActivityLog::query()
            ->where('project_id', $this->project->id)
            ->when($events, fn ($query) => $query->whereIn('event', array_map(fn (ProjectActivityEvent $e) => $e->value, $events)))
            ->when($this->user !== '', fn ($query) => $query->where('user_id', (int) $this->user))
            ->when($this->from !== '', fn ($query) => $query->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($query) => $query->where('created_at', '<=', $this->to.' 23:59:59'))
            ->with(['user:id,name', 'task' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20, pageName: 'history');
    }

    /**
     * Readable before/after rows for the current page, resolved in bulk.
     *
     * @return array<int, list<array{field: string, old: string, new: string}>>
     */
    #[Computed]
    public function changes(): array
    {
        return app(ActivityChangeFormatter::class)->format($this->entries->getCollection());
    }

    /**
     * People who appear in this project's history.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()
            ->whereIn('id', ProjectActivityLog::query()->where('project_id', $this->project->id)->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function canAudit(): bool
    {
        return Auth::user()->can(Permission::AuditView->value);
    }
}; ?>

<section class="flex flex-col gap-5" aria-labelledby="history-heading">
    <div>
        <flux:heading id="history-heading" level="2" size="lg">Historial</flux:heading>
        <flux:text class="text-sm">Todos los cambios del proyecto y de sus tareas, con quién y cuándo</flux:text>
    </div>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="group" label="Tipo de cambio">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach (ProjectActivityEvent::groups() as $key => $option)
                <flux:select.option :value="$key" wire:key="grp-{{ $key }}">{{ $option['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="user" label="Persona">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($this->people as $person)
                <flux:select.option :value="$person->id" wire:key="ppl-{{ $person->id }}">{{ $person->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="from" label="Desde" />
        <flux:input type="date" wire:model.live="to" label="Hasta" />
    </div>

    @if ($group !== '' || $user !== '' || $from !== '' || $to !== '')
        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" class="self-start">Limpiar filtros</flux:button>
    @endif

    @if ($this->entries->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
            No hay cambios registrados con esos filtros.
        </div>
    @else
        <ol class="flex flex-col" wire:loading.class="opacity-60" wire:target="group, user, from, to, clearFilters, gotoPage, nextPage, previousPage">
            @foreach ($this->entries as $entry)
                @php
                    $rows = $this->changes[$entry->id] ?? [];
                @endphp
                <li wire:key="log-{{ $entry->id }}" class="relative flex gap-3 pb-5 last:pb-0" x-data="{ open: false }">
                    <span class="absolute start-4 top-9 bottom-0 w-px bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                    <span class="relative flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                        <flux:icon :name="$entry->event->icon()" variant="micro" />
                    </span>
                    <div class="min-w-0 flex-1 text-sm">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $entry->event->label() }}</span>
                            @if ($entry->task)
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">· {{ $entry->task->name }}</span>
                            @endif
                        </div>
                        @if ($entry->description)
                            <div class="text-zinc-600 dark:text-zinc-300">{{ $entry->description }}</div>
                        @endif
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $entry->user?->name ?? 'Sistema' }}</span>
                            <span>·</span>
                            <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->translatedFormat('d M Y, h:i a') }}</time>
                            @if ($this->canAudit && $entry->ip_address)
                                <span>· IP {{ $entry->ip_address }}</span>
                            @endif
                            @if ($rows !== [])
                                <button type="button" class="font-medium text-zinc-700 underline-offset-2 hover:underline dark:text-zinc-200"
                                    x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="log-detail-{{ $entry->id }}">
                                    <span x-show="! open">Ver detalle</span><span x-show="open" x-cloak>Ocultar detalle</span>
                                </button>
                            @endif
                        </div>

                        @if ($rows !== [])
                            <div id="log-detail-{{ $entry->id }}" x-show="open" x-cloak class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                        <tr>
                                            <th scope="col" class="px-3 py-1.5 font-medium">Campo</th>
                                            <th scope="col" class="px-3 py-1.5 font-medium">Antes</th>
                                            <th scope="col" class="px-3 py-1.5 font-medium">Después</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach ($rows as $row)
                                            <tr>
                                                <th scope="row" class="px-3 py-1.5 font-medium text-zinc-700 dark:text-zinc-200">{{ $row['field'] }}</th>
                                                <td class="max-w-72 whitespace-pre-line px-3 py-1.5 text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($row['old'], 300) }}</td>
                                                <td class="max-w-72 whitespace-pre-line px-3 py-1.5 text-zinc-800 dark:text-white">{{ \Illuminate\Support\Str::limit($row['new'], 300) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        <flux:pagination :paginator="$this->entries" />
    @endif
</section>
