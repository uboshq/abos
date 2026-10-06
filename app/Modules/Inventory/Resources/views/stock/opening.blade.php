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
            ⓘ উপরে একবার গুদাম, তারিখ, বিবরণ; নিচে প্রতি সারি এক পণ্য। পণ্যের ঘর ব্রাউজারের নিজের তালিকা থেকে খোঁজে
            (কোড, নাম বা বারকোড) — কোনো JS ছাড়াই। এক চাপে সব সারি, এক লেনদেনে ([[OpeningStockController::storeMany()]]);
            একটা সারি ভুল হলে কোনোটাই বসে না, আর ভুল সারিটা লাল।
        --}}
        @php
            $cartRows = max(15, min(200, (int) request('rows', 15)), count((array) old('rows', [])));
            $bad = collect($errors->keys())
                ->map(fn ($k) => preg_match('/^rows\.(\d+)\./', $k, $m) === 1 ? (int) $m[1] : null)
                ->filter(fn ($v) => $v !== null)->unique()->values()->all();
            $mainStore = $warehouses->firstWhere('is_default', true)?->id ?? ($warehouses->count() === 1 ? $warehouses->first()->id : null);
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

            <form method="POST" action="{{ route('inventory.stock.opening.cart') }}">
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

                <datalist id="opening-products">
                    @foreach ($products as $p)
                        <option value="{{ $p->code }} — {{ $p->name() }}{{ $p->barcode ? ' — '.$p->barcode : '' }}"></option>
                    @endforeach
                </datalist>

                <div class="overflow-x-auto">
                    <table class="ui-list w-full text-sm">
                        <thead>
                            <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                                <th class="w-8">#</th>
                                <th class="min-w-64">{{ __('inventory::field.product') }}</th>
                                <th class="w-24 text-right">{{ __('inventory::field.quantity') }}</th>
                                <th class="w-28 text-right">{{ __('inventory::field.opening_rate') }}</th>
                                <th class="w-36">{{ __('inventory::field.batch_no') }}</th>
                                <th class="w-36">{{ __('inventory::field.expiry_date') }}</th>
                                <th class="w-44">{{ __('inventory::field.opening_principal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for ($i = 0; $i < $cartRows; $i++)
                                <tr data-cart-row="{{ $i }}" @class(['border-b border-(--color-border)/60', 'bg-(--color-badge-danger-bg)' => in_array($i, $bad, true)])>
                                    <td class="num text-(--color-ink-muted)">{{ $i + 1 }}</td>
                                    <td>
                                        <input name="rows[{{ $i }}][product]" list="opening-products" autocomplete="off"
                                               value="{{ old("rows.$i.product") }}" placeholder="{{ __('inventory::message.opening_cart_search') }}"
                                               aria-label="{{ __('inventory::field.product') }} {{ $i + 1 }}"
                                               class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                                        @foreach ($errors->get("rows.$i.*") as $messages)
                                            @foreach ((array) $messages as $message)
                                                <span class="block text-2xs text-(--color-badge-danger-ink)">{{ $message }}</span>
                                            @endforeach
                                        @endforeach
                                    </td>
                                    <td><input name="rows[{{ $i }}][qty]" type="number" step="any" min="0" inputmode="decimal" value="{{ old("rows.$i.qty") }}"
                                               aria-label="{{ __('inventory::field.quantity') }} {{ $i + 1 }}"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input name="rows[{{ $i }}][unit_cost]" type="number" step="any" min="0" inputmode="decimal" value="{{ old("rows.$i.unit_cost") }}"
                                               aria-label="{{ __('inventory::field.opening_rate') }} {{ $i + 1 }}"
                                               class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right"></td>
                                    <td><input name="rows[{{ $i }}][batch_no]" value="{{ old("rows.$i.batch_no") }}" placeholder="{{ __('inventory::message.opening_cart_lot_auto') }}"
                                               aria-label="{{ __('inventory::field.batch_no') }} {{ $i + 1 }}"
                                               class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1"></td>
                                    <td><input name="rows[{{ $i }}][expiry_date]" type="date" value="{{ old("rows.$i.expiry_date") }}"
                                               aria-label="{{ __('inventory::field.expiry_date') }} {{ $i + 1 }}"
                                               class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1"></td>
                                    <td>
                                        <select name="rows[{{ $i }}][supplier_id]" aria-label="{{ __('inventory::field.opening_principal') }} {{ $i + 1 }}"
                                                class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                                            <option value="">—</option>
                                            @foreach ($suppliers as $sid => $sname)
                                                <option value="{{ $sid }}" @selected((string) old("rows.$i.supplier_id") === (string) $sid)>{{ $sname }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    <a href="{{ route('inventory.stock.opening', ['rows' => $cartRows + 10]) }}" class="text-sm text-(--color-brand-600) hover:underline" data-cart-more>
                        {{ __('inventory::message.opening_cart_add') }} (+10)
                    </a>
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
