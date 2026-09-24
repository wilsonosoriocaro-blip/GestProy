<?php

use App\Enums\Permission;
use App\Actions\Catalogs\ManageCatalogs;
use App\Services\Catalogs\CatalogRegistry;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Catálogos')] class extends Component {

    /**
     * Checked on every request, not only by the route: the component stays
     * protected wherever it is rendered.
     */
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::CatalogsManage->value), 403);
    }
    #[Url(except: 'categories')]
    public string $type = 'categories';

    #[Locked]
    public ?int $editingId = null;

    /** The behaviour of a status cannot change once projects or tasks use it. */
    #[Locked]
    public bool $kindLocked = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function updatedType(): void
    {
        if (! array_key_exists($this->type, CatalogRegistry::all())) {
            $this->type = 'categories';
        }

        unset($this->entries);
    }

    #[Computed]
    public function catalog(): array
    {
        return CatalogRegistry::get($this->type);
    }

    /**
     * Entries with how many projects/tasks use each one.
     *
     * @return Collection<int, array{entry: \Illuminate\Database\Eloquent\Model, usage: int}>
     */
    #[Computed]
    public function entries(): Collection
    {
        $catalog = $this->catalog;
        $query = $catalog['model']::query();
        $query = $catalog['level'] ? $query->orderByDesc('level') : $query->orderBy('sort_order')->orderBy('name');

        $usage = CatalogRegistry::usageCounts($this->type);

        return $query->get()->map(fn ($entry) => [
            'entry' => $entry,
            'usage' => $usage[(int) $entry->getKey()] ?? 0,
        ]);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->kindLocked = false;
        $this->form = [
            'name' => '',
            'color' => 'zinc',
            'is_active' => true,
            'kind' => $this->catalog['kinds'] ? $this->catalog['kinds']::cases()[0]->value : null,
            'icon' => $this->catalog['icon'] ? 'clock' : null,
            'sort_order' => ($this->entries->max(fn ($row) => $row['entry']->getAttribute('sort_order')) ?? 0) + 1,
            'level' => ($this->entries->max(fn ($row) => $row['entry']->getAttribute('level')) ?? 0) + 10,
            'is_default' => false,
        ];
        $this->resetValidation();
        Flux::modal('catalog-form')->show();
    }

    public function edit(int $id): void
    {
        $entry = $this->catalog['model']::query()->findOrFail($id);
        $this->editingId = $id;
        $this->kindLocked = CatalogRegistry::usage($this->type, $id) > 0;
        $this->form = [
            'name' => $entry->getAttribute('name'),
            'color' => $entry->getAttribute('color'),
            'is_active' => (bool) $entry->getAttribute('is_active'),
            'kind' => $entry->getAttribute('kind')?->value,
            'icon' => $entry->getAttribute('icon'),
            'sort_order' => $entry->getAttribute('sort_order'),
            'level' => $entry->getAttribute('level'),
            'is_default' => (bool) $entry->getAttribute('is_default'),
        ];
        $this->resetValidation();
        Flux::modal('catalog-form')->show();
    }

    public function save(ManageCatalogs $catalogs): void
    {
        $catalog = $this->catalog;
        $table = (new $catalog['model'])->getTable();

        $rules = [
            'form.name' => ['required', 'string', 'max:100', Rule::unique($table, 'name')->ignore($this->editingId)],
            'form.color' => ['required', Rule::in(CatalogRegistry::COLORS)],
            'form.is_active' => ['boolean'],
        ];

        if ($catalog['kinds']) {
            $rules['form.kind'] = ['required', Rule::enum($catalog['kinds'])];
        }
        if ($catalog['icon']) {
            $rules['form.icon'] = ['nullable', Rule::in(CatalogRegistry::ICONS)];
        }
        if ($catalog['order']) {
            $rules['form.sort_order'] = ['required', 'integer', 'between:0,1000'];
        }
        if ($catalog['level']) {
            $rules['form.level'] = ['required', 'integer', 'between:1,30000', Rule::unique($table, 'level')->ignore($this->editingId)];
        }
        if ($catalog['default']) {
            $rules['form.is_default'] = ['boolean'];
        }

        $data = $this->validate($rules, attributes: [
            'form.name' => 'nombre', 'form.color' => 'color', 'form.kind' => 'comportamiento',
            'form.icon' => 'icono', 'form.sort_order' => 'orden', 'form.level' => 'nivel',
        ])['form'];

        $catalogs->save($this->type, $this->editingId, $data);

        Flux::modal('catalog-form')->close();
        Flux::toast(variant: 'success', text: 'Catálogo actualizado.');
        unset($this->entries);
    }

    public function delete(int $id, ManageCatalogs $catalogs): void
    {
        $catalogs->delete($this->type, $id);

        Flux::toast(text: 'Elemento eliminado.');
        unset($this->entries);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Catálogos</flux:heading>
            <flux:subheading>Categorías, estados y prioridades del módulo de proyectos</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Agregar</flux:button>
    </div>

    <div role="tablist" aria-label="Catálogo" class="-mb-px flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        @foreach (CatalogRegistry::all() as $key => $option)
            <button type="button" role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}" wire:click="$set('type', '{{ $key }}')" wire:key="cat-tab-{{ $key }}"
                @class([
                    'shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition',
                    'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $type === $key,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $type !== $key,
                ])>{{ $option['label'] }}</button>
        @endforeach
    </div>

    @if ($this->catalog['kinds'])
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>
                El <strong>comportamiento</strong> define cómo el sistema trata el estado (si cuenta como abierto, finalizado, en pausa…).
                Puedes crear todos los estados que necesites con el nombre que quieras; el comportamiento no se puede cambiar una vez esté en uso.
            </flux:callout.text>
        </flux:callout>
    @endif

    @error('catalog')
        <flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Nombre</flux:table.column>
            @if ($this->catalog['kinds'])
                <flux:table.column>Comportamiento</flux:table.column>
            @endif
            @if ($this->catalog['level'])
                <flux:table.column align="end">Nivel</flux:table.column>
            @endif
            <flux:table.column align="end">En uso</flux:table.column>
            <flux:table.column>Estado</flux:table.column>
            <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->entries as ['entry' => $entry, 'usage' => $usage])
                <flux:table.row :key="$type.'-'.$entry->getKey()">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$entry->getAttribute('color')" :icon="$entry->getAttribute('icon') ?? ($this->catalog['level'] ? 'flag' : null)">{{ $entry->getAttribute('name') }}</flux:badge>
                            @if ($entry->getAttribute('is_default'))
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Por defecto</span>
                            @endif
                        </div>
                    </flux:table.cell>
                    @if ($this->catalog['kinds'])
                        <flux:table.cell>{{ $entry->getAttribute('kind')?->label() }}</flux:table.cell>
                    @endif
                    @if ($this->catalog['level'])
                        <flux:table.cell align="end" class="tabular-nums">{{ $entry->getAttribute('level') }}</flux:table.cell>
                    @endif
                    <flux:table.cell align="end" class="tabular-nums">{{ $usage }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($entry->getAttribute('is_active'))
                            <flux:badge size="sm" color="green" icon="check-circle">Activo</flux:badge>
                        @else
                            <flux:badge size="sm" icon="no-symbol">Inactivo</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $entry->getKey() }})" aria-label="Editar {{ $entry->getAttribute('name') }}" />
                            @if ($usage === 0 && ! $entry->getAttribute('is_default'))
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $entry->getKey() }})"
                                    wire:confirm="¿Eliminar {{ $entry->getAttribute('name') }}?" aria-label="Eliminar {{ $entry->getAttribute('name') }}" />
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="catalog-form" class="w-full max-w-md">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Editar' : 'Agregar' }}: {{ $this->catalog['label'] }}</flux:heading>

            <flux:input wire:model="form.name" label="Nombre" required />

            @if ($this->catalog['kinds'])
                <flux:select wire:model="form.kind" label="Comportamiento" :disabled="$kindLocked"
                    :description="$kindLocked ? 'Está en uso: para otro comportamiento crea un estado nuevo.' : null">
                    @foreach ($this->catalog['kinds']::cases() as $kind)
                        <flux:select.option :value="$kind->value">{{ $kind->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="form.color" label="Color">
                    @foreach (CatalogRegistry::COLORS as $color)
                        <flux:select.option :value="$color">{{ ucfirst($color) }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->catalog['icon'])
                    <flux:select wire:model.live="form.icon" label="Icono">
                        @foreach (CatalogRegistry::ICONS as $icon)
                            <flux:select.option :value="$icon">{{ $icon }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($this->catalog['order'])
                    <flux:input type="number" wire:model="form.sort_order" label="Orden" min="0" />
                @endif

                @if ($this->catalog['level'])
                    <flux:input type="number" wire:model="form.level" label="Nivel (mayor = más urgente)" min="1" />
                @endif
            </div>

            <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                Vista previa:
                <flux:badge size="sm" :color="$form['color'] ?? 'zinc'" :icon="$form['icon'] ?? null">{{ ($form['name'] ?? '') !== '' ? $form['name'] : 'Nombre' }}</flux:badge>
            </div>

            <flux:checkbox wire:model="form.is_active" label="Activo (disponible para nuevos registros)" />
            @if ($this->catalog['default'])
                <flux:checkbox wire:model="form.is_default" label="Valor por defecto para nuevos registros" />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
