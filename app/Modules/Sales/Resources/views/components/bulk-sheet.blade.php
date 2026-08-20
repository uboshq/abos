{{--
    গোটা ক্যাটালগ এক শীটে — চার্ট / বাল্ক DO।

    ── কেন সারি-ধরে-যোগ করার এডিটরটা এখানে যথেষ্ট নয় ────────────────────
    লাইন এডিটর একবারে একটা পণ্য নেয়, আর কাউন্টারে দাঁড়ানো ক্রেতার চারটা
    জিনিসের জন্য ওটাই ঠিক। কাজের অন্য অর্ধেকটার জন্য নয়: ডিলারের মাসিক
    চার্ট, রুটের গাড়ি ভরা, বা ছাপা তালিকা হাতে নিয়ে বসা। ওখানে মানুষ
    কাগজ ধরে নিচে নামেন — বিশ, চল্লিশ, একশো সারি — আর প্রতিটার জন্য আগে
    একটা খোঁজার ঘরে পণ্য বাছা মানে বিশ, চল্লিশ, একশোটা বাধা।

    তাই: প্রতিটা পণ্য পর্দায়, পরিমাণ সারিতেই টাইপ, পাশে মজুদের অবস্থা,
    আর উপরে চলতি যোগফল।

    ── কেন Apply চাপার আগে কিছুই ঢোকে না ───────────────────────────────
    এটাই এই শীটটাকে নিরাপদ করে। ঝুড়ির দিকে না তাকিয়ে টাইপ করে নামা যায়,
    আর ভুল হলে বাতিল করে বেরিয়ে যাওয়া যায় — ডকুমেন্টে কিছুই বসেনি।

    ── কেন যোগফলটা যা টাইপ করা হয়েছে তার উপর, যা দেখা যাচ্ছে তার উপর নয় ──
    খুঁজলে বা ছাঁকলে যে যোগফল বদলে যায়, সেটা কেউ বিশ্বাস করে না।
--}}
@props([
    'products',
    'stock' => [],
    'freeQty' => false,
])

@php
    use App\Core\Support\Money;

    /*
     * শীটের নিজের তথ্য — প্রতিটা পণ্যের একটা করে সারি।
     *
     * দর পণ্যের নিজের বিক্রয়মূল্য থেকে; এটা শীটের হিসাব দেখানোর জন্য,
     * আর সেভ করার সময় সার্ভারই শেষ কথা বলে (CalculatesSalesLines)।
     */
    $sheet = $products->map(fn ($product) => [
        'id' => (string) $product->id,
        'code' => $product->code,
        'name' => $product->name(),
        'unit' => $product->unit?->name(),
        'rate' => (string) ($product->sale_price ?? '0'),

        /*
         * পরিমাণগুলো ছাঁটা — "১৭৭৫", "১৭৭৫.০০০০" নয়।
         *
         * চারশো সারির শীটে প্রতিটা সংখ্যার লেজে চারটা শূন্য থাকলে চোখ
         * আসল অঙ্কটা খুঁজে পেতে দেরি করে, আর এই পর্দাটার পুরো কাজই
         * দ্রুত নিচে নামা। টাকার ঘর নয় বলে ছাঁটাই ঠিক: আধা বস্তা
         * থাকলে ".5" থেকেই যায়।
         */
        'available' => Money::quantity($stock[$product->id]['available'] ?? '0'),
        'floor' => Money::quantity($stock[$product->id]['floor'] ?? '0'),
        'reserved' => Money::quantity($stock[$product->id]['reserved'] ?? '0'),
        'hold' => Money::quantity($stock[$product->id]['hold'] ?? '0'),
        'free_available' => Money::quantity($stock[$product->id]['free_available'] ?? '0'),
    ])->values();
@endphp

