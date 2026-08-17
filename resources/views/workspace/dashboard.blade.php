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
    @endphp

    {{-- ── বাছা কালপর্বটা ───────────────────────────────────────────
         সংখ্যার কার্ড, কিন্তু সব সমান নয়: "আজ"-এর প্রথম টাকার
         সংখ্যাটা প্রধান কার্ড, দুই কলাম জুড়ে, গাঢ় জমিনে।

         কেন: বিশটা একরকম বাক্সে চোখের কোনো শুরু নেই। মালিক পর্দায়
         এসে প্রথম যে প্রশ্নটা করেন সেটা "টাকা কত" — ওটার উত্তর বড় ও
         আলাদা দেখালে বাকিগুলো আর প্রতিযোগিতা করে না।            --}}
    {{-- একটা সময়ে একটাই দল — উপরের তাব যেটা বলে --}}
    @foreach ([$period] as $group)
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

            /*
             * প্রধান কার্ড — টাকা **কোথায় আছে**, কোনটা বড় সেটা নয়।
             *
             * ── আগে যা ছিল, আর কেন সেটা ভুল ─────────────────────────
             * নিয়মটা ছিল "সবচেয়ে বড় টাকার সংখ্যাটা"। ভালো একটা দিনে
             * আজকের বিক্রয় হাতের নগদকে ছাড়িয়ে যায়, আর তখন প্রধান
             * কার্ডটা নিজে থেকেই বদলে যেত — একই পর্দা দুই দিনে দুই
             * প্রশ্নের উত্তর দিত, আর মালিককে প্রতিবার পড়ে বুঝতে হত
             * এবার কোনটা বড় করে দেখানো হয়েছে।
             *
             * মালিক পর্দায় এসে প্রথম যে প্রশ্নটা করেন সেটা **"টাকা
             * কত"** — "আজ কত বেচলাম" নয়। বিক্রয় একটা প্রবাহ, টাকা
             * একটা অবস্থান; দিন শেষে সিদ্ধান্তগুলো অবস্থানটা দেখেই
             * নেওয়া হয়।
             *
             * ভাগওয়ালা কার্ডটাই সেই অবস্থান (নগদ কাউন্টারে · ব্যাংক ও
             * MFS · পথে), আর ওই ভাগগুলো আছে বলেই সে বড় জায়গার দাবিদার
             * — ছোট কার্ডে তিনটা ঘর পাশাপাশি বসেই না।
             */
            if (in_array($group, \App\Core\Dashboard\Widget::PERIODS, true)) {
                $position = collect($widgets)
                    ->filter(fn ($w) => $w->tone === 'money' && $w->parts !== []);

                /*
                 * অবস্থানের কার্ড না থাকলে তবেই সবচেয়ে বড়টা।
                 *
                 * হিসাব মডিউল বন্ধ থাকলে বা তার অনুমতি না থাকলে ওই
                 * কার্ডটাই আসে না — তখন চোখের শুরুটা যা আছে তার
                 * মধ্যে সবচেয়ে বড়টাই।
                 */
                $biggest = $position->isNotEmpty() ? $position : collect($widgets)
                    ->filter(fn ($w) => $w->tone === 'money' && $pending($w))
                    ->sortByDesc(fn ($w) => (float) preg_replace('/[^0-9.]/', '',
                        strtr($w->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
                            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'])));

                $leadIndex = $biggest->isEmpty() ? false : $biggest->keys()->first();

                /*
                 * প্রধান কার্ডটা সারির শুরুতে।
                 *
                 * ── কেন ক্রম বদলাতে হলো ────────────────────────────
                 * উইজেটগুলো মডিউলের `sort` ধরে আসে, আর তাতে হিসাবের
                 * কার্ডটা বিক্রয়ের দুইটার পরে পড়ত — ফলে সবচেয়ে বড়
                 * কার্ডটা দুইটা ছোট কার্ডের **নিচে** বসত, আর চোখের
                 * শুরুর জায়গাটাই মাঝখানে চলে যেত।
                 *
                 * বাকিগুলোর নিজেদের ক্রম অটুট থাকে — কেবল প্রধানটাকে
                 * সামনে তোলা হয়।
                 */
                if ($leadIndex !== false) {
                    $lead = $widgets[$leadIndex];

                    unset($widgets[$leadIndex]);

                    $widgets = [$lead, ...array_values($widgets)];
                    $leadIndex = 0;
                }
            }
        @endphp

        <section class="mb-6">
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
    <div class="mb-6 grid gap-4 lg:grid-cols-2">

    @if (! empty($groups['todo']))
        @php
            $todo = collect($groups['todo']);
            $waiting = $todo->filter($pending);
            $quiet = $todo->reject($pending);
        @endphp

        <section>
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
    @if ($happenings !== [])
        <section>
            <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">
                {{ __('core.dashboard.just_happened') }}
            </h2>

            <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
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
