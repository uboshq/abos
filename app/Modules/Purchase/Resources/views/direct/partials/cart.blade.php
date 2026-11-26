            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                {{-- ⭐ কেবল পণ্যের তালিকা নিজে সরে — বাকি সব ঘর জায়গায় থাকে (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v9)।
                     ⓘ মাথার সারি আটকে থাকে (`sticky`), তাই নিচে নামলেও কলামের নাম চোখে থাকে। --}}
                <div data-cart-scroll class="max-h-[calc(100dvh-29rem)] min-h-48 overflow-auto">
                    <table class="ui-grid is-compact w-full text-sm [&_tbody]:border-0">
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
                        <thead class="sticky top-0 z-10 bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
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
                                <th class="text-end bg-(--color-badge-danger-bg)/60">{{ __('supplier::labour.unloading_col') }}</th>
                                <th><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                            </tr>
                        </thead>

                        {{-- ⭐ প্রতিটা পণ্য নিজের `<tbody>`-তে — তার উপহার ঠিক তার নিচেই (বিক্রয়ের কার্টের মতো; মালিক, ২৬ নভেম্বর ২০২৬)।
                             ⓘ আগে সব উপহার তালিকার শেষে একসাথে বসত, আর কোনটা কার সাথে তা চোখে মেলাতে হত। --}}
                            <template x-for="(line, index) in lines" :key="line.key">
                                <tbody>
                                {{-- ⓘ উপরে খোলা সারিটা রঙে আলাদা — কোনটা হালনাগাদ হবে, চোখে পড়ে --}}
                                {{-- ⓘ দাগ একটাই, চিকন আর হালকা — `.ui-grid tbody tr`-এর নিচের দাগই যথেষ্ট (মালিক, ২৬ নভেম্বর ২০২৬: "আরও চিকন") --}}
                                <tr class="border-(--color-border)/50"
                                    :class="line.key === editingKey ? 'bg-(--color-badge-pending-bg)' : ''">
                                    <td class="num" x-text="index + 1"></td>
                                    <td>
                                        {{-- ⓘ গতবারের দর (এই সরবরাহকারীর) — নামের উপর মাউস রাখলে; সারিতে আলাদা লাইন নয় (নমুনা v9) --}}
                                        <span :id="'dc-' + index + '-product'" class="whitespace-normal break-words font-medium"
                                              :title="lastRateFor(line) ? (@js(__('purchase::message.last_from_supplier')) + ': ' + money(lastRateFor(line).rate) + ' · ' + lastRateFor(line).on) : ''"
                                              x-text="line.name"></span>
                                        <input type="hidden" :name="'lines[' + (index) + '][product_id]'" :value="line.id">
                                        <input type="hidden" :name="'lines[' + (index) + '][sales_price]'"
                                               :value="line.sales_price">

                                        {{-- ⭐ লট — উপরের হলুদ বাক্সে লেখা হয় (নমুনা v9); এখানে কেবল এক ছোট লাইন, আর ঘরগুলো লুকানো।
                                             ⓘ খালি লট সংরক্ষণে কাগজের তারিখে নিজে বসে ([[PurchaseLots]]) — আগের মতোই। বদলাতে ⋯ → সম্পাদনা। --}}
                                        <template x-if="tracksLot(line)">
                                            <span data-lot-line class="block text-2xs text-(--color-ink-muted)">
                                                <span x-show="line.batch_no" x-text="@js(__('inventory::field.batch_no')) + ' ' + line.batch_no"></span>
                                                <span x-show="! line.batch_no">{{ __('purchase::lot.auto_short') }} · <span x-text="lotHint"></span></span>
                                                <span x-show="line.expiry_date" x-cloak x-text="' · ' + @js(__('inventory::field.expiry_date')) + ' ' + line.expiry_date"></span>
                                                <input type="hidden" :name="'lines[' + (index) + '][batch_no]'" :value="line.batch_no || ''">
                                                <input type="hidden" :name="'lines[' + (index) + '][expiry_date]'" :value="line.expiry_date || ''">
                                                <input type="hidden" :name="'lines[' + (index) + '][mrp]'" :value="line.mrp || ''">
                                            </span>
                                        </template>

                                        {{-- ⓘ সার্ভারের লট-বার্তা — সারির ঠিক নিচে --}}
                                        <p x-show="lotProblem(line, index)" x-cloak role="alert"
                                           class="mt-1 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-0.5
                                                  text-2xs text-(--color-badge-danger-ink)"
                                           x-text="lotProblem(line, index)"></p>

                                        {{-- ⭐ হাতে বসানো আনলোডিং দর, কার্টনপ্রতি — এখন "আনলোডিং বিল" পপআপে, "পণ্য ধরে" বাছলে (মালিক, ২৬ নভেম্বর ২০২৬)।
                                             ⓘ "পুরো চালানে একটা" হলে খালি যায় — তখন সার্ভার পণ্যের নিজের দর বা সমান ভাগ ধরে ([[UnloadingSplit]])। --}}
                                        <input type="hidden" data-unload-rate :name="'lines[' + (index) + '][unload_rate]'" :value="unloadRateOf(line)">
                                    </td>
                                    {{-- ── ⛔ কার্টে লেখার ঘর নেই — মালিক, ৩ অক্টোবর ২০২৬ ──────────────
                                         দর, পরিমাণ, ফ্রি আর ছাড় কেবল দেখা যায়; বদলাতে ✎ — সারিটা উপরের বাক্সে
                                         ফেরে, যেখানে দর ০ আর বিক্রয়দরের পাহারা আছে ([[editLine()]])। ⚠️ লুকানো
                                         ঘরগুলো ছাড়া চলে না: পড়ার লেখা জমা দেওয়ার সময় কিছুই পাঠায় না। ⓘ লেখা
                                         `<span>`-এ, ঘরটা পাশে — `x-text` `<td>`-তে বসালে লুকানো ঘরটা মুছে যেত। --}}
                                    {{-- দর — পরিমাণের আগে, ছবির ক্রম --}}
                                    <td class="num text-end">
                                        <span x-text="money(line.rate)"></span>
                                        <input type="hidden" :name="'lines[' + (index) + '][rate]'" :value="line.rate">
                                    </td>

                                    {{-- ⓘ একক দুইটা লুকানো ঘরে যায়, আর নামটা
                                         সংখ্যার নিচে ছোট করে লেখা থাকে। ⚠️ না
                                         লিখলে কার্টে "১" দেখে বোঝার উপায় থাকত
                                         না ওটা এক বাক্স না এক পিস — আর ওই
                                         ভুলটার দাম একশো গুণ। --}}
                                    <td class="num text-end">
                                        <span x-text="qty(line.qty)"></span>
                                        <span class="text-2xs text-(--color-ink-muted)" x-text="unitName(line.unit_id, line)"></span>
                                        <input type="hidden" :name="'lines[' + (index) + '][qty]'" :value="line.qty">
                                        <input type="hidden" :name="'lines[' + (index) + '][unit_id]'" :value="line.unit_id">
                                    </td>

                                    @if ($show['free_qty'])
                                        <td class="num text-end">
                                            <span x-text="line.free_qty ? qty(line.free_qty) : ''"></span>
                                            <input type="hidden" :name="'lines[' + (index) + '][free_qty]'"
                                                   :value="line.free_qty || ''">
                                            <input type="hidden" :name="'lines[' + (index) + '][free_unit_id]'"
                                                   :value="line.free_unit_id">
                                            <span x-show="line.free_qty" class="text-2xs text-(--color-ink-muted)"
                                                  x-text="unitName(line.free_unit_id, line)"></span>
                                        </td>
                                    @endif

                                    {{-- ছবির `Total Qty.` — কেনা আর ফ্রি একসাথে,
                                         অর্থাৎ গুদামে সত্যিই কতটা ঢুকছে --}}
                                    <td class="num" x-text="qty(lineTotalQty(line))"></td>

                                    <td class="num text-(--color-ink-muted)"
                                        x-text="line.sales_price ? money(line.sales_price) : '—'"></td>

                                    @if ($show['line_discount'])
                                        <td class="num text-end">
                                            <span x-text="line.discount ? money(line.discount) : ''"></span>
                                            <input type="hidden" :name="'lines[' + (index) + '][discount]'"
                                                   :value="line.discount || ''">
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
                                                       aria-label="{{ __('purchase::field.tax') }}" :id="'dc-' + index + '-tax'"
                                                       :aria-labelledby="'dc-' + index + '-tax dc-' + index + '-product'"
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
                                    {{-- ⭐ এই সারির আনলোডিংয়ের ভাগ — পপআপে বসানোর পরে (নমুনা v9) --}}
                                    <td class="num bg-(--color-badge-danger-bg)/40" x-text="unloadShareOf(line) > 0 ? money(unloadShareOf(line)) : '—'"></td>
                                    <td class="text-end">
                                        <div x-data="{ acts: false }" class="relative inline-block">
                                            <button type="button" @click="acts = ! acts" data-row-acts
                                                    aria-label="{{ __('core.table.actions') }}"
                                                    class="rounded-(--radius-field) border border-(--color-border) px-2 leading-6 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">⋯</button>
                                            {{-- ⛔ CSP-র Alpine এক হ্যান্ডলারে দুই কাজ (`a(); b = c`) চালায় না — তাই বোতাম কেবল কাজটা করে, আর তালিকাটা
                                                 নিজের ক্লিকে বন্ধ হয় (বোতামের ক্লিক এখানে উঠে আসে) — মালিক, ২৬ নভেম্বর ২০২৬: "মুছতেছে না" --}}
                                            <div x-show="acts" x-cloak @click.outside="acts = false" @click="acts = false"
                                                 class="absolute end-0 z-20 mt-1 w-40 rounded-(--radius-field) border border-(--color-border)
                                                        bg-(--color-surface-card) py-1 text-start text-sm shadow-lg">
                                                {{-- উপহার যোগ — এই সারির সাথে বাঁধা --}}
                                                <button type="button" @click="addGift(line)"
                                                        class="block w-full px-3 py-1.5 text-start hover:bg-(--color-surface-hover)">🎁 {{ __('purchase::action.add_gift') }}</button>
                                                <button type="button" @click="editLine(index)" data-edit-line
                                                        class="block w-full px-3 py-1.5 text-start hover:bg-(--color-surface-hover)">✎ {{ __('purchase::action.edit_line') }}</button>
                                                <button type="button" @click="dropLine(index)"
                                                        class="block w-full px-3 py-1.5 text-start text-(--color-danger) hover:bg-(--color-surface-hover)">✕ {{ __('purchase::action.clear_line') }}</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

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

                                                <select :name="'gifts[' + (line.key) + '-' + (gi) + '][product_id]'" aria-label="{{ __('purchase::action.gift_item') }}"
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
                                                       :name="'gifts[' + (line.key) + '-' + (gi) + '][qty]'" x-model="gift.qty" aria-label="{{ __('purchase::field.qty') }}"
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
                                                <select :name="'gifts[' + (line.key) + '-' + (gi) + '][unit_id]'" aria-label="{{ __('purchase::field.unit') }}"
                                                        x-model="gift.unit_id"
                                                        class="h-(--spacing-field-dense) rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-card)
                                                               px-1 text-xs">
                                                    <option value="" x-text="unitLabelFor(gift.product_id)"></option>
                                                    <template x-for="u in unitsFor(gift.product_id)" :key="u.id">
                                                        <option :value="u.id" x-text="u.label"></option>
                                                    </template>
                                                </select>

                                                <input type="text" maxlength="191" aria-label="{{ __('purchase::field.narration') }}"
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
                                        <td colspan="{{ 4
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
                                </tbody>
                            </template>

                        <tbody>
                            <tr x-show="lines.length === 0" x-cloak>
                                <td colspan="{{ 9
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
