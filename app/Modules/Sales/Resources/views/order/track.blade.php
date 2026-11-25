{{--
    আদেশ কোথায় দাঁড়িয়ে।

    ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    *"এখানে Order Tracking-এর ব্যবস্থা করতে হবে… অ্যাপ ১০০% হওয়ার পর সব
    customer তার নিজের, employee তার অধীনের সকল order ট্রেস করতে পারবে।"*

    ⓘ আজকের পাতাটা কর্মীদের, আর সত্যিকারের: প্রতিটা আদেশ কোন ধাপে আছে,
    কতটা মাল গেছে, বিল হয়েছে কি না। ⚠️ গ্রাহকের নিজের আদেশ দেখার পথটা
    পরের ধাপ — কিন্তু "আসছে" লেখা খালি বোতাম এখানে বসে না
    ([[SixButtonsThatOnlySaidComingSoonTest]])।
--}}
@php
    $money = fn ($n) => \App\Core\Support\Money::format((string) $n);
    $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.');

    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($o) => \App\Core\Support\DateFormat::format($o->trx_date)],

        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '10rem',
         'render' => fn ($o) => new \Illuminate\Support\HtmlString(
             '<a class="text-(--color-link)" href="'.e(route('sales.order.show', $o->id)).'">'
             .e($o->document_no).'</a>')],

        /* ⭐ গ্রাহকের নামও লিংক — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬:
           *"sob jaygay hyper link dewar kotha"*। ⓘ আদেশ দেখতে দেখতে
           প্রশ্নটা ওঠে *"এই গ্রাহকের বাকি কত"*, আর উত্তরটা ওদের পাতায়। */
        ['key' => 'customer', 'label' => __('sales::field.customer'),
         'render' => fn ($o) => $o->customer === null
             ? '—'
             : new \Illuminate\Support\HtmlString(
                 '<a class="text-(--color-link)" href="'.e(route('customer.show', $o->customer->id)).'">'
                 // ⭐ "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম)
                 .e($o->customer->nameWithPoint()).'</a>')],

        ['key' => 'total', 'total' => 'money', 'label' => __('sales::field.total'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($o) => $money($o->total)],

        /* ⓘ "কতটা গেছে" — পরিমাণে, টাকায় নয়: আদেশ মালের হিসাব, আর
           আংশিক চালানে টাকার হিসাব বিভ্রান্ত করত। */
        ['key' => 'moved', 'label' => __('sales::field.delivered_of_ordered'),
         'numeric' => true, 'width' => '10rem',
         'render' => fn ($o) => $trim($o->delivered_total).' / '.$trim($o->ordered_total)],

        ['key' => 'stage', 'label' => __('sales::field.stage'), 'width' => '10rem',
         'render' => fn ($o) => view('sales::order.partials.stage', [
             'stage' => $tracking->stageOf($o),
         ])],

        ['key' => 'do', 'label' => __('core.table.actions'), 'width' => '7rem',
         'render' => fn ($o) => new \Illuminate\Support\HtmlString(
             '<a class="text-(--color-link)" href="'.e(route('sales.order.show', $o->id)).'">'
             .e(__('core.action.view')).'</a>')],
    ];

    $tabs = [
        'all' => __('sales::field.stage_all'),
        \App\Modules\Sales\Services\OrderTracking::PLACED => __('sales::field.stage_placed'),
        \App\Modules\Sales\Services\OrderTracking::PARTIAL => __('sales::field.stage_partial'),
        \App\Modules\Sales\Services\OrderTracking::DELIVERED => __('sales::field.stage_delivered'),
        \App\Modules\Sales\Services\OrderTracking::BILLED => __('sales::field.stage_billed'),
    ];

    $tabList = [];
    foreach ($tabs as $key => $label) {
        $tabList[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0, 'active' => $stage === $key,
            'url' => route('sales.order.track', array_filter([...request()->except(['stage', 'page']), 'stage' => $key === 'all' ? null : $key]))];
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.order_track') }}</x-slot:title>

    {{-- ধাপের ট্যাব — পাশে কয়টা; ছাঁকনি খোলা থাকলে ট্যাবেও থাকে --}}
    <x-ui.list-tabs :tabs="$tabList" :label="__('sales::menu.order_track')" />

    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('sales::filter.order_noun')">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($stage !== 'all')
                <input type="hidden" name="stage" value="{{ $stage }}">
            @endif

            @if ($customer !== null)
                <input type="hidden" name="customer" value="{{ $customer->id }}">
            @endif

            {{-- ⭐ বাকি সব তালিকার মতোই গড়ন (১৯ সেপ্টেম্বর ২০২৬) --}}
            <x-ui.toolbar :title="__('sales::menu.order_track')"
                          :subtitle="$customer?->nameWithPoint() ?? __('sales::message.order_track_note')"
                          :columns="$columns" :facets="$filters"
                          :search-placeholder="__('sales::message.order_track_search')"
                          :quiet="['stage', 'customer']" />
        </form>

        <x-ui.table :rows="$orders"
                    :grand="$grand ?? []"
                    :columns="$columns"
                    :compact="\App\Core\Support\Ui::compact()"
                    :empty="filled(request('q')) ? __('core.empty.no_results') : __('sales::message.no_orders_to_track')" />
    </div>

    <div class="mt-3">{{ $orders->links() }}</div>
    <x-ui.list-totals :rows="$orders" :grand="$grand ?? []" :columns="$columns" />
    </x-ui.filter-layout>
</x-layouts.app>
