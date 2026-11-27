{{--
    মূল্য তালিকা — প্রতিটা পণ্যের বিক্রয়-দাম, সারি থেকেই বদলানো যায় (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।

    ⓘ "এডিট" একটা <details> — চাপলে ঐ সারিতেই দামের ঘর খোলে। JavaScript লাগে না,
    তাই CSP-Alpine বা বান্ডিল কোনোটার উপর নির্ভর নেই।
    ⭐ পণ্যের নামটাই লিংক (নিয়ম ১), আর "ইতিহাস" দেখায় কে কবে কত থেকে বদলাল।
--}}
@php
    use App\Core\Security\FieldSecurity;
    use App\Core\Support\Money;
    use App\Modules\Inventory\Models\Product;
    use Illuminate\Support\HtmlString;

    $link = fn ($p) => new HtmlString('<a href="'.e(route('inventory.product.show', $p)).'"'
        .' class="text-(--color-brand-500) underline-offset-2 hover:underline">'.e($p->name()).'</a>');

    $columns = [
        ['key' => 'code', 'label' => __('sales::price_list.code'), 'width' => '8rem', 'render' => fn ($p) => $p->code],
        ['key' => 'product', 'label' => __('sales::price_list.product'), 'render' => $link],
        ['key' => 'unit', 'label' => __('sales::price_list.unit'), 'width' => '6rem', 'render' => fn ($p) => $p->unit?->name() ?? '—'],
    ];

    // ⛔ ক্রয়মূল্য কেবল তার ঘোষিত চাবিতে (inventory.cost.view) — বিক্রয়ের খরচের চাবি যথেষ্ট নয়
    $showCost = FieldSecurity::visible(Product::class, 'purchase_price');

    if ($showCost) {
        $columns[] = ['key' => 'cost', 'label' => __('sales::price_list.cost'), 'numeric' => true,
            'render' => fn ($p) => Money::format((string) $p->purchase_price)];
    }

    $columns[] = ['key' => 'price', 'label' => __('sales::price_list.price'), 'numeric' => true,
        'render' => fn ($p) => Money::format((string) $p->sale_price)];

    $columns[] = ['key' => 'changed', 'label' => __('sales::price_list.changed'),
        'render' => fn ($p) => isset($last[$p->id])
            ? \App\Core\Support\DateFormat::format(\Illuminate\Support\Carbon::parse($last[$p->id]->when)).($last[$p->id]->user ? ' · '.$last[$p->id]->user : '')
            : __('sales::price_list.never')];

    $columns[] = ['key' => 'actions', 'label' => '', 'width' => '16rem',
        'render' => fn ($p) => view('sales::price_list.partials.row-actions', ['product' => $p, 'canEdit' => $canEdit])];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]), এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([\App\Core\ListFilter\Facets::pick('category', __('inventory::field.category'), $categories)]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::price_list.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('sales::price_list.title')" :subtitle="__('sales::price_list.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::price_list.search') }}</span>
                        <input type="search" name="q" value="{{ $q }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    <x-ui.errors />

    <x-ui.filter-layout :filters="$abosHead ? $facetPanel : \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        {{-- ⭐ সাধারণ টুলবার (মালিক, ৩ অক্টোবর ২০২৬: "sob jaygay toolbar dibe"); খোঁজার ঘর নেই — এই পাতার নিয়ন্ত্রক খোঁজে না --}}
        @if ($abosHead)
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :sort="\App\Core\ListFilter\ListSort::labels(['name_az', 'name_za', 'code', 'amount_high', 'amount_low'])" :title="__('sales::price_list.title')" :info="__('sales::price_list.subtitle').' '.__('sales::price_list.history_note')"
                              :search-placeholder="__('sales::price_list.search')" :columns="$columns" :facets="$facetPanel" />
            </form>
        @else
            <x-ui.toolbar :title="__('sales::price_list.title')" :search="false" :filter="false" :columns="$columns" />
        @endif

        <x-ui.table :rows="$products" :columns="$columns" :empty="__('sales::price_list.no_products')" />
    </div>

    @unless ($abosHead)
        <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::price_list.history_note') }}</p>
    @endunless

    <x-ui.pager :rows="$products" />
    <x-ui.list-totals :rows="$products" />
    </x-ui.filter-layout>
</x-layouts.app>
