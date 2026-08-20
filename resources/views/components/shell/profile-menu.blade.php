@props(['user' => null])

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            class="flex shrink-0 rounded-full"
            title="{{ $user?->name }}">
        <x-ui.avatar :user="$user" />
    </button>

    <div x-show="open"
         x-cloak
         @click.outside="open = false"
         role="menu"
         class="pops-onto-page absolute end-0 top-full z-40 mt-1 w-56 rounded-(--radius-field) border
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
        <a href="{{ route('profile') }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <span aria-hidden="true">👤</span>
            {{ __('core.profile.title') }}
        </a>

        <a href="{{ route('appearance') }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm
                  transition-colors hover:bg-(--color-surface-hover)">
            <span aria-hidden="true">🎨</span>
            {{ __('core.appearance.title') }}
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
