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
@endphp

<div x-data="{
        rows: {{ Illuminate\Support\Js::from($lines) }},
        packs: {{ Illuminate\Support\Js::from($packs) }},
        unitsFor(row) {
            return this.packs[row.product_id] ?? [];
        },
        add() {
            this.rows.push({
                product_id: '', qty: '', rate: '', discount: '', tax: '', link: '', unit_id: '',
            });
        },
        remove(i) {
            this.rows.splice(i, 1);
            if (this.rows.length === 0) this.add();
        },

        /*
         * চার্ট/বাল্ক শীট থেকে আসা সারিগুলো।
         *
         * ── কেন মিলিয়ে বসানো হয়, বদলে দেওয়া হয় না ──────────────────
         * অর্ডার ধরে খোলা চালানে লাইনগুলো আগে থেকেই ভরা থাকে। শীট
         * Apply করলে ওগুলো মুছে গেলে মানুষ ভাবতেন শীটটা কিছু নষ্ট
         * করেছে — অথচ তিনি শুধু আরও কয়েকটা পণ্য যোগ করতে চেয়েছিলেন।
         *
         * একই পণ্য দুই জায়গায় থাকলে শীটের সংখ্যাটাই থাকে: শীটে তিনি
         * সবে ওটা টাইপ করেছেন, আর নতুন কথাটাই শেষ কথা।
         */
        absorb(rows) {
            for (const row of rows) {
                const existing = this.rows.find(r => String(r.product_id) === String(row.product_id));

                if (existing) {
                    existing.qty = row.qty;
                    existing.rate = row.rate || existing.rate;
                    continue;
                }

                this.rows.push({
                    product_id: String(row.product_id),
                    qty: row.qty, rate: row.rate ?? '',
                    discount: '', tax: '', link: '', unit_id: '',
                });
            }

            // শুরুর খালি সারিটা — শীট থেকে আসার পর ওটা কেবল একটা ফাঁকা ঘর
            this.rows = this.rows.filter(r => r.product_id !== '' || this.rows.length === 1);
        },
        amount(row) {
            const base = (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0);
            const net = base - (parseFloat(row.discount) || 0);
            return net + (parseFloat(row.tax) || 0);
        },
        get total() {
            return this.rows.reduce((sum, row) => sum + this.amount(row), 0);
        },
     }"
     x-init="if (rows.length === 0) add()"
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
                            <select :name="`lines[${i}][product_id]`" x-model="row.product_id" required
                                    @change="row.unit_id = ''"
                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2">
                                <option value="">-</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name() }}</option>
                                @endforeach
                            </select>
                        </td>

                        @if ($linkField)
                            <td class="cell-input" data-label="{{ __('sales::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}">
                                <select :name="`lines[${i}][{{ $linkField }}]`" x-model="row.link"
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
                                   :name="`lines[${i}][{{ $qtyField }}]`" x-model="row.qty"
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
                                <select :name="`lines[${i}][unit_id]`" x-model="row.unit_id"
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
                                   :name="`lines[${i}][rate]`" x-model="row.rate"
                                   class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($showDiscount)
                            <td class="cell-input" data-label="{{ __('sales::field.discount') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][discount]`" x-model="row.discount"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="cell-input" data-label="{{ __('sales::field.tax') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][tax]`" x-model="row.tax"
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
