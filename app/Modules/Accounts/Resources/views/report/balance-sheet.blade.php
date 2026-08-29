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
                <x-ui.button tone="secondary" icon="print" onclick="window.print()">
                    {{ __('core.action.print') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ── কোন দিন পর্যন্ত ───────────────────────────────────────────
         "কবে থেকে" ঘরটা নেই, ইচ্ছাকৃতভাবে: স্থিতিপত্র একটা মুহূর্তের
         ছবি, পরিসরের হিসাব নয়। ঘরটা থাকলে ব্যবহারকারী ধরে নিতেন
         সংখ্যাগুলো ওই তারিখ থেকে গোনা। --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide">
        <x-ui.field name="as_of" type="date" :label="__('accounts::field.as_of')"
                    :value="$sheet['as_of']" />

        @if ($branches->count() > 1)
            <x-ui.select name="branch_id" :label="__('core.company.branch')"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                         :placeholder="__('accounts::field.all_branches')"
                         :selected="$branchId" />
        @endif

        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
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

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- ── বাঁ পক্ষ · সম্পদ ─────────────────────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-baseline justify-between border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <span>{{ __('accounts::field.assets') }}</span>
                <span class="tabular-nums">{{ $money($sheet['totals']['assets']) }}</span>
            </h2>

            @include('accounts::report.partials.sheet-side', ['groups' => $sheet['assets']])
        </section>

        {{-- ── ডান পক্ষ · দায় ও মূলধন ───────────────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-baseline justify-between border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <span>{{ __('accounts::field.liabilities_and_equity') }}</span>
                <span class="tabular-nums">{{ $money($sheet['totals']['funding']) }}</span>
            </h2>

            @include('accounts::report.partials.sheet-side', ['groups' => $sheet['liabilities']])

            @include('accounts::report.partials.sheet-side', [
                'groups' => $sheet['equity'],
                'extra' => [
                    /*
                     * চলতি বছরের ফলটা মূলধনের শেষ সারি।
                     *
                     * এটা কোনো খাত নয় — হিসাব করা একটা সংখ্যা, আর
                     * সেটাই আগের পর্দাটায় ছিল না বলে দুই পক্ষ কোনোদিন
                     * মিলত না। খাত বানালে বছর বন্ধ করার সময় সংখ্যাটা
                     * দুইবার গোনা হত।
                     */
                    'label' => __('accounts::field.profit_this_year'),
                    'amount' => $sheet['profit'],
                ],
            ])
        </section>
    </div>
</x-layouts.app>
