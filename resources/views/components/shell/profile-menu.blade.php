@props(['user' => null, 'company' => null, 'branch' => null])

{{--
    ⭐ ছবির মেনু — অ্যাপের বারের এক নিয়মে (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬; নকশা পাতা ৩: প্রোফাইল ও ছবি · কোম্পানি বদল · ভাষা ·
    রং · পূর্ণপর্দা · সেটিংস · বের হওয়া)। ⓘ বার থেকে যা উঠে এল (ভাষা, রং, পূর্ণপর্দা, কোম্পানি বদল) তা এখানে — Fiori-র
    "user actions" মেনু।
--}}
@php
    $theme = $user?->theme ?? 'light';
    $canSwitch = app(\App\Core\Services\ShellFacts::class)->canSwitch();
    $row = 'flex min-h-(--spacing-touch) w-full items-center gap-2 px-3 text-start text-sm transition-colors hover:bg-(--color-surface-hover)';
@endphp
<div x-data="{ open: false }" class="relative" data-profile-menu>
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="{{ __('core.profile.menu', ['name' => $user?->name]) }}"
            class="flex shrink-0 rounded-full"
            title="{{ $user?->name }}">
        <x-ui.avatar :user="$user" />
    </button>

    <div x-show="open"
         x-cloak
         @click.outside="open = false"
         role="menu"
         class="pops-onto-page absolute end-0 top-full z-40 mt-1 w-72 rounded-(--radius-field) border
                border-(--color-border) bg-(--color-surface-card) py-1 shadow-(--shadow-overlay)">

        <div class="flex items-center gap-2 border-b border-(--color-border) px-3 py-2">
            <x-ui.avatar :user="$user" size="sm" />
            <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-(--color-ink)">{{ $user?->name }}</span>
                <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $user?->email }}</span>
            </span>
        </div>

        {{-- ছবি ও নাম বদলানোর জায়গা এখানে নয়, প্রোফাইল পাতায়: মেনুর
             ভেতরে আপলোড বসালে সেটিং দুই জায়গায় ভাগ হয়ে যায়। --}}
        <a href="{{ route('profile') }}" role="menuitem" class="{{ $row }}">
            <span aria-hidden="true">👤</span>
            {{ __('core.profile.title') }}
        </a>

        {{-- কোম্পানি বা শাখা বদল — বারের "কোম্পানি · শাখা ▾"-টাই খোলে, দ্বিতীয় কোনো তালিকা নয় --}}
        @if ($canSwitch)
            <button type="button" role="menuitem" class="{{ $row }}" data-menu-company
                    @click="open = false; $dispatch('open-company-switcher')">
                <span aria-hidden="true">🏢</span>
                {{ __('core.company.switch') }}
            </button>
        @endif

        {{-- ভাষা — দুইটাই দেখা যায়, চলতিটা চিহ্নিত; চাপলে সেটাতেই যায় --}}
        <form method="POST" action="{{ route('locale.switch') }}" class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm" data-menu-language>
            @csrf
            <span aria-hidden="true">🌐</span>
            <span>{{ __('core.profile.language') }}:</span>
            @foreach (['bn' => 'বাংলা', 'en' => 'English'] as $code => $name)
                <button type="submit" name="locale" value="{{ $code }}" role="menuitemradio"
                        aria-checked="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                        @class(['rounded-(--radius-field) px-1.5 hover:bg-(--color-surface-hover)', 'font-semibold underline' => app()->getLocale() === $code])>{{ $name }}</button>
            @endforeach
        </form>

        {{-- রং — ফোনের মতো (যন্ত্রের পছন্দ), আলো, অন্ধকার — অ্যাপের একই তিনটা --}}
        <form method="POST" action="{{ route('theme.switch') }}" class="flex min-h-(--spacing-touch) flex-wrap items-center gap-x-2 px-3 text-sm" data-menu-theme>
            @csrf
            <span aria-hidden="true">🎨</span>
            <span>{{ __('core.appearance.colour') }}:</span>
            @foreach (['system', 'light', 'dark'] as $mode)
                <button type="submit" name="theme" value="{{ $mode }}" role="menuitemradio"
                        aria-checked="{{ $theme === $mode ? 'true' : 'false' }}"
                        @class(['rounded-(--radius-field) px-1.5 hover:bg-(--color-surface-hover)', 'font-semibold underline' => $theme === $mode])>{{ __('core.appearance.'.$mode) }}</button>
            @endforeach
        </form>

        {{-- পূর্ণপর্দা — বারের বোতামের একই যন্ত্র ([[fullscreenToggle]]) --}}
        <button type="button" role="menuitem" class="{{ $row }}" data-menu-fullscreen
                x-data="fullscreenToggle" x-init="sync()" @fullscreenchange.document="sync()" @click="toggle()">
            <span aria-hidden="true">⛶</span>
            <span x-text="full ? '{{ __('core.action.exit_fullscreen') }}' : '{{ __('core.action.fullscreen') }}'">{{ __('core.action.fullscreen') }}</span>
        </button>

        <a href="{{ route('appearance') }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <span aria-hidden="true">🎨</span>
            {{ __('core.appearance.title') }}
        </a>

        {{-- ⭐ রিপোর্ট সেন্টার — সব মডিউলের রিপোর্ট আর নিজের প্রিয়, এক পাতায় (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।
             ⓘ এখানে, কারণ প্রোফাইলের মেনু দশটা রূপেই আছে আর ফোনেও; সারিগুলো প্রতিটা নিজের চাবিতে ছাঁকা। --}}
        <a href="{{ route('reports.center') }}" role="menuitem" data-report-center
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <x-ui.icon name="reports" :size="16" />
            {{ __('report_center.open') }}
        </a>

        {{--
            কোথায় কোথায় লগইন আছি।

            ── কেন এখানে, মেনুর ভেতরে নয় ────────────────────────────────
            "আমি কোথায় কোথায় লগইন আছি" প্রতিটা ব্যবহারকারীর নিজের প্রশ্ন,
            প্রশাসনিক নয় — চেহারা বা প্রোফাইলের মতোই। মডিউলের মেনুতে
            রাখলে সেটার একটা অনুমতি লাগত, আর যাঁর সবচেয়ে বেশি দরকার —
            যে কর্মী কাউন্টারে লগইন রেখে এসেছেন — তিনিই পৌঁছাতে পারতেন না।

            লগ-আউটের ঠিক উপরে, কারণ প্রশ্ন দুইটা পাশাপাশি: "এখান থেকে
            বেরোব" আর "বাকি জায়গাগুলো থেকেও বেরোব"।
        --}}
        {{-- দুই ধাপের লগইন — নিজের অ্যাকাউন্টের তালা, নিজের সিদ্ধান্ত --}}
        <a href="{{ route('mfa') }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <x-ui.icon name="lock" :size="16" />
            {{ __('auth.mfa_title') }}
        </a>

        <a href="{{ route('governance.session.index') }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <x-ui.icon name="lock" :size="16" />
            {{ __('governance::menu.my_sessions') }}
        </a>

        <form method="POST" action="{{ route('logout') }}" class="border-t border-(--color-border)">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex min-h-(--spacing-touch) w-full items-center px-3 text-start text-sm
                           text-(--color-danger) transition-colors hover:bg-(--color-surface-hover)">
                {{ __('core.action.logout') }}
            </button>
        </form>
    </div>
</div>
