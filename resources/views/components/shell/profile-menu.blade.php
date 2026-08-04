@props(['user' => null])

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            class="flex size-(--spacing-touch) items-center justify-center rounded-full
                   bg-(--color-brand-700) text-sm font-semibold text-(--color-ink-inverse)"
            title="{{ $user?->name }}">
        {{-- আদ্যক্ষর — ছবি নেই বলে ফাঁকা বৃত্ত দেখানোর চেয়ে ভালো --}}
        {{ mb_substr($user?->name ?? '?', 0, 1) }}
    </button>

    <div x-show="open"
         x-cloak
         @click.outside="open = false"
         role="menu"
         class="absolute end-0 top-full z-40 mt-1 w-56 rounded-(--radius-field) border
                border-(--color-border) bg-(--color-surface-card) py-1 shadow-(--shadow-overlay)">

        <div class="border-b border-(--color-border) px-3 py-2">
            <p class="truncate text-sm font-medium text-(--color-ink)">{{ $user?->name }}</p>
            <p class="truncate text-2xs text-(--color-ink-muted)">{{ $user?->email }}</p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex min-h-(--spacing-touch) w-full items-center px-3 text-start text-sm
                           text-(--color-danger) transition-colors hover:bg-(--color-surface-hover)">
                {{ __('core.action.logout') }}
            </button>
        </form>
    </div>
</div>
