<?php

use App\Enums\ScheduleHealth;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Queries\Projects\PortfolioDashboardQuery;
use App\Queries\Projects\TaskSignals;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tablero ejecutivo')] class extends Component {
    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $owner = '';

    /** Set by "Actualizar" to skip the short cache once. */
    private bool $fresh = false;

    public function refresh(): void
    {
        $this->fresh = true;
        unset($this->data);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function data(): array
    {
        return app(PortfolioDashboardQuery::class)->get(
            Auth::user(),
            ['category' => $this->category, 'owner' => $this->owner],
            fresh: $this->fresh,
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

    /**
     * Link to the project list keeping the dashboard filters.
     *
     * @param  array<string, mixed>  $extra
     */
    public function listUrl(array $extra = []): string
    {
        return route('projects.index', array_filter([
            'category' => $this->category,
            'owner' => $this->owner,
            ...$extra,
        ], fn ($value) => $value !== '' && $value !== null && $value !== false));
    }
}; ?>

@php
    $data = $this->data;
    $p = $data['projects'];
    $t = $data['tasks'];
    $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many;
    $date = fn (?string $value) => $value ? CarbonImmutable::parse($value)->translatedFormat('d M') : '—';
@endphp

<section class="flex w-full flex-col gap-6">
    {{-- Header + filters --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tablero ejecutivo</flux:heading>
            <flux:subheading>Qué está pasando con los proyectos y dónde prestar atención</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="w-48">
            <flux:select wire:model.live="category" aria-label="Categoría">
                <flux:select.option value="">Todas las categorías</flux:select.option>
                @foreach ($this->categories as $option)
                    <flux:select.option :value="$option->id" wire:key="cat-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            </div>
            <div class="w-48">
            <flux:select wire:model.live="owner" aria-label="Responsable">
                <flux:select.option value="">Todos los responsables</flux:select.option>
                @foreach ($this->owners as $option)
                    <flux:select.option :value="$option->id" wire:key="own-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            </div>
            <flux:button icon="arrow-path" wire:click="refresh" aria-label="Actualizar datos">
                <span wire:loading.remove wire:target="refresh, category, owner">{{ CarbonImmutable::parse($data['generated_at'])->setTimezone(config('app.timezone'))->format('h:i a') }}</span>
                <span wire:loading wire:target="refresh, category, owner">Cargando…</span>
            </flux:button>
        </div>
    </div>

    <div wire:loading.class="opacity-60" wire:target="refresh, category, owner" class="flex flex-col gap-6 transition-opacity">
        {{-- Headline: portfolio progress + project KPIs --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Avance general del portafolio</div>
                @if ($data['progress']['real'] === null)
                    <div class="mt-2 text-5xl font-semibold text-zinc-900 dark:text-white">—</div>
                    <flux:text class="mt-2 text-sm">No hay proyectos activos con fechas para comparar.</flux:text>
                @else
                    <div class="mt-1 flex items-baseline gap-3">
                        <span class="text-5xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $data['progress']['real'] }}%</span>
                        @php $gap = $data['progress']['real'] - $data['progress']['expected']; @endphp
                        <span @class([
                            'inline-flex items-center gap-1 text-sm font-medium',
                            'text-red-700 dark:text-red-400' => $gap < -config('business_calendar.behind_schedule_tolerance'),
                            'text-zinc-600 dark:text-zinc-300' => $gap >= -config('business_calendar.behind_schedule_tolerance'),
                        ])>
                            <flux:icon :name="$gap < 0 ? 'arrow-trending-down' : 'arrow-trending-up'" variant="micro" />
                            {{ $gap > 0 ? '+' : '' }}{{ $gap }} pts vs. esperado
                        </span>
                    </div>
                    <x-projects.progress-bar class="mt-4" :value="$data['progress']['real']" :expected="$data['progress']['expected']" label="Avance general" />
                    <flux:text class="mt-2 text-xs">Promedio de {{ $data['progress']['measured'] }} {{ $plural($data['progress']['measured'], 'proyecto activo', 'proyectos activos') }} con fechas. La marca muestra el avance esperado a hoy según los días hábiles transcurridos.</flux:text>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                @foreach ([
                    ['Activos', $p['active'], 'play-circle', null, 'En ejecución o en riesgo'],
                    ['En riesgo', $p['at_risk'], 'shield-exclamation', $this->listUrl(['atRisk' => true]), 'Declarados en riesgo o atrasados'],
                    ['Atrasados', $p['overdue'], 'exclamation-triangle', $this->listUrl(['overdue' => true]), 'Vencieron sin finalizar'],
                    ['Próximos a finalizar', $p['due_soon'], 'bell-alert', null, 'Vencen en '.config('business_calendar.due_soon_business_days').' días hábiles'],
                    ['En pausa', $p['paused'], 'pause-circle', null, null],
                    ['Planeados', $p['planned'], 'calendar', null, null],
                    ['Finalizados', $p['completed'], 'check-circle', null, null],
                    ['Total', $p['total'], 'briefcase', $this->listUrl(), 'Sin contar archivados'],
                ] as [$label, $value, $icon, $href, $hint])
                    <a @if ($href) href="{{ $href }}" wire:navigate @endif
                        @class(['rounded-xl border border-zinc-200 p-4 dark:border-zinc-700', 'transition hover:border-zinc-400 dark:hover:border-zinc-500' => $href])>
                        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:icon :name="$icon" variant="micro" /> {{ $label }}
                        </div>
                        <div class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
                        @if ($hint)
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Alerts --}}
        @php
            $alerts = [
                ['Proyectos atrasados', 'exclamation-triangle', $data['alerts']['overdue_projects'], 'project',
                    fn ($i) => $i['overdue_days'].' '.$plural($i['overdue_days'], 'día hábil', 'días hábiles').' de retraso'],
                ['Avance bajo frente al tiempo', 'arrow-trending-down', $data['alerts']['behind_projects'], 'project',
                    fn ($i) => $i['progress'].'% real vs. '.$i['expected'].'% esperado'],
                ['Sin actividad reciente', 'moon', $data['alerts']['stale_projects'], 'project',
                    fn ($i) => 'Último movimiento '.CarbonImmutable::parse($i['last_activity'])->setTimezone(config('app.timezone'))->translatedFormat('d M Y')],
                ['Tareas vencidas', 'exclamation-circle', $data['alerts']['overdue_tasks'], 'task',
                    fn ($i) => 'Venció el '.$date($i['due_date']).' · '.($i['owner'] ?? 'sin asignar')],
                ['Tareas próximas a vencer', 'bell-alert', $data['alerts']['due_soon_tasks'], 'task',
                    fn ($i) => 'Vence el '.$date($i['due_date']).' · '.($i['owner'] ?? 'sin asignar')],
            ];
            $attention = collect($alerts)->sum(fn ($a) => $a[2]['count']);
        @endphp

        <x-projects.panel title="Requiere atención"
            :subtitle="$attention === 0 ? 'Todo en orden: no hay alertas activas.' : 'Proyectos y tareas que necesitan una decisión o seguimiento'">
            @if ($attention > 0)
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($alerts as [$title, $icon, $list, $type, $detail])
                        <div class="flex flex-col gap-2" wire:key="alert-{{ $loop->index }}">
                            <div class="flex items-center gap-2 text-sm font-medium text-zinc-800 dark:text-white">
                                <flux:icon :name="$icon" variant="micro" @class(['text-red-600 dark:text-red-400' => $list['count'] > 0, 'text-zinc-400' => $list['count'] === 0]) />
                                {{ $title }}
                                <flux:badge size="sm" :color="$list['count'] > 0 ? 'red' : 'zinc'" class="ms-auto">{{ $list['count'] }}</flux:badge>
                            </div>
                            <ul class="flex flex-col gap-2">
                                @forelse ($list['items'] as $item)
                                    <li class="text-sm" wire:key="alert-{{ $loop->parent->index }}-{{ $item['id'] }}">
                                        <a href="{{ route('projects.show', $type === 'project' ? $item['id'] : $item['project_id']) }}" wire:navigate class="block rounded-md px-2 py-1 -mx-2 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                            <span class="block truncate font-medium text-zinc-800 dark:text-white">{{ $item['name'] }}</span>
                                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $item['code'] }} · {{ $detail($item) }}</span>
                                        </a>
                                    </li>
                                @empty
                                    <li class="text-xs text-zinc-500 dark:text-zinc-400">Ninguno.</li>
                                @endforelse
                                @if ($list['count'] > count($list['items']))
                                    <li class="text-xs text-zinc-500 dark:text-zinc-400">y {{ $list['count'] - count($list['items']) }} más</li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-projects.panel>

        {{-- Task KPIs --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 xl:grid-cols-7">
            @foreach ([
                ['Tareas', $t['total'], 'clipboard-document-list'],
                ['Pendientes', $t['by_kind']['pending'], 'clock'],
                ['En ejecución', $t['by_kind']['in_progress'], 'play-circle'],
                ['Bloqueadas', $t['signals'][TaskSignals::BLOCKED], 'no-symbol'],
                ['Vencidas', $t['signals'][TaskSignals::OVERDUE], 'exclamation-triangle'],
                ['Próximas a vencer', $t['signals'][TaskSignals::DUE_SOON], 'bell-alert'],
                ['Finalizadas', $t['by_kind']['completed'], 'check-circle'],
            ] as [$label, $value, $icon])
                <x-projects.stat :label="$label" :value="$value" :icon="$icon" />
            @endforeach
        </div>

        {{-- Charts --}}
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <x-projects.panel title="Proyectos por estado" subtitle="Sin contar archivados">
                <x-projects.bar-list label="Proyectos por estado"
                    :items="array_map(fn ($s) => $s + ['href' => $s['count'] ? $this->listUrl(['status' => $s['id']]) : null], $data['by_status'])" />
            </x-projects.panel>

            <x-projects.panel title="Proyectos por prioridad" subtitle="Solo proyectos abiertos">
                <x-projects.bar-list label="Proyectos por prioridad"
                    :items="array_map(fn ($s) => $s + ['href' => $s['count'] ? $this->listUrl(['priority' => $s['id']]) : null], $data['by_priority'])" />
            </x-projects.panel>

            <x-projects.panel title="Tareas por estado">
                <x-projects.bar-list label="Tareas por estado" unit="tareas" :items="$data['task_statuses']" />
            </x-projects.panel>

            <x-projects.panel title="Carga de trabajo" subtitle="Tareas abiertas por persona">
                @if ($data['workload'] === [])
                    <flux:text class="text-sm">No hay tareas abiertas asignadas.</flux:text>
                @else
                    @php $maxOpen = max(array_column($data['workload'], 'open')); @endphp
                    <div class="flex items-center gap-4 text-xs text-zinc-600 dark:text-zinc-300" aria-hidden="true">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-blue-600 dark:bg-blue-500"></span>A tiempo</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-600 dark:bg-red-500"></span>Vencidas</span>
                    </div>
                    <ul class="flex flex-col gap-3" aria-label="Carga de trabajo por persona">
                        @foreach ($data['workload'] as $row)
                            @php
                                $onTime = $row['open'] - $row['overdue'];
                                $scale = 100 / $maxOpen;
                            @endphp
                            <li class="text-sm" wire:key="load-{{ $row['user_id'] }}"
                                title="{{ $row['name'] }}: {{ $row['open'] }} abiertas, {{ $row['overdue'] }} vencidas, {{ $row['owned_projects'] }} proyectos a cargo">
                                <div class="mb-1 flex flex-wrap items-baseline justify-between gap-x-2">
                                    <span class="text-zinc-800 dark:text-white">{{ $row['name'] }}</span>
                                    <span class="whitespace-nowrap text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $row['open'] }}</span> {{ $plural($row['open'], 'abierta', 'abiertas') }}
                                        @if ($row['overdue'])
                                            · {{ $row['overdue'] }} {{ $plural($row['overdue'], 'vencida', 'vencidas') }}
                                        @endif
                                        @if ($row['owned_projects'])
                                            · {{ $row['owned_projects'] }} {{ $plural($row['owned_projects'], 'proyecto', 'proyectos') }} a cargo
                                        @endif
                                    </span>
                                </div>
                                <div class="flex h-3 gap-0.5" aria-hidden="true">
                                    @if ($onTime > 0)
                                        <span @class(['h-full bg-blue-600 dark:bg-blue-500', 'rounded-e' => ! $row['overdue']]) style="width: {{ $onTime * $scale }}%"></span>
                                    @endif
                                    @if ($row['overdue'] > 0)
                                        <span class="h-full rounded-e bg-red-600 dark:bg-red-500" style="width: {{ $row['overdue'] * $scale }}%"></span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-projects.panel>

            <x-projects.panel title="Avance real vs. esperado" subtitle="Proyectos activos, los más atrasados primero" class="lg:col-span-2 xl:col-span-2">
                @if ($data['progress_gap'] === [])
                    <flux:text class="text-sm">No hay proyectos activos con fechas.</flux:text>
                @else
                    <div class="flex items-center gap-4 text-xs text-zinc-600 dark:text-zinc-300" aria-hidden="true">
                        <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-4 rounded-full bg-[var(--color-accent)]"></span>Avance real</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-0.5 rounded bg-zinc-800 dark:bg-white"></span>Esperado a hoy</span>
                    </div>
                    <ul class="grid gap-x-8 gap-y-3 md:grid-cols-2">
                        @foreach ($data['progress_gap'] as $row)
                            @php $health = ScheduleHealth::from($row['health']); @endphp
                            <li wire:key="gap-{{ $row['id'] }}">
                                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                    <a href="{{ route('projects.show', $row['id']) }}" wire:navigate class="truncate font-medium text-zinc-800 hover:underline dark:text-white">{{ $row['name'] }}</a>
                                    <flux:badge size="sm" :color="$health->color()" :icon="$health->icon()">{{ $row['gap'] > 0 ? '+' : '' }}{{ $row['gap'] }} pts</flux:badge>
                                </div>
                                <x-projects.progress-bar :value="$row['progress']" :expected="$row['expected']" :label="$row['name']" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-projects.panel>
        </div>

        {{-- Upcoming --}}
        <x-projects.panel title="Próximos vencimientos" subtitle="Proyectos y tareas abiertos que vencen en los próximos 30 días">
            @if ($data['upcoming'] === [])
                <flux:text class="text-sm">Nada vence en los próximos 30 días.</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200 md:hidden dark:divide-zinc-700">
                    @foreach ($data['upcoming'] as $item)
                        <li class="flex items-start justify-between gap-3 py-3 text-sm" wire:key="up-m-{{ $item['type'] }}-{{ $item['id'] }}">
                            <div class="min-w-0">
                                <a href="{{ route('projects.show', $item['project_id']) }}" wire:navigate class="font-medium hover:underline">{{ $item['name'] }}</a>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item['type'] === 'project' ? 'Proyecto' : 'Tarea' }} · {{ $item['code'] }} · {{ $item['owner'] ?? 'Sin asignar' }}</div>
                            </div>
                            <div class="whitespace-nowrap text-end text-xs">
                                <div class="font-medium text-zinc-800 dark:text-white">{{ CarbonImmutable::parse($item['due_date'])->translatedFormat('D d M') }}</div>
                                <div class="text-zinc-500 dark:text-zinc-400">{{ $item['due_date'] === today()->toDateString() ? 'Vence hoy' : $item['remaining'].' días háb.' }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <flux:table class="max-md:hidden">
                    <flux:table.columns>
                        <flux:table.column>Fecha</flux:table.column>
                        <flux:table.column>Tipo</flux:table.column>
                        <flux:table.column>Nombre</flux:table.column>
                        <flux:table.column>Responsable</flux:table.column>
                        <flux:table.column align="end">Días hábiles restantes</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($data['upcoming'] as $item)
                            <flux:table.row :key="$item['type'].'-'.$item['id']">
                                <flux:table.cell class="whitespace-nowrap tabular-nums">{{ CarbonImmutable::parse($item['due_date'])->translatedFormat('D d M') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :icon="$item['type'] === 'project' ? 'briefcase' : 'clipboard-document-check'">{{ $item['type'] === 'project' ? 'Proyecto' : 'Tarea' }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="max-w-96 whitespace-normal">
                                    <a href="{{ route('projects.show', $item['project_id']) }}" wire:navigate class="font-medium hover:underline">{{ $item['name'] }}</a>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item['code'] }}@if ($item['project']) · {{ $item['project'] }}@endif</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $item['owner'] ?? 'Sin asignar' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">
                                    {{ $item['due_date'] === today()->toDateString() ? 'Vence hoy' : $item['remaining'] }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-projects.panel>
    </div>
</section>
