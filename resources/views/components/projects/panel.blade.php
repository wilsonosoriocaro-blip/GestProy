@props(['title', 'subtitle' => null])

<section {{ $attributes->class('flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <header>
        <flux:heading level="2" size="lg">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:text class="text-sm">{{ $subtitle }}</flux:text>
        @endif
    </header>

    {{ $slot }}
</section>
