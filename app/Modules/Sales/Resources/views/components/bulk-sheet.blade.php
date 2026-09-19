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

    /*
     * খোলার বোতামের চেহারা — ডাকা জায়গা ঠিক করে দিতে পারে।
     *
     * ── কেন এই prop লাগল (৩ সেপ্টেম্বর ২০২৬) ─────────────────────────
     * সরাসরি বিক্রয়ে ছয়টা বোতাম এক সারিতে বসে, আর মালিক চেয়েছেন **ছয়টাই
     * এক মাপে, আর প্রতিটার আলাদা রঙ**। এই কম্পোনেন্টটা নিজের বোতাম নিজে
     * আঁকে, তাই বাইরের নিয়ম না জানলে **ওটা একাই আলাদা দেখাত** — আর ঠিক
     * সেটাই হয়েছিল: "চার্ট" বোতামটা বাকি পাঁচটার চেয়ে সরু ছিল।
     *
     * ⚠️ ডিফল্টটা আগের মতোই, তাই বাকি পর্দাগুলো (চালানের ফর্ম) অপরিবর্তিত।
     */
    'buttonClass' => 'rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                      transition-colors hover:bg-(--color-surface-hover)',
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

<div x-data="bulkSheet({
                 sheet: @js($sheet),
               })">

    <button type="button" @click="open = true" class="{{ $buttonClass }}">
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
            <table class="ui-grid is-sheet w-full text-sm">
                <thead class="sticky top-0 z-10 bg-(--color-surface-card) text-2xs
                              uppercase text-(--color-ink-muted) shadow-sm">
                    <tr>
                        <th class="pad-wide text-start font-semibold">{{ __('sales::field.product') }}</th>
                        <th class="text-end font-semibold">{{ __('sales::field.quantity') }}</th>
                        @if ($freeQty)
                            <th class="text-end font-semibold">{{ __('sales::bulk.free') }}</th>
                        @endif
                        <th class="text-end font-semibold">{{ __('sales::bulk.available') }}</th>
                        {{-- বাকি অবস্থাগুলো ডেস্কের জন্য। ফোনে ওগুলো থাকলে
                             প্রতিটা সারি আড়াআড়ি স্ক্রল হত, আর ওভাবেই মানুষ
                             ভুল সারিতে পরিমাণ লেখেন। --}}
                        <th class="hidden text-end font-semibold lg:table-cell">{{ __('sales::bulk.floor') }}</th>
                        <th class="hidden text-end font-semibold lg:table-cell">{{ __('sales::bulk.reserved') }}</th>
                        <th class="hidden text-end font-semibold lg:table-cell">{{ __('sales::bulk.hold') }}</th>
                        <th class="hidden text-end font-semibold lg:table-cell">{{ __('sales::field.rate') }}</th>
                        <th class="pad-wide hidden text-end font-semibold lg:table-cell">{{ __('sales::field.amount') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-(--color-border)">
                    <template x-for="row in visible" :key="row.id">
                        <tr :class="hasSomething(row.id) ? 'bg-(--color-surface-hover)' : ''">
                            <td class="pad-wide">
                                <span class="text-(--color-ink-muted)" x-text="row.code"></span>
                                <span x-text="' ' + row.name"></span>
                                <span class="text-2xs text-(--color-ink-muted)" x-text="row.unit ? ' · ' + row.unit : ''"></span>
                            </td>

                            <td>
                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                       x-model="box(row.id).qty"
                                       class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-1.5 text-end">
                            </td>

                            @if ($freeQty)
                                <td>
                                    <input type="number" step="0.01" inputmode="decimal" min="0"
                                           x-model="box(row.id).free"
                                           class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-card) px-1.5 text-end">
                                </td>
                            @endif

                            <td class="num" x-text="row.available"></td>
                            <td class="num hidden lg:table-cell" x-text="row.floor"></td>
                            <td class="num hidden lg:table-cell" x-text="row.reserved"></td>
                            <td class="num hidden lg:table-cell" x-text="row.hold"></td>
                            <td class="num hidden lg:table-cell" x-text="row.rate"></td>
                            <td class="num pad-wide hidden lg:table-cell"
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
