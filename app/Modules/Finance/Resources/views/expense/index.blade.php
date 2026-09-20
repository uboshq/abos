{{--
    খরচ — কোন খাতে কত গেল।

    ── কেন তালিকা নয়, খাত ─────────────────────────────────────────────
    ভাউচারের তালিকা হিসাবে আছেই। ম্যানেজার তালিকা পড়েন না; তিনি জানতে
    চান এই মাসে জ্বালানিতে কত গেল, আর গত মাসের চেয়ে বেশি না কম।

    ── কেন আগের সময়টা পাশে ────────────────────────────────────────────
    "জ্বালানিতে ১২,৪০০" একা কিছু বলে না। "আগে ছিল ৮,১০০" বলার পরেই
    সংখ্যাটা একটা প্রশ্ন হয়ে ওঠে — আর ওই প্রশ্নটাই খরচ কমানোর শুরু।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.expense') }}</x-slot:title>

    {{--
        ⭐ বাকি তালিকার গড়নে — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"সব পাতাতেই সমস্যা"*।

        ⓘ আগে শিরোনাম পাতার মাথায়, তার নিচে খোলা তারিখের ফর্ম আর তার এক
        কোণে "নতুন খরচ", তারপর আলাদা বাক্সে খাতের তালিকা। ⭐ এখন গ্রাহক আর
        ভাউচারের তালিকার মতো: টুলবার (শিরোনাম · বর্ণনা · + নতুন খরচ, নিচের
        লাইনে ছাঁকনি · খোঁজা · সরঞ্জাম) → খাতের তালিকা, একই বাক্সে। তারিখ
        দুইটা টুলবারের ছাঁকনিতে, ভাউচারের পাতার মতোই।

        ⓘ টুলবার কেবল খাতের তালিকার — খোঁজা, কলাম, ঘনত্ব আর রপ্তানি ওটাকেই
        ধরে। নিচের "অপেক্ষায়" আর "সাম্প্রতিক" আগের মতোই নিজের বাক্সে, নিজের
        শিরোনামে। ⓘ সময়টা খাতের শিরোনামের ডানে লেখা থাকে: ছাঁকনির প্যানেল
        বন্ধ থাকলেও কোন সময়ের সংখ্যা তা পর্দায় থাকা চাই।
    --}}
    @php
        $expenseColumns = [
            ['key' => 'head', 'label' => __('finance::field.head'),
             'render' => fn ($r) => view('finance::partials.head-link', ['account' => $r['account']])],
            ['key' => 'now', 'label' => __('finance::field.this_period'), 'numeric' => true, 'width' => '11rem',
             'render' => fn ($r) => view('ui.amount-link', [
                 'value' => $r['now'],
                 'href' => route('accounts.coa.show', $r['account']).'#transactions',
             ])],
            ['key' => 'before', 'label' => __('finance::field.period_before'), 'numeric' => true, 'width' => '11rem',
             'render' => fn ($r) => \App\Core\Support\Money::format($r['before'])],
            ['key' => 'change', 'label' => __('finance::field.change'), 'numeric' => true, 'width' => '10rem',
             'render' => fn ($r) => view('finance::expense.partials.change', ['row' => $r])],
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

    <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('finance::menu.expense')"
                          :subtitle="__('finance::message.expense_note')"
                          :columns="$expenseColumns"
                          :search-placeholder="__('finance::message.head_search')">
                <x-slot:actions>
                    {{-- খরচ লেখা হয় ভাউচারেই — এখানে আরেকটা ফর্ম বানালে একই
                         জিনিসের দুইটা পথ হত, আর দুইটার যাচাই একদিন আলাদা হয়ে যেত। --}}
                    {{-- ⓘ `@can` নতুন — বোতামটা ভাউচার বানানোর পাতায় নিয়ে যায়, আর ওই
                         রুট `accounts.voucher.create` অনুমতি চায়। অনুমতি ছাড়া বোতামটা
                         দেখালে ক্লিকে ৪০৩ — একটা মৃত বোতাম। --}}
                    @can('accounts.voucher.create')
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('accounts.voucher.create', ['type' => 'expense'])">
                            {{ __('finance::action.new_expense') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                {{-- সময়ের পরিসর — ডিফল্ট চলতি মাস, কারণ ভাড়া-বেতন-বিদ্যুৎ মাসের
                     হিসাব, আর "আজ কত গেল" প্রশ্নটা কেউ করে না। --}}
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

        <h2 class="flex flex-wrap items-center gap-2 border-b border-(--color-border) bg-(--color-section-head)
                   px-3 py-2 text-sm font-semibold">
            {{ __('finance::field.by_head') }}
            <span class="ms-auto text-xs font-normal tabular-nums text-(--color-ink-muted)">
                {{ \App\Core\Support\DateFormat::format($from) }} – {{ \App\Core\Support\DateFormat::format($to) }}
            </span>
        </h2>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_expense_yet')"
            :rows="$heads"
            :columns="$expenseColumns" />
    </div>

    {{--
        অনুমোদনের অপেক্ষায় — উপরের যোগফলে এগুলো **নেই**।

        ── কেন এটা উপরের তালিকার পরে, নিচেরটার আগে ──────────────────────
        উপরে "কোন খাতে কত গেল", আর এই কাগজগুলো ঠিক ওই সংখ্যাগুলো থেকেই
        বাদ পড়েছে — খসড়া খতিয়ানে বসেনি, তাই কোনো খাতে যোগও হয়নি।
        পাশাপাশি না রাখলে ম্যানেজার কম খরচ দেখে সিদ্ধান্ত নিতেন, আর
        অনুমোদন হয়ে গেলে সংখ্যাটা মাসের মাঝখানে হঠাৎ বেড়ে যেত।

        ⚠️ ── ছক না বসালে এই অংশটা কোনোদিন দেখাই যায় না ──────────────────
        `@if` ইচ্ছাকৃত। কোনো কোম্পানি অনুমোদনের ছক না বসালে সংগ্রহটা
        চিরকাল খালি, আর একটা চিরকাল-খালি বাক্স পর্দায় থাকা মানে **প্রতিদিন
        একটা প্রশ্ন যার উত্তর কেউ জানে না** ("এখানে কী আসার কথা ছিল?")।
        আজ চারটা কোম্পানির একটাতেও খরচের ছক নেই — অর্থাৎ আজ এই অংশটা
        কারো পর্দায় নেই, আর সেটাই ঠিক।
    --}}
    @if ($waiting->isNotEmpty())
        <section data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-center gap-2 border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <x-ui.icon name="clock" :size="16" />
                {{ __('finance::field.waiting_approval') }}

                {{-- সংখ্যাটা শিরোনামেই, কারণ প্রশ্নটা "কয়টা" — আর
                     উত্তরটা ঠিক নিচেই, গোনা যায় এমন সারিতে (নিয়ম ১)।

                     ⚠️ `$waitingTotal`, `$waiting->count()` নয়। তালিকাটা
                     পঞ্চাশে বাঁধা, তাই সারি গুনলে ব্যাজটা বড়জোর "৫০"
                     বলত — আর যে কোম্পানিতে একশো সাঁইত্রিশটা ঝুলে আছে
                     সেখানে ওটাই সবচেয়ে ভুল সংখ্যা, কারণ দেখতে ঠিক
                     আগের মতোই। --}}
                <span class="rounded-full bg-(--color-badge-warning-bg) px-2 py-0.5 text-xs
                             text-(--color-badge-warning-ink)">{{ $waitingTotal }}</span>
            </h2>

            <p class="border-b border-(--color-border) px-4 py-2 text-xs text-(--color-ink-muted)">
                {{ __('finance::message.waiting_approval_note') }}
            </p>

            {{-- কাটা পড়েছে কি না, আর কতটা — কেবল সত্যিই কাটা পড়লে।

                 নিচে যা দেখা যাচ্ছে সেটাই সবটা নয় — এই লাইনটা না থাকলে
                 পর্দাটা সম্পূর্ণ দেখাত অথচ বাকিগুলো চুপচাপ লুকিয়ে রাখত। --}}
            @if ($waitingTotal > $waiting->count())
                <p role="status"
                   class="border-b border-(--color-border) bg-(--color-badge-warning-bg) px-4 py-2
                          text-xs text-(--color-badge-warning-ink)">
                    {{ __('finance::message.waiting_approval_capped', [
                        'shown' => $waiting->count(),
                        'total' => $waitingTotal,
                    ]) }}
                </p>
            @endif

            <x-ui.table
                :rows="$waiting"
                :columns="[
                    ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                     'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
                    /* নম্বরটাই লিংক — ঝুলে থাকা কাগজটা দেখার পথ (নিয়ম ১) */
                    ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                     'render' => fn ($v) => view('finance::expense.partials.paper', ['voucher' => $v])],
                    ['key' => 'narration', 'label' => __('finance::field.what_for')],
                    ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($v) => \App\Core\Support\Money::format($v->totals()['debit'])],
                ]" />
        </section>
    @endif

    {{-- শেষ কুড়িটা — "আজ কী কী লেখা হয়েছে" প্রশ্নের উত্তর, আর ওটাই
         দিনের শেষে মিলিয়ে দেখার একমাত্র জায়গা। --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.recent_expenses') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_expense_yet')"
            :rows="$recent"
            :columns="[
                ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                 'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
                /* নম্বরটাই লিংক — কাগজটা দেখার একমাত্র পথ (নিয়ম ১) */
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                 'render' => fn ($v) => view('finance::expense.partials.paper', ['voucher' => $v])],
                ['key' => 'narration', 'label' => __('finance::field.what_for')],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($v) => \App\Core\Support\Money::format($v->totals()['debit'])],
            ]" />
    </section>
</x-layouts.app>
