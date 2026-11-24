{{-- খোলা মজুদের বসানো সারি — পাতার তালিকা-অংশ; বার কেবল প্যানেল থাকলে (তিন ABOS রূপ)। চাই: $entered, $columns, $total, $shownTotal, $showCost, $facetPanel --}}
<section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
    <div class="flex items-baseline justify-between border-b border-(--color-border) px-4 py-3">
        <h2 class="font-semibold">{{ __('inventory::message.opening_total') }}</h2>
        <span class="num font-semibold">{{ $showCost ? \App\Core\Support\Money::format($total) : \App\Core\Security\FieldSecurity::mask() }}</span>
    </div>

    @if ($facetPanel)
    <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('inventory::menu.opening')"
                      :count="trans_choice('core.count.records', $entered->total(), ['count' => $entered->total()])"
                      :search-placeholder="__('inventory::field.product')" :columns="$columns"
                      :sort="['recent' => __('inventory::sort.recent'), 'oldest' => __('inventory::sort.oldest'), 'name' => __('inventory::sort.name')]"
                      :quiet="['rows']" :facets="$facetPanel" />
    </form>
    @endif

    <x-ui.table
        :empty="__('inventory::message.opening_none')"
        :rows="$entered"
        compact
        :columns="$columns" />

    {{-- ⓘ উপরের "মোট" এই পাতার নয়, সবটার — পেজারের সীমা আর
         ওই সংখ্যাটা তাই ইচ্ছাকৃতভাবে দুইটা আলাদা কথা বলে। --}}
    <x-ui.pager :rows="$entered" />
    {{-- ⓘ মোট মূল্য উপরের মতোই সবটার, আর দর লুকানো থাকলে ঢাকা --}}
    <x-ui.list-totals :rows="$entered" :totals="[['label' => __('inventory::message.opening_total'), 'value' => $showCost ? \App\Core\Support\Money::format($shownTotal) : \App\Core\Security\FieldSecurity::mask()]]" />
</section>
