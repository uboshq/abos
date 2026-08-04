{{--
    পণ্য তালিকা।

    এখানে মজুদের সংখ্যা দেখানো হয় না, ইচ্ছাকৃতভাবে: এটা পণ্যের মাস্টার,
    আর মজুদ গুদামভেদে আলাদা। একটা "মোট মজুদ" কলাম দিলে ব্যবহারকারী ওটাকে
    "আমার শাখায় কত" ভেবে নিতেন। মজুদের নিজের পর্দা আছে, গুদামের ফিল্টার সহ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.products') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('inventory::menu.products')"
            :subtitle="trans_choice('inventory::message.count', $products->total(), ['count' => $products->total()])">
            <x-slot:actions>
                @can('create', \App\Modules\Inventory\Models\Product::class)
                    <x-ui.button tone="primary" icon="+" :href="route('inventory.product.create')">
                        {{ __('inventory::action.new_product') }}
                    </x-ui.button>
                @endcan
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :search-placeholder="__('inventory::message.search_placeholder')"
                :sort="$sortOptions"
                view>
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('inventory::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.none_yet')"
            :rows="$products"
            :compact="request()->boolean('compact')"
            :grid="request('view') === 'grid'"
            :columns="[
                [
                    'key' => 'code',
                    'label' => __('inventory::field.code'),
                    'width' => '13rem',
                    'render' => fn ($p) => view('inventory::partials.code-link', ['product' => $p]),
                ],
                [
                    'key' => 'name_en',
                    'label' => __('inventory::field.name'),
                    'width' => '20rem',
                    'render' => fn ($p) => $p->name(),
                ],
                ['key' => 'barcode', 'label' => __('inventory::field.barcode'), 'width' => '10rem'],
                [
                    'key' => 'unit_id',
                    'label' => __('inventory::field.unit'),
                    'width' => '7rem',
                    'render' => fn ($p) => $p->unit?->name(),
                ],
                [
                    'key' => 'sale_price',
                    'label' => __('inventory::field.sale_price'),
                    'numeric' => true,
                    'width' => '9rem',
                    'render' => fn ($p) => number_format((float) $p->sale_price, 2),
                ],
                [
                    'key' => 'is_active',
                    'label' => __('inventory::field.state'),
                    'width' => '7rem',
                    'render' => fn ($p) => view('inventory::partials.state-badge', ['record' => $p]),
                ],
            ]" />

        @if ($products->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $products->links() }}</div>
        @endif
    </div>
</x-layouts.app>
