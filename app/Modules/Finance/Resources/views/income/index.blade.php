{{--
    আয় — কোন খাতে কত এল, আর কতটা বিক্রয় ছাড়া।

    ── কেন উপরে তিনটা সংখ্যা ───────────────────────────────────────────
    "মোট আয় ৩,২০,০০০" একা কিছু বলে না। ভাগটাই খবর: **কতটা বিক্রয় ছাড়া
    এল**। ভাড়া, কমিশন আর বাতিল মালের টাকার কোনো ক্রয়মূল্য নেই — অর্থাৎ
    পুরোটাই মুনাফা। ৪% মার্জিনের ব্যবসায় ওই সংখ্যাটা বিক্রয়ের চেয়ে বেশি
    দরকারি হতে পারে, আর মিশিয়ে রাখলে কেউ সেটা কোনোদিন দেখত না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.income') }}</x-slot:title>

    {{--
        ⭐ বাকি তালিকার গড়নে — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"সব পাতাতেই সমস্যা"*।

        ⓘ আগে শিরোনাম পাতার মাথায়, তার নিচে খোলা তারিখের ফর্ম, তারপর আলাদা
        তিনটা কার্ড, আর সবশেষে আলাদা বাক্সে তালিকা — চারটা টুকরো। ⭐ এখন
        গ্রাহক আর ভাউচারের তালিকার মতো একটাই বাক্স: টুলবার (শিরোনাম ·
        বর্ণনা, নিচের লাইনে ছাঁকনি · খোঁজা · সরঞ্জাম) → তিনটা যোগফল → খাতের
        তালিকা। তারিখ দুইটা টুলবারের ছাঁকনিতে, ভাউচারের পাতার মতোই।

        ⓘ খোঁজা খাতের নামে (নিয়ন্ত্রকের `matching`) — যোগফল তিনটা ছাঁকা হয় না।
        ⓘ সময়টা খাতের শিরোনামের ডানে লেখা থাকে: ছাঁকনির প্যানেল বন্ধ থাকলেও
        কোন সময়ের সংখ্যা তা পর্দায় থাকা চাই।
    --}}
    @php
        $incomeColumns = [
            ['key' => 'head', 'label' => __('finance::field.head'),
             'render' => fn ($r) => view('finance::partials.head-link', ['account' => $r['account']])],
            ['key' => 'now', 'label' => __('finance::field.this_period'), 'numeric' => true,
             'width' => '11rem',
             'render' => fn ($r) => view('ui.amount-link', [
                 'value' => $r['now'],
                 'href' => route('accounts.coa.show', $r['account']).'#transactions',
             ])],
            ['key' => 'before', 'label' => __('finance::field.period_before'), 'numeric' => true,
             'width' => '11rem',
             'render' => fn ($r) => \App\Core\Support\Money::format($r['before'])],
            ['key' => 'change', 'label' => __('finance::field.change'), 'numeric' => true,
             'width' => '10rem',
             'render' => fn ($r) => view('finance::expense.partials.change',
                 ['row' => $r, 'upIsGood' => true])],
            /* খাতের খতিয়ান — খোলার মতো রেকর্ড; দেখার অনুমতি না থাকলে লিংক নয় */
            ['key' => 'do', 'label' => __('core.table.actions'), 'width' => '7rem',
             'render' => fn ($r) => auth()->user()?->can('view', $r['account'])
                 ? new \Illuminate\Support\HtmlString('<a href="'.e(route('accounts.coa.show', $r['account']).'#transactions').'"'
                     .' class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm'
                     .' text-(--color-link) transition-colors hover:bg-(--color-surface-hover) print-hide">'
                     .e(__('core.action.view')).'</a>')
                 : '—'],
        ];
    @endphp

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('finance::menu.income')"
                          :subtitle="__('finance::message.income_note')"
                          :columns="$incomeColumns"
                          :search-placeholder="__('finance::message.head_search')">
                {{-- সময়ের পরিসর — ডিফল্ট চলতি মাস, খরচের পর্দার মতোই একই কারণে --}}
                <x-ui.date name="from"
                           value="{{ $from }}"
                           aria-label="{{ __('finance::field.from') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                <x-ui.date name="to"
                           value="{{ $to }}"
                           aria-label="{{ __('finance::field.to') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
            </x-ui.toolbar>
        </form>

        {{-- ── বিক্রয় বনাম বিক্রয় ছাড়া ──────────────────────────────────── --}}
        <section class="grid gap-3 border-b border-(--color-border) p-3 sm:grid-cols-3">
            @foreach ([
                ['finance::field.income_from_sales', $totals['sales'], false],
                ['finance::field.income_not_from_sales', $totals['other'], true],
                ['finance::field.income_all', $totals['all'], false],
            ] as [$label, $value, $highlight])
                <div @class([
                    'rounded-(--radius-card) border bg-(--color-surface-app) p-3',
                    'border-(--color-border)' => ! $highlight,
                    'border-(--color-state-on)' => $highlight,
                ])>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ \App\Core\Support\Money::format($value) }}
                    </p>
                </div>
            @endforeach
        </section>

        {{-- ── খাত ধরে ───────────────────────────────────────────────────
             প্রতিটা সংখ্যা তার খাতের এন্ট্রিগুলোতে নামায় — নিয়ম ১। --}}
        <h2 class="flex flex-wrap items-center gap-2 border-b border-(--color-border) bg-(--color-section-head)
                   px-3 py-2 text-sm font-semibold">
            {{ __('finance::field.by_head') }}
            <span class="ms-auto text-xs font-normal tabular-nums text-(--color-ink-muted)">
                {{ \App\Core\Support\DateFormat::format($from) }} – {{ \App\Core\Support\DateFormat::format($to) }}
            </span>
        </h2>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_income_yet')"
            :rows="$heads"
            :columns="$incomeColumns" />
    </div>
</x-layouts.app>
