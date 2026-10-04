{{--
    সুযোগের তালিকা — পাইপলাইনের "বাকিগুলো দেখুন" এখানে আসে, ধাপের ছাঁকনিসহ।
--}}
@php
    $columns = [
        ['key' => 'document_no', 'label' => __('sales::crm.opportunity_no'), 'width' => '9rem',
         'render' => fn ($o) => new \Illuminate\Support\HtmlString(
             '<a class="text-(--color-link) hover:underline" href="'
             . e(route('sales.opportunity.show', $o->id)) . '">' . e($o->document_no) . '</a>')],
        ['key' => 'title', 'label' => __('sales::crm.title'),
         'render' => fn ($o) => $o->title],
        ['key' => 'party', 'label' => __('sales::crm.party'),
         'render' => fn ($o) => $o->partyName() ?: '-'],
        ['key' => 'stage', 'label' => __('sales::crm.stage'), 'width' => '9rem',
         'render' => fn ($o) => $o->stage?->name() ?: '-'],
        ['key' => 'value', 'label' => __('sales::crm.estimated_value'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($o) => \App\Core\Support\Money::format($o->estimated_value)],
        ['key' => 'probability', 'label' => __('sales::crm.probability'), 'numeric' => true, 'width' => '6rem',
         'render' => fn ($o) => $o->probability . '%'],
        ['key' => 'close', 'label' => __('sales::crm.expected_close_date'), 'width' => '9rem',
         'render' => fn ($o) => \App\Core\Support\DateFormat::format($o->expected_close_date) ?: '-'],
        ['key' => 'salesperson', 'label' => __('sales::crm.salesperson'), 'width' => '10rem',
         'render' => fn ($o) => $o->salesperson?->name ?: '-'],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::crm.opportunities') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::crm.opportunities')" :subtitle="__('sales::crm.opportunities_note')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('sales.opportunity.pipeline')">
                    {{ __('sales::crm.pipeline') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::crm.opportunities')"
                          :columns="$columns"
                          :search-placeholder="__('sales::crm.opportunity_search')"
                          :export="false" :share="false" :print="false">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.opportunity.create')">
                        {{ __('sales::crm.new_opportunity') }}
                    </x-ui.button>
                </x-slot:actions>

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <select name="stage"
                            class="h-(--spacing-field) rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">{{ __('sales::crm.all_stages') }}</option>
                        @foreach ($stages as $id => $name)
                            <option value="{{ $id }}" @selected((string) $stage === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$opportunities" :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('sales::crm.no_opportunities')" />

        <x-ui.pager :rows="$opportunities" />
    </div>
</x-layouts.app>
