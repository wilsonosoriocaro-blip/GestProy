{{--
    Timeline drawn from GanttBuilder output. Pure Blade + CSS + one SVG for
    dependency arrows; horizontal scroll with the name column pinned.
--}}
@props(['chart', 'label' => 'Cronograma'])

@php
    $rowHeight = 44;
    $rows = $chart['rows'];
    $height = count($rows) * $rowHeight;
    $px = $chart['px_per_day'];
@endphp

@if ($rows === [])
    <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
        No hay elementos para mostrar en el cronograma.
    </div>
@else
    {{-- Opens scrolled so that today sits at a third of the visible chart. --}}
    @php
        $scrollToToday = $chart['today'] === null ? '' : "\$nextTick(() => { const label = parseFloat(getComputedStyle(\$el).getPropertyValue('--gantt-label')) || 0; \$el.scrollLeft = Math.max(0, {$chart['today']} - (\$el.clientWidth - label) / 3) })";
    @endphp
    {{-- The name column is narrower on phones: --gantt-label drives every offset. --}}
    <div {{ $attributes->class('overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700 [--gantt-label:256px] max-sm:[--gantt-label:150px]') }} role="region" aria-label="{{ $label }}" tabindex="0"
        x-data x-init="{{ $scrollToToday }}">
        <div class="relative" style="width: calc(var(--gantt-label) + {{ $chart['width'] }}px); min-width: 100%">
            {{-- Time axis --}}
            <div class="sticky top-0 z-20 flex border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="sticky left-0 z-30 flex shrink-0 items-end border-e border-zinc-200 bg-white px-3 pb-1.5 text-xs font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400" style="width: var(--gantt-label)">
                    Nombre
                </div>
                <div class="relative shrink-0" style="width: {{ $chart['width'] }}px; height: {{ $chart['weeks'] ? 52 : 30 }}px">
                    @foreach ($chart['months'] as $month)
                        <div class="absolute top-0 h-7 truncate border-s border-zinc-200 px-2 pt-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200"
                            style="left: {{ $month['x'] }}px; width: {{ $month['w'] }}px">{{ $month['label'] }}</div>
                    @endforeach
                    @foreach ($chart['weeks'] as $week)
                        <div class="absolute top-7 h-6 border-s border-zinc-100 px-1 pt-1 text-[11px] text-zinc-500 dark:border-zinc-700/60 dark:text-zinc-400"
                            style="left: {{ $week['x'] }}px; width: {{ $week['w'] }}px">{{ $week['label'] }}</div>
                    @endforeach
                    @if ($chart['today'] !== null)
                        <div class="absolute bottom-0 z-10 -translate-x-1/2 rounded bg-zinc-900 px-1.5 text-[10px] font-medium text-white dark:bg-white dark:text-zinc-900"
                            style="left: {{ $chart['today'] + intdiv($px, 2) }}px">Hoy</div>
                    @endif
                </div>
            </div>

            {{-- Body --}}
            <div class="relative">
                {{-- Background: non-working days, month separators, today --}}
                <div class="pointer-events-none absolute inset-y-0" style="left: var(--gantt-label); width: {{ $chart['width'] }}px" aria-hidden="true">
                    @foreach ($chart['non_working'] as $run)
                        <div class="absolute inset-y-0 bg-zinc-100/80 dark:bg-zinc-700/30" style="left: {{ $run['x'] }}px; width: {{ $run['w'] }}px"></div>
                    @endforeach
                    @foreach ($chart['months'] as $month)
                        <div class="absolute inset-y-0 border-s border-zinc-200 dark:border-zinc-700" style="left: {{ $month['x'] }}px"></div>
                    @endforeach
                    @if ($chart['today'] !== null)
                        <div class="absolute inset-y-0 z-10 border-s-2 border-dashed border-zinc-900 dark:border-white" style="left: {{ $chart['today'] + intdiv($px, 2) }}px"></div>
                    @endif
                </div>

                {{-- Dependency arrows (finish → start) --}}
                @if ($chart['links'])
                    <svg class="pointer-events-none absolute top-0 z-[15] text-zinc-600 dark:text-zinc-300" style="left: var(--gantt-label)"
                        width="{{ $chart['width'] }}" height="{{ $height }}" aria-hidden="true">
                        <defs>
                            <marker id="gantt-arrow-{{ $attributes->get('id', 'g') }}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto">
                                <path d="M0,0 L8,4 L0,8 z" fill="currentColor" />
                            </marker>
                        </defs>
                        @foreach ($chart['links'] as $link)
                            @php
                                $y1 = $link['from'] * $rowHeight + $rowHeight / 2;
                                $y2 = $link['to'] * $rowHeight + $rowHeight / 2;
                                $elbow = max($link['x1'], $link['x2'] - 10) + 6;
                            @endphp
                            <path d="M{{ $link['x1'] }},{{ $y1 }} H{{ $elbow }} V{{ $y2 }} H{{ $link['x2'] - 1 }}"
                                fill="none" stroke="currentColor" stroke-width="1.5" marker-end="url(#gantt-arrow-{{ $attributes->get('id', 'g') }})" />
                        @endforeach
                    </svg>
                @endif

                <ul>
                    @foreach ($rows as $row)
                        @php
                            $health = App\Enums\ScheduleHealth::from($row['health']);
                            $summary = $row['label'].': '.$row['dates'].', avance '.$row['progress'].'%, '.$health->label();
                            // Only set when several projects share this timeline (portfolio view, "por persona"):
                            // ties every bar back to its project without depending on the health colors.
                            $barColor = match ($row['color'] ?? null) {
                                'violet' => 'bg-violet-600 dark:bg-violet-500',
                                'amber' => 'bg-amber-600 dark:bg-amber-500',
                                'emerald' => 'bg-emerald-600 dark:bg-emerald-500',
                                'cyan' => 'bg-cyan-600 dark:bg-cyan-500',
                                'fuchsia' => 'bg-fuchsia-600 dark:bg-fuchsia-500',
                                'orange' => 'bg-orange-600 dark:bg-orange-500',
                                'teal' => 'bg-teal-600 dark:bg-teal-500',
                                'rose' => 'bg-rose-600 dark:bg-rose-500',
                                default => 'bg-blue-600 dark:bg-blue-500',
                            };
                        @endphp
                        <li class="flex border-b border-zinc-100 last:border-b-0 dark:border-zinc-700/60" style="height: {{ $rowHeight }}px" wire:key="gantt-{{ $row['key'] }}">
                            <div class="sticky left-0 z-20 flex shrink-0 flex-col justify-center border-e border-zinc-200 bg-white px-3 dark:border-zinc-700 dark:bg-zinc-800" style="width: var(--gantt-label)">
                                @if ($row['href'])
                                    <a href="{{ $row['href'] }}" wire:navigate class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $row['label'] }}</a>
                                @else
                                    <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $row['label'] }}</span>
                                @endif
                                <span class="flex items-center gap-1 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($row['color'] ?? null)
                                        <span class="size-2 shrink-0 rounded-full {{ $barColor }}" title="Proyecto: {{ $row['sublabel'] }}" aria-hidden="true"></span>
                                    @endif
                                    <flux:icon :name="$health->icon()" variant="micro" class="shrink-0" />
                                    <span class="truncate">{{ $health->label() }} · {{ $row['sublabel'] }}</span>
                                </span>
                            </div>

                            <div class="relative shrink-0" style="width: {{ $chart['width'] }}px">
                                @if ($row['milestone'])
                                    <span class="absolute top-1/2 z-10 size-3.5 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] {{ $barColor }} ring-2 ring-white dark:ring-zinc-800"
                                        style="left: {{ $row['x'] }}px" title="{{ $summary }}" role="img" aria-label="{{ $summary }}"></span>
                                @elseif ($row['has_bar'])
                                    <span class="absolute top-3 z-10 h-5 overflow-hidden rounded bg-zinc-300 dark:bg-zinc-600"
                                        style="left: {{ $row['x'] }}px; width: {{ max($row['w'], 4) }}px" title="{{ $summary }}" role="img" aria-label="{{ $summary }}">
                                        <span class="block h-full {{ $barColor }}" style="width: {{ $row['progress'] }}%"></span>
                                    </span>
                                    @if ($row['overdue_w'])
                                        <span class="absolute top-3 z-10 h-5 rounded-e bg-[repeating-linear-gradient(135deg,var(--color-red-600)_0_3px,transparent_3px_7px)] opacity-70 dark:bg-[repeating-linear-gradient(135deg,var(--color-red-500)_0_3px,transparent_3px_7px)]"
                                            style="left: {{ $row['overdue_x'] }}px; width: {{ $row['overdue_w'] }}px" title="Retraso: vencía el {{ $row['dates'] }}" aria-hidden="true"></span>
                                    @endif
                                    @php
                                        // Progress label right after the bar (or its delay); inside the bar end when there is no room.
                                        $after = $row['x'] + $row['w'] + ($row['overdue_w'] ?? 0) + 6;
                                        $labelX = $after + 40 <= $chart['width'] ? $after : max($row['x'] + 2, $row['x'] + $row['w'] - 40);
                                    @endphp
                                    <span class="pointer-events-none absolute top-3 z-10 flex h-5 items-center text-[11px] font-medium tabular-nums text-zinc-700 dark:text-zinc-200"
                                        style="left: {{ $labelX }}px" aria-hidden="true">
                                        <span class="rounded bg-white/85 px-1 dark:bg-zinc-800/85">{{ $row['progress'] }}%</span>
                                    </span>
                                @else
                                    <span class="absolute inset-y-0 left-3 flex items-center text-xs text-zinc-400">Sin fechas</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-zinc-600 dark:text-zinc-300" aria-label="Convenciones del cronograma">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-6 rounded-sm bg-zinc-300 dark:bg-zinc-600"></span>Duración planeada</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-6 rounded-sm bg-blue-600 dark:bg-blue-500"></span>Avance</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-6 rounded-sm bg-[repeating-linear-gradient(135deg,var(--color-red-600)_0_3px,transparent_3px_7px)] opacity-70"></span>Retraso a hoy</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] bg-blue-600 dark:bg-blue-500"></span>Hito (una sola fecha)</span>
        @if ($chart['links'])
            <span class="inline-flex items-center gap-1.5"><flux:icon name="arrow-long-right" variant="micro" />Dependencia</span>
        @endif
        @if ($chart['non_working'])
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-sm bg-zinc-200 dark:bg-zinc-700"></span>Fin de semana o festivo</span>
        @endif
        <span class="inline-flex items-center gap-1.5"><span class="h-3 border-s-2 border-dashed border-zinc-900 dark:border-white"></span>Hoy</span>
        @if (collect($rows)->contains(fn ($row) => $row['color'] ?? null))
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-violet-600 dark:bg-violet-500"></span>Un color por proyecto (ver el punto junto a cada fila)</span>
        @endif
    </div>
@endif
