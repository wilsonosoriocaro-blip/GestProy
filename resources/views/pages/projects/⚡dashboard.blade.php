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
        {{-- Portfolio status: one card per status kind; together they add up to the total. --}}
        @php
            $kinds = $data['by_kind'];
            $total = max($p['total'], 1);
            $statusCards = [
                ['total', 'Total', $p['total'], 'briefcase', 'Sin contar archivados', $this->listUrl()],
                ['active', 'En ejecución', $kinds['active'], 'play-circle', 'Avanzando según su plan', $this->listUrl(['kind' => 'active'])],
                ['planned', 'Planeados', $kinds['planned'], 'calendar', 'Aún no inician', $this->listUrl(['kind' => 'planned'])],
                ['paused', 'En pausa', $kinds['paused'], 'pause-circle', 'Detenidos temporalmente', $this->listUrl(['kind' => 'paused'])],
                ['at_risk', 'En riesgo', $kinds['at_risk'], 'shield-exclamation', 'Declarados en riesgo', $this->listUrl(['kind' => 'at_risk'])],
                ['completed', 'Finalizados', $kinds['completed'], 'check-badge', 'Entregados', $this->listUrl(['kind' => 'completed'])],
                ['cancelled', 'Cancelados', $kinds['cancelled'], 'x-circle', 'No continuarán', $this->listUrl(['kind' => 'cancelled'])],
            ];
            // Literal classes so Tailwind keeps them. Accent = top bar + composition segment; chip = icon background.
            $accent = [
                'total' => 'bg-zinc-800 dark:bg-zinc-200',
                'active' => 'bg-blue-600 dark:bg-blue-500',
                'planned' => 'bg-violet-600 dark:bg-violet-500',
                'paused' => 'bg-amber-500 dark:bg-amber-400',
                'at_risk' => 'bg-red-600 dark:bg-red-500',
                'completed' => 'bg-green-600 dark:bg-green-500',
                'cancelled' => 'bg-zinc-400 dark:bg-zinc-500',
            ];
            $chip = [
                'total' => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100',
                'active' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
                'planned' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
                'paused' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
                'at_risk' => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
                'completed' => 'bg-green-50 text-green-700 dark:bg-green-500/15 dark:text-green-300',
                'cancelled' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
            ];
        @endphp

        <section aria-labelledby="portfolio-status" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading id="portfolio-status" level="2" size="lg">Estado del portafolio</flux:heading>
                <flux:text class="text-xs">Selecciona una tarjeta para ver esos proyectos</flux:text>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                @foreach ($statusCards as [$key, $label, $value, $icon, $hint, $href])
                    <a href="{{ $href }}" wire:navigate wire:key="status-card-{{ $key }}"
                        aria-label="{{ $label }}: {{ $value }} {{ $plural($value, 'proyecto', 'proyectos') }}. Ver en la lista de proyectos"
                        class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 pt-5 transition hover:-translate-y-0.5 hover:border-zinc-400 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500">
                        <span class="absolute inset-x-0 top-0 h-1 {{ $accent[$key] }}" aria-hidden="true"></span>
                        <div class="flex items-center gap-2">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg {{ $chip[$key] }}">
                                <flux:icon :name="$icon" variant="micro" />
                            </span>
                            <span class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                        </div>
                        <div class="mt-3 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($key === 'total')
                                {{ $hint }}
                            @else
                                {{ (int) round($value * 100 / $total) }}% del portafolio · {{ $hint }}
                            @endif
                        </div>
                        <span class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-zinc-500 group-hover:text-zinc-900 dark:text-zinc-400 dark:group-hover:text-white" aria-hidden="true">
                            Ver proyectos <flux:icon name="arrow-right" variant="micro" class="size-3 transition group-hover:translate-x-0.5" />
                        </span>
                    </a>
                @endforeach
            </div>

            @if ($p['total'] > 0)
                {{-- Composition: same colors as the cards, which double as its legend. --}}
                <div class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full" role="img"
                    aria-label="Composición del portafolio: {{ collect($statusCards)->skip(1)->map(fn ($c) => $c[1].' '.$c[2])->join(', ') }}">
                    @foreach (array_slice($statusCards, 1) as [$key, $label, $value])
                        @if ($value > 0)
                            <span class="h-full first:rounded-s-full last:rounded-e-full {{ $accent[$key] }}" style="width: {{ $value * 100 / $total }}%" title="{{ $label }}: {{ $value }}"></span>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Management indicators with a traffic light (RAG): icon + label + color, goal shown on each. --}}
        @php
            $ind = $data['indicators'];
            $indicators = [
                ['schedule', 'Cumplimiento del cronograma', 'chart-bar',
                    $ind['schedule']['value'] === null ? 'No hay proyectos en ejecución con fechas para medir.' : 'Avance real '.$ind['schedule']['real'].'% frente al '.$ind['schedule']['expected'].'% esperado a hoy · '.$ind['schedule']['total'].' '.$plural($ind['schedule']['total'], 'proyecto', 'proyectos'),
                    'Avance real dividido por el avance que debería llevar según los días hábiles transcurridos (índice de desempeño del cronograma, SPI).'],
                ['on_time', 'Proyectos dentro del plazo', 'clock',
                    $ind['on_time']['total'] === 0 ? 'No hay proyectos en ejecución con fechas.' : $ind['on_time']['ok'].' de '.$ind['on_time']['total'].' en ejecución sin atraso ni avance bajo',
                    'Proyectos en ejecución o en riesgo que no están vencidos ni por debajo del avance esperado.'],
                ['delivered', 'Entregas a tiempo', 'check-badge',
                    $ind['delivered']['total'] === 0 ? 'Aún no hay proyectos finalizados con fecha.' : $ind['delivered']['ok'].' de '.$ind['delivered']['total'].' finalizados en la fecha comprometida',
                    'Proyectos finalizados cuya fecha real de entrega no superó la fecha estimada.'],
                ['tasks', 'Tareas al día', 'clipboard-document-check',
                    $ind['tasks']['total'] === 0 ? 'No hay tareas abiertas.' : $ind['tasks']['ok'].' de '.$ind['tasks']['total'].' tareas abiertas sin vencer',
                    'Tareas pendientes, en ejecución, bloqueadas o en revisión que no han pasado su fecha de vencimiento.'],
            ];
            $kpiFill = ['good' => 'bg-[#0ca30c]', 'warning' => 'bg-[#fab219]', 'critical' => 'bg-[#d03b3b]', 'no_data' => 'bg-zinc-300 dark:bg-zinc-600'];
            $kpiBadge = [
                'good' => 'bg-[#e3f6e3] text-[#0a6b0a] dark:bg-[#0ca30c]/20 dark:text-[#7ee07e]',
                'warning' => 'bg-[#fff4d6] text-[#8a5a00] dark:bg-[#fab219]/20 dark:text-[#fbd27a]',
                'critical' => 'bg-[#fbe4e4] text-[#a02525] dark:bg-[#d03b3b]/25 dark:text-[#f3a3a3]',
                'no_data' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
            ];
        @endphp

        <section aria-labelledby="management-kpis" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading id="management-kpis" level="2" size="lg">Indicadores de gestión</flux:heading>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-300" aria-label="Convenciones del semáforo">
                    @foreach ([App\Enums\KpiLevel::Good, App\Enums\KpiLevel::Warning, App\Enums\KpiLevel::Critical] as $level)
                        <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full {{ $kpiFill[$level->value] }}"></span>{{ $level->label() }}</span>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($indicators as [$key, $title, $icon, $detail, $help])
                    @php
                        $value = $ind[$key]['value'];
                        $level = App\Enums\KpiLevel::for($key, $value);
                        [$goal, $floor] = App\Enums\KpiLevel::THRESHOLDS[$key];
                    @endphp
                    <div wire:key="kpi-{{ $key }}" class="flex flex-col rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                <flux:icon :name="$icon" variant="micro" class="text-zinc-500 dark:text-zinc-400" />{{ $title }}
                            </div>
                            <flux:tooltip :content="$help">
                                <flux:icon name="information-circle" variant="micro" class="text-zinc-400" aria-label="Cómo se calcula" />
                            </flux:tooltip>
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <span class="text-4xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value === null ? '—' : $value.'%' }}</span>
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $kpiBadge[$level->value] }}">
                                <flux:icon :name="$level->icon()" variant="micro" class="size-3.5" />{{ $level->label() }}
                            </span>
                        </div>
                        <div class="relative mt-3 h-2 rounded-full bg-zinc-100 dark:bg-zinc-700" role="img"
                            aria-label="{{ $title }}: {{ $value === null ? 'sin datos' : $value.'%' }}, meta {{ $goal }}%">
                            <span class="block h-full rounded-full {{ $kpiFill[$level->value] }}" style="width: {{ min(100, $value ?? 0) }}%"></span>
                            <span class="absolute -top-1 h-4 w-0.5 rounded bg-zinc-900 dark:bg-white" style="left: {{ $goal }}%" title="Meta {{ $goal }}%"></span>
                        </div>
                        <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $detail }}</div>
                        <div class="mt-auto pt-2 text-[11px] text-zinc-500 dark:text-zinc-400">Meta ≥ {{ $goal }}% · Alerta {{ $floor }}–{{ $goal - 1 }}% · Crítico &lt; {{ $floor }}%</div>
                    </div>
                @endforeach
            </div>
        </section>

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
