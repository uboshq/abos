{{--
    পণ্য তালিকা।

    এখানে মজুদের সংখ্যা দেখানো হয় না, ইচ্ছাকৃতভাবে: এটা পণ্যের মাস্টার,
    আর মজুদ গুদামভেদে আলাদা। একটা "মোট মজুদ" কলাম দিলে ব্যবহারকারী ওটাকে
    "আমার শাখায় কত" ভেবে নিতেন। মজুদের নিজের পর্দা আছে, গুদামের ফিল্টার সহ।
--}}
@php
    /*
     * কলামগুলো একবার, দুই জায়গায়।
     *
     * টেবিল এগুলো এঁকে, আর টুলবারের Columns মেনু এগুলোর নাম দেখিয়ে
     * টিক তোলার সুযোগ দেয়। আগে তালিকাটা কেবল টেবিলের ভেতরে ইনলাইনে
     * ছিল, তাই টুলবার জানতই না কোন কলামগুলো আছে — আর Columns বোতামটা
     * কোনো পর্দাতেই দেখা যেত না।
     *
     * দুই জায়গায় হাতে লিখলে একদিন একটায় কলাম যোগ হত আর অন্যটায় নয়,
     * তাই একটাই তালিকা।
     */
    $columns = [
        [
            'key' => 'code',
            'label' => __('inventory::field.code'),
            'width' => '8rem',
            'render' => fn ($p) => view('inventory::partials.code-link', ['product' => $p]),
        ],
        [
            'key' => 'brand_id',
            'label' => __('inventory::field.brand'),
            'width' => '7rem',
            /*
             * সংযুক্ত ব্র্যান্ড, না থাকলে পুরনো মুক্ত-লেখাটা।
             *
             * ⚠️ `brandRow`, `brand` নয় — `brand` টেবিলের একটা কলামের
             * নামও, আর সম্পর্কের নাম এক হলে Eloquent ওটা ঢেকে দিত
             * (কারণটা মডেলে লেখা)।
             *
             * fallback-টা জরুরি: যে সারিগুলো তালিকা আসার আগে হাতে লেখা
             * হয়েছিল, তাদের `brand_id` নেই কিন্তু নামটা আছে। কেবল
             * সম্পর্কটা দেখালে ওই সারিগুলো খালি দেখাত — আর সেটা
             * "ডেটা হারিয়ে গেছে" বলে পড়া হত।
             */
            'render' => fn ($p) => $p->brandRow?->name() ?? $p->brand,
        ],
        [
            'key' => 'name_en',
            'label' => __('inventory::field.name'),
            'width' => '13rem',
            'render' => fn ($p) => $p->name(),
        ],
        ['key' => 'barcode', 'label' => __('inventory::field.barcode'), 'width' => '8rem'],
        [
            'key' => 'category_id',
            'label' => __('inventory::field.category'),
            'width' => '7rem',
            'render' => fn ($p) => $p->categoryRow?->name() ?? $p->category,
        ],
        [
            'key' => 'unit_id',
            'label' => __('inventory::field.unit'),
            'width' => '4.5rem',
            'render' => fn ($p) => $p->unit?->name(),
        ],
        [
            'key' => 'purchase_price',
            'label' => __('inventory::field.purchase_price'),
            'numeric' => true,
            'width' => '6.5rem',
            'render' => fn ($p) => \App\Core\Support\Money::format($p->purchase_price),
        ],
        [
            'key' => 'sale_price',
            'label' => __('inventory::field.sale_price'),
            'numeric' => true,
            'width' => '6.5rem',
            'render' => fn ($p) => \App\Core\Support\Money::format($p->sale_price),
        ],
        /*
         * মার্জিন ও মার্কআপ — সংরক্ষিত নয়, প্রতিবার হিসাব করা।
         *
         * সূত্র দুইটা [[Margin]]-এ, আর সেখানেই একমাত্র জায়গা: তালিকা ও
         * ফর্ম দুই জায়গায় আলাদা করে লিখলে একদিন একটা বদলাত আর অন্যটা
         * পুরনো সূত্রে থেকে যেত — একই পণ্যে দুই পর্দায় দুই সংখ্যা।
         *
         * ⚠️ `null` আর `0` এক জিনিস নয়, আর তালিকায় আলাদা দেখাতেই হবে:
         *
         *     null  →  "—"   হিসাবই করা যায় না (ভাগের নিচে শূন্য)
         *     0     →  "০%"  হিসাব হয়েছে, লাভ নেই
         *
         * একটাকে অন্যটা দেখালে "দর বসানোই হয়নি" আর "দরে লাভ নেই" —
         * এই দুইটা আলাদা অবস্থা এক দেখাত, আর মালিক ভুল পণ্যটার পিছনে
         * সময় দিতেন।
         *
         * তাই null যাচাইটা আগে — `(float) null` = 0, অর্থাৎ চেক না করলে
         * "হিসাব করা যায় না" নীরবে "০%" হয়ে যেত।
         */
        [
            'key' => 'margin',
            'label' => __('inventory::field.margin'),
            'numeric' => true,
            'width' => '5rem',
            'render' => function ($p) {
                $v = \App\Modules\Inventory\Support\Margin::margin($p->purchase_price, $p->sale_price);

                return $v === null ? '—' : \App\Core\Support\Money::format($v).'%';
            },
        ],
        [
            'key' => 'markup',
            'label' => __('inventory::field.markup'),
            'numeric' => true,
            'width' => '5rem',
            'render' => function ($p) {
                $v = \App\Modules\Inventory\Support\Margin::markup($p->purchase_price, $p->sale_price);

                return $v === null ? '—' : \App\Core\Support\Money::format($v).'%';
            },
        ],
        [
            'key' => 'is_active',
            'label' => __('inventory::field.state'),
            'width' => '5.5rem',
            'render' => fn ($p) => view('inventory::partials.state-badge', ['record' => $p]),
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '3.5rem',
            'render' => fn ($p) => view('inventory::partials.product-actions', ['product' => $p]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.products') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.products')" :count="trans_choice('inventory::message.count', $products->total(), ['count' => $products->total()])"
                :search-placeholder="__('inventory::message.search_placeholder')"
                :sort="$sortOptions"
                :columns="$columns"
                view>
        <x-slot:actions>
            @can('create', \App\Modules\Inventory\Models\Product::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('inventory.product.create')">
                        {{ __('inventory::action.new_product') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
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
            :columns="$columns" />

        <x-ui.pager :rows="$products" />
    </div>
</x-layouts.app>
