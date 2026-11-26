{{--
    পরিশোধের সময়সূচি — অর্থের মানচিত্র §৬। শিরোনাম · বর্ণনা → ভাগের ট্যাব
    (প্রতিটায় কয়টা আর কত) → বিলের তালিকা, শেষ তারিখ ধরে।
    ⓘ কেন ক্রয়ে: [[PaymentScheduleController]]-এর মাথায়।
--}}
@php
    use App\Core\Support\Money;

    // ⓘ প্রস্তাবের ঘর কলামের আঁকায় লাগে — তাই কলামের আগেই (Mac run of 8a4037f6c: নিচে সংজ্ঞা থাকায় ৫০০)
    $proposing = $accounts->isNotEmpty();
    $columns = [
            ['key' => 'due', 'label' => __('purchase::schedule.due_on'), 'width' => '9rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.when',
                 ['bill' => $bill, 'today' => $today])],
            ['key' => 'bill', 'label' => __('purchase::schedule.bill'), 'width' => '10rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.bill', ['bill' => $bill])],
            ['key' => 'supplier', 'label' => __('purchase::schedule.supplier'),
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.supplier', ['bill' => $bill])],
            ['key' => 'total', 'total' => 'money', 'label' => __('purchase::schedule.total'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($bill) => Money::format($bill->total)],
            ['key' => 'amount', 'total' => 'money', 'raw' => fn ($bill) => $bill->dueAmount(), 'label' => __('purchase::schedule.due'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($bill) => Money::format($bill->dueAmount())],
            ['key' => 'propose', 'label' => __('purchase::schedule.propose'), 'width' => '9rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.propose', ['bill' => $bill, 'proposing' => $proposing])],
            ['key' => 'pay', 'label' => '', 'width' => '7rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.pay', ['bill' => $bill])],
    ];
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো — ভাগগুলো বারের সারিতে পিল হয়ে ওঠে
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([\App\Core\ListFilter\Facets::dates(__('purchase::schedule.due_on'))]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::schedule.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('purchase::schedule.title')"
                          :subtitle="__('purchase::schedule.subtitle')" />
    </x-slot:header>
    @endunless

    @if ($abosHead)
        <x-ui.list-tabs :label="__('purchase::schedule.title')" :tabs="collect(\App\Modules\Purchase\Http\Controllers\PaymentScheduleController::TABS)->map(fn ($key) => [
            'key' => $key, 'label' => __('purchase::schedule.'.$key), 'count' => $buckets[$key]['count'],
            'hint' => Money::format($buckets[$key]['amount']),
            'url' => route('purchase.payment_schedule.index', $key === 'all' ? [] : ['tab' => $key]), 'active' => $tab === $key,
        ])->all()" />
    @else
    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('purchase::schedule.title') }}">
        @foreach (\App\Modules\Purchase\Http\Controllers\PaymentScheduleController::TABS as $key)
            @php $on = $tab === $key; @endphp
            <a href="{{ route('purchase.payment_schedule.index', $key === 'all' ? [] : ['tab' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) flex-col justify-center border-b-2 px-3 py-1
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}
                      {{ $key === 'overdue' && $buckets[$key]['count'] > 0 ? 'text-(--color-badge-danger-ink)' : '' }}">
                <span>{{ __('purchase::schedule.'.$key) }} · {{ $buckets[$key]['count'] }}</span>
                <span class="text-2xs tabular-nums">{{ Money::format($buckets[$key]['amount']) }}</span>
            </a>
        @endforeach
    </nav>
    @endif

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ⭐ সাধারণ টুলবার (মালিক, ৩ অক্টোবর ২০২৬: "sob jaygay toolbar dibe") — ভাগটা লুকানো ঘরে;
             ⓘ খোঁজার ঘর নেই: সূচির নিয়ন্ত্রক খোঁজে না, আর কাজ না করা খোঁজার ঘর একটা মরা বোতাম --}}
        <form id="list-form" method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($abosHead)
                <x-ui.toolbar :title="__('purchase::schedule.title')" :info="__('purchase::schedule.subtitle')" :quiet="['tab']"
                              :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                              :search-placeholder="__('purchase::schedule.bill').', '.__('purchase::schedule.supplier')" :facets="$facetPanel" :columns="$columns"
                              :sort="['due' => __('purchase::schedule.due_on'), 'largest' => __('purchase::schedule.total')]" />
            @else
                <x-ui.toolbar :title="__('purchase::schedule.title')" :search="false" :filter="false"
                              :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])" />
            @endif
        </form>

        {{-- ⭐ পরিশোধের প্রস্তাব (ধাপ খ ১১, ৭ অক্টোবর ২০২৬) — প্রতি সারিতে অঙ্ক, খালি মানে বাছা নয়; JS ছাড়া --}}
        @if ($proposing)
        <form method="POST" action="{{ route('purchase.payment_schedule.propose') }}" data-payment-proposal>
            @csrf
        @endif

        <x-ui.table :rows="$rows" :empty="__('purchase::schedule.none')"
            :grand="$grand ?? []"
            :view-url="fn ($bill) => route('purchase.bill.show', $bill)"
            :columns="$columns" />

        @if ($proposing && $rows->total() > 0)
            <div class="flex flex-wrap items-end gap-3 border-t border-(--color-border) p-3" data-proposal-footer>
                <x-ui.select name="account_id" :label="__('purchase::field.account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()])" placeholder="-" required />
                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')" :value="now()->toDateString()" />
                <x-ui.button type="submit" tone="primary">{{ __('purchase::schedule.make_proposal') }}</x-ui.button>
                <p class="text-xs text-(--color-ink-muted)">{{ __('purchase::schedule.proposal_hint') }}</p>
            </div>
        @endif
        @if ($proposing)
        </form>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" :totals="[
            ['label' => __('purchase::schedule.total'), 'value' => Money::format((string) ($grand['total'] ?? '0'))],
            ['label' => __('purchase::schedule.due'), 'value' => Money::format((string) ($grand['amount'] ?? '0')), 'tone' => 'bad'],
        ]" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
