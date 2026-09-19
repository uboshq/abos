            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) shadow-sm">
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('sales::field.sl') }}</th>
                                <th class="text-start">{{ __('sales::field.item_name') }}</th>
                                <th class="text-end">{{ __('sales::field.unit_price') }}</th>
                                <th class="text-end">{{ __('sales::field.quantity') }}</th>
                                @if ($show['free_qty'])
                                    <th class="text-end">{{ __('sales::field.free_unit') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.total_qty') }}</th>
                                @if ($show['line_discount'])
                                    <th class="text-end">{{ __('sales::field.dis') }}</th>
                                @endif
                                @if ($vatEnabled)
                                    <th class="text-end">{{ __('sales::field.vat') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <template x-for="(line, i) in lines" :key="line.key">
                        <tbody>
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell" x-text="i + 1"></td>

                                    <td class="cell" data-label="{{ __('sales::field.item_name') }}">
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="'lines[' + (i) + '][product_id]'" :value="line.id">
                                        {{-- বাছা প্যাকের একক — সার্ভার এটা দেখেই
                                             "২ বাক্স"-কে পিসে নামায়, দর সহ --}}
                                        <input type="hidden" :name="'lines[' + (i) + '][unit_id]'" :value="line.unitId || ''">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.unit_price') }}">
                                        <input type="number" step="0.0001" min="0" x-model="line.rate"
                                               :name="'lines[' + (i) + '][rate]'"
                                               class="num h-(--spacing-field-dense) w-full sm:w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.quantity') }}">
                                        <input type="number" step="0.01" min="0.01" x-model="line.qty"
                                               :name="'lines[' + (i) + '][qty]'"
                                               class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    @if ($show['free_qty'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.free_unit') }}">
                                            <input type="number" step="0.01" min="0" x-model="line.freeQty"
                                                   :name="'lines[' + (i) + '][free_qty]'"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    <td class="num cell" data-label="{{ __('sales::field.total_qty') }}"
                                        x-text="qty($num(line.qty || 0) + $num(line.freeQty || 0))"></td>

                                    @if ($show['line_discount'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.dis') }}">
                                            <input type="number" step="0.01" min="0" max="100"
                                                   x-model="line.discountPercent"
                                                   :name="'lines[' + (i) + '][discount_percent]'"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    @if ($vatEnabled)
                                        <td class="num cell" data-label="{{ __('sales::field.vat') }}"
                                            x-text="money(lineVat(line))"></td>
                                    @endif

                                    <td class="num cell font-medium" data-label="{{ __('sales::field.amount') }}"
                                        x-text="money(lineNet(line))"></td>

                                    <td class="cell-input text-end">
                                        <button type="button" @click="lines.splice(i, 1)"
                                                aria-label="{{ __('sales::action.remove_line') }}"
                                                class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                       hover:bg-(--color-surface-hover)">&times;</button>
                                    </td>
                                </tr>

                                {{--
                                    ── উপহার — লাইনের নিচে, ভেতরে ────────────

                                    ── কেন সন্তান-সারি, আলাদা টেবিল নয় ───────
                                    মালিকের নমুনা (৩ সেপ্টেম্বর ২০২৬): উপহারটা
                                    পণ্যের ঠিক নিচে, `↳` চিহ্ন দিয়ে।

                                    ⚠️ **এতে "কোন পণ্যের জন্য" প্রশ্নটাই মুছে
                                    যায়** — উত্তরটা জায়গাতেই লেখা। আগে ওটা
                                    একটা ড্রপডাউন ছিল, আর সেটা খালি থাকলে
                                    উপহার কোনো পণ্যের সাথে বাঁধা পড়ত না।

                                    ⓘ NEXUS-এ ঠিক এই ভুলটাই একবার হয়েছিল, আর
                                    সেখানেও সমাধানটা এটাই — ড্রপডাউন তুলে
                                    দিয়ে উপহারকে লাইনের ভেতরে নেওয়া।

                                    দাম-ছাড়-ভ্যাটের ঘরে ড্যাশ, কারণ **উপহারের
                                    দাম নেই** — থাকলে ওটা বিক্রি হয়ে যেত।
                                --}}
                                <template x-for="(gift, g) in line.gifts" :key="gift.key">
                                    <tr class="border-b border-(--color-border) bg-(--color-badge-pending-bg)/40 text-2xs">
                                        <td class="cell text-end text-(--color-badge-pending-ink)">↳</td>

                                        {{-- ⓘ ইন্ডেন্টটা টোকেন থেকে — হাতে লেখা একটা ধ্রুবক
                                             ঘন থিমে একা আগের মাপে বসে থাকত, আর
                                             উপহারের সারিটা মূল সারির সাথে আর
                                             সারিবদ্ধ থাকত না। --}}
                                        <td class="cell text-(--color-badge-pending-ink)"
                                            style="padding-inline-start: calc(var(--grid-pad-x) * 2)"
                                            data-label="{{ __('sales::field.item_name') }}">
                                            🎁 <span x-text="productName(gift.productId)"></span>
                                        </td>

                                        <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        <td class="cell text-end text-(--color-ink-muted)">—</td>

                                        @if ($show['free_qty'])
                                            <td class="num cell text-(--color-badge-pending-ink)"
                                                data-label="{{ __('sales::field.free_unit') }}"
                                                x-text="qty($num(gift.qty || 0)) + ' ' + (line.unit || '')"></td>
                                        @endif

                                        <td class="num cell text-(--color-badge-pending-ink)"
                                            data-label="{{ __('sales::field.total_qty') }}"
                                            x-text="qty($num(gift.qty || 0))"></td>

                                        @if ($show['line_discount'])
                                            <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        @endif

                                        @if ($vatEnabled)
                                            <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        @endif

                                        <td class="cell text-end italic text-(--color-badge-pending-ink)"
                                            data-label="{{ __('sales::field.amount') }}"
                                            x-text="gift.remarks"></td>

                                        <td class="cell-input text-end">
                                            <button type="button" @click="removeGift(i, g)"
                                                    aria-label="{{ __('sales::action.remove_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </template>
                    </table>
                </div>

                <p x-show="lines.length === 0" x-cloak
                   class="p-8 text-center text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.nothing_added') }}
                </p>
            </section>
