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

                                    {{-- ⛔ কার্টের ঘরগুলো আর লেখার নয় — মালিকের নির্দেশ,
                                         ২৫ সেপ্টেম্বর ২০২৬।

                                         তাঁর কথা: *"পণ্যের cart list e entry bebosta
                                         takle upore za atkay ta niche edite atkay na"*।

                                         ── ⛔ যে ফাঁকটা এতদিন খোলা ছিল ───────────────
                                         এন্ট্রির বাক্সে প্রতিটা নিয়ম বসানো — দর নির্ধারিত
                                         দামের নিচে কি না, ফ্রি সীমা ছাড়িয়েছে কি না,
                                         মজুদ আছে কি না। ⚠️ কিন্তু সারিটা কার্টে ওঠার
                                         **পরে** ঐ ঘরগুলোতেই দর বা পরিমাণ বদলানো যেত,
                                         আর তখন **একটা নিয়মও চলত না**।

                                         ⓘ একই তথ্যের দুইটা দরজা, একটায় পাহারা — আর
                                         বাইরে থেকে সব ঠিক দেখায়। ⛔ দরজা দুইটা হলে
                                         ঢিলাটাই ব্যবহার হয়।

                                         ⭐ এখন সংখ্যাগুলো কেবল দেখা যায়, আর বদলানোর
                                         একটাই পথ: সম্পাদনার চিহ্নে চেপে সারিটা উপরের
                                         এন্ট্রি বাক্সে ফেরত নেওয়া, যেখানে সব পাহারা আছে।

                                         ⚠️ লুকানো ঘরগুলো ছাড়া চলে না — পড়ার ঘর জমা
                                         দেওয়ার সময় কিছুই পাঠায় না, আর তখন সার্ভারে
                                         প্রতিটা সারির দর ও পরিমাণ **খালি** যেত। --}}
                                    {{-- ⚠️ লেখাটা `<span>`-এ, লুকানো ঘরটা তার পাশে —
                                         ⛔ `x-text` ঘরটার **ভেতরটা মুছে** লেখা বসায়,
                                         তাই `<td>`-তে বসালে লুকানো ঘরটাও উড়ে যেত আর
                                         সার্ভারে প্রতিটা সারি খালি পৌঁছাত। --}}
                                    <td class="num cell text-end" data-label="{{ __('sales::field.unit_price') }}">
                                        <span x-text="money(line.rate)"></span>
                                        <input type="hidden" :name="'lines[' + (i) + '][rate]'" :value="line.rate">
                                    </td>

                                    <td class="num cell text-end" data-label="{{ __('sales::field.quantity') }}">
                                        <span x-text="qty(line.qty)"></span>
                                        <input type="hidden" :name="'lines[' + (i) + '][qty]'" :value="line.qty">
                                    </td>

                                    @if ($show['free_qty'])
                                        <td class="num cell text-end" data-label="{{ __('sales::field.free_unit') }}">
                                            <span x-text="line.freeQty ? qty(line.freeQty) : ''"></span>
                                            <input type="hidden" :name="'lines[' + (i) + '][free_qty]'"
                                                   :value="line.freeQty || ''">
                                        </td>
                                    @endif

                                    <td class="num cell" data-label="{{ __('sales::field.total_qty') }}"
                                        x-text="qty($num(line.qty || 0) + $num(line.freeQty || 0))"></td>

                                    @if ($show['line_discount'])
                                        <td class="num cell text-end" data-label="{{ __('sales::field.dis') }}">
                                            <span x-text="line.discountPercent || 0"></span>
                                            <input type="hidden" :name="'lines[' + (i) + '][discount_percent]'"
                                                   :value="line.discountPercent || 0">
                                        </td>
                                    @endif

                                    @if ($vatEnabled)
                                        <td class="num cell" data-label="{{ __('sales::field.vat') }}"
                                            x-text="money(lineVat(line))"></td>
                                    @endif

                                    <td class="num cell font-medium" data-label="{{ __('sales::field.amount') }}"
                                        x-text="money(lineNet(line))"></td>

                                    {{-- ⭐ সম্পাদনার চিহ্ন, `✕`-এর আগে — মালিকের নির্দেশ,
                                         ২৫ সেপ্টেম্বর ২০২৬: *"cance X cinner age edite
                                         cinno lagaw zate produts aber chart boxe ese
                                         edite hoy"*।

                                         ⓘ সারিটা কার্ট থেকে উঠে **এন্ট্রি বাক্সে** ফেরত
                                         যায় — সেখানেই দর, পরিমাণ, ফ্রি ও ছাড় বদলানো যায়,
                                         আর সেখানেই প্রতিটা নিয়ম বসানো।

                                         ⚠️ ক্রম ইচ্ছাকৃত: সম্পাদনা আগে, মোছা পরে। ⛔ দুইটা
                                         চিহ্ন পাশাপাশি থাকলে ধ্বংসাত্মকটা **শেষে** থাকা
                                         উচিত, নাহলে তাড়াহুড়োর ক্লিকটা ওখানেই পড়ে। --}}
                                    <td class="cell-input text-end">
                                        <span class="inline-flex items-center gap-0.5">
                                            {{-- ⚠️ লেখাটা স্থির, তাই সাধারণ অ্যাট্রিবিউট — `:aria-label`
                                                 নয়। ⛔ CSP-Alpine এক্সপ্রেশনে কেবল কম্পোনেন্টের নিজের
                                                 নাম ও অপারেটর চলে, আর কিছু না চললে সে **চুপচাপ বাঁধাই
                                                 ছেড়ে দেয়** — কনসোলে কিছু আসে না, শুধু ঘরটা খালি থাকে।
                                                 ⓘ পাশের `✕` বোতামটাও তাই করে। --}}
                                            <button type="button" @click="editLine(i)"
                                                    aria-label="{{ __('sales::action.edit_line') }}"
                                                    title="{{ __('sales::action.edit_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">
                                                <x-ui.icon name="edit" class="size-4" />
                                            </button>

                                            <button type="button" @click="lines.splice(i, 1)"
                                                    aria-label="{{ __('sales::action.remove_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">&times;</button>
                                        </span>
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
