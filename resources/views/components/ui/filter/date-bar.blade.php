{{-- ⭐ 📅 তারিখ ▾ — [[toolbar]] আর [[filter-bar]] দুই জায়গায় হুবহু একই ([[FilterSet::bar()]]) --}}
<div x-data="{ open: false }" class="relative order-3" data-date-bar>
    <button type="button" @click="open = ! open" @click.outside="open = false" @keydown.escape.window="open = false"
            :aria-expanded="open.toString()" aria-haspopup="true"
            aria-label="{{ $dateBar['label'] }}{{ $dateBar['text'] !== null ? ': '.$dateBar['text'] : '' }}"
            @class(['flex h-(--spacing-field-compact) items-center gap-1.5 whitespace-nowrap rounded-(--radius-field) border px-2.5 text-sm transition-colors hover:bg-(--color-surface-hover)',
                    'border-(--color-border) text-(--color-ink-muted)' => $dateBar['text'] === null,
                    'border-(--color-brand-500) font-medium text-(--color-ink)' => $dateBar['text'] !== null])>
        <span aria-hidden="true">📅</span>
        <span data-date-bar-text>{{ $dateBar['text'] ?? $dateBar['label'] }}</span>
        <span aria-hidden="true">▾</span>
    </button>
    <div x-show="open" x-cloak x-transition.opacity
         class="absolute start-0 z-30 mt-1 w-52 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) py-1 shadow-lg">
        @foreach ($dateBar['presets'] as $preset)
            <a href="{{ $preset['url'] }}" data-date-preset="{{ $preset['key'] }}" @if ($preset['on']) aria-current="true" @endif
               @class(['flex items-center justify-between gap-2 px-3 py-1.5 text-sm hover:bg-(--color-surface-hover)',
                       'border-t border-(--color-border)' => $preset['key'] === 'any',
                       'font-semibold text-(--color-brand-600)' => $preset['on']])>
                {{ $preset['label'] }}
                @if ($preset['on'])<span aria-hidden="true">✓</span>@endif
            </a>
        @endforeach
        <span x-data="filterToggle" class="block border-t border-(--color-border)">
            <button type="button" x-on:click="toggle" data-date-custom aria-label="{{ __('core.list_filter.custom') }}"
                    class="block w-full px-3 py-1.5 text-start text-sm text-(--color-ink-muted) hover:bg-(--color-surface-hover)">
                {{ __('core.list_filter.custom') }}
            </button>
        </span>
    </div>
</div>
