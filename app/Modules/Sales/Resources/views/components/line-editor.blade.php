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

<div x-data="{
        rows: {{ Illuminate\Support\Js::from($lines) }},
        add() {
            this.rows.push({
                product_id: '', qty: '', rate: '', discount: '', tax: '', link: '',
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
     }"
     x-init="if (rows.length === 0) add()">

    <div class="table-responsive">
        <table class="table-cards w-full text-sm">
            <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                <tr>
                    <th class="p-2 text-start font-medium">{{ __('sales::field.product') }}</th>
                    @if ($linkField)
                        <th class="p-2 text-start font-medium">{{ __('sales::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}</th>
                    @endif
                    <th class="p-2 text-end font-medium">{{ __('sales::field.quantity') }}</th>
                    <th class="p-2 text-end font-medium">{{ __('sales::field.rate') }}</th>
                    @if ($showDiscount)
                        <th class="p-2 text-end font-medium">{{ __('sales::field.discount') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('sales::field.tax') }}</th>
                    @endif
                    <th class="p-2 text-end font-medium">{{ __('sales::field.amount') }}</th>
                    <th class="p-2"><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                </tr>
            </thead>

            <tbody>
                <template x-for="(row, i) in rows" :key="i">
                    <tr class="border-b border-(--color-border)">
                        <td class="p-1" data-label="{{ __('sales::field.product') }}">
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
                            <td class="p-1" data-label="{{ __('sales::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}">
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

                        <td class="p-1" data-label="{{ __('sales::field.quantity') }}">
                            <input type="number" step="0.01" inputmode="decimal" required
                                   :name="`lines[${i}][{{ $qtyField }}]`" x-model="row.qty"
                                   class="num h-9 w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        <td class="p-1" data-label="{{ __('sales::field.rate') }}">
                            <input type="number" step="0.0001" inputmode="decimal" required
                                   :name="`lines[${i}][rate]`" x-model="row.rate"
                                   class="num h-9 w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($showDiscount)
                            <td class="p-1" data-label="{{ __('sales::field.discount') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][discount]`" x-model="row.discount"
                                       class="num h-9 w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="p-1" data-label="{{ __('sales::field.tax') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="`lines[${i}][tax]`" x-model="row.tax"
                                       class="num h-9 w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        <td class="num p-2 text-end" data-label="{{ __('sales::field.amount') }}"
                            x-text="amount(row).toFixed(2)"></td>

                        <td class="p-1 text-end">
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
                    <td class="p-2 text-end font-medium" colspan="{{ ($showDiscount ? 5 : 3) + ($linkField ? 1 : 0) }}">
                        {{ __('sales::field.total') }}
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
        + {{ __('sales::action.add_line') }}
    </button>
</div>
