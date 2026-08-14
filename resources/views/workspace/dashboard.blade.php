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

        /*
         * "কিছু বাকি আছে" মানে সংখ্যাটা শূন্যের বেশি।
         *
         * মানটা সাজানো লেখা ("০", "1,240", "0 / 1"), তাই অঙ্কগুলো বের
         * করে দেখা হয়। বাংলা অঙ্কও ধরা পড়ে, কারণ পর্দার ভাষা বাংলা
         * হলে সংখ্যাগুলোও বাংলায় আসে।
         */
        $pending = function ($widget) {
            $latin = strtr($widget->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
                '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

            return (int) preg_replace('/\D/', '', $latin) > 0;
        };

        /*
         * একটাও সংখ্যা নেই কি না।
         *
         * উপরে হিসাব করা হয়, নিচে `@php(...)` দিয়ে নয় — ইনলাইন রূপটা
         * এই সংস্করণে `<?php(` বানিয়ে দেয়, আর তারপর থেকে ফাইলের
         * বাকিটা আর কম্পাইলই হয় না। ভুলটা তখন অনেক নিচে গিয়ে দেখা
         * দেয় ("unexpected endforeach"), তাই খুঁজে পেতে সময় লাগে।
         */
        $nothing = $groups === [] || array_filter($groups) === [];
    @endphp

    {{-- ── আজ ও এই মাস ─────────────────────────────────────────────
         সংখ্যার কার্ড, কিন্তু সব সমান নয়: "আজ"-এর প্রথম টাকার
         সংখ্যাটা প্রধান কার্ড, দুই কলাম জুড়ে, গাঢ় জমিনে।

         কেন: বিশটা একরকম বাক্সে চোখের কোনো শুরু নেই। মালিক পর্দায়
         এসে প্রথম যে প্রশ্নটা করেন সেটা "টাকা কত" — ওটার উত্তর বড় ও
         আলাদা দেখালে বাকিগুলো আর প্রতিযোগিতা করে না।            --}}
    @foreach (['today', 'month'] as $group)
        @if (! empty($groups[$group]))
        @php
            $widgets = $groups[$group];

            /*
             * প্রধান কার্ড — "আজ"-এর সবচেয়ে বড় টাকার সংখ্যাটা।
             *
             * ── কেন প্রথমটা নয় ─────────────────────────────────────
             * প্রথমে "প্রথম টাকার উইজেট" নেওয়া হয়েছিল, আর সাধারণ
             * সকালে সেটা "আজকের বিক্রয় ০.০০" — গোটা সারি জুড়ে একটা
             * বিশাল শূন্য। প্রধান কার্ডের কাজ চোখকে শুরুর জায়গা দেওয়া,
             * আর শূন্য কোনো শুরু নয়।
             *
             * ── সব শূন্য হলে প্রধান কার্ড নেই ───────────────────────
             * তখন সবগুলো সমান কার্ডই থাকে। দিনের শুরুতে কিছুই ঘটেনি —
             * পর্দাটা সেটাই বলুক, একটা বড় শূন্য দিয়ে নয়।
             */
            $leadIndex = false;

            if ($group === 'today') {
                $biggest = collect($widgets)
                    ->filter(fn ($w) => $w->tone === 'money' && $pending($w))
                    ->sortByDesc(fn ($w) => (float) preg_replace('/[^0-9.]/', '',
                        strtr($w->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
                            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])));

                $leadIndex = $biggest->isEmpty() ? false : $biggest->keys()->first();
            }
        @endphp

        <section class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ $titles[$group] }}</h2>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($widgets as $i => $widget)
                    @php
                        $lead = $i === $leadIndex;

                        /*
                         * গ্রেডিয়েন্টটা এখানে হিসাব হয়, ট্যাগের ভেতরে
                         * শর্ত বসিয়ে নয় — মান হিসেবে রাখলে ট্যাগটা
                         * সাধারণ HTML-ই থাকে।
                         *
                         * সাবধান: এই মন্তব্যে ডিরেক্টিভের নাম লেখা যায়
                         * না। Blade টেমপ্লেটের কাঁচা লেখার উপর দিয়েই
                         * ডিরেক্টিভ খোঁজে, তাই PHP-র মন্তব্যের ভেতরে
                         * লেখা নামও সে সত্যিকারের ডিরেক্টিভ ধরে নেয় —
                         * আর তখন শর্তটা কোথাও বন্ধ হয় না।
                         */
                        $leadStyle = $lead
                            ? 'background: linear-gradient(135deg,'
                                .' var(--color-brand-700), var(--color-brand-900))'
                            : null;
                    @endphp

                    {{-- পুরো টাইলটাই লিংক, শুধু সংখ্যাটা নয় — আঙুলে ছোট
                         লক্ষ্যবস্তু ধরা কঠিন, আর ফোনেই এটা বেশি দেখা হয় --}}
                    <a href="{{ $widget->href }}"
                       @class([
                           'block rounded-(--radius-card) p-4 transition-shadow',
                           'sm:col-span-2 border-transparent text-(--color-ink-inverse) shadow-lg
                            hover:shadow-xl' => $lead,
                           'border border-(--color-border) bg-(--color-surface-card)
                            shadow-(--shadow-card) hover:bg-(--color-surface-hover)' => ! $lead,
                       ])
                       @style([$leadStyle])>

                        <p @class([
                            'text-sm',
                            'text-white/70' => $lead,
                            'text-(--color-ink-muted)' => ! $lead,
                        ])>{{ $widget->label }}</p>

                        <p @class([
                            'tabular mt-1 font-semibold',
                            'text-4xl' => $lead,
                            'text-2xl' => ! $lead,
                            'text-(--color-ink)' => ! $lead && $widget->tone === 'neutral',
                            'text-(--color-brand-600)' => ! $lead && $widget->tone === 'money',
                            'text-(--color-badge-success-ink)' => ! $lead && $widget->tone === 'good',
                            'text-(--color-badge-warning-ink)' => ! $lead && $widget->tone === 'warn',
                        ])>
                            {{ $widget->value }}
                        </p>

                        {{-- তুলনা ও ইঙ্গিত এক সারিতে: "↑ ১২.৪%" নিজে
                             কিছু বলে না, "কিসের তুলনায়" পাশে থাকলেই
                             সংখ্যাটা পড়া যায়। --}}
                        @if ($widget->delta || $widget->hint)
                            <p @class([
                                'mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs',
                                'text-white/60' => $lead,
                                'text-(--color-ink-muted)' => ! $lead,
                            ])>
                                @if ($widget->delta)
                                    {{-- ব্লক রূপেই, ইনলাইনে নয় — ইনলাইনটা
                                         এই সংস্করণে ভাঙা (উপরের নোট) --}}
                                    @php
                                        $up = ! str_starts_with(trim($widget->delta), '-');
                                    @endphp

                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold',
                                        'bg-white/15 text-white' => $lead,
                                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                                            => ! $lead && $up,
                                        'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)'
                                            => ! $lead && ! $up,
                                    ])>
                                        <x-ui.icon :name="$up ? 'arrow_up' : 'arrow_down'" :size="11" />
                                        {{ ltrim($widget->delta, '+-') }}
                                    </span>
                                @endif

                                @if ($widget->hint)
                                    <span>{{ $widget->hint }}</span>
                                @endif
                            </p>
                        @endif

                        {{-- ভাগটা কেবল প্রধান কার্ডে — ছোট কার্ডে তিনটা
                             ঘর পাশাপাশি বসলে কোনোটাই পড়া যায় না। --}}
                        @if ($lead && $widget->parts !== [])
                            <div class="mt-4 flex flex-wrap gap-x-8 gap-y-3 border-t border-white/15 pt-3">
                                @foreach ($widget->parts as $partLabel => $partValue)
                                    <div>
                                        <p class="text-2xs text-white/60">{{ $partLabel }}</p>
                                        <p class="tabular mt-0.5 font-semibold">{{ $partValue }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
        @endif
    @endforeach

    {{-- ── যা করা বাকি ───────────────────────────────────────────────
         কার্ডের ছক নয়, সারির তালিকা — আর শূন্যগুলো এক লাইনে গুটানো।

         ── কেন ────────────────────────────────────────────────────
         এই দলে বিশটার মতো সংখ্যা থাকে, আর সাধারণ দিনে তার চোদ্দটাই
         শূন্য। বিশটা সমান কার্ডে সেগুলো পর্দার দুই-তৃতীয়াংশ খেয়ে
         নিত, আর যে চারটায় সত্যিই কিছু বাকি সেগুলো ওই ভিড়ে হারিয়ে
         যেত। যা করার নেই তা দেখানোর দরকার নেই; কিন্তু "দেখা হয়েছে,
         কিছু নেই" কথাটার দরকার আছে — নাহলে মানুষ ভাবে সংখ্যাটা
         আসেইনি।                                                   --}}
    @if (! empty($groups['todo']))
        @php
            $todo = collect($groups['todo']);
            $waiting = $todo->filter($pending);
            $quiet = $todo->reject($pending);
        @endphp

        <section class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ $titles['todo'] }}</h2>

            <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) shadow-(--shadow-card)">
                @foreach ($waiting as $widget)
                    <a href="{{ $widget->href }}"
                       class="flex items-center gap-3 border-b border-(--color-border) px-4 py-3
                              transition-colors last:border-b-0 hover:bg-(--color-surface-hover)">
                        {{-- আইকনটা সারির চরিত্রের রঙে — বিশটা সারি একই
                             রকম দেখালে কোনটা টাকার আর কোনটা মালের তা
                             পড়ে বের করতে হয়। আইকন না দিলে ঘরটাই থাকে না। --}}
                        @if ($widget->icon)
                            <span @class([
                                'grid size-8 shrink-0 place-items-center rounded-(--radius-field)',
                                'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
                                    => $widget->tone === 'warn',
                                'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                                    => $widget->tone === 'good',
                                'bg-(--color-surface-app) text-(--color-brand-600)'
                                    => ! in_array($widget->tone, ['warn', 'good'], true),
                            ])>
                                <x-ui.icon :name="$widget->icon" :size="16" />
                            </span>
                        @endif

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ $widget->label }}</span>
                            @if ($widget->hint)
                                <span class="block truncate text-2xs text-(--color-ink-muted)">
                                    {{ $widget->hint }}
                                </span>
                            @endif
                        </span>

                        <span @class([
                            'tabular text-lg font-semibold',
                            'text-(--color-badge-warning-ink)' => $widget->tone === 'warn',
                            'text-(--color-ink)' => $widget->tone !== 'warn',
                        ])>{{ $widget->value }}</span>

                        <x-ui.icon name="chevron_right" :size="16"
                                   class="text-(--color-ink-disabled) rtl:rotate-180" />
                    </a>
                @endforeach

                @if ($quiet->isNotEmpty())
                    <p class="flex items-center gap-2 px-4 py-3 text-sm text-(--color-ink-muted)">
                        <x-ui.icon name="check_circle" :size="16" class="text-(--color-success)" />
                        {{ trans_choice('core.dashboard.nothing_pending', $quiet->count(),
                            ['count' => $quiet->count()]) }}
                    </p>
                @endif
            </div>
        </section>
    @endif

    @if ($nothing)
        {{--
            একটাও উইজেট নেই।

            হয় ব্যবহারকারীর কোনো মডিউলে ঢোকার অনুমতি নেই, নয় কোনো মডিউল
            এখনো সংখ্যা দেয় না। খালি পর্দা রেখে দিলে মানুষ ভাবত কিছু
            ভেঙেছে, তাই কারণটা লেখা থাকে।
        --}}
        <x-ui.empty-state :message="__('core.dashboard.nothing_to_show')" />
    @endif
</x-layouts.app>
