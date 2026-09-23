<?php

use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\Projects\Gantt\GanttBuilder;
use App\Services\Projects\Gantt\GanttItemFactory;
use App\Services\Projects\Gantt\GanttZoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portfolio timeline: one bar per project the user can see.
 */
new #[Title('Cronograma de proyectos')] class extends Component {
    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: false)]
    public bool $includeClosed = false;

    #[Url(except: 'month')]
    public string $zoom = 'month';

    public function updatedZoom(): void
    {
        $this->zoom = (GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Month)->value;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function chart(): array
    {
        $projects = Project::query()
            ->visibleTo(Auth::user())
            ->notArchived()
            ->when($this->category !== '', fn (Builder $q) => $q->where('category_id', (int) $this->category))
            ->when($this->owner !== '', fn (Builder $q) => $q->where('owner_id', (int) $this->owner))
            ->unless($this->includeClosed, fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->whereNotIn('kind', [
                ProjectStatusKind::Completed->value,
                ProjectStatusKind::Cancelled->value,
            ])))
            ->with(['status', 'owner:id,name'])
            ->orderByRaw('start_date asc NULLS LAST')
            ->orderBy('due_date')
            ->limit(200)
            ->get();

        return app(GanttBuilder::class)->build(
            app(GanttItemFactory::class)->fromProjects($projects),
            GanttZoom::tryFrom($this->zoom) ?? GanttZoom::Month,
        );
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
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()->whereHas('ownedProjects', fn ($q) => $q->visibleTo(Auth::user()))->orderBy('name')->get(['id', 'name']);
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
            <flux:select wire:model.live="owner" aria-label="Responsable">
                <flux:select.option value="">Todos los responsables</flux:select.option>
                @foreach ($this->owners as $option)
                    <flux:select.option :value="$option->id" wire:key="own-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:checkbox wire:model.live="includeClosed" label="Incluir finalizados y cancelados" />
        <flux:text class="ms-auto text-sm" wire:loading wire:target="category, owner, includeClosed, zoom">Actualizando…</flux:text>
    </div>

    <div wire:loading.class="opacity-60" wire:target="category, owner, includeClosed, zoom" class="transition-opacity">
        <x-projects.gantt :chart="$this->chart" id="portfolio" label="Cronograma del portafolio" />
    </div>
</section>
