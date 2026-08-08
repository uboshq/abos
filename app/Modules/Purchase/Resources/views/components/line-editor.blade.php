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
    'showSalesPrice' => false,
])

<div x-data="{
        rows: {{ Illuminate\Support\Js::from($lines) }},
        add() {
            this.rows.push({
                product_id: '', qty: '', rate: '', discount: '', tax: '', link: '',
                sales_price: '', markup: '', margin: '', anchor: '',
            });
        },
        remove(i) {
            this.rows.splice(i, 1);
            if (this.rows.length === 0) this.add();
        },
        amount(row) {
            const base = (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0);
            const net = base - (parseFloat(row.discount) || 0);
            return net + (parseFloat(row.tax) || 0);
        },
        get total() {
            return this.rows.reduce((sum, row) => sum + this.amount(row), 0);
        },

        /*
         * দাম · markup · margin — তিনটা জ্যান্ত বাক্স, চারটা সংখ্যা।
         *
         * ── markup আর margin এক জিনিস নয় ─────────────────────────────
         * markup মাপা হয় খরচের ওপর, margin দামের ওপর। ১০০-তে কিনে
         * ১৫০-তে বেচা মানে ৫০% markup, কিন্তু ৩৩.৩% margin। যে ডিপো
         * "৪০%" বলতে margin বোঝে আর পায় markup, সে প্রতিটা লাইনেই কম
         * দামে বেচে — সারা বছর, আর বছরশেষে কেউ ধরতে পারে না কেন কম
         * পড়ল। তাই দুইটা বাক্সই থাকে, দুইটাই জ্যান্ত।
         *
         * ── কোনটা স্থির থাকে, তা নির্ভর করে শেষে কী বলা হয়েছিল ────────
         * markup বা margin লিখলে মানুষটা একটা **নীতি** বলেছেন ("আমি
         * এটা চল্লিশ শতাংশে বেচি") — দর বদলালে দামটা নতুন দর ধরে বসবে।
         * দাম লিখলে তিনি একটা **দাম** বলেছেন, সচরাচর প্যাকেটে ছাপা বা
         * ডিলারের সাথে ঠিক করা — দর বদলালেও দামটা টেকে, বদলায় margin,
         * যেটা তাঁর ঠিক জানা দরকার।
         *
         * ── যেটাতে কার্সর আছে সেটা ছোঁয়া হয় না ──────────────────────
         * নিজে থেকে বসিয়ে দিলে টাইপ করতে থাকা মানুষটার সাথে লড়াই হত।
         * তাই edited ঘরটা কখনো লেখা হয় না।
         *
         * ── আর কোনোটা না ছোঁয়া পর্যন্ত কিছুই বসে না ─────────────────
         * নইলে কেউ শুধু ক্রয়দরটা লিখলেই পর্দায় একটা বিক্রয়মূল্য ভেসে
         * উঠত যেটা কেউ বেছে নেয়নি — আর সেটাই সেভ হয়ে যেত।
         */
        priced(row, edited) {
            Object.assign(row, window.abos.reprice(row, edited));
        },
     }"
     x-init="if (rows.length === 0) add()">

    <div class="table-responsive">
        <table class="table-cards w-full text-sm">
            <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                <tr>
                    <th class="p-2 text-start font-medium">{{ __('purchase::field.product') }}</th>
                    @if ($linkField)
                        <th class="p-2 text-start font-medium">{{ __('purchase::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}</th>
                    @endif
                    <th class="p-2 text-end font-medium">{{ __('purchase::field.quantity') }}</th>
                    <th class="p-2 text-end font-medium">{{ __('purchase::field.rate') }}</th>
                    @if ($showSalesPrice)
                        <th class="p-2 text-end font-medium">{{ __('purchase::field.sales_price') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('purchase::field.markup') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('purchase::field.margin') }}</th>
                    @endif
                    @if ($showDiscount)
                        <th class="p-2 text-end font-medium">{{ __('purchase::field.discount') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('purchase::field.tax') }}</th>
                    @endif
                    <th class="p-2 text-end font-medium">{{ __('purchase::field.amount') }}</th>
                    <th class="p-2"><span class="sr-only">{{ __('purchase::action.remove_line') }}</span></th>
                </tr>
            </thead>

            <tbody>
                <template x-for="(row, i) in rows" :key="i">
                    <tr class="border-b border-(--color-border)">
                        <td class="p-1" data-label="{{ __('purchase::field.product') }}">
                            <select :name="`lines[${i}][product_id]`" x-model="row.product_id" required
                                    class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2">
                                <option value="">-</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name() }}</option>
                                @endforeach
                            </select>
                        </td>

                        @if ($linkField)
                            <td class="p-1" data-label="{{ __('purchase::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}">
                                <select :name="`lines[${i}][{{ $linkField }}]`" x-model="row.link"
                                        class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">-</option>
                                    @foreach ($linkOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif

                        <td class="p-1" data-label="{{ __('purchase::field.quantity') }}">
                            <input type="number" step="0.01" inputmode="decimal" required
                                   :name="`lines[${i}][{{ $qtyField }}]`" x-model="row.qty"
                                   class="num h-9 w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        <td class="p-1" data-label="{{ __('purchase::field.rate') }}">
                            <input type="number" step="0.0001" inputmode="decimal" required
                                   :name="`lines[${i}][rate]`" x-model="row.rate"
                                   @input="priced(row, 'rate')"
                                   class="num h-9 w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($showSalesPrice)
                            {{--
                                বিক্রয়মূল্যের ঘরটাই একমাত্র যেটা সার্ভারে
                                যায়। markup ও margin-এর name নেই — ওরা
                                দুইটা জানালা, সংরক্ষিত তথ্য নয়। একই
                                জিনিস দুই জায়গায় জমা রাখলে একদিন আলাদা
                                হবেই, আর তখন কোনটা সত্যি বলার উপায় থাকে না।
                            --}}
                            <td class="p-1" data-label="{{ __('purchase::field.sales_price') }}">
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       :name="`lines[${i}][sales_price]`" x-model="row.sales_price"
                                       @input="priced(row, 'sales_price')"
                                       class="num h-9 w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="p-1" data-label="{{ __('purchase::field.markup') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="row.markup" @input="priced(row, 'markup')"
                                       class="num h-9 w-full sm:w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="p-1" data-label="{{ __('purchase::field.margin') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="row.margin" @input="priced(row, 'margin')"
                                       class="num h-9 w-full sm:w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        @if ($showDiscount)
                            <td class="p-1" data-label="{{ __('purchase::field.discount') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][discount]`" x-model="row.discount"
                                       class="num h-9 w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="p-1" data-label="{{ __('purchase::field.tax') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][tax]`" x-model="row.tax"
                                       class="num h-9 w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        <td class="num p-2 text-end" data-label="{{ __('purchase::field.amount') }}"
                            x-text="amount(row).toFixed(2)"></td>

                        <td class="p-1 text-end">
                            <button type="button" @click="remove(i)"
                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                           hover:bg-(--color-surface-hover)">
                                &times;<span class="sr-only">{{ __('purchase::action.remove_line') }}</span>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>

            <tfoot>
                <tr>
                    <td class="p-2 text-end font-medium"
                        colspan="{{ ($showDiscount ? 5 : 3) + ($linkField ? 1 : 0) + ($showSalesPrice ? 3 : 0) }}">
                        {{ __('purchase::field.total') }}
                    </td>
                    <td class="num p-2 text-end font-semibold" x-text="total.toFixed(2)"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <button type="button" @click="add()"
            class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                   transition-colors hover:bg-(--color-surface-hover)">
        + {{ __('purchase::action.add_line') }}
    </button>
</div>
