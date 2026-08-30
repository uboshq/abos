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

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.income')"
                          :subtitle="__('finance::message.income_note')" />
    </x-slot:header>

    {{-- সময়ের পরিসর — ডিফল্ট চলতি মাস, খরচের পর্দার মতোই একই কারণে --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 print-hide">
        <x-ui.field name="from" type="date" :label="__('finance::field.from')" :value="$from" />
        <x-ui.field name="to" type="date" :label="__('finance::field.to')" :value="$to" />

        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
    </form>

    {{-- ── বিক্রয় বনাম বিক্রয় ছাড়া ──────────────────────────────────── --}}
    <section data-boxed class="mb-4 grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['finance::field.income_from_sales', $totals['sales'], false],
            ['finance::field.income_not_from_sales', $totals['other'], true],
            ['finance::field.income_all', $totals['all'], false],
        ] as [$label, $value, $highlight])
            <div @class([
                'rounded-(--radius-card) border bg-(--color-surface-card) p-4',
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
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.by_head') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_income_yet')"
            :rows="$heads"
            :columns="[
                ['key' => 'head', 'label' => __('finance::field.head'),
                 'render' => fn ($r) => $r['account']->name()],
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
            ]" />
    </section>
</x-layouts.app>
