{{--
    খোলা মজুদ — পুরনো খাতা থেকে আসার দিনের একবারের কাজ।

    ── কেন ফর্মের পাশে বসানো তালিকাটা ─────────────────────────────────
    খোলা মজুদ বসানো হয় একদিনে নয়, কয়েকদিন ধরে — পণ্য ধরে ধরে, গুদাম ধরে
    ধরে। মাঝপথে "কোনটা করা হয়ে গেছে" প্রশ্নটা বারবার আসে, আর উত্তরটা
    হাতের কাছে না থাকলে একই পণ্য দুইবার বসানোর চেষ্টা হয়।

    সার্ভার সেটা আটকায় ঠিকই, কিন্তু আটকানো আর জানানো এক জিনিস নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.opening') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.opening')" />
    </x-slot:header>

    {{-- ⭐ আগে বসানো খোলা মজুদে প্রিন্সিপাল বসানোর পাতা (মালিক, ৬ অক্টোবর ২০২৬) --}}
    <p class="mb-3 text-sm">
        <a href="{{ route('inventory.stock.opening.principal') }}" class="text-(--color-brand-600) hover:underline" data-opening-principal-link>
            {{ __('inventory::message.opening_principal_link') }}
        </a>
    </p>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-4">

        {{--
            ⭐ খোলা মজুদের কার্ট — মালিক, ৬ অক্টোবর ২০২৬: *"এভাবে না দিয়ে পাশাপাশি করে দিলে হতো না"*।
            ⓘ উপরে একবার গুদাম, তারিখ, বিবরণ; নিচে প্রতি সারি এক পণ্য। এক চাপে সব সারি, এক লেনদেনে
            ([[OpeningStockController::storeMany()]]); একটা সারি ভুল হলে কোনোটাই বসে না, আর ভুল সারিটা লাল।
            ⭐ দ্বিতীয় ধাপ, একই দিন — মালিক: সার্চ বার (কোড, নাম, বারকোড), পরিমাণের পাশে ফ্রি, দর-মার্কআপ-মার্জিন-বিক্রয়মূল্য
            (যেকোনো একটা লিখলে বাকিগুলো), লট খালি = "Opening", Enter = পরের ঘর, সারির মূল্য আর মোট সাথে সাথে।
            ⓘ যুক্তিটা [[opening-cart.js]]-এ, এখানে কেবল নাম ডাকা (CSP-Alpine)।
        --}}
        @php
            $cartRows = max(15, min(200, (int) request('rows', 15)), count((array) old('rows', [])));
            $bad = collect($errors->keys())
                ->map(fn ($k) => preg_match('/^rows\.(\d+)\./', $k, $m) === 1 ? (int) $m[1] : null)
                ->filter(fn ($v) => $v !== null)->unique()->values()->all();
            $mainStore = $warehouses->firstWhere('is_default', true)?->id ?? ($warehouses->count() === 1 ? $warehouses->first()->id : null);

            // ⓘ দর লুকানো থাকলে তালিকায় পণ্যের কেনা দর যায় না — লেখা যায়, দেখা যায় না (অডিট ম১২)
            $cartShowsCost = \App\Core\Security\FieldSecurity::visible(\App\Modules\Inventory\Models\StockMovement::class, 'unit_cost');
            $catalogue = $products->map(fn ($p) => [
                'id' => $p->id,
                'code' => (string) $p->code,
                'name' => (string) $p->name(),
                'barcode' => (string) ($p->barcode ?? ''),
                'find' => mb_strtolower($p->code.' '.$p->name_en.' '.$p->name_bn.' '.($p->barcode ?? '')),
                'rate' => $cartShowsCost && $p->purchase_price !== null ? (string) $p->purchase_price : '',
                'sales_price' => $p->sale_price !== null ? (string) $p->sale_price : '',
                'pricing_anchor' => (string) ($p->pricing_anchor ?? ''),
                'pricing_pct' => $p->pricing_pct !== null ? (string) $p->pricing_pct : null,
            ])->values()->all();
            $seed = collect((array) old('rows', []))->map(fn ($r, $i) => array_merge((array) $r, [
                'error' => collect($errors->get("rows.$i.*"))->flatten()->implode(' '),
            ]))->all();
        @endphp
        <section data-boxed data-opening-cart class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('inventory::message.opening_cart_title') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.opening_note') }}
                <a href="{{ route('system_admin.import.index') }}" class="ms-2 text-(--color-brand-600) hover:underline" data-opening-import>
                    {{ __('inventory::message.opening_cart_import') }}
                </a>
            </p>

            @if ($bad !== [])
                <p role="alert" class="mb-3 text-sm font-medium text-(--color-badge-danger-ink)">{{ __('inventory::message.opening_cart_row_error') }}</p>
            @endif

            <form method="POST" action="{{ route('inventory.stock.opening.cart') }}"
                  x-data="openingCart({ products: @js($catalogue), rows: @js((object) $seed), count: @js($cartRows) })">
                @csrf

                <div class="mb-3 grid gap-3 sm:grid-cols-3">
                    <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                                 :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                                 :selected="old('warehouse_id', $mainStore)"
                                 placeholder="-" required />
                    <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                                :value="old('trx_date', now()->toDateString())" />
                    <x-ui.field name="narration" :label="__('inventory::field.narration')" :value="old('narration')" />
                </div>

                <div class="overflow-x-auto">
                    <table class="ui-list w-full text-sm">
                        <thead>
                            <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                                <th class="w-8">#</th>
                                <th class="min-w-56">{{ __('inventory::field.product') }}</th>
                                <th class="w-20 text-right">{{ __('inventory::field.quantity') }}</th>
                                <th class="w-16 text-right">{{ __('inventory::field.free') }}</th>
                                <th class="w-24 text-right">{{ __('inventory::field.opening_rate') }}</th>
                                <th class="w-20 text-right">{{ __('inventory::field.markup') }} %</th>
                                <th class="w-20 text-right">{{ __('inventory::field.margin') }} %</th>
                                <th class="w-24 text-right">{{ __('inventory::field.sale_price') }}</th>
                                <th class="w-24 text-right">{{ __('inventory::field.opening_value') }}</th>
                                <th class="w-28">{{ __('inventory::field.batch_no') }}</th>
                                <th class="w-32">{{ __('inventory::field.expiry_date') }}</th>
                                <th class="w-36">{{ __('inventory::field.opening_principal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="row.key">
                                <tr data-cart-row class="border-b border-(--color-border)/60" :class="row.error ? 'bg-(--color-badge-danger-bg)' : ''">
                                    <td class="num text-(--color-ink-muted)" x-text="i + 1"></td>
                                    <td class="relative">
                                        <input type="hidden" :name="name(i, 'product_id')" :value="row.product_id">
                                        <input :name="name(i, 'product')" x-model="row.search" data-cell="product" autocomplete="off"
                                               placeholder="{{ __('inventory::message.opening_cart_search') }}" aria-label="{{ __('inventory::field.product') }}"
                                               x-on:input="typed(row)" x-on:keydown.arrow-down.prevent="down(row)" x-on:keydown.arrow-up.prevent="up(row)"
                                               x-on:keydown.escape="close(row)" x-on:blur="close(row)" x-on:keydown.enter="enter($event, row)"
                                               class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                                        <ul x-show="row.open" x-cloak role="listbox" data-cart-matches
                                            class="absolute z-20 mt-1 max-h-64 w-80 overflow-y-auto rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                                            <template x-for="(p, n) in matches(row)" :key="p.id">
                                                <li role="option" x-on:mousedown.prevent="pick(row, p)"
                                                    class="cursor-pointer px-2 py-1 hover:bg-(--color-surface-sunken)"
                                                    :class="isCursor(row, n) ? 'bg-(--color-surface-sunken)' : ''">
                                                    <span class="font-medium" x-text="p.code"></span>
                                                    <span x-text="p.name"></span>
                                                    <span class="text-2xs text-(--color-ink-muted)" x-text="p.barcode"></span>
                                                </li>
                                            </template>
                                            <li x-show="matches(row).length === 0" class="px-2 py-1 text-(--color-ink-muted)">{{ __('inventory::message.opening_cart_no_match') }}</li>
                                        </ul>
                                        <span x-show="row.error" class="block text-2xs text-(--color-badge-danger-ink)" x-text="row.error"></span>
                                    </td>
                                    <td><input :name="name(i, 'qty')" x-model="row.qty" data-cell="qty" type="number" step="any" min="0" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.quantity') }}" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input :name="name(i, 'free_qty')" x-model="row.free_qty" data-cell="free" type="number" step="any" min="0" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.free') }}" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input :name="name(i, 'unit_cost')" x-model="row.rate" data-cell="rate" type="number" step="any" min="0" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.opening_rate') }}" x-on:input="priced(row, 'rate')" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input x-model="row.markup" data-cell="markup" type="number" step="any" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.markup') }}" x-on:input="priced(row, 'markup')" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input x-model="row.margin" data-cell="margin" type="number" step="any" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.margin') }}" x-on:input="priced(row, 'margin')" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td>
                                        <input :name="name(i, 'sales_price')" x-model="row.sales_price" data-cell="sales_price" type="number" step="any" min="0" inputmode="decimal"
                                               aria-label="{{ __('inventory::field.sale_price') }}" x-on:input="priced(row, 'sales_price')" x-on:keydown.enter="enter($event, row)"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right">
                                        <input type="hidden" :name="name(i, 'pricing_anchor')" :value="row.anchor">
                                        <input type="hidden" :name="name(i, 'pricing_pct')" :value="pctOf(row)">
                                    </td>
                                    <td class="num text-right" data-cart-value x-text="valueText(row)"></td>
                                    <td><input :name="name(i, 'batch_no')" x-model="row.batch_no" data-cell="batch_no"
                                               placeholder="{{ __('inventory::message.opening_cart_lot_auto') }}" aria-label="{{ __('inventory::field.batch_no') }}"
                                               x-on:keydown.enter="enter($event, row)" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1"></td>
                                    <td><input :name="name(i, 'expiry_date')" x-model="row.expiry_date" data-cell="expiry_date" type="date"
                                               aria-label="{{ __('inventory::field.expiry_date') }}" x-on:keydown.enter="enter($event, row)" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1"></td>
                                    <td>
                                        <select :name="name(i, 'supplier_id')" x-model="row.supplier_id" data-cell="supplier_id"
                                                aria-label="{{ __('inventory::field.opening_principal') }}" x-on:keydown.enter="enter($event, row)" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                                            <option value="">—</option>
                                            @foreach ($suppliers as $sid => $sname)
                                                <option value="{{ $sid }}">{{ $sname }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-(--color-border) font-semibold" data-cart-totals>
                                <td></td>
                                <td>{{ __('inventory::message.opening_cart_rows') }}: <span class="num" x-text="filledCount"></span></td>
                                <td class="num text-right" x-text="totalQty"></td>
                                <td class="num text-right" x-text="totalFree"></td>
                                <td colspan="4" class="text-right">{{ __('inventory::message.opening_cart_value') }}</td>
                                <td class="num text-right" data-cart-total x-text="totalValueText"></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    <button type="button" x-on:click="addRows()" class="text-sm text-(--color-brand-600) hover:underline" data-cart-more>
                        {{ __('inventory::message.opening_cart_add') }} (+10)
                    </button>
                </div>
            </form>
        </section>

        {{-- ⭐ দর লুকানো থাকলে মূল্যও — অডিট ম১২, ৫ অক্টোবর ২০২৬। ⛔ আগে দর ঢাকা অথচ মূল্য খোলা: মূল্য ÷ পরিমাণ = দর, পাহারাটা অলংকার। --}}
        @php $showCost = \App\Core\Security\FieldSecurity::visible(\App\Modules\Inventory\Models\StockMovement::class, 'unit_cost'); @endphp

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <div class="flex items-baseline justify-between border-b border-(--color-border) px-4 py-3">
                <h2 class="font-semibold">{{ __('inventory::message.opening_total') }}</h2>
                <span class="num font-semibold">{{ $showCost ? \App\Core\Support\Money::format($total) : \App\Core\Security\FieldSecurity::mask() }}</span>
            </div>

            <x-ui.table
                :empty="__('inventory::message.opening_none')"
                :rows="$entered"
                compact
                :columns="[
                    ['key' => 'trx_date', 'label' => __('inventory::field.date'), 'width' => '7rem',
                     'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
                    ['key' => 'product', 'label' => __('inventory::field.product'),
                     'render' => fn ($r) => $r->product_code . ' - '
                         . (app()->getLocale() === 'bn' && $r->name_bn ? $r->name_bn : $r->name_en)],
                    ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'), 'width' => '10rem',
                     'render' => fn ($r) => app()->getLocale() === 'bn' && $r->warehouse_bn
                         ? $r->warehouse_bn : $r->warehouse_en],
                    ['key' => 'qty', 'label' => __('inventory::field.quantity'), 'numeric' => true, 'width' => '7rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->qty)],
                    /*
                     * খোলা মজুদের দর — অন্য নামে ক্রয়মূল্য।
                     *
                     * পণ্যের পাতায় ক্রয়মূল্য ঢেকে এখানে খোলা রাখলে
                     * পাহারাটা অলংকার হত: এক পর্দায় বন্ধ, অন্যটায়
                     * একই সংখ্যা।
                     */
                    ['key' => 'unit_cost', 'label' => __('inventory::field.opening_rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($r) => $showCost
                         ? \App\Core\Support\Money::format($r->unit_cost)
                         : \App\Core\Security\FieldSecurity::mask()],
                    ['key' => 'value', 'label' => __('inventory::field.opening_value'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($r) => $showCost
                         ? \App\Core\Support\Money::format($r->value)
                         : \App\Core\Security\FieldSecurity::mask()],
                ]" />

            {{-- ⓘ উপরের "মোট" এই পাতার নয়, সবটার — পেজারের সীমা আর
                 ওই সংখ্যাটা তাই ইচ্ছাকৃতভাবে দুইটা আলাদা কথা বলে। --}}
            <x-ui.pager :rows="$entered" />
            {{-- ⓘ মোট মূল্য উপরের মতোই সবটার, আর দর লুকানো থাকলে ঢাকা --}}
            <x-ui.list-totals :rows="$entered" :totals="[['label' => __('inventory::message.opening_total'), 'value' => $showCost ? \App\Core\Support\Money::format($total) : \App\Core\Security\FieldSecurity::mask()]]" />
        </section>
    </div>
</x-layouts.app>
