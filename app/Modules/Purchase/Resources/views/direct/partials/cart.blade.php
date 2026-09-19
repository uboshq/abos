            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="overflow-x-auto">
                    <table class="ui-grid is-compact w-full text-sm">
                        {{-- ── কলামের ক্রম — মালিকের ছবির ক্রম ────────────────

                             ```
                             SL# · Item Name · Rate · Qty · Free Unit ·
                             Total Qty. · Sales Price · VAT · Amount
                             ```

                             ⓘ দর পরিমাণের **আগে**, আর সেটা ইচ্ছাকৃত: কাউন্টারে
                             চোখ আগে দরে যায় (ওটাই দরাদরির সংখ্যা), পরিমাণটা
                             কাগজ দেখে টোকা হয়।

                             ⚠️ ছাড়ের কলামটা ছবিতে নেই, তবু আছে — ঘরটা সত্যি,
                             আর "এই লাইনে" ছকে ছাড় লেখা যায়। ⛔ কলাম না থাকলে
                             সারিটা কার্টে যাওয়ার পর ছাড়টা আর দেখাই যেত না,
                             বদলানো তো দূরের কথা। --}}
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('core.table.serial') }}</th>
                                <th class="text-start">{{ __('purchase::field.product') }}</th>
                                <th class="text-end">{{ __('purchase::field.rate') }}</th>
                                <th class="text-end">{{ __('purchase::field.qty') }}</th>
                                @if ($show['free_qty'])
                                    <th class="text-end">{{ __('purchase::field.free_qty') }}</th>
                                @endif
                                <th class="text-end">{{ __('purchase::field.line_qty_total') }}</th>
                                <th class="text-end">{{ __('purchase::field.sales_price') }}</th>
                                @if ($show['line_discount'])
                                    <th class="text-end">{{ __('purchase::field.discount') }}</th>
                                @endif
                                @if ($show['vat'])
                                    <th class="text-end">{{ __('purchase::field.tax') }}</th>
                                @endif
                                <th class="text-end">{{ __('purchase::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(line, index) in lines" :key="line.key">
                                <tr class="border-t border-(--color-border)">
                                    <td class="num" x-text="index + 1"></td>
                                    <td>
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="'lines[' + (index) + '][product_id]'" :value="line.id">
                                        <input type="hidden" :name="'lines[' + (index) + '][sales_price]'"
                                               :value="line.sales_price">

                                        {{-- ⭐ গতবারের দর — সারিতেই, ভাসমান নয়।

                                             এন্ট্রি স্ট্রিপেও একটা "শেষ ক্রয়দর" আছে, কিন্তু
                                             সেটা সারিটা কার্টে যাওয়ামাত্র মিলিয়ে যায়। বারো
                                             লাইন পরে, তিনটা গতবারের চেয়ে দামি — আর যিনি
                                             চূড়ান্ত বোতাম চাপতে যাচ্ছেন তিনি জানতেই পারতেন না।

                                             ⚠️ দুইটা সংখ্যা দুইটা আলাদা প্রশ্নের উত্তর:
                                               স্ট্রিপেরটা  কোম্পানি-ব্যাপী শেষ দর, যে কারো কাছ থেকে
                                               এইটা         **এই সরবরাহকারীর** কাছ থেকে — দরাদরির সংখ্যা
                                             তাই লেবেল দুইটাও আলাদা। --}}
                                        <template x-if="lastRateFor(line)">
                                            <span class="block text-2xs text-(--color-ink-muted)">
                                                {{ __('purchase::message.last_from_supplier') }}:
                                                <span class="num" x-text="money(lastRateFor(line).rate)"></span>
                                                <span x-text="'· ' + (lastRateFor(line).on)"></span>
                                            </span>
                                        </template>

                                        {{-- ⛔ শূন্য লেখা হয় না।

                                             "গতবারের দর" শিরোনামের নিচে ০.০০ মানে "ফ্রি
                                             দিয়েছিল", আর সেটা মিথ্যা। আগে কখনো না কেনা থাকলে
                                             কথাটা সরাসরি লেখা থাকে। --}}
                                        <template x-if="supplierChosen && ! lastRateFor(line)">
                                            <span class="block text-2xs text-(--color-ink-muted)">
                                                {{ __('purchase::message.first_from_supplier') }}
                                            </span>
                                        </template>
                                    </td>
                                    {{-- দর — পরিমাণের আগে, ছবির ক্রম --}}
                                    <td>
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="'lines[' + (index) + '][rate]'" x-model="line.rate"
                                               class="num h-(--spacing-field-dense) w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    </td>

                                    {{-- ⓘ একক দুইটা লুকানো ঘরে যায়, আর নামটা
                                         সংখ্যার নিচে ছোট করে লেখা থাকে। ⚠️ না
                                         লিখলে কার্টে "১" দেখে বোঝার উপায় থাকত
                                         না ওটা এক বাক্স না এক পিস — আর ওই
                                         ভুলটার দাম একশো গুণ। --}}
                                    <td>
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="'lines[' + (index) + '][qty]'" x-model="line.qty"
                                               class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        <input type="hidden" :name="'lines[' + (index) + '][unit_id]'" :value="line.unit_id">
                                        <span class="block text-end text-2xs text-(--color-ink-muted)"
                                              x-text="unitName(line.unit_id, line)"></span>
                                    </td>

                                    @if ($show['free_qty'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + (index) + '][free_qty]'" x-model="line.free_qty"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                            <input type="hidden" :name="'lines[' + (index) + '][free_unit_id]'"
                                                   :value="line.free_unit_id">
                                            <span class="block text-end text-2xs text-(--color-ink-muted)"
                                                  x-text="unitName(line.free_unit_id, line)"></span>
                                        </td>
                                    @endif

                                    {{-- ছবির `Total Qty.` — কেনা আর ফ্রি একসাথে,
                                         অর্থাৎ গুদামে সত্যিই কতটা ঢুকছে --}}
                                    <td class="num" x-text="qty(lineTotalQty(line))"></td>

                                    <td class="num text-(--color-ink-muted)"
                                        x-text="line.sales_price ? money(line.sales_price) : '—'"></td>

                                    @if ($show['line_discount'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + (index) + '][discount]'" x-model="line.discount"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif

                                    @if ($show['vat'])
                                        {{-- ── ভ্যাট — সারিটা নিজের ধরন মনে রাখে ──

                                             ⛔ ঘরটা **নেই** যখন ধরনটা "পণ্য
                                             অনুযায়ী" — আর সেটাই মূল কথা: নাম
                                             ছাড়া ঘরটা সার্ভারে পৌঁছায় না, আর
                                             তখন [[CalculatesLineTotals]] পণ্যের
                                             নিজের হার থেকে কষে।

                                             ⚠️ পাশে সংখ্যাটা তবু দেখা যায়, কারণ
                                             "কষে নেবে" মানে "দেখা যাবে না" নয়।
                                             ⓘ পর্দার আর সার্ভারের অঙ্ক একই সূত্রে
                                             (হার ও `is_inclusive` দুইটাই পণ্য
                                             থেকে আসে), তাই দুইটা মেলে। --}}
                                        <td>
                                            <template x-if="line.vat_mode === 'amount'">
                                                <input type="number" step="0.01" inputmode="decimal"
                                                       :name="'lines[' + (index) + '][tax]'" x-model="line.tax"
                                                       class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                            </template>

                                            <template x-if="line.vat_mode === 'none'">
                                                <input type="hidden" :name="'lines[' + (index) + '][tax]'" value="0">
                                            </template>

                                            <span class="block text-end text-2xs text-(--color-ink-muted)"
                                                  x-show="line.vat_mode !== 'amount'"
                                                  x-text="money(lineTax(line))"></span>
                                        </td>
                                    @endif

                                    <td class="num font-medium" x-text="money(lineNet(line))"></td>
                                    <td class="text-end">
                                        <div class="flex items-center justify-end gap-1">
                                            {{-- উপহার যোগ — এই সারির সাথে বাঁধা --}}
                                            <button type="button" @click="addGift(line)"
                                                    class="rounded-(--radius-field) border border-(--color-border)
                                                           px-2 py-0.5 text-2xs text-(--color-ink-muted)
                                                           hover:text-(--color-ink)">
                                                {{ __('purchase::action.add_gift') }}
                                            </button>

                                            <button type="button" @click="lines.splice(index, 1)"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-danger)"
                                                    aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            {{-- ── উপহারের সারিগুলো ─────────────────────────────

                                 ⚠️ কেন মূল সারির **নিচে**, আলাদা কোনো তালিকায় নয়:
                                 মালিকের নির্দেশ — *"উপহার কোন পণ্যের সাথে আসল তাও
                                 manage করতে হবে"*। আলাদা তালিকায় বসালে পর্দাতেও
                                 জোড়াটা দেখা যেত না, আর মানুষটা ভুল পণ্যের সাথে
                                 জুড়ে দিতেন।

                                 ⓘ নামের চাবিতে `line.key` ব্যবহার করা হয়, ক্রমিক
                                 সংখ্যা নয় — মাঝখানের একটা সারি মুছে দিলে ক্রমিক
                                 সংখ্যাগুলো পিছিয়ে যেত আর দুইটা উপহার একই নামে
                                 জমা পড়ত। --}}
                            <template x-for="line in lines" :key="'g' + (line.key)">
                                <template x-for="(gift, gi) in line.gifts" :key="gift.key">
                                    <tr class="bg-(--color-surface-sunken)/50">
                                        <td></td>
                                        {{-- ⓘ সারিটা টেবিলের **বাকি সব কলাম জুড়ে** — মালিক:
                                             *"gift likha ek line daw"*।

                                             ⛔ আগে `colspan="3"` ছিল, তাই উপহারের ঘরগুলো
                                             তিন কলামের প্রস্থে আটকে দুই লাইনে ভাঁজ হত।
                                             ⚠️ `flex-wrap`-ও ছিল, অর্থাৎ ভাঁজটা ইচ্ছাকৃত
                                             দেখাত — কিন্তু জায়গা থাকতেও ভাঁজ হওয়ার কোনো
                                             কারণ নেই।

                                             ⓘ `colspan` বেশি দেওয়া নিরাপদ: ব্রাউজার
                                             টেবিলের আসল কলাম-সংখ্যায় নামিয়ে আনে, তাই
                                             একটা কলাম যোগ-বিয়োগ হলেও সারিটা ভাঙে না। --}}
                                        <td colspan="20">
                                            <div class="flex items-center gap-2 whitespace-nowrap">
                                                <span class="rounded-(--radius-field) bg-(--color-badge-info-bg)
                                                             px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                                    {{ __('purchase::field.gift') }}
                                                </span>

                                                <select :name="'gifts[' + (line.key) + '-' + (gi) + '][product_id]'"
                                                        x-model="gift.product_id" required
                                                        class="h-(--spacing-field-dense) rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-card)
                                                               px-1 text-xs">
                                                    {{-- ⛔ এখানে একবার **এককের** লেখা বসে গিয়েছিল।

                                                         ⚠️ একটা স্ক্রিপ্ট দিয়ে সব `<option value="">—</option>`
                                                         একসাথে বদলাতে গিয়ে এই একটাও ধরা পড়েছিল — অথচ এটা
                                                         **পণ্যের** তালিকা, এককের নয়। ⓘ ফলে উপহারের ঘরে
                                                         প্রথম বিকল্প হিসেবে `pcs` লেখা উঠত।

                                                         ⭐ পাঠ: একই দেখতে দুইটা জিনিস এক নিয়মে বদলানো যায় না —
                                                         **কোন তালিকা কীসের, সেটা দেখেই বদলাতে হয়।** --}}
                                                    <option value="">—</option>
                                                    <template x-for="p in catalogue" :key="p.id">
                                                        <option :value="p.id" x-text="p.name"></option>
                                                    </template>
                                                </select>

                                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                                       :name="'gifts[' + (line.key) + '-' + (gi) + '][qty]'" x-model="gift.qty"
                                                       placeholder="{{ __('purchase::field.qty') }}"
                                                       class="num h-(--spacing-field-dense) w-16 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card)
                                                              px-1 text-end text-xs">

                                                {{-- ── উপহারের একক ────────────────────────────────

                                                     মালিক (৬ সেপ্টেম্বর ২০২৬):
                                                     *"Qty er pase UoM dropdown dite hobe"*।

                                                     ⭐ ঘরটা নতুন, কিন্তু **পথটা আগে থেকেই ছিল**:
                                                     সার্ভার `gifts.*.unit_id` যাচাই করে,
                                                     `packed()` ওটা দিয়ে বেস এককে নামায়, আর
                                                     টেবিলে `entered_unit_id` কলামও আছে।
                                                     ⛔ কেবল **পর্দায় ঘরটা ছিল না**, তাই মান
                                                     কোনোদিন যেত না — একটা পূর্ণ পথ, একটা ফাঁকা
                                                     মুখ। ⓘ আজকের চেনা ছাঁচ: ঘর না থাকায়
                                                     ক্ষমতাটা ঘুমিয়ে ছিল।

                                                     ⚠️ মিল কার্টনে উপহার দেয়, গুনতি হয় পিসে —
                                                     একক না বললে ১ কার্টন ১ পিস হয়ে বসত।

                                                     ⓘ খালি মানে পণ্যের নিজের একক, ঠিক উপরের
                                                     সারির নিয়মেই। --}}
                                                <select :name="'gifts[' + (line.key) + '-' + (gi) + '][unit_id]'"
                                                        x-model="gift.unit_id"
                                                        class="h-(--spacing-field-dense) rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-card)
                                                               px-1 text-xs">
                                                    <option value="" x-text="unitLabelFor(gift.product_id)"></option>
                                                    <template x-for="u in unitsFor(gift.product_id)" :key="u.id">
                                                        <option :value="u.id" x-text="u.label"></option>
                                                    </template>
                                                </select>

                                                <input type="text" maxlength="191"
                                                       :name="'gifts[' + (line.key) + '-' + (gi) + '][remarks]'"
                                                       x-model="gift.remarks"
                                                       placeholder="{{ __('purchase::field.narration') }}"
                                                       class="h-(--spacing-field-dense) w-40 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card)
                                                              px-2 text-xs">

                                                {{-- ⭐ জোড়াটা এখানেই বসে, আর মানুষটাকে বাছতে হয় না।

                                                     সারিটা যে পণ্যের, উপহারটা তার বিপরীতেই — সেটাই
                                                     পর্দায় লেখা আছে, আর সেটাই সার্ভারে যায়। বাছতে
                                                     দিলে একদিন ভুল পণ্য বাছা হত, আর তখন "সাবানে আসল
                                                     ক্রয়দর কত পড়ল" হিসাবটা নীরবে ভুল হত। --}}
                                                <input type="hidden"
                                                       :name="'gifts[' + (line.key) + '-' + (gi) + '][against_product_id]'"
                                                       :value="line.id">

                                                <span class="text-2xs text-(--color-ink-muted)">
                                                    {{ __('purchase::field.gift_against') }}:
                                                    <span x-text="line.name"></span>
                                                </span>
                                            </div>
                                        </td>
                                        {{-- বাকি কলামগুলো ফাঁকা।

                                             সংখ্যাটা Blade গোনে, JS নয় — কোন ঘরগুলো
                                             দেখা যাবে সেটা সেটিংসের সিদ্ধান্ত, আর সেটা
                                             পাতা তৈরির সময়েই জানা। JS-এ গুনলে একই কথা
                                             দুই জায়গায় থাকত। --}}
                                        {{-- ⚠️ সংখ্যাটা কলামের ক্রম বদলানোর সাথে
                                             বেড়েছে (মোট ৮ + তিনটা সুইচ): ক্রমিক ১,
                                             পণ্যের ঘরটা ৩, আর শেষে মোছার ঘর ১ —
                                             বাকিটা এখানে। ⓘ ভুল হলে সারিটা এক
                                             কলাম সরে বসত, আর ছকটা এলোমেলো দেখাত। --}}
                                        <td colspan="{{ 3
                                            + ($show['free_qty'] ? 1 : 0)
                                            + ($show['line_discount'] ? 1 : 0)
                                            + ($show['vat'] ? 1 : 0) }}"></td>
                                        <td class="text-end">
                                            <button type="button" @click="line.gifts.splice(gi, 1)"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-danger)"
                                                    aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </template>

                            <tr x-show="lines.length === 0" x-cloak>
                                <td colspan="{{ 8
                                        + ($show['free_qty'] ? 1 : 0)
                                        + ($show['line_discount'] ? 1 : 0)
                                        + ($show['vat'] ? 1 : 0) }}"
                                    class="text-center text-sm text-(--color-ink-muted)">
                                    {{-- ⭐ খালি ছকটা কেবল "কিছু নেই" বলে না, **পরের
                                         কাজটা** বলে — মালিকের স্ক্রিনশটের বাক্যটাই।
                                         ⓘ পুরনো `no_lines_yet` রয়ে গেল: ক্রয়ের অন্য
                                         পর্দাগুলো ওটাই ব্যবহার করে, আর সেখানে কার্ট
                                         নেই বলে এই বাক্যটা ভুল হত। --}}
                                    {{ __('purchase::message.cart_empty_hint') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
