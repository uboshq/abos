{{--
    স্থিতিপত্র — একটা দিনে ব্যবসা কোথায় দাঁড়িয়ে।

    ── কেন দুই কলাম, পাশাপাশি ──────────────────────────────────────────
    বাঁয়ে যা কিছু ব্যবসার, ডানে সেগুলো কার টাকায় কেনা। দুইটার নিচের
    সংখ্যা এক না হলে খাতায় ভুল আছে — আর ওই সমতাটাই স্থিতিপত্রের
    একমাত্র কাজ। উপরে-নিচে সাজালে দুইটা যোগফল একসাথে চোখে পড়ত না,
    আর পড়াটাই এখানে সব।

    ছোট পর্দায় একটার নিচে আরেকটা, কারণ পাশাপাশি রাখলে সংখ্যাগুলো
    এমন ছোট হত যে কেউ পড়ত না।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.balance_sheet') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.balance_sheet')"
                          :subtitle="__('accounts::message.balance_sheet_as_of', [
                              'date' => \App\Core\Support\DateFormat::format($sheet['as_of']),
                          ])">
            <x-slot:actions>
                {{-- ⭐ প্রিন্ট ভিউ (নতুন ট্যাবে, IAS 1 ছকের কাগজ) আর PDF — "তালিকা নামানো" চাবিতে (মালিক, ১৬ নভেম্বর ২০২৬) --}}
                @can('system_admin.list.export')
                    <x-ui.button tone="secondary" icon="print" target="_blank" rel="noopener" data-paper="print"
                                 :href="url()->current().'?'.http_build_query(request()->query() + ['export' => 'print'])">
                        {{ __('core.action.print') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" data-paper="pdf" target="_blank" rel="noopener"
                                 :href="url()->current().'?'.http_build_query(request()->query() + ['export' => 'pdf'])">
                        {{ __('core.toolbar.export_pdf') }}
                    </x-ui.button>
                    {{-- ⭐ ইংরেজি কাগজ আর পূর্ণ সেট (লাভ-ক্ষতি, মালিকানা স্বত্ব, নগদ প্রবাহ, নোট) — মালিক, ১৭ নভেম্বর ২০২৬ "ok" --}}
                    <x-ui.button tone="secondary" target="_blank" rel="noopener" data-paper="print-en"
                                 :href="url()->current().'?'.http_build_query(request()->query() + ['export' => 'print', 'lang' => 'en'])">
                        {{ __('accounts::fs.set.print_bs_en') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" target="_blank" rel="noopener" data-paper="set-bn"
                                 :href="route('accounts.financial_statements', request()->only(['as_of', 'branch_id']) + ['export' => 'print', 'lang' => 'bn'])">
                        {{ __('accounts::fs.set.print_bn') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" target="_blank" rel="noopener" data-paper="set-en"
                                 :href="route('accounts.financial_statements', request()->only(['as_of', 'branch_id']) + ['export' => 'print', 'lang' => 'en'])">
                        {{ __('accounts::fs.set.print_en') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ── কোন দিন পর্যন্ত ───────────────────────────────────────────
         "কবে থেকে" ঘরটা নেই, ইচ্ছাকৃতভাবে: স্থিতিপত্র একটা মুহূর্তের
         ছবি, পরিসরের হিসাব নয়। ঘরটা থাকলে ব্যবহারকারী ধরে নিতেন
         সংখ্যাগুলো ওই তারিখ থেকে গোনা। --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide" data-report>
        <x-ui.field name="as_of" type="date" :label="__('accounts::field.as_of')"
                    :value="$sheet['as_of']" />

        @if ($branches->count() > 1)
            <x-ui.select name="branch_id" :label="__('core.company.branch')"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                         :placeholder="__('accounts::field.all_branches')"
                         :selected="$branchId" />
        @endif

        <x-ui.button type="submit" tone="secondary" data-report-show>{{ __('core.action.show') }}</x-ui.button>
    </form>

    @if ($branchId !== null)
        {{-- এক শাখার স্থিতিপত্র সচরাচর মেলে না, আর সেটা লুকানো অসৎ হত --}}
        <p role="note" class="mb-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                              text-sm text-(--color-badge-pending-ink)">
            {{ __('accounts::message.branch_sheet_may_not_agree') }}
        </p>
    @endif

    {{-- ── সমতার দাবিটা উপরে ─────────────────────────────────────────
         পুরনো পর্দায় সংখ্যাটা নিচে পড়ে থাকত আর কেউ দেখত না — অথচ
         খাতা মেলে কি না, সেটাই প্রথম প্রশ্ন। --}}
    <section data-boxed
             @class([
                 'mb-4 flex flex-wrap items-center gap-3 rounded-(--radius-card) border px-4 py-3',
                 'border-(--color-border) bg-(--color-badge-success-bg)' => $sheet['agrees'],
                 'border-(--color-danger) bg-(--color-badge-danger-bg)' => ! $sheet['agrees'],
             ])>
        <span @class(['font-semibold',
            'text-(--color-badge-success-ink)' => $sheet['agrees'],
            'text-(--color-badge-danger-ink)' => ! $sheet['agrees']])>
            {{ $sheet['agrees']
                ? __('accounts::message.sheet_agrees')
                : __('accounts::message.sheet_does_not_agree', ['gap' => $money($sheet['difference'])]) }}
        </span>

        <span class="flex-1"></span>

        <span class="text-sm tabular-nums">
            {{ __('accounts::field.total_assets') }} <strong>{{ $money($sheet['totals']['assets']) }}</strong>
            &nbsp;=&nbsp;
            {{ __('accounts::field.total_funding') }} <strong>{{ $money($sheet['totals']['funding']) }}</strong>
        </span>
    </section>

    {{-- ⭐ অনুপাতের সারি আর "অন্তর্বর্তী, নিরীক্ষিত নয়" — কাগজের একই অঙ্ক ([[FinancialStatements::position()]]; মালিক, ১৭ নভেম্বর ২০২৬ "ok") --}}
    @isset($fs)
        @php $ratio = fn (?string $r) => $r === null ? __('accounts::fs.bs.not_meaningful') : __('accounts::fs.bs.ratio_times', ['value' => $r]); @endphp
        <p data-ratios class="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-sm text-(--color-ink-muted)">
            <span>{{ __('accounts::fs.bs.ratio_current') }} <strong class="num text-(--color-ink)" data-ratio="current">{{ $ratio($fs['ratios']['current']) }}</strong>
                {{ __('accounts::fs.bs.ratio_was', ['value' => $fs['ratios']['current_prior'] ?? __('accounts::fs.bs.not_meaningful')]) }}</span>
            <span>{{ __('accounts::fs.bs.working_capital') }} <strong class="num text-(--color-ink)" data-ratio="working">{{ $money($fs['ratios']['working_capital']) }}</strong></span>
            <span>{{ __('accounts::fs.bs.debt_equity') }} <strong class="num text-(--color-ink)" data-ratio="debt_equity">{{ $ratio($fs['ratios']['debt_equity']) }}</strong></span>
            <span data-interim>{{ __('accounts::fs.bs.interim') }}</span>
        </p>
    @endisset

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- ── বাঁ পক্ষ · সম্পদ ─────────────────────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            @include('accounts::report.partials.sheet-columns')
            <h2 class="flex items-baseline justify-between gap-3 border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <span class="flex-1">{{ __('accounts::field.assets') }}</span>
                <span class="w-32 text-end tabular-nums">{{ $money($sheet['totals']['assets']) }}</span>
                <span data-prior class="w-32 text-end tabular-nums text-(--color-ink-muted)">{{ $money($sheet['prior']['totals']['assets']) }}</span>
            </h2>

            @include('accounts::report.partials.sheet-side', ['groups' => $sheet['assets']])
        </section>

        {{-- ── ডান পক্ষ · দায় ও মূলধন ───────────────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            @include('accounts::report.partials.sheet-columns')
            <h2 class="flex items-baseline justify-between gap-3 border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <span class="flex-1">{{ __('accounts::field.liabilities_and_equity') }}</span>
                <span class="w-32 text-end tabular-nums">{{ $money($sheet['totals']['funding']) }}</span>
                <span data-prior class="w-32 text-end tabular-nums text-(--color-ink-muted)">{{ $money($sheet['prior']['totals']['funding']) }}</span>
            </h2>

            @include('accounts::report.partials.sheet-side', ['groups' => $sheet['liabilities']])

            @include('accounts::report.partials.sheet-side', [
                'groups' => $sheet['equity'],
                'extras' => array_values(array_filter([
                    /*
                     * ⛔ আগের বছরের বন্ধ-না-হওয়া লাভ নিজের নামে — নাহলে "চলতি বছরের লাভ" লাভ-ক্ষতির পাতার সাথে মিলত না
                     * (ধাপ ০, ১২ অক্টোবর ২০২৬; [[TheSheetsProfitThisYearIsTheProfitAndLossTest]])। শূন্য হলে সারিটা থাকে না।
                     */
                    bccomp($sheet['profit_earlier'], '0', 4) !== 0 ? [
                        'label' => __('accounts::field.profit_earlier_years'),
                        'amount' => $sheet['profit_earlier'],
                        'prior' => $sheet['prior']['profit_earlier'],
                    ] : null,
                    [
                    /*
                     * চলতি বছরের ফলটা মূলধনের শেষ সারি।
                     *
                     * এটা কোনো খাত নয় — হিসাব করা একটা সংখ্যা, আর
                     * সেটাই আগের পর্দাটায় ছিল না বলে দুই পক্ষ কোনোদিন
                     * মিলত না। খাত বানালে বছর বন্ধ করার সময় সংখ্যাটা
                     * দুইবার গোনা হত।
                     */
                    'label' => __('accounts::field.profit_this_year'),
                    'amount' => $sheet['profit_this_year'],
                    'prior' => $sheet['prior']['profit_this_year'],
                    ],
                ])),
            ])
        </section>
    </div>
</x-layouts.app>
