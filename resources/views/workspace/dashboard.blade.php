{{--
    হোম পর্দা — মালিকের প্রশ্নগুলো, তিন দলে।

    আজ কী হলো · মাসটা কেমন যাচ্ছে · কী করা বাকি।

    প্রতিটা টাইল একটা লিংক, ব্যতিক্রম ছাড়া (নিয়ম ১)। যে সংখ্যা ক্লিক করা
    যায় না সেটা ব্যবহারকারীকে বিশ্বাস করতে বাধ্য করে, যাচাই করতে দেয় না —
    আর ভুল হলে কেউ ধরতে পারে না।

    কোন সংখ্যাগুলো আসবে তা এই ফাইল জানে না; মডিউলরা নিজেরা দেয়।
--}}
@php
        /*
         * কোন কালপর্বটা দেখা হচ্ছে।
         *
         * ── কেন ঠিকানায়, Alpine-এ নয় ────────────────────────────────
         * তিনটা দলই সার্ভারে হিসাব হয়ে যায়, তাই লুকিয়ে-দেখিয়ে করা
         * যেত। কিন্তু তখন "এই বছরের পর্দাটা দেখো" বলে কাউকে লিংক
         * পাঠানো যেত না, আর পাতা রিফ্রেশ করলেই আজকের পর্দায় ফিরে
         * আসত — যিনি বছরের সংখ্যা নিয়ে কাজ করছেন তাঁর জন্য সেটা
         * প্রতিবার একটা বাড়তি ক্লিক।
         */
        $period = in_array(request('period'), \App\Core\Dashboard\Widget::PERIODS, true)
            ? request('period')
            : 'today';

        $titles = [
            'today' => __('core.dashboard.today'),
            'month' => __('core.dashboard.this_month'),
            'year' => __('core.dashboard.this_year'),
            'todo' => __('core.dashboard.needs_doing'),
        ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.menu.dashboard') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('core.menu.dashboard')"
            :subtitle="auth()->user()?->currentCompany?->name()
                . (auth()->user()?->currentBranch ? ' · ' . auth()->user()->currentBranch->name() : '')">

            {{-- `actions` স্লটেই — পাতার শিরোনামের ডান পাশে ঠিক ওখানেই
                 প্রতিটা পর্দার নিয়ন্ত্রণগুলো বসে, আর নমুনাতেও তাব তিনটা
                 ওখানেই। কম্পোনেন্টটার নামহীন স্লট নেই, তাই ওটা ব্যবহার
                 করতে গিয়ে তাবগুলো নীরবে হারিয়ে গিয়েছিল। --}}
            <x-slot:actions>
            {{--
                আজ · এই মাস · এই বছর।

                ── কেন একটা সময়েই একটা ─────────────────────────────────
                আগে আজ ও এই মাস দুইটাই একসাথে দেখানো হত, একটার নিচে
                আরেকটা। তাতে পর্দার উপরের অর্ধেকটা আটটা কার্ডে ভরে
                যেত, আর কোন সংখ্যাটা কোন সময়ের তা প্রতিবার শিরোনাম
                পড়ে বুঝতে হত। একটা সময়ে একটাই — আর কোনটা, সেটা
                মালিকের হাতে।
            --}}
            {{-- ⭐ নতুন ড্যাশবোর্ডে সময় একটা ছোট ঘরে — "এ মাস ▾" (মালিকের অনুমোদিত নকশা, ২ অক্টোবর ২০২৬:
                 জায়গা বাঁচে, ফোনেও এক লাইনে)। ⓘ `<details>` — জাভাস্ক্রিপ্ট ছাড়াই খোলে, শেলের বাকি মেনুর মতো।
                 সুইচ বন্ধ থাকলে নিচের পুরনো তাবগুলোই। --}}
            @if (config('abos.dashboards_v2'))
            {{-- ⭐ "লেআউট সাজান" — কোন অংশ দেখা যাবে আর কোনটা আগে (মালিক, ৪ অক্টোবর ২০২৬); কেবল নিজের জন্য।
                 ⓘ `<details>` — সময়ের ঘরের মতোই জাভাস্ক্রিপ্ট ছাড়া খোলে। --}}
            <details data-layout-menu class="relative">
                <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
                                border border-(--color-border) bg-(--color-surface-card) px-3 text-sm text-(--color-ink)">
                    <x-ui.icon name="settings" :size="14" class="text-(--color-ink-muted)" />
                    <span class="font-semibold">{{ __('home.layout') }}</span>
                </summary>
                <form method="POST" action="{{ route('home.layout') }}"
                      class="pops-onto-page absolute end-0 top-full z-50 mt-1 w-72 space-y-2 rounded-(--radius-field)
                             border border-(--color-border) bg-(--color-surface-card) p-3 shadow-lg">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('home.layout_hint') }}</p>
                    @foreach (\App\Core\Dashboard\HomeLayout::PARTS as $part)
                        @php $unit = in_array($part, ['exceptions', 'happenings'], true) ? 'work' : $part; @endphp
                        <div class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="show[{{ $part }}]" value="1" @checked($layout->shows($part)) class="size-4">
                            <span class="min-w-0 flex-1 truncate">{{ __('home.layout_part_'.$part) }}</span>
                            @if ($part !== 'happenings')
                                <input type="number" name="position[{{ $unit }}]" min="1" max="4" value="{{ $layout->position($unit) }}"
                                       aria-label="{{ __('home.layout_position') }}"
                                       class="num h-8 w-14 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
                            @else
                                <span class="w-14 text-center text-2xs text-(--color-ink-muted)">↑</span>
                            @endif
                        </div>
                    @endforeach
                    <div class="flex justify-between gap-2 pt-1">
                        <button type="submit" name="reset" value="1"
                                class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">{{ __('home.layout_reset') }}</button>
                        <button type="submit"
                                class="rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold text-white">{{ __('home.layout_save') }}</button>
                    </div>
                </form>
            </details>
                <details data-period-menu class="relative">
                    <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
                                    border border-(--color-border) bg-(--color-surface-card) px-3 text-sm text-(--color-ink)">
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('home.period') }}</span>
                        <span class="font-semibold">{{ $titles[$period] }}</span>
                        <x-ui.icon name="chevron_down" :size="14" class="text-(--color-ink-muted)" />
                    </summary>
                    <div class="pops-onto-page absolute end-0 top-full z-50 mt-1 w-48 rounded-(--radius-field)
                                border border-(--color-border) bg-(--color-surface-card) p-1 shadow-lg">
                        @foreach (\App\Core\Dashboard\Widget::PERIODS as $option)
                            <a href="{{ route('dashboard', ['period' => $option]) }}"
                               @class([
                                   'block rounded-(--radius-field) px-3 py-2 text-sm',
                                   'bg-(--color-brand-50) font-semibold text-(--color-brand-700)' => $period === $option,
                                   'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => $period !== $option,
                               ])
                               @if ($period === $option) aria-current="page" @endif>{{ $titles[$option] }}</a>
                        @endforeach
                    </div>
                </details>
            @else
            <div class="flex rounded-(--radius-field) border border-(--color-border)
                        bg-(--color-surface-card) p-0.5 text-sm">
                @foreach (\App\Core\Dashboard\Widget::PERIODS as $option)
                    <a href="{{ route('dashboard', ['period' => $option]) }}"
                       @class([
                           'rounded-(--radius-field) px-3 py-1 transition-colors',
                           'bg-(--color-brand-600) font-medium text-white' => $period === $option,
                           'text-(--color-ink-muted) hover:bg-(--color-surface-hover)'
                               => $period !== $option,
                       ])
                       @if ($period === $option) aria-current="page" @endif>
                        {{ $titles[$option] }}
                    </a>
                @endforeach
            </div>
            @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @php


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

        /*
         * ⭐ টাকার অবস্থানের কার্ড — কমান্ড সেন্টারের মাথায়, ডান পাশে।
         * মালিকের নকশা, ১ অক্টোবর ২০২৬।
         *
         * ⓘ ভাগওয়ালা টাকার কার্ডটাই অবস্থান (হাতে নগদ · MFS · ব্যাংক ·
         * পথে)। অবস্থান কোনো কালপর্বের নয় — "এই মাসে হাতে কত" বলে কিছু
         * নেই — তাই আজ/মাস/বছর যেটাই বাছা হোক, কার্ডটা একই জায়গায়
         * থাকে, আর নিচের সারি থেকে বাদ যায় যাতে একই সংখ্যা দুইবার না আসে।
         *
         * ⚠️ হিসাব মডিউল বন্ধ বা অনুমতি না থাকলে কার্ডটা আসেই না, আর
         * মাথার সারিতে তখন শুধু শিরোনাম থাকে।
         */
        $position = null;

        foreach (\App\Core\Dashboard\Widget::PERIODS as $positionGroup) {
            foreach ($groups[$positionGroup] ?? [] as $candidate) {
                if ($candidate->tone === 'money' && $candidate->parts !== []) {
                    $position = $candidate;

                    break 2;
                }
            }
        }
    @endphp

    {{-- ── কমান্ড সেন্টারের মাথা ─────────────────────────────────────
         বাঁয়ে শিরোনাম, ডানে টাকার অবস্থান — মালিকের নকশা, ১ অক্টোবর ২০২৬। --}}
    <section data-command-head class="mb-6 flex flex-col gap-4 lg:flex-row" style="justify-content: space-between">
        <div class="flex min-w-0 flex-col justify-center gap-1">
            <h2 class="text-2xl font-semibold text-(--color-ink)">{{ __('home.command_center') }}</h2>
            <p class="text-sm text-(--color-ink-body)">{{ __('home.command_center_flow') }}</p>
        </div>

        @if ($position)
            <a href="{{ $position->href }}" data-money-position
               class="block w-full shrink-0 rounded-(--radius-card) p-5 text-(--color-ink-inverse) shadow-lg
                      transition-shadow hover:shadow-xl"
               style="background: linear-gradient(135deg, var(--color-brand-700), var(--color-brand-900)); max-width: 40rem"
               @if ($position->definition) title="{{ $position->definition }}" @endif>
                <p class="flex items-center gap-1.5 text-sm text-white/70">
                    @if ($position->icon)
                        <x-ui.icon :name="$position->icon" :size="15" />
                    @endif
                    {{ $position->label }}
                </p>

                <p class="tabular mt-1 text-4xl font-semibold">{{ $position->value }}</p>

                <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 border-t border-white/15 pt-3 sm:grid-cols-4">
                    @foreach ($position->parts as $partLabel => $partValue)
                        <div class="min-w-0">
                            <p class="truncate text-2xs text-white/60">{{ $partLabel }}</p>
                            <p class="tabular mt-0.5 font-semibold">{{ $partValue }}</p>
                        </div>
                    @endforeach
                </div>
            </a>
        @endif
    </section>

    {{-- ── গোটা ব্যবসা এক সারিতে ───────────────────────────────────
         প্রতিটা মডিউলের মাথার সংখ্যাটা, পাশাপাশি। মালিকের নির্দেশ,
         ২ সেপ্টেম্বর ২০২৬।

         ── কেন এটা মডিউলের ড্যাশবোর্ডের নকল নয় ─────────────────────
         মডিউলের পর্দা গভীর: এক বিষয়ের ছয়টা সংখ্যা, চার্ট, তালিকা।
         এটা চওড়া: বারো বিষয়ের একটা করে সংখ্যা, আর কোনটায় নামতে হবে
         সেই সিদ্ধান্ত। দুইটা আলাদা প্রশ্ন, তাই দুইটা পর্দা।     --}}

    {{-- ── বাছা কালপর্বটা ───────────────────────────────────────────
         সংখ্যার কার্ড, কিন্তু সব সমান নয়: "আজ"-এর প্রথম টাকার
         সংখ্যাটা প্রধান কার্ড, দুই কলাম জুড়ে, গাঢ় জমিনে।

         কেন: বিশটা একরকম বাক্সে চোখের কোনো শুরু নেই। মালিক পর্দায়
         এসে প্রথম যে প্রশ্নটা করেন সেটা "টাকা কত" — ওটার উত্তর বড় ও
         আলাদা দেখালে বাকিগুলো আর প্রতিযোগিতা করে না।            --}}
    {{-- একটা সময়ে একটাই দল — উপরের তাব যেটা বলে --}}
    {{-- ⭐ সাজানো যায় এমন ভাগগুলো এক খাড়া সারিতে — ক্রম CSS `order` দিয়ে, লুকানো অংশ আঁকাই হয় না ([[HomeLayout]]) --}}
    <div data-home-units style="display: flex; flex-direction: column">
    <div data-home-unit="period" style="order: {{ $layout->position('period') }}">
    @foreach ([$period] as $group)
        @if (! empty($groups[$group]) && $layout->shows('period'))
        @php
            /*
             * ⭐ টাকার অবস্থান এখন মাথার সারিতে (উপরে) — এখানে আর নয়,
             * আর প্রধান কার্ডও নেই: সব কার্ড সমান, চারটা করে এক লাইনে।
             * মালিকের নকশা, ১ অক্টোবর ২০২৬। আগের "সবচেয়ে বড় টাকার কার্ড" বাছাইয়ের নিয়মটা
             * বাদ গেছে; `$leadIndex` এখন সবসময় false।
             */
            $widgets = array_values(array_filter($groups[$group], fn ($w) => $w !== $position));
            $leadIndex = false;
        @endphp

        <section data-period-cards class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ $titles[$group] }}</h2>

            {{--
                হিরো সারিটা এক লাইনে: বড় কার্ড + দুইটা ছোট।

                ── কেন চারের ছক, দুইয়ের নয় ─────────────────────────────
                প্রধান কার্ড দুই ঘর নেয়, ছোটগুলো এক করে — তাই চওড়া
                পর্দায় তিনটাই এক সারিতে বসে, ঠিক নমুনার মতো। আগের
                দুই-ঘরের ছকে প্রধান কার্ডটা গোটা সারি খেয়ে নিত আর
                বাকিগুলো নিচে নেমে যেত, ফলে "টাকা কত · আজ কত বেচলাম ·
                আজ কত আদায়" — তিনটা একসাথে দেখা যেত না।
            --}}
            {{-- ⭐ এক লাইনে — বড় কার্ড দুই ঘর + চারটা ছোট = ছয় (মালিক, ২৯ সেপ্টেম্বর ২০২৬) --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                       @style([$leadStyle])
                       {{-- সংজ্ঞাটা টুলটিপে, কার্ডের ভেতরে নয়।

                            ── কেন সরানো হলো ─────────────────────────
                            "গোনা হয়: নিশ্চিত, সম্পন্ন কাগজ · তারিখ:
                            লেনদেনের তারিখ · দশমিক ২ ঘর · রাউন্ডিং শেষে
                            একবার" — বাক্যটা সত্যি ও দরকারি, কিন্তু
                            কার্ডের ভেতরে বসালে সেটা সংখ্যাটার চেয়ে
                            বেশি জায়গা নেয়, আর চারটা কার্ড পাশাপাশি
                            বসলে পর্দাটা লেখায় ভরে যায়।

                            হারিয়ে যায় না: মাউস রাখলেই পুরোটা পড়া যায়,
                            আর HTML-এ থেকেই যায় বলে "প্রতিটা সংখ্যা
                            নিজের সংজ্ঞা বলে" নিয়মটাও ভাঙে না। --}}
                       @if ($widget->definition) title="{{ $widget->definition }}" @endif>

                        {{-- লেবেলের আগে আইকন — নমুনার মতো।

                             চারটা কার্ড পাশাপাশি বসলে লেখাগুলো একই
                             রকম দেখায়; আইকনটাই দূর থেকে বলে দেয়
                             কোনটা টাকার আর কোনটা বিক্রয়ের। --}}
                        <p @class([
                            'flex items-center gap-1.5 text-sm',
                            'text-white/70' => $lead,
                            'text-(--color-ink-muted)' => ! $lead,
                        ])>
                            @if ($widget->icon)
                                <x-ui.icon :name="$widget->icon" :size="15" />
                            @endif
                            {{ $widget->label }}
                        </p>

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

                        {{-- কেবল তুলনার চিপটা — সংজ্ঞাটা এখন টুলটিপে।

                             "↑ ১২.৪%" নিজে কিছু বলে না; "কিসের তুলনায়"
                             কথাটা মডিউল চাইলে `delta`-র পাশে পাঠাতে
                             পারে, আর তখন সেটাও এখানেই বসে। --}}
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

                        {{--
                            শেষ সাত দিনের রেখা।

                            ── কেন সংখ্যাটার পাশে একটা রেখা ─────────────
                            "আজ ৪,০৫০" একটা বিন্দু, আর একটা বিন্দু দিয়ে
                            কোনো দিক বোঝা যায় না। রেখাটা বলে দেয় আজকের
                            লাফটা অস্বাভাবিক নাকি রোজকার — আর সেটা কোনো
                            সংখ্যাতেই থাকে না।

                            ── কেন সমতল রেখা আঁকা হয় না ─────────────────
                            সাত দিনই সমান (বা সবই শূন্য) হলে রেখাটা
                            মাঝ বরাবর একটা সরলরেখা হত, আর সেটা দেখতে
                            "স্থির ব্যবসা"-র মতো — অথচ সত্যিটা "কিছুই
                            ঘটেনি"। তাই তখন কিছুই আঁকা হয় না।
                        --}}
                        @php
                            $spark = array_map('floatval', $widget->spark);
                            $peak = $spark === [] ? 0.0 : max($spark);
                            $floor = $spark === [] ? 0.0 : min($spark);
                        @endphp

                        @if (count($spark) > 1 && $peak > $floor)
                            @php
                                /*
                                 * বিন্দুগুলো ১০০×৩২-এর ছকে।
                                 *
                                 * `viewBox` ধরে আঁকা হয় আর `preserveAspectRatio="none"`
                                 * দিয়ে টেনে বসানো হয়, তাই কার্ড যত চওড়াই
                                 * হোক রেখাটা পুরোটা জুড়ে থাকে — প্রতিটা
                                 * কার্ডের জন্য আলাদা মাপ হিসাব করতে হয় না।
                                 */
                                $span = $peak - $floor;
                                $step = 100 / (count($spark) - 1);

                                $points = [];

                                foreach ($spark as $i => $value) {
                                    $points[] = round($i * $step, 2).','
                                        .round(30 - (($value - $floor) / $span) * 28, 2);
                                }
                            @endphp

                            <svg viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"
                                 @class([
                                     'mt-3 h-8 w-full',
                                     'text-white/50' => $lead,
                                     'text-(--color-ink-disabled)' => ! $lead,
                                 ])>
                                <polyline points="{{ implode(' ', $points) }}"
                                          fill="none" stroke="currentColor" stroke-width="1.5"
                                          vector-effect="non-scaling-stroke"
                                          stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
        @endif
    @endforeach
    </div>

    {{-- ⭐ "সোজা কথা" (প্রতিটা মডিউলের মাথার সংখ্যা) এখন "আজ"-এর নিচে — মালিকের নির্দেশ,
         ২৯ সেপ্টেম্বর ২০২৬: "আজ"-এর সারিটা এক লাইনে, সবার উপরে। --}}
    <div data-home-unit="overall" style="order: {{ $layout->position('overall') }}">
    @if (! empty($overall) && $layout->shows('overall'))
        <div class="mb-4">
            <h2 class="mb-2 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('core.dashboard.across_the_business') }}
            </h2>
            {{-- ⭐ দুই লাইনে — বড় পর্দায় আটটা করে (মালিক, ২৯ সেপ্টেম্বর ২০২৬: "১৫টা বক্স ২ লাইনে") --}}
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                @foreach ($overall as $row)
                    <a href="{{ route('module.dashboard', ['module' => $row['module']]) }}"
                       data-boxed
                       class="block min-w-0 rounded-(--radius-card) border border-(--color-border)
                              bg-(--color-surface-card) px-3 py-2">
                        <div class="truncate text-2xs text-(--color-ink-muted)">{{ $row['name'] }}</div>
                        <div class="mt-0.5 truncate text-xs text-(--color-ink-muted)">{{ $row['stat']->label }}</div>
                        {{-- ⓘ মান কাটা নয়, ভাঙে — নকশার পর্যালোচনা, ১ অক্টোবর ২০২৬ (ধাপ ৭ · ১): আটটা সরু ঘরে
                             "২৬ দিন আগে" কেটে "২৬ দিন…" হত, আর কেউ বুঝত না কত দিন কী। সংখ্যা ছোট, লেখা দুই লাইনে। --}}
                        <div @class([
                            'mt-1 break-words text-lg font-semibold leading-tight tabular-nums',
                            'text-(--color-badge-warning-ink)' => $row['stat']->tone === \App\Core\Engines\Dashboard\Stat::WARN,
                            'text-(--color-badge-danger-ink)' => $row['stat']->tone === \App\Core\Engines\Dashboard\Stat::BAD,
                        ])>{{ $row['stat']->value ?? '—' }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
    </div>

    {{-- ⭐ ব্যবসার চিত্র — প্রতিটা মডিউলের প্রধান চার্ট, তিনটা করে এক সারিতে (মালিকের নকশা, ১ অক্টোবর ২০২৬)।
         ⓘ চার্টটা মডিউলের নিজের ড্যাশবোর্ডের প্রথমটা — হোমে আলাদা কোনো হিসাব নেই, তাই দুই পর্দা কখনো দুই কথা বলে না।
         ⓘ পুরো কার্ডটাই মডিউলের ড্যাশবোর্ডের দরজা: চার্ট থেকে বিস্তারিত, সেখান থেকে লেনদেন। --}}
    @php
        $pictures = array_values(array_filter($overall ?? [], fn (array $row) => ($row['panel'] ?? null) !== null));

        /* ⓘ নতুন হোম: মডিউলের প্রতিটা চার্ট নিজের কার্ডে (বিক্রির বারো মাস, মজুদের ধারা…) — সুইচ বন্ধে আগের মতো প্রথমটাই */
        if (config('abos.dashboards_v2')) {
            $pictures = array_merge([], ...array_map(
                fn (array $row) => array_map(fn ($panel) => ['panel' => $panel] + $row, $row['panels'] ?? []),
                $overall ?? [],
            ));
        }
    @endphp

    <div data-home-unit="pictures" style="order: {{ $layout->position('pictures') }}">
    @if ($pictures !== [] && $layout->shows('pictures'))
        <section data-business-pictures class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('home.business_pictures') }}</h2>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($pictures as $row)
                    @php $panel = $row['panel']; @endphp

                    <a href="{{ route('module.dashboard', ['module' => $row['module']]) }}" data-boxed
                       class="block min-w-0 rounded-(--radius-card) border border-(--color-border)
                              bg-(--color-surface-card) shadow-(--shadow-card) transition-colors
                              hover:bg-(--color-surface-hover)">
                        <div class="flex items-baseline justify-between gap-3 border-b border-(--color-border) px-4 py-3">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-(--color-ink)">{{ $row['name'] }}</span>
                                <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $panel->label }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-(--color-link)">{{ __('home.details') }} →</span>
                        </div>

                        @if ($panel instanceof \App\Core\Engines\Dashboard\Series)
                            @php $peak = $panel->peak(); @endphp

                            <div class="flex items-end gap-2 px-4 pt-4" style="height: 9rem">
                                @foreach ($panel->points as $point)
                                    <div class="flex h-full flex-1 items-end justify-center gap-0.5">
                                        @foreach ([['first', 'bg-(--color-brand-500)', $panel->firstLabel], ['second', 'bg-(--color-brand-700)/25', $panel->secondLabel]] as [$side, $fill, $name])
                                            <div class="w-1/2 rounded-t {{ $fill }}"
                                                 style="height:{{ max(2, (int) round((float) $point[$side] / $peak * 100)) }}%"
                                                 title="{{ $point['label'] }} · {{ $name }}: {{ $point[$side.'Title'] ?? $point[$side] }}"></div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex gap-2 px-4 pt-1">
                                @foreach ($panel->points as $point)
                                    <div class="min-w-0 flex-1 truncate text-center text-2xs text-(--color-ink-muted)">{{ $point['label'] }}</div>
                                @endforeach
                            </div>

                            <div class="flex items-center gap-4 px-4 py-2 text-2xs text-(--color-ink-muted)">
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-500)"></span>{{ $panel->firstLabel }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-700)/25"></span>{{ $panel->secondLabel }}
                                </span>
                            </div>
                        @else
                            @php
                                /* ⚠️ মানটা প্রায়ই সাজানো টাকা ("1,234.00", বাংলা অঙ্ক) — (float) কমায় থেমে যেত আর দণ্ড ভুল মাপের হত */
                                $num = fn ($v) => (float) str_replace(',', '', strtr((string) $v, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
                                $total = max(1.0, array_sum(array_map(fn ($part) => $num($part['value']), $panel->parts)));
                            @endphp

                            <div class="space-y-3 px-4 py-3">
                                @foreach (array_slice($panel->parts, 0, 5) as $part)
                                    <div>
                                        <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                                            <span class="min-w-0 truncate text-(--color-ink-muted)">{{ $part['label'] }}</span>
                                            <span class="shrink-0 font-semibold tabular-nums">{{ $part['value'] }}</span>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-(--color-surface-hover)">
                                            <div class="h-full bg-(--color-brand-500)"
                                                 style="width:{{ min(100, max(0, (int) round($num($part['value']) / $total * 100))) }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
    </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
    {{-- ── যা করা বাকি ───────────────────────────────────────────────
         কার্ডের ছক নয়, সারির তালিকা — আর শূন্যগুলো এক লাইনে গুটানো।

         ── কেন ────────────────────────────────────────────────────
         এই দলে বিশটার মতো সংখ্যা থাকে, আর সাধারণ দিনে তার চোদ্দটাই
         শূন্য। বিশটা সমান কার্ডে সেগুলো পর্দার দুই-তৃতীয়াংশ খেয়ে
         নিত, আর যে চারটায় সত্যিই কিছু বাকি সেগুলো ওই ভিড়ে হারিয়ে
         যেত। যা করার নেই তা দেখানোর দরকার নেই; কিন্তু "দেখা হয়েছে,
         কিছু নেই" কথাটার দরকার আছে — নাহলে মানুষ ভাবে সংখ্যাটা
         আসেইনি।                                                   --}}
    {{--
        করণীয় ও সদ্য-যা-হয়েছে — পাশাপাশি।

        ── কেন একই সারিতে ─────────────────────────────────────────────
        দুইটা একই প্রশ্নের দুই দিক: কী আটকে আছে, আর কী হয়ে গেছে। উপর-
        নিচে বসালে দ্বিতীয়টা পাতার ভাঁজের নিচে চলে যেত, আর দিনের শুরুতে
        মালিকের প্রথম প্রশ্নটাই ("আমি না থাকতে কী কী হলো") স্ক্রল না
        করলে দেখা যেত না।

        সরু পর্দায় একটার নিচে আরেকটা — পাশাপাশি রাখলে দুইটাই এত সরু হত
        যে প্রতিটা সারির লেখা দুই লাইনে ভেঙে যেত।
    --}}
    <div data-home-unit="work" style="order: {{ $layout->position('work') }}">
    <div class="mb-6 grid gap-4 lg:grid-cols-2">

    @if (! empty($groups['todo']) && $layout->shows('exceptions'))
        @php
            $todo = collect($groups['todo']);
            $waiting = $todo->filter($pending);
            $quiet = $todo->reject($pending);
        @endphp

        {{-- `min-w-0` — নাহলে grid-এর ঘরটা নিজের লেখার চেয়ে ছোট হতে পারে না।

             ── কী ভাঙা ছিল ─────────────────────────────────────────────
             grid ও flex দুইটাতেই ঘরের ন্যূনতম প্রস্থ ডিফল্টে `auto`,
             অর্থাৎ **ভিতরের সবচেয়ে লম্বা লেখাটাই মেঝে**। ভিতরের
             লাইনগুলোয় `truncate` আছে, কিন্তু সেটা কাটে ঘরটা ছোট হতে
             পারলে — আর ঘরটাই ছোট হতে পারত না।

             ফল: ৩৭৫px ফোনে এই দুইটা ভাগ ৪৩৭px চওড়া হয়ে বসত, আর গোটা
             ড্যাশবোর্ড পাশে গড়াত। "আজ" ভাগটা ঠিক ছিল, কারণ ওটা এই
             grid-এর ভিতরে নয়।

             টপবারে ঠিক এই নিয়মটাই আগে লেখা আছে — সেখানে flex, এখানে
             grid, কারণ একই। --}}
        {{-- ⭐ ব্যতিক্রম কেন্দ্র — "যা করা বাকি" এখন কার্ডে, গুরুত্বের চিহ্ন আর পদক্ষেপের বোতামসহ, গোটা সারি জুড়ে।
             মালিকের নকশা, ১ অক্টোবর ২০২৬। ⓘ উৎস বদলায়নি: মডিউলের নিজের করণীয় উইজেট, অনুমতি ছেঁকে।
             ⚠️ চিহ্ন রঙে একা নয় — আইকন আর লেখা দুইটাই থাকে, রঙ না চিনলেও পড়া যায়। --}}
        <section data-exception-center class="min-w-0 lg:col-span-2">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('home.exceptions_title') }}</h2>

            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4 shadow-(--shadow-card)">
                @if ($waiting->isNotEmpty())
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($waiting as $widget)
                            @php $warn = $widget->tone === 'warn'; @endphp

                            <div @class([
                                'flex min-w-0 flex-col gap-2 rounded-(--radius-card) border border-(--color-border) p-3',
                                'bg-(--color-badge-warning-bg)/40' => $warn,
                                'bg-(--color-surface-sunken)' => ! $warn,
                            ])>
                                <span @class([
                                    'inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold',
                                    'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $warn,
                                    'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)' => ! $warn,
                                ])>
                                    <x-ui.icon :name="$warn ? 'bell' : 'clock'" :size="12" />
                                    {{ $warn ? __('home.severity_warn') : __('home.severity_attention') }}
                                </span>

                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-(--color-ink)">{{ $widget->label }}</span>
                                        @if ($widget->hint)
                                            <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $widget->hint }}</span>
                                        @endif
                                    </span>
                                    <span @class([
                                        'tabular shrink-0 text-xl font-semibold',
                                        'text-(--color-badge-warning-ink)' => $warn,
                                        'text-(--color-ink)' => ! $warn,
                                    ])>{{ $widget->value }}</span>
                                </div>

                                <a href="{{ $widget->href }}"
                                   class="inline-flex w-fit items-center gap-1 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-3 py-1.5 text-xs font-semibold text-(--color-link)
                                          hover:bg-(--color-surface-hover)">
                                    {{ __('home.open') }}
                                    <x-ui.icon name="chevron_right" :size="14" class="rtl:rotate-180" />
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($quiet->isNotEmpty())
                    <p @class(['flex items-center gap-2 text-sm text-(--color-ink-muted)', 'mt-3' => $waiting->isNotEmpty()])>
                        <x-ui.icon name="check_circle" :size="16" class="text-(--color-success)" />
                        {{ trans_choice('core.dashboard.nothing_pending', $quiet->count(),
                            ['count' => $quiet->count()]) }}
                    </p>
                @endif
            </div>
        </section>
    @endif

    {{--
        সদ্য যা হয়েছে।

        ── কেন এটা করণীয়ের চেয়ে আলাদা প্রশ্ন ──────────────────────────
        করণীয় বলে কী আটকে আছে — সেটা ভবিষ্যতের কাজ। এটা বলে কী হয়ে
        গেছে, আর দিনের শুরুতে মালিকের প্রথম প্রশ্নটা সেটাই: "আমি না
        থাকতে কী কী হলো"। আজ পর্যন্ত উত্তরটা পেতে বিক্রয়, আদায়, ক্রয়
        আর নগদ গণনার চারটা তালিকা আলাদা করে খুলে তারিখ ধরে ছাঁকতে হত।

        ── প্রতিটা সারি ক্লিকযোগ্য ────────────────────────────────────
        "৳4,050 বিক্রয়" পড়ে মালিক জানতে চান কার কাছে, কী কী (নিয়ম ১)।
        লিংক ছাড়া সারিটা কেবল একটা ঘোষণা, আর ঘোষণা যাচাই করা যায় না।

        ── খালি থাকলে কিছুই দেখানো হয় না ──────────────────────────────
        নতুন কোম্পানিতে সত্যিই কিছু ঘটেনি। "কিছু হয়নি" লেখা একটা খালি
        কার্ড পাশের করণীয় তালিকাটাকে অর্ধেক করে দিত, কোনো তথ্য না দিয়ে।
    --}}
    @if ($happenings !== [] && $layout->shows('happenings'))
        <section class="min-w-0">
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">
                {{ __('core.dashboard.just_happened') }}
            </h2>

            <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) shadow-(--shadow-card)">
                @foreach ($happenings as $happening)
                    {{--
                        পুরো সারিটাই লিংক — `x-ui.drill` দিয়ে।

                        ── কেন নিজে রুট বানানো হয় না ───────────────────
                        "কোন ডকুমেন্ট কোন পর্দায় খোলে" জানে একমাত্র
                        `DrillResolver`, আর সেটাই ঠিক: এখানে হাতে রুট
                        লিখলে নতুন ডকুমেন্ট টাইপ যোগ হওয়ার পর এই
                        তালিকাটায় সেটা ক্লিকযোগ্য হত না, আর কেউ খেয়ালও
                        করত না।

                        উৎস হারিয়ে গেলে (বাতিল, মুছে ফেলা) কম্পোনেন্টটাই
                        নিষ্ক্রিয় করে দেয় — সারিটা থেকে যায়, শুধু আর
                        কোথাও নিয়ে যায় না।
                    --}}
                    <x-ui.drill :source="$happening->sourceType" :id="$happening->sourceId"
                                class="flex items-center gap-3 border-b border-(--color-border) px-4 py-3
                                       !text-inherit !no-underline transition-colors last:border-b-0
                                       hover:bg-(--color-surface-hover)">

                        <span @class([
                            'grid size-8 shrink-0 place-items-center rounded-(--radius-field)',
                            'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                                => $happening->tone === 'good',
                            'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
                                => $happening->tone === 'warn',
                            'bg-(--color-surface-app) text-(--color-brand-600)'
                                => ! in_array($happening->tone, ['good', 'warn'], true),
                        ])>
                            <x-ui.icon :name="$happening->icon" :size="16" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ $happening->title }}</span>

                            {{-- কার সাথে, আর কখন — একই লাইনে।

                                 সময়টা ছাড়া সারিটা বলে কী হয়েছে, বলে না
                                 কখন — আর "আজ সকালে না গতকাল" প্রশ্নটাই
                                 বেশিরভাগ সময় আসল প্রশ্ন। --}}
                            <span class="block truncate text-2xs text-(--color-ink-muted)">
                                {{ $happening->subtitle }}
                                @if ($happening->subtitle !== '') · @endif
                                <span class="num">{{ $happening->when->format('H:i') }}</span>
                            </span>
                        </span>

                        @if ($happening->isDrillable())
                            <x-ui.icon name="chevron_right" :size="16"
                                       class="text-(--color-ink-disabled) rtl:rotate-180" />
                        @endif
                    </x-ui.drill>
                @endforeach
            </div>
        </section>
    @endif

    </div>
    </div>
    </div>{{-- data-home-units --}}

    @if ($nothing)
        {{--
            একটাও উইজেট নেই।

            হয় ব্যবহারকারীর কোনো মডিউলে ঢোকার অনুমতি নেই, নয় কোনো মডিউল
            এখনো সংখ্যা দেয় না। খালি পর্দা রেখে দিলে মানুষ ভাবত কিছু
            ভেঙেছে, তাই কারণটা লেখা থাকে।
        --}}
        {{-- ⛔ একটাও মডিউল না থাকা আর সংখ্যা না থাকা — এক জিনিস নয়।

             ── ⓘ মালিকের প্রশ্ন, ২১ সেপ্টেম্বর ২০২৬ ────────────────
             *"Abu Kawser user a kono kichui dekhayna keno"* — নতুন একজনকে
             খোলা হয়েছিল কিন্তু কোনো ভূমিকা বসানো হয়নি। ⛔ অনুমতি আসে
             ভূমিকা থেকে, তাই ভূমিকা না থাকলে মেনুর প্রতিটা সারি ছাঁকনিতে
             পড়ে যায় — পর্দা ফাঁকা, আর কোনো কারণ লেখা নেই।

             ⚠️ পুরনো বার্তাটা এখানে **ভুল কথা বলত**: *"দেখানোর মতো
             কিছু নেই"* পড়লে মানুষ ভাবেন আজ কাজ হয়নি, অথচ তাঁর তো
             কোনো দরজাই খোলা হয়নি। ⓘ দুইটা অবস্থায় দুই কথা, আর
             দ্বিতীয়টা বলে দেয় **কাকে বলতে হবে** — নিয়ম ১। --}}
        {{-- ⛔ তিনটা অবস্থা, তিন কথা — ২১ সেপ্টেম্বর ২০২৬।

             ⓘ সবচেয়ে পরেরটা লাইভে মেপে পাওয়া: একজনের ভূমিকা ছিল Demo-তে,
             আর তিনি দাঁড়িয়ে ছিলেন Test Company-তে। ⚠️ তাঁকে "কোনো ভূমিকা
             দেওয়া হয়নি" বলা মিথ্যা হত, আর মিথ্যা কারণ দিলে তিনি
             প্রশাসকের কাছে ছোটেন, আর প্রশাসকও খুঁজে পান না — কারণ সবই
             ঠিক আছে। ⭐ সঠিক উত্তরটা তাঁকে এক ক্লিকে কাজে ফিরিয়ে দেয়। --}}
        @php
            $why = match (true) {
                $menu !== [] => __('core.dashboard.nothing_to_show'),
                ($roleLivesIn ?? []) !== [] => __('core.dashboard.role_lives_elsewhere', [
                    'companies' => implode(', ', $roleLivesIn),
                ]),
                default => __('core.dashboard.no_module_at_all'),
            };
        @endphp

        <x-ui.empty-state :message="$why" />
    @endif
</x-layouts.app>
