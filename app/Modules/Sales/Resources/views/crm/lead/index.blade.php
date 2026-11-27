{{--
    লিডের তালিকা — বিক্রয়কর্মী কেবল নিজেরগুলো দেখেন।

    দেয়ালটা কোয়েরিতে ([[Lead::scopeVisibleTo()]]), পর্দায় নয় — তাই এখানে
    কিছু লুকানোর শর্ত নেই। যা আসে, তা দেখার অধিকার আছে বলেই আসে।
--}}
@php
    $columns = [
        ['key' => 'document_no', 'label' => __('sales::crm.lead_no'), 'width' => '9rem',
         'render' => fn ($l) => new \Illuminate\Support\HtmlString(
             '<a class="text-(--color-link) hover:underline" href="'
             . e(route('sales.lead.show', $l->id)) . '">' . e($l->document_no) . '</a>')],
        ['key' => 'name', 'label' => __('sales::crm.lead_name'),
         'render' => fn ($l) => $l->name],
        ['key' => 'phone', 'label' => __('sales::crm.phone'), 'width' => '9rem',
         'render' => fn ($l) => $l->phone ?: '-'],
        ['key' => 'location', 'label' => __('sales::crm.location'), 'width' => '10rem',
         'render' => fn ($l) => $l->location?->name() ?: '-'],
        ['key' => 'owner', 'label' => __('sales::crm.owner'), 'width' => '10rem',
         'render' => fn ($l) => $l->owner?->name ?: '-'],
        ['key' => 'status', 'label' => __('sales::crm.status'), 'width' => '8rem',
         'render' => fn ($l) => view('sales::crm.lead.partials.status', ['lead' => $l])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('status', __('sales::crm.status'),
            collect(\App\Modules\Sales\Models\Lead::STATUSES)
                ->mapWithKeys(fn ($state) => [$state => __('sales::crm.lead_status.' . $state)])->all(),
            __('sales::crm.all_statuses')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::crm.leads') }}</x-slot:title>

    @unless (\App\Core\Support\AbosHead::on())
    <x-slot:header>
        <x-ui.page-header :title="__('sales::crm.leads')" :subtitle="__('sales::crm.leads_note')" />
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :sort="\App\Core\ListFilter\ListSort::labels(['newest', 'oldest'])" :title="__('sales::crm.leads')" :info="\App\Core\Support\AbosHead::on() ? __('sales::crm.leads_note') : null"
                          :columns="$columns"
                          :search-placeholder="__('sales::crm.lead_search')"
                          :export="false" :share="false" :print="false" :facets="$facetPanel">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.lead.create')">
                        {{ __('sales::crm.new_lead') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :view-url="fn ($r) => route('sales.lead.show', $r->id)" :rows="$leads" :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('sales::crm.no_leads')" />

        <x-ui.pager :rows="$leads" />
        <x-ui.list-totals :rows="$leads" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
