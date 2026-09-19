            <aside class="lg:col-span-2 xl:col-span-1 xl:col-start-3 xl:row-start-1 xl:row-span-2 self-start rounded-(--radius-card) border-2 border-(--color-success)
                          bg-(--color-surface-card) p-2"
                   style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-success)">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-2xs font-semibold tracking-wide text-(--color-success) uppercase">
                        {{ __('purchase::field.this_line') }}
                    </span>
                    <span class="num text-xl font-bold text-(--color-success)">৳<span x-text="money(entryNet)"></span></span>
                </div>

                <dl class="mt-2 space-y-1 text-2xs">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.total_amount') }}</dt>
                        <dd class="num" x-text="money(entryBase)"></dd>
                    </div>

                    @if ($show['line_discount'])
                        {{-- ── ছাড়: টাকা নাকি শতাংশ ──────────────

                             ⭐ লেখা যায় দুইভাবে, **সংরক্ষিত হয় একভাবে** —
                             টাকায়। ⓘ কলামটা (`pur_bill_lines.discount`)
                             টাকার, আর সার্ভার সরাসরি বিয়োগ করে; একই
                             কলামে কখনো টাকা কখনো শতাংশ বসলে একদিন কেউ
                             ৫ লিখতেন আর ৫ টাকা বাদ যেত, যেখানে তিনি
                             ৫% বুঝিয়েছিলেন।

                             ⚠️ আর ক্রয়ে ছাড়টা সরবরাহকারীর কাগজে টাকায়
                             ছাপা থাকে — দুই খাতা মেলানোর সময় ওই
                             সংখ্যাটাই মেলে। --}}
                        {{-- ⚠️ লেবেলটা `shrink-0` ছিল, আর সেটাই সারিটাকে বাক্সের
                             বাইরে ঠেলে দিত।

                             ⛔ ডান পাশের ঘরগুলো স্থির মাপের (৬৪ + ২৪ + ৬৪px),
                             তাই সরু প্যানেলে যোগফলটা প্যানেলের চেয়ে চওড়া হয়ে
                             যেত — আর শেষ সংখ্যাটা **পর্দার কিনারার বাইরে**
                             ভেসে থাকত। ⓘ মালিক ঠিক ঐ ভাসমান `0.00`-টাই
                             দেখেছেন।

                             ⭐ এখন লেবেলটা সংকুচিত হয় আর দরকারে কাটে; সংখ্যার
                             ঘরগুলো কখনো বাইরে যায় না। ⚠️ **সংখ্যা আগে, নাম
                             পরে** — একটা কাটা লেবেল পড়া যায়, বাক্সের বাইরের
                             সংখ্যা পড়াই যায় না। --}}
                        <div class="flex min-w-0 items-center justify-between gap-2">
                            <dt class="min-w-0 truncate text-(--color-ink-muted)">
                                {{ __('purchase::field.discount_on_line') }}
                            </dt>
                            <dd class="flex shrink-0 items-center gap-1">
                                {{-- ⓘ ঘরটা `w-16` → `w-12`, আর ফলের সংখ্যাটাও।

                                     ⛔ স্থির মাপগুলো মিলে ১৫২px নিত, আর সরু
                                     প্যানেলে লেবেলের জন্য কিছুই থাকত না —
                                     *"Discount on this line"* কেটে `D…` হয়ে
                                     যেত। ⚠️ একটা লেবেল যেটা এক অক্ষরে নেমে
                                     আসে সেটা আর লেবেল নয়।

                                     ⭐ ঘর দুইটা ছোট করলে তিনটাই থাকে: নাম,
                                     লেখার ঘর, আর ফল। --}}
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.discount" :disabled="! picked"
                                       class="num h-(--spacing-field-dense) w-12 rounded-(--radius-field)
                                              border border-(--color-border) bg-(--color-surface-card)
                                              px-1 text-end disabled:opacity-50">

                                <button type="button" @click="toggleDiscountMode()"
                                        :aria-pressed="entry.discount_mode === 'percent'"
                                        class="w-6 shrink-0 rounded-(--radius-field) border
                                               border-(--color-border) py-0.5 text-center
                                               font-medium hover:bg-(--color-surface-hover)"
                                        x-text="entry.discount_mode === 'percent' ? '%' : '৳'"></button>

                                <span class="num w-14 shrink-0 text-end" x-text="money(entryDiscount)"></span>
                            </dd>
                        </div>

                        {{-- ⚠️ সার্ভারও এটাই আটকায়, কিন্তু পর্দায় আগে
                             বলাটাই ভদ্রতা — সাবমিটের পর জানা মানে বিশ
                             লাইন টাইপ করার পর জানা। --}}
                        <p x-show="discountOverLine" x-cloak
                           class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-1.5 py-0.5
                                  text-(--color-badge-danger-ink)">
                            {{ __('purchase::message.discount_over_line_screen') }}
                        </p>
                    @endif

                    @if ($show['vat'])
                        {{-- ── ভ্যাট: কোন নিয়মে ───────────────────

                             ⭐ তিনটা ধরন, আর তিনটাই আজকের সার্ভারে সত্যি
                             ([[CalculatesLineTotals::lineFigures]]):
                             ঘরটা ফাঁকা গেলে পণ্যের নিজের হার, অঙ্ক গেলে
                             সেটাই, আর ০ গেলে ভ্যাট নেই।

                             ⚠️ এতদিন পর্দায় শুধু একটা খালি ঘর ছিল, আর
                             **খালি রাখা আর ০ লেখা দুইটা আলাদা কাজ করত** —
                             অথচ পর্দা সেটা কোথাও বলত না। ⓘ ড্রপডাউনটা
                             মূলত ওই নীরব পার্থক্যটাকে দৃশ্যমান করে।

                             ⛔ "গোটা বিলের ভ্যাট" এখানে নেই: `pur_bills`-এ
                             বিল-স্তরের ভ্যাটের ঘর নেই, তাই অপশনটা রাখলে
                             পর্দা এক সংখ্যা দেখাত আর খতিয়ানে আরেকটা বসত —
                             ঠিক যে কারণে খরচ ও রাউন্ডিংয়ের ঘর দুইটাও
                             এখনো বসেনি। --}}
                        <div class="flex items-center justify-between gap-2">
                            <dt class="shrink-0 text-(--color-ink-muted)">
                                {{ __('purchase::field.vat_mode') }}
                            </dt>
                            <dd class="flex items-center gap-1">
                                <select x-model="entry.vat_mode" :disabled="! picked"
                                        class="h-(--spacing-field-dense) w-24 rounded-(--radius-field)
                                               border border-(--color-border) bg-(--color-surface-card)
                                               px-1 disabled:opacity-50">
                                    <option value="product">{{ __('purchase::field.vat_mode_product') }}</option>
                                    <option value="amount">{{ __('purchase::field.vat_mode_amount') }}</option>
                                    <option value="none">{{ __('purchase::field.vat_mode_none') }}</option>
                                </select>

                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.tax"
                                       x-show="entry.vat_mode === 'amount'" x-cloak
                                       class="num h-(--spacing-field-dense) w-16 rounded-(--radius-field)
                                              border border-(--color-border) bg-(--color-surface-card)
                                              px-1 text-end">

                                <span class="num w-16 text-end" x-show="entry.vat_mode !== 'amount'"
                                      x-text="money(entryTax)"></span>
                            </dd>
                        </div>
                    @endif

                    <div class="flex items-center justify-between gap-2 border-t
                                border-(--color-border) pt-1">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.net_value') }}</dt>
                        <dd class="num font-medium" x-text="money(entryNet)"></dd>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.total_qty') }}</dt>
                        <dd class="num" x-text="qty(entryTotalQty)"></dd>
                    </div>

                    <div class="flex items-center justify-between gap-2 border-t
                                border-(--color-border) pt-1">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.in_cart') }}</dt>
                        <dd class="num" x-text="@js(__('purchase::field.items_count', ['count' => ':n']))
                                                 .replace(':n', lines.length)"></dd>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.running_total') }}</dt>
                        <dd class="num font-medium" x-text="money(netPayable)"></dd>
                    </div>
                </dl>

                {{-- ── চারটা বোতাম, এক সারিতে — মালিকের স্ক্রিনশট ────────

                     ```
                     [ Gift ]  [ Costing ]  [ Add to Cart ]  [ Clear Data ]
                      ধূসর       পাড়          সবুজ ভরাট        লালচে
                     ```

                     ⚠️ `Gift` পণ্য না বাছলে **চাপা যায় না**, আর ছবিতেও ওটা
                     ধূসর — কারণ উপহার সবসময় কোনো একটা পণ্যের বিপরীতে বসে
                     (`against_product_id`), আর চলতি এন্ট্রিতে পণ্য না থাকলে
                     জোড়া লাগানোর মতো কিছুই নেই।

                     ⓘ আগের খসড়ায় এখানে `Rate chart` আর `Bulk` নামে আরও
                     দুইটা বোতাম বসানো হয়েছিল — মালিকের স্ক্রিনশটে ও দুইটা
                     এখানে নেই, তাই সরানো হলো। ⭐ তালিকার বোতামটা ডান
                     কার্ডের ছয়-বোতামের ছকে, ছবিতে যেখানে আছে। --}}
                <div class="mt-3 grid grid-cols-[1fr_1.5fr_1fr] gap-1 text-center [&>button]:whitespace-normal">
                    {{-- ⭐ নকশার রং ফিরল — অ্যাম্বার পাড়, হালকা জমিন।

                         ⛔ প্রথম খসড়ায় এটা ধূসর ছিল, আর তাতে বোতামটা
                         **চিরকাল মরা দেখাত** — পণ্য বাছার পরেও কেউ বুঝত
                         না ওটা এখন চাপা যায়। ⓘ মালিকের নকশায় রংটা লেখা
                         ছিল (*"হলুদ/অ্যাম্বার পাড়"*), আর রংটাই এখানে
                         কাজের: নিষ্ক্রিয় অবস্থা ফ্যাকাশে, সক্রিয় অবস্থা
                         স্পষ্ট।

                         ⚠️ পণ্য না বাছা পর্যন্ত তবু চাপা যায় না, আর সেটাও
                         নকশারই কথা — উপহার সবসময় **কোনো একটা পণ্যের
                         বিপরীতে** বসে, তাই জোড়া লাগানোর মতো কিছু না
                         থাকলে বোতামটার কোনো কাজ নেই। --}}
                    <button type="button" @click="giftForThisLine()" :disabled="! picked"
                            class="rounded-(--radius-field) border px-0.5 py-1.5 text-2xs font-medium
                                   leading-tight break-words disabled:opacity-40"
                            style="border-color: var(--color-module-purchase);
                                   color: var(--color-module-purchase);
                                   background: var(--color-badge-warning-bg)">
                        {{ __('purchase::action.gift_short') }}
                    </button>

                    <button type="button" @click="addToCart()" :disabled="! picked"
                            class="rounded-(--radius-field) bg-(--color-success) px-0.5 py-1.5 text-2xs
                                   font-medium leading-tight break-words text-(--color-ink-inverse)
                                   hover:bg-(--color-success-hover) disabled:opacity-40">
                        {{ __('purchase::action.add_to_cart') }}
                    </button>

                    <button type="button" @click="clearEntry()"
                            class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-0.5 py-1.5
                                   text-2xs font-medium leading-tight break-words
                                   text-(--color-badge-danger-ink) hover:bg-(--color-surface-hover)">
                        {{ __('purchase::action.clear_data') }}
                    </button>
                </div>
            </aside>
