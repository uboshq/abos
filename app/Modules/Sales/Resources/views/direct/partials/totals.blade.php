        <aside class="flex flex-col self-start overflow-hidden rounded-(--radius-card) border
                      border-(--color-border) bg-(--color-surface-selected) shadow-sm
                      xl:sticky xl:top-3 xl:max-h-[calc(100dvh-5.5rem)]"
               style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-brand-500)">

            <div class="min-h-0 flex-1 overflow-y-auto">

            {{-- এই চালান --}}

            {{-- ⚠️ নিচের ফাঁকটা কাটা — মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                 *"mark kora faka komale scrol bar r thakbe na"*।

                 টাকার সারিগুলোর নিচে ১৫px ফাঁক ছিল, আর তার ঠিক নিচেই
                 বকেয়ার লাল বড়িটা — দুইটার মাঝে ওই ফাঁকের কোনো কাজ ছিল না,
                 কারণ **বড়িটা নিজেই একটা আলাদা আকার**, তাকে আলাদা করতে
                 ফাঁকের দরকার হয় না।

                 ⚠️ উপরের `pt-3` রাখা হয়েছে: ওখানে প্যানেলের কিনারা, আর
                 কিনারা ঘেঁষা লেখা সস্তা দেখায়। --}}
            <div class="space-y-1 px-3 pt-3 pb-1 text-2xs">
                {{--
                    ── বিলের মোট টাকা — প্যানেলের মাথায়, বড় করে ────────────

                    ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────────
                    *"Invoice Total Amount ei upore mark kora box e boro kore
                    dekhabe"*।

                    ── কেন এটাই ঠিক জায়গা ───────────────────────────────────
                    "এই লাইন" বাক্সে বড় সংখ্যাটা **একটা পণ্যের**; এখানে বড়
                    সংখ্যাটা **পুরো কাগজের**। দুইটা এক মাপে থাকলে চোখ বুঝত না
                    কোনটা কীসের — আর কাউন্টারে ভুলটা দামি: ক্রেতা জিজ্ঞেস
                    করেন *"সব মিলিয়ে কত?"*, আর উত্তরটা এই সংখ্যাটাই।

                    ⓘ নিচের সারিগুলো ছোট থাকল ইচ্ছে করে — ছাড় · ভ্যাট · খরচ
                    সবই **এই সংখ্যাটার ব্যাখ্যা**, প্রতিদ্বন্দ্বী নয়।
                --}}
                <div class="flex items-baseline justify-between gap-2 border-b
                            border-(--color-border) pb-2">
                    <span class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                        {{ __('sales::field.invoice_total') }}
                    </span>
                    {{-- ⚠️ রঙ ও হাইলাইট — মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                         *"INV Total ৳10.00 — Navy blue or Royal blue kore
                         highlight kore daw"*।

                         ── কেন এই একটা সংখ্যাই ─────────────────────────────
                         প্যানেলে পনেরোটা সংখ্যা আছে, আর ক্রেতা জিজ্ঞেস করেন
                         একটাই: *"সব মিলিয়ে কত?"* **রঙটা ওই একটাকেই বাকি
                         চোদ্দটা থেকে আলাদা করে।**

                         ⚠️ সব সংখ্যা রঙিন করলে রঙের কোনো মানে থাকত না —
                         **আলাদা করা মানে বাকিদের সাদাসিধে রাখা।**

                         ⓘ `--color-brand-700` — থিমের নিজের রয়্যাল ব্লু।
                         হার্ডকোড করলে নয়টা থিমের বাকিগুলোয় বেমানান হত। --}}
                    <span class="num rounded-(--radius-field) bg-(--color-brand-50)
                                 px-2 py-0.5 text-2xl font-bold text-(--color-brand-700)"
                          x-text="'৳' + money(grossTotal)"></span>
                </div>

                @if ($show['sub_total'])
                    <x-sales::panel-row :label="__('sales::field.sub_total_no_vat')">
                        <span class="num" x-text="'৳' + money(subTotal)"></span>
                    </x-sales::panel-row>
                @endif

                {{--
                    ── ছাড় — শতাংশ এখন সত্যিই কাজ করে ───────────────────────

                    ── কী ভাঙা ছিল (মাপা ৩ সেপ্টেম্বর ২০২৬) ──────────────────
                    ঘরটার placeholder বলত **"টাকা বা %"**, অথচ শতাংশ
                    **তিন জায়গার একটাতেও** কাজ করত না:

                        `type="number"`     ব্রাউজার `%` টাইপই করতে দিত না
                        `Number(...)`       শতাংশ হলে NaN → ০
                        সার্ভারে `money()`   সরাসরি টাকা ধরে নিত

                    ⚠️ **অর্থাৎ লেখাটা একটা প্রতিশ্রুতি দিত যেটা ব্যবস্থাটা
                    রাখত না।** কেউ ৬% লিখতে গিয়ে না পেরে ভাবতেন কীবোর্ড
                    নষ্ট, আর কেউ হয়তো "৬" লিখে ৬ টাকা ছাড় দিয়ে ফেলতেন —
                    ৬% ভেবে। **দ্বিতীয়টা টাকার ভুল, আর নীরব।**

                    ── এখন যা হয় ────────────────────────────────────────────
                    ঘরটা লেখার ঘর, তাই `6%` লেখা যায়। পাশে **টাকার অঙ্কটা
                    দেখা যায়** — মালিকের দেখানো NEXUS-এর নমুনায় ঠিক ওটাই।

                    ⭐ **সার্ভার এক অক্ষরও বদলায়নি**: লুকানো ঘরটা সবসময়
                    **টাকা** পাঠায়, শতাংশ নয়। পর্দা হিসাব করে, সার্ভার
                    আগের মতোই টাকা পায় — উপহারের সাথে একই কৌশল।
                --}}
                <x-sales::panel-row :label="__('sales::field.discount_amount')">
                    <span class="flex flex-1 items-center gap-2">
                        <input type="text" inputmode="decimal" x-model="discountInput"
                               placeholder="{{ __('sales::field.amount_or_pct') }}"
                               class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">

                        {{-- হিসাব হওয়া টাকাটা — "৬%" লিখে কত হলো তা না
                             দেখালে শতাংশ দেওয়াটা অন্ধ বাজি হয়ে যেত --}}
                        <span class="num ms-auto w-16 text-end"
                              x-text="discountValue > 0 ? '৳' + money(discountValue) : '—'"></span>
                    </span>

                    <input type="hidden" name="discount_amount" :value="discountValue">
                </x-sales::panel-row>

                @if ($vatEnabled)
                    {{--
                        ── ভ্যাট — পুরো কাগজের জন্য একবারে বদলানো যায় ─────────

                        ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────
                        *"vat er dropdawn daw ni"* — NEXUS-এর নমুনায় ভ্যাটের
                        পাশে একটা বাছাই আছে, ABOS-এ ছিল না।

                        ── কেন ডিফল্টটা "পণ্য অনুযায়ী", আর সেটাই সাধারণ ──────
                        একটা ডিপো এক হারে বিস্কুট বেচে আর অন্য হারে সাবান —
                        **প্রতিটা লাইন নিজের পণ্যের ঘোষিত হারই নেবে**, এটাই
                        একমাত্র সঠিক উত্তর। তাই বাছাইটা ফাঁকা থাকলে কিছুই
                        বদলায় না।

                        বদলটা ওই চালানের জন্য যেটা **সত্যিই এক হারের**:
                        রপ্তানি, অব্যাহতিপ্রাপ্ত ক্রেতা, লিখিতভাবে ঠিক করা
                        হার। ⚠️ **আর কথাটা সারিতেই লেখা থাকে** — কোথাও লুকানো
                        একটা সেটিংসে নয়, যেটা কেউ বসিয়ে ভুলে যেত।

                        ── তিনটা বদল ─────────────────────────────────────────
                            ভ্যাট বাদে   দরের **উপরে** বসে
                            ভ্যাট সহ     দরের **ভেতরেই** আছে
                            ভ্যাট নেই     শূন্য

                        ⚠️ হারের ঘরটা কেবল প্রথম দুইটায় দেখা যায় — "পণ্য
                        অনুযায়ী"-র পাশে একটা হার মানে **এমন একটা সংখ্যা যেটা
                        কোথাও বসে না**, আর "ভ্যাট নেই"-এর পাশে হার অর্থহীন।
                    --}}
                    <x-sales::panel-row :label="__('sales::field.vat')">
                        <span class="flex flex-1 flex-wrap items-center gap-1">
                            <select x-model="vatMode" name="vat_mode"
                                    title="{{ __('sales::field.vat_per_product_hint') }}"
                                    class="h-(--spacing-inline) w-24 rounded-(--radius-field) border
                                           border-(--color-border) bg-(--color-surface-app) px-1">
                                <option value="">{{ __('sales::field.vat_per_product') }}</option>
                                <option value="exclusive">{{ __('sales::field.vat_exclusive') }}</option>
                                <option value="inclusive">{{ __('sales::field.vat_inclusive') }}</option>
                                <option value="exempt">{{ __('sales::field.vat_exempt') }}</option>
                            </select>

                            <template x-if="vatMode === 'exclusive' || vatMode === 'inclusive'">
                                <input type="number" min="0" max="100" step="0.01" x-model="vatRate"
                                       name="vat_rate" placeholder="%"
                                       title="{{ __('sales::field.vat_rate_for_every_line') }}"
                                       class="num h-(--spacing-inline) w-12 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-app) px-1 text-end">
                            </template>

                            <span class="num ms-auto" x-text="'৳' + money(vatTotal)"></span>
                        </span>
                    </x-sales::panel-row>
                @endif

                {{--
                    ── খরচ — ঘর বাঁয়ে, অঙ্ক ডানে, কারণ নিচে ──────────────────

                    মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"Expense-এর বাক্সও
                    পাশে নিয়ে আসো, বক্সে বসালে ডানে show করবে, আর নিচে
                    narration on হবে — কী লিখতে হবে সেটা narration বক্সের
                    ভিতরে লেখা থাকবে"*।

                    ⭐ **তিনটা কথাই আলাদা আলাদা কারণে ঠিক:**

                    **ঘর বাঁয়ে** — ছাড়, ভ্যাট, রাউন্ডিংয়ের মতোই। যে সারিতে
                    কিছু লেখা যায়, সেটা এক নজরে চেনা যায়।

                    **অঙ্ক ডানে** — প্যানেলের বাকি পনেরোটা সংখ্যার সাথে এক
                    খাড়া রেখায়। ⚠️ কেবল ঘরটা থাকলে চোখকে **টাকার কলামের
                    মাঝখানে একটা ফাঁক** পেরোতে হত।

                    **কারণ নিচে, ভেতরে ইঙ্গিত** — আগে ইঙ্গিতটা ছিল আলাদা
                    লেবেলে, আর ওটা একটা বাড়তি সারি খেত। ঘরের ভিতরে থাকলে
                    জায়গা লাগে না, আর **টাইপ শুরু করলেই সরে যায়** — যখন
                    আর দরকার নেই।
                --}}
                @if ($show['expense'])
                    <x-sales::panel-row :label="__('sales::field.expense')">
                        {{-- ⚠️ টাকা **বা** শতাংশ — মালিকের নির্দেশ
                             (৩ সেপ্টেম্বর ২০২৬): *"Expense e discount er moto
                             Amount o % thakbe"*।

                             ── কেন শতাংশ দরকার ───────────────────────────
                             পরিবহন ভাড়া প্রায়ই **চালানের মূল্যের অনুপাতে**
                             ধরা হয় — "বিলের ১%"। আগে ঘরটা `type="number"`
                             ছিল, তাই `1%` লেখাই যেত না; কেউ ১ লিখে **এক
                             টাকা** খরচ বসিয়ে দিতেন, আর সেটা নীরব ভুল।

                             ⭐ সার্ভার এক অক্ষরও বদলায়নি: লুকানো ঘরটা
                             সবসময় **টাকা** পাঠায় — ছাড়ের সাথে একই কৌশল। --}}
                        <span class="flex flex-1 items-center gap-2">
                            <input type="text" inputmode="decimal" x-model="expenseInput"
                                   placeholder="{{ __('sales::field.amount_or_pct') }}"
                                   class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border
                                          border-(--color-border) bg-(--color-surface-app) px-1 text-end">

                            <span class="num ms-auto w-16 text-end"
                                  x-text="expenseValue > 0 ? '৳' + money(expenseValue) : '—'"></span>
                        </span>

                        <input type="hidden" name="expense_amount" :value="expenseValue">
                    </x-sales::panel-row>

                    {{--
                        ⚠️ টাকা বসলে তবেই কারণের ঘর — আর তখন বাধ্যতামূলক।

                        বেশিরভাগ চালানে কোনো খরচ নেই। ঘরটা সবসময় দেখালে
                        **প্রতিদিন একটা খালি ঘর** চোখের সামনে থাকত, আর যেদিন
                        সত্যিই দরকার সেদিনও চোখে পড়ত না।

                        ⚠️ আর টাকা বসার পরে ঐচ্ছিক রাখা যায় না: "খরচ ২০০"
                        এক মাস পরে কারও কাজে আসে না — ভাড়া ছিল না হাম্মালি,
                        জানার একমাত্র সময় এখনই।
                    --}}
                    <template x-if="expenseValue > 0">
                        <input type="text" name="expense_narration" maxlength="191" required
                               placeholder="{{ __('sales::field.expense_for_placeholder') }}"
                               class="mt-1 h-(--spacing-inline) w-full rounded-(--radius-field) border
                                      border-(--color-warning) bg-(--color-surface-app) px-2">
                    </template>
                @endif
                {{--
                    ── রাউন্ডিং — চিহ্ন আলাদা, অঙ্ক আলাদা ────────────────────

                    মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"Rounding-এর পাশে
                    বাক্স নিয়ে আসো, এখানে select করার option থাকবে +/−/amount"*।

                    ── কেন চিহ্নটা আলাদা ঘরে ────────────────────────────────
                    রাউন্ডিং দুই দিকেই যায়: ৪,৩০০.৪০-কে ৪,৩০০ করতে **−০.৪০**,
                    আর ৪,২৯৯.৬০-কে ৪,৩০০ করতে **+০.৪০**। আগে ঘরটা কেবল
                    ধনাত্মক নিত, তাই **অর্ধেক কাজটা করাই যেত না** — বিক্রেতা
                    বাধ্য হয়ে ছাড়ের ঘরে বসাতেন, আর তখন ওটা রিপোর্টে ছাড়
                    হিসেবে গোনা হত।

                    ⚠️ ঋণচিহ্ন সরাসরি টাইপ করতে দিলে ভুলে `-৪৩০০` বসে যেতে
                    পারত। দুইটা ঘরে ভাগ করায় **অঙ্কটা সবসময় ধনাত্মক**, আর
                    দিকটা একটা বাছাই — ভুল করা কঠিন।
                --}}
                @if ($show['rounding'])
                    <x-sales::panel-row :label="__('sales::field.rounding')">
                        <span class="flex flex-1 items-center gap-1">
                            <select x-model="roundingSign"
                                    class="h-(--spacing-inline) w-12 rounded-(--radius-field) border
                                           border-(--color-border) bg-(--color-surface-app) px-1 text-center">
                                <option value="+">+</option>
                                <option value="-">−</option>
                            </select>

                            <input type="number" step="0.01" min="0" x-model="roundingInput"
                                   class="num h-(--spacing-inline) w-16 rounded-(--radius-field) border
                                          border-(--color-border) bg-(--color-surface-app) px-1 text-end">

                            <span class="num ms-auto w-16 text-end"
                                  x-text="roundingValue ? money(roundingValue) : '—'"></span>
                        </span>

                        {{-- সার্ভারে চিহ্নসহ একটাই সংখ্যা যায় --}}
                        <input type="hidden" name="rounding_amount" :value="roundingValue">
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.net_payable')" strong>
                    <span class="num text-sm" x-text="'৳' + money(netPayable)"></span>
                </x-sales::panel-row>

                {{--
                    ⚠️ "জমা নেওয়া হলো"-র ঘরটা এখান থেকে **তুলে দেওয়া হয়েছে**।

                    মালিকের কথা (৩ সেপ্টেম্বর ২০২৬): *"Received Deposit আলাদাভাবে
                    হয়, এখানে বক্স রাখলে সমস্যা হবে"*।

                    ── কেন তিনি ঠিক ─────────────────────────────────────────
                    জমা একটা **ঘটনা**, একটা সংখ্যা নয়: কবে, কোন পদ্ধতিতে
                    (নগদ/বিকাশ/ব্যাংক), কোন রেফারেন্সে, কোন খাতে। আর একটা
                    বিলে **একাধিক জমা** থাকতে পারে — ৫,০০০ নগদ আর ১০,০০০
                    বিকাশে।

                    ⚠️ এখানে একটা ঘর থাকা মানে **দুই জায়গায় একই জিনিস**, আর
                    দুইটার একটাতে লিখলে অন্যটা জানত না। এখন লেখার একমাত্র
                    জায়গা "জমা যোগ" বোতামের প্যানেল, আর এখানে কেবল **যোগফল**।
                --}}
                @if ($show['deposit'])
                    <x-sales::panel-row :label="__('sales::field.received_deposit')">
                        <span class="num" x-text="Number(deposit) > 0 ? '৳' + money(deposit) : '—'"></span>
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.invoice_due')" strong>
                    <span class="num" x-text="'৳' + money(invoiceDue)"></span>
                </x-sales::panel-row>

                {{-- ⚠️ বিলের চেয়ে বেশি নিলে সেটা পর্দায় বলতেই হবে।

                     সারিটা কেবল তখনই দেখা যায় যখন সত্যিই উদ্বৃত্ত আছে —
                     স্বাভাবিক চালানে একটা স্থায়ী শূন্যের সারি চোখের সামনে
                     রাখার কোনো কারণ নেই।

                     ⓘ সবুজ, লাল নয়: উদ্বৃত্ত জমা কোনো সমস্যা নয়, ওটা
                     গ্রাহকের পাওনা — পরের চালানে কাটা যাবে। --}}
                <template x-if="depositExcess > 0">
                    <x-sales::panel-row :label="__('sales::field.kept_as_advance')">
                        <span class="num font-semibold text-(--color-success)"
                              x-text="'৳' + money(depositExcess)"></span>
                    </x-sales::panel-row>
                </template>

                {{--
                    ── আগের হিসাব — নামটা চিহ্ন দেখে বদলায় ──────────────────

                    মালিকের নির্দেশ: *"আগে যদি বাকি থাকে তাহলে **আগের বকেয়া**,
                    আর যদি টাকা জমা থাকে তাহলে **আগের অগ্রিম**"*।

                    ⚠️ আগে একটাই নাম ছিল — "আগের ব্যালেন্স" — আর ঋণাত্মক হলেও
                    ওই নামেই দেখাত। **কাউন্টারে দাঁড়িয়ে "ব্যালেন্স ৫০০" পড়ে
                    বোঝার উপায় ছিল না তিনি ৫০০ পাবেন না দেবেন** — আর ওই
                    ভুলের দাম টাকা।
                --}}
                {{-- ⚠️ কম্পোনেন্ট নয়, হাতে লেখা সারি — আর কারণটা সূক্ষ্ম।

                     `<x-sales::panel-row>`-এর লেবেলটা **PHP-তে রেন্ডার হয়**,
                     তাই ওখানে Alpine-এর বাঁধন (`::label`) বসালে সেটা কেবল
                     একটা অ্যাট্রিবিউট হয়ে পড়ে থাকত — লেখাটা কোনোদিন
                     বদলাত না, আর কোনো ত্রুটিও হত না।

                     এখানে লেবেলটাই বদলায়, তাই সারিটা নিজে হাতে লেখা। --}}
                <div class="flex items-center justify-between gap-2">
                    <span class="text-(--color-ink-muted)"
                          x-text="customer.due < 0
                            ? @js(__('sales::field.previous_advance'))
                            : @js(__('sales::field.previous_due'))"></span>

                    <span class="num"
                          x-text="customer.due ? money(Math.abs(customer.due)) : '—'"></span>
                </div>
            </div>

            {{--
                ── মোট বকেয়া / অগ্রিম — আলাদা, বড়, রঙিন ───────────────────

                মালিকের নির্দেশ: *"আলাদাভাবে round box-এ একটু বড় করে highlight
                হবে — Due হলে লাল solid, Advance হলে সবুজ হালকা"*।

                ── কেন দুইটা রঙ, আর দুইটা আলাদা রকমের ─────────────────────
                ⭐ **বকেয়া একটা সমস্যা, অগ্রিম নয়।** তাই বকেয়া ভরাট লাল —
                চোখ এড়ানো যায় না; আর অগ্রিম হালকা সবুজ — জানা থাকা ভালো,
                কিন্তু কিছু করার নেই।

                ⚠️ দুইটাকে একই রঙে দেখালে **ভালো খবরও লাল দেখাত**, আর তখন
                লাল রঙটার আর কোনো মানে থাকত না।

                ⓘ শূন্য হলে দুইটার কোনোটাই নয় — নিরপেক্ষ ধূসর।
            --}}
            {{-- ⓘ নিচের ফাঁকও কাটা, একই কারণে — নিচে সাথে সাথেই পরিমাণের
                 দল, আর ওটার নিজের উপরের কিনারা-রেখা আছে। দুইটা আলাদা করার
                 কাজটা রেখাটাই করে, ফাঁকটা কেবল স্ক্রলবার আনত। --}}
            <div class="px-3 pb-1">
                <div class="flex items-center justify-between gap-2 rounded-full px-3 py-1"
                     :class="outstanding > 0
                        ? 'bg-(--color-danger) text-white'
                        : (outstanding < 0
                            ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                            : 'bg-(--color-surface-sunken) text-(--color-ink-muted)')">
                    <span class="text-2xs font-semibold uppercase tracking-wide"
                          x-text="outstanding < 0
                            ? @js(__('sales::field.advance'))
                            : @js(__('sales::field.due'))"></span>

                    <span class="num text-sm font-bold"
                          x-text="'৳' + money(Math.abs(outstanding))"></span>
                </div>
            </div>

            {{--
                ── পরিমাণের দল — আলাদা রঙে ────────────────────────────────

                মালিকের নির্দেশ: *"এই group-টাকে আলাদা color করে রাখো"*।

                ⭐ কারণটা যুক্তিসঙ্গত: উপরের সবটাই **টাকা**, আর এই চারটা
                **পরিমাণ** — সম্পূর্ণ আলাদা জিনিস। এক রঙে থাকলে চোখ
                ৳-চিহ্ন খুঁজে খুঁজে আলাদা করত।
            --}}
            <div class="space-y-1 border-t border-(--color-border) bg-(--color-surface-sunken)
                        px-3 py-2 text-2xs">
                @foreach ([
                    ['label' => 'sales::field.total_item', 'expr' => 'counts.totalItem', 'on' => 'total_item'],
                    ['label' => 'sales::field.total_sales_qty', 'expr' => 'counts.totalSalesQty', 'on' => 'sales_qty'],
                    ['label' => 'sales::field.total_free_qty', 'expr' => 'counts.totalFreeQty', 'on' => 'free_qty_total'],
                    ['label' => 'sales::field.total_free_plus_sales', 'expr' => 'counts.totalQty', 'on' => 'total_qty'],
                ] as $row)
                    @if ($show[$row['on']])
                        <x-sales::panel-row :label="__($row['label'])">
                            <span class="num font-semibold text-(--color-brand-700)"
                                  x-text="{{ $row['expr'] }} || '—'"></span>
                        </x-sales::panel-row>
                    @endif
                @endforeach
            </div>

            </div>

            {{--
                ── চালানের কাজগুলো — ডান প্যানেলের নিচে ────────────────────

                ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────────────
                *"Chart / Bulk DO, Add Note, Clear Full Data / Add Deposit,
                Transportation, / Confirm — ei krome lal box e bosaw"* —
                সংখ্যাগুলোর নিচের ফাঁকা জায়গাটা লাল দিয়ে ঘেরা।

                ── কেন এখানে ভালো ─────────────────────────────────────────
                বোতামগুলো আগে পাতার নিচে একটা আলাদা বারে ছিল। **কিন্তু ওরা
                যে অঙ্কগুলোর উপর কাজ করে, সেগুলো এই প্যানেলেই** — জমা যোগ
                করলে "বিলের বকেয়া" বদলায়, খরচ যোগ করলে "নিট পরিশোধযোগ্য"।
                এখন কারণ আর ফল এক বাক্সে।

                ⚠️ **প্যানেলগুলোও সাথে এসেছে** — নাহলে বোতাম এক জায়গায় আর
                তার ঘরগুলো আরেক জায়গায় খুলত, আর কেউ খুঁজে পেত না।

                ⚠️ স্ক্রল-ঘরের **বাইরে**, তাই সংখ্যাগুলো গড়ালেও বোতামগুলো
                জায়গাতেই থাকে — কাউন্টারে "নিশ্চিত করুন" খুঁজতে স্ক্রল করা
                সবচেয়ে বিরক্তিকর মুহূর্ত।

                ⓘ শিপমেন্ট ও খরচ মালিকের তালিকায় ছিল না, কিন্তু তাঁর
                দ্বিতীয় সারিটা কমা দিয়ে শেষ হয়েছিল — তাই ওরা ওই সারিতেই।
                **নিজে থেকে কোনো বোতাম তোলা হয়নি**, প্রশ্ন করা হয়েছে।
            --}}
            <div class="border-t border-(--color-border) p-2">

                {{--
                    ── ছয়টা বোতাম — এক মাপ, ছয় রঙ ──────────────────────────

                    মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"ek ekta ek ek
                    color daw, solid color diba"*, আর *"sob kotir botam ek
                    soman koro"*।

                    ── কেন আগে সমান ছিল না ─────────────────────────────────
                    "চার্ট" বোতামটা `<x-sales::bulk-sheet>` কম্পোনেন্টের ভেতর
                    থেকে আঁকা হয়, আর সে নিজের ক্লাস নিজেই ঠিক করত। ⚠️ **তাই
                    বাকি পাঁচটা যা-ই হোক, ওটা একা আলাদা দেখাত।** এখন
                    কম্পোনেন্টে একটা `buttonClass` prop, আর সেটা এখান থেকেই
                    দেওয়া — ছয়টাই হুবহু এক নিয়মে।

                    ── রঙের ভাগটা অর্থ ধরে, খেয়ালখুশিতে নয় ─────────────────
                        জমা যোগ    সবুজ    একমাত্র বোতাম যেটা টাকা আনে
                        নোট যোগ    নীলচে   কাগজে ছাপা হবে, তথ্য
                        শিপমেন্ট    ব্র্যান্ড  মাল কোথায় যাচ্ছে
                        চার্ট       গোলাপি   একবারে বহু পণ্য — মালিকের বাছাই
                        পরিবহন     হলুদ    টাকা বেরোয়, তাই সতর্কতার রঙ
                        সব মুছুন    লাল     ফেরানো যায় না

                    ⚠️ **প্রতিটা রঙ থিমের টোকেন থেকে**, হার্ডকোড নয় — নাহলে
                    নয়টা থিমের বাকিগুলোয় বেমানান হত। এটা মালিকের স্থায়ী
                    নিয়ম: কোনো পর্দা রঙ হার্ডকোড করবে না।

                    ⓘ খোলা প্যানেলের বোতামে একটা রিং বসে (`ring-2`), রঙ
                    বদলায় না — রঙটা পরিচয়, খোলা-বন্ধ অবস্থা নয়।
                --}}
                @php
                    $btnBase = 'w-full rounded-(--radius-field) px-1 py-2 text-2xs font-semibold
                                leading-tight text-white transition-colors';
                @endphp

                <div class="grid grid-cols-3 gap-1">
                    {{-- ১ · জমা যোগ --}}
                    @if ($show['deposit'])
                        <button type="button" @click="openPanel('deposit')"
                                :class="panel === 'deposit' ? 'ring-2 ring-(--color-ink)' : ''"
                                class="{{ $btnBase }} bg-(--color-success) hover:bg-(--color-success-hover)">
                            {{ __('sales::action.add_deposit') }}
                        </button>
                    @endif

                    {{-- ২ · নোট যোগ --}}
                    <button type="button" @click="openPanel('note')"
                            :class="panel === 'note' ? 'ring-2 ring-(--color-ink)' : ''"
                            class="{{ $btnBase }} bg-(--color-info) hover:opacity-90">
                        {{ __('sales::action.add_note') }}
                    </button>

                    {{-- ৩ · শিপমেন্ট --}}
                    @if ($show['shipment'])
                        <button type="button" @click="openPanel('shipment')"
                                :class="panel === 'shipment' ? 'ring-2 ring-(--color-ink)' : ''"
                                class="{{ $btnBase }} bg-(--color-brand-600) hover:bg-(--color-brand-700)">
                            {{ __('sales::action.shipment') }}
                        </button>
                    @endif

                    {{-- ৪ · চার্ট — নিজের বোতাম আঁকে, তাই ক্লাসটা পাঠানো হয় --}}
                    {{-- ⓘ `class="contents"` — মোড়কটা ছকে নিজের ঘর নেয় না,
                         তাই ভেতরের বোতামটাই ঘরটা ভরে। F6 এই ref ধরেই
                         বোতামটা চাপে। --}}
                    <div x-ref="chartEntry" class="contents">
                        <x-sales::bulk-sheet :products="$sheetProducts" :stock="$sheetStock"
                                             :free-qty="$show['free_qty']"
                                             button-class="{{ $btnBase }} bg-(--color-accent-pink) hover:bg-(--color-accent-pink-hover)" />
                    </div>

                    {{-- ৫ · পরিবহন --}}
                    @if ($show['transport'])
                        <button type="button" @click="openPanel('transport')"
                                :class="panel === 'transport' ? 'ring-2 ring-(--color-ink)' : ''"
                                class="{{ $btnBase }} bg-(--color-warning) text-(--color-warning-ink)
                                       hover:bg-(--color-warning-hover)">
                            {{ __('sales::action.transportation') }}
                        </button>
                    @endif

                    {{--
                        ৬ · সব মুছুন — লাল, আর ফেরানো যায় না।

                        ⚠️ পাশের "এই লাইন" বাক্সে আরেকটা বোতামে ইংরেজিতে
                        "Clear Data" লেখা, আর ওটা কেবল চলতি লাইনটা মোছে।
                        এটা পুরো চালান মোছে — তাই পার্থক্যটা লেখা নয়,
                        রঙ বলে।
                    --}}
                    <button type="button" @click="clearAll()"
                            class="{{ $btnBase }} bg-(--color-danger) hover:bg-(--color-danger-hover)">
                        {{ __('sales::action.clear_full') }}
                    </button>
                </div>

                {{-- সারি ৩ — শেষ করা।

                     পুরো প্রস্থে আর সবার নিচে: কাউন্টারে এটাই শেষ চাপ, আর
                     অঙ্কটা গায়ে লেখা বলে **কত টাকার কাগজ পাকা হচ্ছে সেটা
                     চাপ দেওয়ার আগেই চোখে পড়ে** (মালিকের সিদ্ধান্ত)। --}}
                <x-ui.button type="submit" tone="primary" class="mt-2 w-full py-2" x-ref="confirm"
                             ::disabled="! canConfirm">
                    {{ __('sales::action.confirm') }}
                    <span class="num ms-2 font-semibold" x-text="'৳' + money(netPayable)"></span>
                </x-ui.button>
            </div>

        </aside>
