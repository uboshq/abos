{{--
    লাইনের সম্পাদক — তিনটা ফর্মেই একই।

    Alpine দিয়ে সারি যোগ-বিয়োগ হয়, আর প্রতিটা সারির টাকা তখনই গোনা হয়।
    সার্ভারই শেষ কথা (CalculatesLineTotals), কিন্তু ব্যবহারকারী সেভ করার
    আগেই মোটটা দেখতে পান — নাহলে ভুল দর বসিয়ে সেভ করে তারপর বুঝতেন।

    পরিমাণের ঘরের নামটা ডকুমেন্টভেদে আলাদা (ordered_qty · received_qty ·
    qty), তাই সেটা প্রপ হিসেবে আসে।
--}}
@props([
    'products',
    'lines' => [],
    'qtyField' => 'qty',
    'linkField' => null,
    'linkOptions' => [],
    'showDiscount' => true,

    /*
     * ⭐ দুইটা ঐচ্ছিক জিনিস — ২১ সেপ্টেম্বর ২০২৬, অর্ডারের পর্দার জন্য।
     *
     * ⚠️ ছয়টা ফর্ম এই কম্পোনেন্টটা ব্যবহার করে, তাই দুইটাই **বন্ধ
     * অবস্থায়** শুরু হয়। ⓘ `stock` খালি থাকলে মজুদের ইঙ্গিতটা আঁকাই
     * হয় না, আর বাকি পাঁচটা ফর্মে একটা পিক্সেলও বদলায় না।
     */
    'stock' => [],
    'showBreakdown' => false,
])

@php
    /*
        প্যাকের তালিকা — কন্ট্রোল প্যানেলের সুইচের পেছনে।

        ── কেন কন্ট্রোলারে নয়, এখানে ────────────────────────────────
        ছয়টা ফর্ম এই কম্পোনেন্টটা ব্যবহার করে। কন্ট্রোলারে বসালে
        ছয় জায়গায় একই লাইন লিখতে হত, আর একদিন কেউ সপ্তম ফর্ম বানিয়ে
        ওটা ভুলে যেত — তখন ওই পর্দায় একক বাছাই নীরবে উধাও থাকত।

        সুইচ বন্ধ থাকলে খালি অ্যারে, তাই ঘরটাই আসে না — যে ব্যবসা
        এক এককে বেচে তার প্রতিটা সারিতে একটা বাড়তি ড্রপডাউন কেবল
        টাইপিং বাড়াত।
    */
    $packs = app(App\Core\Services\SettingsService::class)->enabled('inventory.pack_entry_enabled')
        ? app(App\Modules\Inventory\Services\PackConversion::class)->optionsFor($products)
        : [];

    /*
     * ⭐ কোন প্যাকটা আগে থেকে বসবে — মালিকের বাছাই, ধাপ ৫, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ পণ্যের ফর্মের রেডিও ("বেচায়") যা বলে, কেবল সেটাই। ⚠️ কেউ কিছু না
     * বাছলে খালি থাকে, আর তখন আগের মতোই পণ্যের নিজের একক — অর্থাৎ যে
     * ব্যবসা প্যাক ব্যবহার করে না, তার কিছুই বদলায় না।
     */
    $packDefaults = $packs === []
        ? []
        : app(App\Modules\Inventory\Services\PackConversion::class)->defaultsFor($products, 'sales');
@endphp

