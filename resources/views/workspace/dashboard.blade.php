{{--
    হোম পর্দা — মালিকের প্রশ্নগুলো, তিন দলে।

    আজ কী হলো · মাসটা কেমন যাচ্ছে · কী করা বাকি।

    প্রতিটা টাইল একটা লিংক, ব্যতিক্রম ছাড়া (নিয়ম ১)। যে সংখ্যা ক্লিক করা
    যায় না সেটা ব্যবহারকারীকে বিশ্বাস করতে বাধ্য করে, যাচাই করতে দেয় না —
    আর ভুল হলে কেউ ধরতে পারে না।

    কোন সংখ্যাগুলো আসবে তা এই ফাইল জানে না; মডিউলরা নিজেরা দেয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.menu.dashboard') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('core.menu.dashboard')"
            :subtitle="auth()->user()?->currentCompany?->name()
                . (auth()->user()?->currentBranch ? ' · ' . auth()->user()->currentBranch->name() : '')" />
    </x-slot:header>

    @php
        $titles = [
            'today' => __('core.dashboard.today'),
            'month' => __('core.dashboard.this_month'),
            'todo' => __('core.dashboard.needs_doing'),
        ];
    @endphp

    @forelse (array_filter($groups) as $group => $widgets)
        <section class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ $titles[$group] }}</h2>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($widgets as $widget)
                    {{-- পুরো টাইলটাই লিংক, শুধু সংখ্যাটা নয় — আঙুলে ছোট
                         লক্ষ্যবস্তু ধরা কঠিন, আর ফোনেই এটা বেশি দেখা হয় --}}
                    <a href="{{ $widget->href }}"
                       class="block rounded-(--radius-card) border border-(--color-border)
                              bg-(--color-surface-card) p-4 shadow-(--shadow-card) transition-colors
                              hover:bg-(--color-surface-hover)">
                        <p class="text-sm text-(--color-ink-muted)">{{ $widget->label }}</p>

                        <p @class([
                            'tabular mt-1 text-2xl font-semibold',
                            'text-(--color-ink)' => $widget->tone === 'neutral',
                            'text-(--color-brand-600)' => $widget->tone === 'money',
                            'text-(--color-badge-success-ink)' => $widget->tone === 'good',
                            'text-(--color-badge-warning-ink)' => $widget->tone === 'warn',
                        ])>
                            {{ $widget->value }}
                        </p>

                        @if ($widget->hint)
                            <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ $widget->hint }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        {{--
            একটাও উইজেট নেই।

            হয় ব্যবহারকারীর কোনো মডিউলে ঢোকার অনুমতি নেই, নয় কোনো মডিউল
            এখনো সংখ্যা দেয় না। খালি পর্দা রেখে দিলে মানুষ ভাবত কিছু
            ভেঙেছে, তাই কারণটা লেখা থাকে।
        --}}
        <x-ui.empty-state :message="__('core.dashboard.nothing_to_show')" />
    @endforelse
</x-layouts.app>
