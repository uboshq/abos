{{--
    ⭐ সম্পদের শ্রেণি — তালিকা (স্থায়ী সম্পদ ধাপ ১, মালিক, ১০ অক্টোবর ২০২৬)।

    ⓘ প্রতিটা শ্রেণি একটা ছাঁচ: পাঁচ খাত আর ডিফল্ট আয়ু। কতগুলো সম্পদ এই শ্রেণিতে, সেটাও সারিতে — শূন্য শ্রেণি
    বন্ধ করে দেওয়া নিরাপদ, ভরা শ্রেণি বদলালে কেবল নতুন সম্পদে খাটে।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('accounts::field.code'), 'width' => '8rem',
            'render' => fn ($c) => view('accounts::asset.category.partials.link', ['category' => $c])],
        ['key' => 'name', 'label' => __('accounts::asset.category'), 'render' => fn ($c) => $c->name()],
        ['key' => 'account', 'label' => __('accounts::asset.account'), 'render' => fn ($c) => $c->assetAccount?->label()],
        ['key' => 'method', 'label' => __('accounts::asset.method'), 'width' => '10rem',
            'render' => fn ($c) => __('accounts::asset.'.$c->method)],
        ['key' => 'life', 'label' => __('accounts::asset.life_months'), 'numeric' => true, 'width' => '7rem',
            'render' => fn ($c) => $c->life_months ?? '—'],
        ['key' => 'residual', 'label' => __('accounts::asset.residual_percent'), 'numeric' => true, 'width' => '7rem',
            'render' => fn ($c) => rtrim(rtrim((string) $c->residual_percent, '0'), '.').'%'],
        ['key' => 'assets_count', 'label' => __('accounts::asset.category_assets'), 'numeric' => true, 'width' => '7rem',
            'render' => fn ($c) => $c->assets_count],
        ['key' => 'active', 'label' => __('accounts::asset.status'), 'width' => '7rem',
            'render' => fn ($c) => $c->is_active ? __('accounts::asset.category_on') : __('accounts::asset.category_off')],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.asset_categories') }}</x-slot:title>

    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::menu.asset_categories')"
                          :subtitle="__('accounts::asset.category_subtitle')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.asset.category.create')">
                        {{ __('accounts::asset.category_new') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$categories" :columns="$columns" :empty="__('accounts::asset.category_empty')" />
    </div>

    <div class="mt-3">{{ $categories->links() }}</div>
    <x-ui.list-totals :rows="$categories" :columns="$columns" />
</x-layouts.app>