<div x-data="salesLineEditor({
                 rows: @js($lines),
                 packs: @js($packs),
                 packDefaults: @js($packDefaults),
                 stock: @js((object) $stock),
               })"
     @bulk-applied.window="absorb($event.detail.rows)">

    <div class="table-responsive">
        <table class="ui-lines table-cards w-full text-sm">
            <thead>
                <tr>
                    <th class="text-start">{{ __('sales::field.product') }}</th>
                    @if ($linkField)
                        <th class="text-start">{{ __('sales::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}</th>
                    @endif
                    <th class="text-end">{{ __('sales::field.quantity') }}</th>
                    @if ($packs !== [])
                        <th class="text-start">{{ __('sales::field.unit') }}</th>
                    @endif
                    <th class="text-end">{{ __('sales::field.rate') }}</th>
                    @if ($showDiscount)
                        <th class="text-end">{{ __('sales::field.discount') }}</th>
                        <th class="text-end">{{ __('sales::field.tax') }}</th>
                    @endif
                    <th class="text-end">{{ __('sales::field.amount') }}</th>
                    <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                </tr>
            </thead>

            <tbody>
                <template x-for="(row, i) in rows" :key="i">
                    <tr class="border-b border-(--color-border)">
                        <td class="cell-input" data-label="{{ __('sales::field.product') }}">
                            <select :name="'lines[' + (i) + '][product_id]'" x-model="row.product_id" required
                                    @change="row.unit_id = defaultUnit(row.product_id)"
                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2">
                                <option value="">-</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name() }}</option>
                                @endforeach
                            </select>

                            @if ($stock !== [])
                                {{--
                                    ⭐ কতটা বিক্রয়যোগ্য আছে — ২১ সেপ্টেম্বর ২০২৬।

                                    ⛔ এটা অর্ডার **আটকায় না**, আর সেটা ইচ্ছাকৃত: অর্ডার
                                    ভবিষ্যতের কাগজ, মাল কাল আসতে পারে। আজ মজুদ নেই বলে
                                    অর্ডারটা নেওয়া যাবে না — এমন নিয়ম ব্যবসাটাই আটকে দিত।

                                    ⓘ সংখ্যাটা পণ্যের ঘরের নিচে বসে, আলাদা কলামে নয় —
                                    কলাম বাড়ালে নিচের যোগফলের সারিটা ছয়টা ফর্মেই সরে যেত।
                                --}}
                                <p class="mt-1 text-xs text-(--color-ink-muted)"
                                   x-show="stockFor(row) !== null">
                                    {{ __('sales::field.available_short') }}:
                                    <span class="tabular" x-text="stockFor(row)"></span>
                                </p>
                            @endif
                        </td>

                        @if ($linkField)
                            <td class="cell-input" data-label="{{ __('sales::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}">
                                <select :name="'lines[' + (i) + '][{{ $linkField }}]'" x-model="row.link"
                                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">-</option>
                                    @foreach ($linkOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif

                        <td class="cell-input" data-label="{{ __('sales::field.quantity') }}">
                            <input type="number" step="0.01" inputmode="decimal" required
                                   :name="'lines[' + (i) + '][{{ $qtyField }}]'" x-model="row.qty"
                                   class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($packs !== [])
                            {{--
                                একক — কেবল যে পণ্যের একাধিক প্যাক আছে তার সারিতে।

                                ফাঁকা রাখলে পণ্যের নিজের এককেই ধরা হয়, অর্থাৎ
                                আগে যেভাবে চলত সেভাবেই। সার্ভার একই নিয়ম মানে
                                (ReadsPackedQuantities), তাই পর্দা আর খাতা কখনো
                                দুই কথা বলে না।

                                পণ্য বদলালে এককটা মুছে যায়: আগের পণ্যের "বাক্স"
                                নতুন পণ্যের সিঁড়িতে না-ও থাকতে পারে, আর তখন
                                সার্ভার অনুরোধটা ফিরিয়ে দিত।
                            --}}
                            <td class="cell-input" data-label="{{ __('sales::field.unit') }}">
                                <select :name="'lines[' + (i) + '][unit_id]'" x-model="row.unit_id"
                                        x-show="unitsFor(row).length > 0"
                                        class="h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">-</option>
                                    <template x-for="unit in unitsFor(row)" :key="unit.id">
                                        <option :value="unit.id" x-text="unit.label"></option>
                                    </template>
                                </select>
                            </td>
                        @endif

                        <td class="cell-input" data-label="{{ __('sales::field.rate') }}">
                            <input type="number" step="0.0001" inputmode="decimal" required
                                   :name="'lines[' + (i) + '][rate]'" x-model="row.rate"
                                   class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($showDiscount)
                            <td class="cell-input" data-label="{{ __('sales::field.discount') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="'lines[' + (i) + '][discount]'" x-model="row.discount"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="cell-input" data-label="{{ __('sales::field.tax') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="'lines[' + (i) + '][tax]'" x-model="row.tax"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        <td class="num cell" data-label="{{ __('sales::field.amount') }}"
                            x-text="amount(row).toFixed(2)"></td>

                        <td class="cell-input text-end">
                            <button type="button" @click="remove(i)"
                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                           hover:bg-(--color-surface-hover)">
                                &times;<span class="sr-only">{{ __('sales::action.remove_line') }}</span>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>

            <tfoot>
                @if ($showBreakdown)
                    {{--
                        ⭐ মোটটা ভেঙে — ২১ সেপ্টেম্বর ২০২৬।

                        ⓘ নিচে এতদিন কেবল একটা সংখ্যা বসত। ⚠️ মালিক অর্ডার নেওয়ার
                        সময় ছাড় দেন, আর "মোট কত" আর "ছাড় কত" এক সংখ্যায় মিশে
                        গেলে ফোনে গ্রাহককে বলার মতো কিছু থাকে না।

                        ⛔ সার্ভারই শেষ কথা ([[CalculatesLineTotals]]) — এগুলো কেবল
                        সেভ করার আগে চোখে দেখার জন্য।
                    --}}
                    @foreach ([
                        'sales::field.subtotal' => 'subtotal',
                        'sales::field.discount' => 'discountTotal',
                        'sales::field.tax' => 'taxTotal',
                    ] as $label => $value)
                        <tr class="text-(--color-ink-muted)">
                            <td class="cell text-end" colspan="{{ ($showDiscount ? 5 : 3) + ($linkField ? 1 : 0) + ($packs !== [] ? 1 : 0) }}">
                                {{ __($label) }}
                            </td>
                            <td class="num cell" x-text="{{ $value }}.toFixed(2)"></td>
                            <td></td>
                        </tr>
                    @endforeach
                @endif

                <tr>
                    {{-- এককের ঘরটা এলে মোটের সারিও এক ঘর পিছিয়ে বসে, নাহলে
                         যোগফলটা টাকার কলামের নিচ থেকে সরে যেত --}}
                    <td class="cell text-end font-medium"
                        colspan="{{ ($showDiscount ? 5 : 3) + ($linkField ? 1 : 0) + ($packs !== [] ? 1 : 0) }}">
                        {{ __('sales::field.total') }}
                    </td>
                    <td class="num cell font-semibold" x-text="total.toFixed(2)"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <button type="button" @click="add()"
            class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                   transition-colors hover:bg-(--color-surface-hover)">
        + {{ __('sales::action.add_line') }}
    </button>
</div>
