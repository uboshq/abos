@php
    /*
     * কলামগুলো তথ্য হিসেবে, কাঁচা `<tr>` হিসেবে নয়।
     *
     * ── প্রথমবার ভুল ধরনে লেখা হয়েছিল ────────────────────────────────
     * `x-ui.table` একটা `rows` + `columns` নেয় আর নিজেই আঁকে — ডেস্কটপে
     * টেবিল, মোবাইলে কার্ড, একই HTML থেকে। প্রথম খসড়ায় হাতে `<thead>`
     * ও `<tr>` লেখা হয়েছিল, আর পাতাটা ৫০০ দিয়েছিল ("Undefined variable
     * $rows")।
     *
     * শুধু ভাঙাই নয় — হাতে লেখা ছক এই প্রকল্পে নিষিদ্ধ
     * ([[EveryScreenObeysTheThemeTest]]), কারণ তখন মোবাইলের কার্ড-চেহারা
     * আর রূপের ঘনত্ব — দুইটাই হারায়।
     */
    $columns = [
        ['key' => 'product_id', 'label' => __('inventory::field.dish'), 'width' => '18rem',
         'render' => fn ($r) => view('inventory::partials.recipe-dish', ['recipe' => $r])],

        ['key' => 'kind', 'label' => __('inventory::field.recipe_kind'), 'width' => '11rem',
         'render' => fn ($r) => $r->isMadeToOrder()
             ? __('inventory::field.recipe_to_order')
             : __('inventory::field.recipe_batch')],

        ['key' => 'yield_qty', 'label' => __('inventory::field.yield'), 'width' => '7rem',
         'align' => 'end',
         'render' => fn ($r) => rtrim(rtrim((string) $r->yield_qty, '0'), '.')],

        /*
         * উপকরণের সংখ্যা — আর শূন্য হলে সতর্কতার রঙে।
         *
         * একটা উপকরণহীন রেসিপি দেখতে সচল রেসিপির মতোই, অথচ ওই খাবার
         * বেচলে গুদাম থেকে কিছুই কমে না। বিক্রির পথে সেটা আটকানো আছে,
         * কিন্তু ততক্ষণে কাউন্টারে একজন দাঁড়িয়ে আছেন — তালিকায় চোখে
         * পড়লে সমস্যাটা আগেই ধরা পড়ে।
         */
        ['key' => 'lines', 'label' => __('inventory::field.ingredients'), 'width' => '8rem',
         'align' => 'end',
         'render' => fn ($r) => view('inventory::partials.recipe-lines', ['recipe' => $r])],

        ['key' => 'actions', 'label' => __('core.action.edit'), 'width' => '6rem',
         'render' => fn ($r) => view('inventory::partials.recipe-actions', ['recipe' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.recipes') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.recipes')"
                          :count="trans_choice('inventory::message.recipe_count', $recipes->total(), ['count' => $recipes->total()])"
                          :columns="$columns"
                          :search-placeholder="__('inventory::field.recipe_search')"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Inventory\Models\Recipe::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('inventory.recipe.create')">
                            {{ __('inventory::action.new_recipe') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <span>{{ __('inventory::field.recipe_kind') }}</span>
                    <select name="kind"
                            class="min-h-(--spacing-field) rounded-(--radius-field)
                                   border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                        <option value="">{{ __('inventory::field.any_kind') }}</option>
                        <option value="to_order" @selected($kind === 'to_order')>
                            {{ __('inventory::field.recipe_to_order') }}
                        </option>
                        <option value="batch" @selected($kind === 'batch')>
                            {{ __('inventory::field.recipe_batch') }}
                        </option>
                    </select>
                </label>

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('inventory::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_recipes')"
            :rows="$recipes"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$recipes" />
    </div>
</x-layouts.app>