<div x-data="{
        open: false,
        search: '',
        filter: 'all',
        sort: 'name',
        sheet: {{ Illuminate\Support\Js::from($sheet) }},

        /* পণ্য ধরে রাখা, সারির ক্রম ধরে নয় — নাহলে ছাঁকনি বদলালে বা
           খুঁজলে আগের টাইপ করা সংখ্যাগুলো অন্য পণ্যের ঘরে গিয়ে বসত।
           চারটা পরিমাণ লিখে পঞ্চমটা খোঁজা যেন প্রথম চারটা হারানোর উপায়
           না হয়। */
        typed: {},

        box(id) {
            if (! this.typed[id]) this.typed[id] = { qty: '', free: '' };
            return this.typed[id];
        },
        num(value) {
            const n = parseFloat(value);
            return Number.isFinite(n) ? n : 0;
        },
        hasSomething(id) {
            const row = this.typed[id];
            return !! row && (this.num(row.qty) > 0 || this.num(row.free) > 0);
        },

        get visible() {
            const needle = this.search.trim().toLowerCase();
            let rows = this.sheet;

            if (needle) {
                rows = rows.filter(r => r.name.toLowerCase().includes(needle)
                    || (r.code ?? '').toLowerCase().includes(needle));
            }
            if (this.filter === 'in_stock') rows = rows.filter(r => this.num(r.available) > 0);
            if (this.filter === 'typed') rows = rows.filter(r => this.hasSomething(r.id));

            const sorted = [...rows];
            if (this.sort === 'name') sorted.sort((a, b) => a.name.localeCompare(b.name));
            if (this.sort === 'available') sorted.sort((a, b) => this.num(b.available) - this.num(a.available));
            if (this.sort === 'typed') {
                sorted.sort((a, b) => (this.hasSomething(a.id) ? 0 : 1) - (this.hasSomething(b.id) ? 0 : 1)
                    || a.name.localeCompare(b.name));
            }
            return sorted;
        },

        /* তিনটা সংখ্যা — যা টাইপ করা হয়েছে তার উপর, যা দেখা যাচ্ছে তার
           উপর নয়। খুঁজলেই বদলে যায় এমন যোগফল কেউ বিশ্বাস করে না। */
        get totals() {
            let amount = 0, items = 0, free = 0;

            for (const row of this.sheet) {
                const box = this.typed[row.id];
                if (! box) continue;

                const qty = this.num(box.qty), freeQty = this.num(box.free);
                if (qty <= 0 && freeQty <= 0) continue;

                items += 1;
                free += freeQty;
                amount += qty * this.num(row.rate);
            }
            return { amount, items, free };
        },

        apply() {
            const rows = this.sheet
                .filter(row => this.hasSomething(row.id))
                .map(row => ({
                    product_id: row.id,
                    qty: this.typed[row.id].qty || '0',
                    free_qty: this.typed[row.id].free || '',
                    rate: row.rate,
                    discount: '', tax: '', link: '', unit_id: '',
                }));

            /* ইভেন্ট দিয়ে, সরাসরি নয় — শীটটা জানে না কে শুনছে, তাই
               একই শীট চালান, সরাসরি বিক্রয় ও ক্রয়েও বসানো যায়। */
            this.$dispatch('bulk-applied', { rows });

            this.open = false;
        },
     }">

    <button type="button" @click="open = true"
            class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                   transition-colors hover:bg-(--color-surface-hover)">
        {{ __('sales::bulk.open') }}
    </button>

    {{-- পুরো পর্দা জুড়ে, কারণ শীটটাই তখন কাজ — আর ছোট বাক্সে চারশো সারি
         মানে ভেতরে আরেকটা স্ক্রল, যেখানে মানুষ ভুল সারিতে টাইপ করেন। --}}
    <div x-show="open" x-cloak
         class="fixed inset-0 z-50 flex flex-col bg-(--color-surface-app)"
         role="dialog" aria-modal="true"
         @keydown.escape.window="open = false">

        <div class="flex flex-wrap items-center gap-x-8 gap-y-2 border-b border-(--color-border)
                    bg-(--color-surface-card) px-4 py-2">
            <div>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::bulk.total_amount') }}</p>
                <p class="tabular text-lg font-semibold" x-text="totals.amount.toFixed(2)"></p>
            </div>
            <div>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::bulk.total_items') }}</p>
                <p class="tabular text-lg font-semibold" x-text="totals.items"></p>
            </div>
            @if ($freeQty)
                <div>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::bulk.total_free') }}</p>
                    <p class="tabular text-lg font-semibold" x-text="totals.free"></p>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-(--color-border)
                    bg-(--color-surface-card) px-4 py-2">
            <input type="search" x-model="search" placeholder="{{ __('sales::bulk.search') }}"
                   class="h-(--spacing-field-compact) min-w-40 flex-1 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-sm">

            <select x-model="filter" aria-label="{{ __('core.toolbar.filter') }}"
                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-card) px-2 text-sm">
                <option value="all">{{ __('sales::bulk.filter_all') }}</option>
                <option value="in_stock">{{ __('sales::bulk.filter_in_stock') }}</option>
                <option value="typed">{{ __('sales::bulk.filter_typed') }}</option>
            </select>

            <select x-model="sort" aria-label="{{ __('core.toolbar.sort_by') }}"
                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-card) px-2 text-sm">
                <option value="name">{{ __('sales::bulk.sort_name') }}</option>
                <option value="available">{{ __('sales::bulk.sort_available') }}</option>
                <option value="typed">{{ __('sales::bulk.sort_typed') }}</option>
            </select>

            <span class="tabular text-xs text-(--color-ink-muted)"
                  x-text="visible.length + '/' + sheet.length"></span>
        </div>

        <div class="min-h-0 flex-1 overflow-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 bg-(--color-surface-card) text-2xs
                              uppercase text-(--color-ink-muted) shadow-sm">
                    <tr>
                        <th class="px-2 py-1.5 text-start font-semibold">{{ __('sales::field.product') }}</th>
                        <th class="px-1 py-1.5 text-end font-semibold">{{ __('sales::field.quantity') }}</th>
                        @if ($freeQty)
                            <th class="px-1 py-1.5 text-end font-semibold">{{ __('sales::bulk.free') }}</th>
                        @endif
                        <th class="px-1 py-1.5 text-end font-semibold">{{ __('sales::bulk.available') }}</th>
                        {{-- বাকি অবস্থাগুলো ডেস্কের জন্য। ফোনে ওগুলো থাকলে
                             প্রতিটা সারি আড়াআড়ি স্ক্রল হত, আর ওভাবেই মানুষ
                             ভুল সারিতে পরিমাণ লেখেন। --}}
                        <th class="hidden px-1 py-1.5 text-end font-semibold lg:table-cell">{{ __('sales::bulk.floor') }}</th>
                        <th class="hidden px-1 py-1.5 text-end font-semibold lg:table-cell">{{ __('sales::bulk.reserved') }}</th>
                        <th class="hidden px-1 py-1.5 text-end font-semibold lg:table-cell">{{ __('sales::bulk.hold') }}</th>
                        <th class="hidden px-1 py-1.5 text-end font-semibold lg:table-cell">{{ __('sales::field.rate') }}</th>
                        <th class="hidden px-2 py-1.5 text-end font-semibold lg:table-cell">{{ __('sales::field.amount') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-(--color-border)">
                    <template x-for="row in visible" :key="row.id">
                        <tr :class="hasSomething(row.id) ? 'bg-(--color-surface-hover)' : ''">
                            <td class="px-2 py-1">
                                <span class="text-(--color-ink-muted)" x-text="row.code"></span>
                                <span x-text="' ' + row.name"></span>
                                <span class="text-2xs text-(--color-ink-muted)" x-text="row.unit ? ' · ' + row.unit : ''"></span>
                            </td>

                            <td class="px-1 py-1">
                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                       x-model="box(row.id).qty"
                                       class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-1.5 text-end">
                            </td>

                            @if ($freeQty)
                                <td class="px-1 py-1">
                                    <input type="number" step="0.01" inputmode="decimal" min="0"
                                           x-model="box(row.id).free"
                                           class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-card) px-1.5 text-end">
                                </td>
                            @endif

                            <td class="num px-1 py-1 text-end" x-text="row.available"></td>
                            <td class="num hidden px-1 py-1 text-end lg:table-cell" x-text="row.floor"></td>
                            <td class="num hidden px-1 py-1 text-end lg:table-cell" x-text="row.reserved"></td>
                            <td class="num hidden px-1 py-1 text-end lg:table-cell" x-text="row.hold"></td>
                            <td class="num hidden px-1 py-1 text-end lg:table-cell" x-text="row.rate"></td>
                            <td class="num hidden px-2 py-1 text-end lg:table-cell"
                                x-text="(num(box(row.id).qty) * num(row.rate)).toFixed(2)"></td>
                        </tr>
                    </template>

                    <tr x-show="visible.length === 0">
                        <td colspan="9" class="px-3 py-6 text-center text-(--color-ink-muted)">
                            {{ __('core.empty.no_results') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-(--color-border)
                    bg-(--color-surface-card) px-4 py-2">
            <span class="text-xs text-(--color-ink-muted)">{{ __('sales::bulk.nothing_until_apply') }}</span>

            <div class="flex items-center gap-2">
                <x-ui.button type="button" tone="secondary" @click="open = false">
                    {{ __('core.action.cancel') }}
                </x-ui.button>

                <x-ui.button type="button" tone="primary" ::disabled="totals.items === 0" @click="apply()">
                    <span>{{ __('core.action.apply') }}</span>
                    <span class="tabular" x-text="'(' + totals.items + ')'"></span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
