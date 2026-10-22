{{--
    মালিকানা-স্বত্বের পরিবর্তনের বিবরণী — শুরুর জের থেকে শেষের জের, প্রতিটা অংশ নিজের কলামে (IAS 1.106; ERP ১০০%-এর হিসাবের
    ফাঁক #৬, ২০ অক্টোবর ২০২৬; [[BalanceSheetService::equityStatement()]])।

    ── কেন কলামে অংশ, সারিতে চলাচল ────────────────────────────────────
    IAS 1.106-এর সাধারণ ছক: প্রতিটা অংশ (মূলধন, উত্তোলন, সঞ্চিত মুনাফা, বন্ধ না হওয়া লাভ) উপর থেকে নিচে পড়লে শুরু থেকে শেষে
    পৌঁছায়, আর ডানের মোট কলাম স্থিতিপত্রের মোট মালিকানা। বছর শেষের স্থানান্তর এক কলামে বাড়ে, অন্যটায় কমে — সারির মোট শূন্য,
    যা দেখায় বছর বন্ধ মালিকানা বদলায় না, কেবল ঘর বদলায়।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $rows = [
        'opening' => __('accounts::field.equity_opening'),
        'profit' => __('accounts::field.equity_profit'),
        'transfer' => __('accounts::field.equity_transfer'),
        'other' => __('accounts::field.equity_other'),
        'closing' => __('accounts::field.equity_closing'),
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.equity_statement') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.equity_statement')"
                          :subtitle="\App\Core\Support\DateFormat::format($statement['from']).' – '.\App\Core\Support\DateFormat::format($statement['to'])">
            <x-slot:actions>
                <x-ui.button tone="secondary" icon="print" data-action="print">
                    {{ __('core.action.print') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide">
        <x-ui.field name="from" type="date" :label="__('accounts::field.equity_from')" :value="$statement['from']" />
        <x-ui.field name="to" type="date" :label="__('accounts::field.equity_to')" :value="$statement['to']" />

        @if ($branches->count() > 1)
            <x-ui.select name="branch_id" :label="__('core.company.branch')"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                         :placeholder="__('accounts::field.all_branches')"
                         :selected="$branchId" />
        @endif

        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
    </form>

    @if ($branchId !== null)
        <p role="note" class="mb-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                              text-sm text-(--color-badge-pending-ink)">
            {{ __('accounts::message.branch_sheet_may_not_agree') }}
        </p>
    @endif

    {{-- শুরু + চলাচল = শেষ — না মিললে পাতা লুকায় না, বলে --}}
    <p role="status" data-equity-agrees="{{ $statement['agrees'] ? '1' : '0' }}"
       @class(['mb-4 rounded-(--radius-card) border px-4 py-3 font-semibold',
           'border-(--color-border) bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $statement['agrees'],
           'border-(--color-danger) bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => ! $statement['agrees']])>
        {{ $statement['agrees'] ? __('accounts::field.equity_agrees') : __('accounts::field.equity_does_not_agree') }}
    </p>

    <section data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ⓘ `ui-grid` — ঘরের মাপ আর ধার থিমের টোকেন থেকে (EveryScreenObeysTheThemeTest), নিজের px/py নয়; সংখ্যার ঘরে `num` --}}
        <table class="ui-grid">
            <thead>
                <tr>
                    <th scope="col" class="text-start">{{ __('accounts::field.equity_component') }}</th>
                    @foreach ($statement['columns'] as $column)
                        <th scope="col" class="num">{{ $column['label'] }}</th>
                    @endforeach
                    <th scope="col" class="num">{{ __('accounts::field.equity_total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $key => $label)
                    <tr data-row="{{ $key }}" @class(['border-t border-(--color-border)', 'font-semibold border-t-2' => in_array($key, ['opening', 'closing'], true)])>
                        <th scope="row" class="text-start font-medium">{{ $label }}</th>
                        @foreach ($statement['columns'] as $column)
                            <td @class(['num',
                                'text-(--color-badge-danger-ink)' => bccomp($column[$key], '0', 4) < 0])>{{ $money($column[$key]) }}</td>
                        @endforeach
                        <td data-total class="num font-semibold">{{ $money($statement['total'][$key]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
