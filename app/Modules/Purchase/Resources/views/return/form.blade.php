{{--
    ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফিরে যাচ্ছে।

    দর হাতে বসানো যায় কেবল তখনই যখন কোনো বিলের লাইন ধরা হয়নি। বিল ধরা
    থাকলে দরটা বিল থেকেই আসে — সেবা স্তর সেটাই বসায়, তাই এখানে ঘরটা
    দেখানো হয় ঠিকই, কিন্তু বদলালে কিছু হয় না।
--}}
@php
    $isNew = ! $return->exists;

    $rows = $isNew && $bill
        ? $bill->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'purchase_bill_line_id' => (string) $l->id,
            'qty' => '',
            'rate' => (string) $l->rate,
            'tax' => '',
        ])->all()
        : $return->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'purchase_bill_line_id' => (string) ($l->purchase_bill_line_id ?? ''),
            'qty' => (string) $l->qty,
            'rate' => (string) $l->rate,
            'tax' => (string) $l->tax,
        ])->all();

    $existing = old('lines', $rows);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('purchase::action.new_return') : $return->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('purchase::action.new_return') : $return->document_no"
            :subtitle="__('purchase::message.return_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('purchase.return.store') : route('purchase.return.update', $return) }}"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                             :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                             :selected="$bill?->supplier_id ?? $return->supplier_id"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('purchase::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$return->warehouse_id" placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', $return->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                {{--
                    বিল বাছলেই পাতাটা ওই বিল নিয়ে ফিরে আসে।

                    বিক্রয় ফেরতের পর্দাতেও হুবহু একই ভুল ছিল, আর একই
                    সমাধান: ঘরটা নিছক একটা ড্রপডাউন ছিল, বেছে নিলে কিছুই
                    ঘটত না। উপরের @php ব্লক লাইনগুলো ভরে কেবল যখন ঠিকানায়
                    `?purchase_bill_id=` থাকে — অর্থাৎ কেবল বিলের পাতা
                    থেকে লিংকে এলে।

                    ফলে পর্দাটা দাবি করত "দর বিলের দর থেকেই আসে", অথচ
                    বাস্তবে সব হাতে টাইপ করতে হত।
                --}}
                <x-ui.select name="purchase_bill_id" :label="__('purchase::field.bill')"
                             :options="$bills->mapWithKeys(fn ($b) => [$b->id => $b->document_no.' — '.$b->supplier?->name()])"
                             :selected="$bill?->id ?? $return->purchase_bill_id" placeholder="-"
                             {{-- সাধারণ onchange, Alpine-এর @change নয়: এই
                                  ফর্মে কোনো x-data নেই, তাই Alpine
                                  অ্যাট্রিবিউটটা পড়তই না আর বিল বাছলে
                                  কিচ্ছু হত না। বিক্রয় ফেরতেও একই ভুল ছিল। --}}
                             onchange="if (this.value) {
                                 window.location = '{{ route('purchase.return.create') }}?purchase_bill_id=' + this.value;
                             }" />

                {{-- কারণটা ঐচ্ছিক নয় বলা যায় না — পুরনো মাল ফেরত এলে
                     কারণ জানা না-ও থাকতে পারে। কিন্তু থাকলে রিপোর্টে
                     "কেন ফেরত যাচ্ছে" প্রশ্নের উত্তর মেলে। --}}
                <x-ui.select name="reason_code_id" :label="__('purchase::field.reason')"
                             :options="$reasons->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                             :selected="$return->reason_code_id" placeholder="-" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('purchase::field.narration')"
                            :value="old('narration', $return->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('purchase::message.lines') }}</h2>

            <div x-data="{
                    rows: {{ Illuminate\Support\Js::from($existing) }},
                    add() { this.rows.push({ product_id: '', purchase_bill_line_id: '', qty: '', rate: '', tax: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    amount(row) {
                        return (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0) + (parseFloat(row.tax) || 0);
                    },
                    get total() { return this.rows.reduce((s, r) => s + this.amount(r), 0); },
                 }"
                 x-init="if (rows.length === 0) add()">

                <div class="table-responsive">
                    <table class="table-cards w-full text-sm">
                        <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                            <tr>
                                <th class="p-2 text-start font-medium">{{ __('purchase::field.product') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('purchase::field.quantity') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('purchase::field.rate') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('purchase::field.tax') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('purchase::field.amount') }}</th>
                                <th class="p-2"><span class="sr-only">{{ __('purchase::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-b border-(--color-border)">
                                    <td class="p-1" data-label="{{ __('purchase::field.product') }}">
                                        <select :name="`lines[${i}][product_id]`" x-model="row.product_id"
                                                class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">-</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name() }}</option>
                                            @endforeach
                                        </select>

                                        {{-- কোন বিলের লাইন — লুকানো, কারণ বিল ধরে
                                             খুললে এটা আগেই বসে যায়, আর হাতে বদলানোর
                                             মতো জিনিস নয় --}}
                                        <input type="hidden" :name="`lines[${i}][purchase_bill_line_id]`"
                                               x-model="row.purchase_bill_line_id">
                                    </td>

                                    <td class="p-1" data-label="{{ __('purchase::field.quantity') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][qty]`" x-model="row.qty"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="p-1" data-label="{{ __('purchase::field.rate') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][rate]`" x-model="row.rate"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="p-1" data-label="{{ __('purchase::field.tax') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][tax]`" x-model="row.tax"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="num p-1 text-end" data-label="{{ __('purchase::field.amount') }}"
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
                                <td colspan="4" class="p-2 text-end font-medium">{{ __('purchase::field.total') }}</td>
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
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('purchase.return.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
