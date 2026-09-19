        <aside class="space-y-2">
            {{-- ── "এই লাইন" — ডান পাশে, বিলের মোটের উপরে ────────────

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"This Line Box Upore utaw"*
                 · *"This line Faka konaw mark kora ache"*।

                 ⛔ প্রথমে ওটাকে উপরের সারির **তৃতীয় কলামে** বসিয়েছিলাম,
                 আর তাতে তিনটা বাক্স ৭৩৩px ভাগ করে নিয়েছিল — সরবরাহকারীর
                 নাম, বিলের নম্বর, গুদাম, তারিখ সব কাটা পড়ছিল, আর "এই
                 লাইন"-এর ভিতরে বড় ফাঁকা জায়গা তৈরি হচ্ছিল।

                 ⭐ আসল জায়গাটা ডান পাশে, বিলের মোটের উপরে — তখন ওটা
                 পাতার একদম উপরেই থাকে (মালিক যা চেয়েছেন), অথচ বাঁ
                 অংশ দুই কলামেই থাকে আর কিছু কাটে না।

                 ⓘ বিক্রয়ের কাউন্টারেও "এই লাইন" আর "বিলের মোট"
                 পাশাপাশি ডান দিকেই বসে — দুই পর্দা আরও মিলল। --}}

        {{-- ── সরবরাহকারীর বাক্স — মোটের কার্ডের উপরে ─────────────────

             মালিকের নির্দেশ (৫ সেপ্টেম্বর ২০২৬): *"Supplier details…
             Bill total er upore niye zaw"*।

             ⭐ বাক্সটা এখন **ডান কলামের মাথায়**, `BILL TOTAL`-এর ঠিক
             উপরে। ⓘ কারণটা পড়লেই বোঝা যায়: *"কার কাছ থেকে কিনছি"* আর
             *"তাঁকে কত দিতে হবে"* — দুইটা একই প্রশ্নের দুই মাথা, আর
             এখন দুইটা চোখের এক জায়গায়।

             ⛔ আগে এটা বাঁ দিকে পণ্যের বাক্সের উপরে ছিল, আর তাতে ডান
             কলামের মাথায় একটা **ফাঁকা জায়গা** পড়ে থাকত — মালিক ছবিতে
             লাল দাগ দিয়ে ঠিক ওই ফাঁকাটাই দেখিয়েছেন। --}}

            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="h-0.5 w-full"
                     style="background: linear-gradient(90deg, var(--color-success), var(--color-module-customer))"></div>

                {{-- ── মাথার ব্যান্ড — নাম আর বড় অঙ্ক ────────────────────

                     ⭐ মালিকের স্ক্রিনশটে ডান কার্ডের মাথায় হালকা নীল একটা
                     ব্যান্ড, আর তাতে বড় করে মোট টাকা। ⓘ কার্ডটার বাকি সব
                     সারি ছোট হরফে — একটাই সংখ্যা বড়, আর সেটাই সেই সংখ্যা
                     যেটা নিয়ে সরবরাহকারীর সাথে কথা হয়। --}}
                <div class="flex items-center justify-between gap-2 px-3 pt-2">
                    <span class="text-2xs font-semibold tracking-wide text-(--color-ink-muted) uppercase">
                        {{ __('purchase::field.bill_total') }}
                    </span>

                    {{-- ⭐ অঙ্কটা একটা নীল চিপের ভিতরে, ছবির মতো — গোটা
                         চওড়ায় ব্যান্ড নয়। ⓘ পার্থক্যটা ছোট মনে হয়, কিন্তু
                         ব্যান্ড পুরো কার্ডটাকে "শিরোনাম" বানিয়ে দেয়, আর
                         চিপ কেবল **সংখ্যাটাকে** আলাদা করে। --}}
                    <span class="num rounded-(--radius-field) bg-(--color-badge-info-bg) px-2 py-1
                                 text-lg font-bold text-(--color-badge-info-ink)">৳<span
                          x-text="money(netPayable)"></span></span>
                </div>

                <div class="p-3">
                {{-- ── ছবির সারি-ক্রম, হুবহু ─────────────────────────────

                     ```
                     Sub Total (without VAT)
                     Discount   [amount or %]      ⏳ nexus-25
                     VAT        [Per product ▾]    ⏳ ড্রপডাউনটা তার, অঙ্কটা আজই সত্যি
                     Expense    [amount or %]      ⏳ nexus-25
                     Rounding   [ + ▾ ] [ ঘর ]      ⏳ nexus-25
                     Net Payable Amount
                     Received Deposit
                     Invoice Due
                     Previous Due
                     DUE
                     ```

                     ⚠️ চারটা ⏳ সারির ঘর `pur_bills`-এ **নেই**, তাই ওগুলো
                     আজ আঁকা হয় না — নিচের ব্লকে কারণটা লেখা। ⓘ জায়গাটা
                     ইচ্ছে করে এখানেই রাখা, যাতে ঘরগুলো এলে ছবির ক্রমেই
                     বসে। --}}
                <dl class="space-y-1 text-2xs">
                    <div class="flex justify-between">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.sub_total_goods') }}</dt>
                        <dd class="num" x-text="money(subTotal)"></dd>
                    </div>

                    @if ($show['vat'])
                        {{-- ⭐ লেখাটাই বলে দিচ্ছে ভ্যাট খরচের **ভিতরে**, উপরে
                             যোগ হওয়া কিছু নয় — মালিকের নিজের শব্দ। ⛔ শুধু
                             "ভ্যাট" লেখা থাকলে কেউ কেউ ওটা মোটের সাথে আবার
                             যোগ করতেন, আর সরবরাহকারীকে বেশি দিয়ে ফেলতেন।

                             ⏳ ছবির `Per product ▾` ড্রপডাউনটা বিল-স্তরের, আর
                             ওটা nexus-25-এর চারটা কলামের সাথে আসবে। অঙ্কটা
                             আজই সত্যি — সারিগুলোর যোগফল। --}}
                        <div class="flex justify-between">
                            <dt class="text-(--color-ink-muted)">{{ __('purchase::field.vat_part_of_cost') }}</dt>
                            <dd class="num" x-text="money(taxTotal)"></dd>
                        </div>
                    @endif

                    {{-- ⏳ ── খরচ ও রাউন্ডিংয়ের ঘর এখানে বসবে, কিন্তু আজ নয় ──

                         মালিকের ছবিতে মোটের কার্ডে দুইটা লেখার ঘর আছে —
                         **খরচ** ও **রাউন্ডিং (+/−)**। ঘর দুইটা বসানো
                         হয়েছিল, তারপর **সরিয়ে নেওয়া হয়েছে**, আর কারণটা
                         লিখে রাখা দরকার:

                         ⛔ **"মোট"-ই খতিয়ানে যায়।** `PurchaseBillService`
                         বিলের `total` থেকে সরবরাহকারীর দেনা বসায়। ঘর
                         দুইটা কেবল পর্দায় যোগ করলে পাতা বলত "মোট দেয়
                         ৳১,০০০" আর খতিয়ানে বসত ৳৯৮০ — **নীরবে, কোনো
                         ত্রুটি ছাড়া**, আর সরবরাহকারীর খাতা আমাদের খাতার
                         সাথে মিলত না।

                         ⚠️ **আর ওটা মৃত বোতামের চেয়েও খারাপ:** মৃত বোতাম
                         কিছুই করে না; এটা **একটা ভুল সংখ্যা দেখাত**, আর
                         মানুষ সেই সংখ্যা দেখে দর ঠিক করতেন।

                         **যা লাগবে, একসাথে:**
                         ```
                         pur_bills-এ expense ও rounding ঘর     মাইগ্রেশন
                         PurchaseBillService — মোটে যোগ
                         DirectPurchaseService — ঘর দুইটা পাস
                         এই কার্ডের দুইটা ইনপুট
                         ```
                         ⓘ চারটার তিনটা থাকলে সংখ্যাটা ভুল — তাই চারটাই
                         একসাথে, নয়তো একটাও নয়। --}}
                    <div class="flex justify-between border-t border-(--color-border) pt-1.5 font-semibold">
                        <dt>{{ __('purchase::field.net_payable') }}</dt>
                        <dd class="num" x-text="money(netPayable)"></dd>
                    </div>

                    {{-- ⓘ ছবির `Received Deposit` — এখন পর্যন্ত যত টাকা
                         দেওয়া হলো, সব পথ মিলিয়ে। ⚠️ সারিগুলো নিজে বসে
                         "জমা" প্যানেলে, কিন্তু **যোগফলটা এখানে**, কারণ
                         বকেয়ার অঙ্কটা এটা বাদ দিয়েই দাঁড়ায়। --}}
                    <div class="flex justify-between">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.paid_total') }}</dt>
                        <dd class="num" x-text="paidTotal ? money(paidTotal) : '—'"></dd>
                    </div>

                    {{-- ── তিনটা সারি, একটা নয় — মালিকের ছবির বিন্যাস ─────

                         ```
                         এই বিলে বাকি   ← এই কাগজটার হিসাব
                         আগের বকেয়া     ← সরবরাহকারীর পুরনো খাতা
                         ─────────────
                         মোট বকেয়া      ← দুইটার যোগফল
                         ```

                         ⚠️ **কেন তিনটা:** একটা সংখ্যায় মিশিয়ে দিলে "৳৫০,০০০
                         বাকি" পড়ে বোঝার উপায় থাকত না ওটা আজকের বিলের নাকি
                         ছয় মাসের জমা দেনা। ⓘ দুইজন মানুষ দুইটা অর্থ করতেন,
                         আর দরাদরির টেবিলে ওই ভুলের দাম টাকা। --}}
                    <div class="flex justify-between font-semibold">
                        <dt>{{ __('purchase::field.invoice_due') }}</dt>
                        <dd class="num" x-text="money(invoiceDue)"></dd>
                    </div>

                    {{-- ⚠️ লেবেলটা বদলায় — অগ্রিম আর বকেয়া এক জিনিস নয়।

                         ⓘ বিক্রয়ের পর্দায় ঠিক এই নজিরটাই আছে, আর কারণটা
                         ওখানে লেখা: *"ব্যালেন্স ৫০০" পড়ে বোঝার উপায় ছিল না
                         তিনি ৫০০ পাবেন না দেবেন*। ⛔ এখানে উল্টো দিক —
                         আমরা দেব, নাকি আগেই বেশি দিয়ে রেখেছি। --}}
                    <div class="flex justify-between">
                        <dt class="text-(--color-ink-muted)"
                            x-text="previousDue < 0
                              ? @js(__('purchase::field.previous_advance'))
                              : @js(__('purchase::field.previous_due'))"></dt>
                        <dd class="num"
                            x-text="previousDue ? money(Math.abs(previousDue)) : '—'"></dd>
                    </div>
                </dl>

                {{-- ⭐ `DUE` — ছবিতে নিজের একটা ধূসর ব্যান্ডে, বাকি সারির
                     সাথে নয়। ⓘ কারণটা পড়ার: এটাই সেই সংখ্যা যা নিয়ে
                     মানুষটা কাল আবার ফোন করবেন, তাই ওটাকে বাকি সারিগুলোর
                     ভিড় থেকে আলাদা করে রাখা হয়েছে। --}}
                <div class="mt-2 flex items-center justify-between rounded-(--radius-field)
                            bg-(--color-surface-sunken) px-2 py-1.5 text-2xs">
                    <span class="font-medium">{{ __('purchase::field.total_due') }}</span>
                    <span class="num font-semibold" x-text="money(totalDue)"></span>
                </div>
                </div>

                {{-- ── এখন পরিশোধ ────────────────────────────────────────
                     ডিপোতে অনেক সময় গাড়ির লোককেই টাকা ধরিয়ে দিতে হয়।
                     আলাদা পর্দায় পাঠালে বেশিরভাগ দিন সেটা লেখাই হত না,
                     আর সরবরাহকারীর খাতা ফুলে থাকত। --}}

                {{-- ── এখন পরিশোধ — একাধিক পথে ───────────────────────

                     ⚠️ আগে এখানে **একটা অঙ্ক আর একটা খাত** ছিল, অর্থাৎ
                     পর্দাটা ধরে নিত টাকা এক পথেই যায়। বাস্তবে যায় না:
                     কিছু নগদ, বাকিটা চেকে বা bKash-এ। ⛔ দ্বিতীয় পথটা
                     তখন কোথাও লেখাই হত না, আর সরবরাহকারীর খাতা ভুল
                     দেখাত।

                     ⓘ গড়নটা বিক্রয়ের জমার প্যানেলের হুবহু — সারি
                     খসড়ায় বসে, "যোগ" চাপলে তালিকায় ওঠে, আর লুকানো ঘর
                     হয়ে সার্ভারে যায়। দুইটা পর্দা এক রকম, তাই একবার
                     শিখলেই দুইটাই চলে। --}}


            </section>

            {{-- ── চারটা গণনা — ছবির নিচের ছক ─────────────────────────

                 ```
                 Total Item · Total Purchase Qnty · Free Qty. · Total Free+Purchase Qty
                 ```

                 ⭐ আগে এখানে দুইটা সারি ছিল, আর দ্বিতীয়টা (`মোট পরিমাণ`)
                 কেনা ও ফ্রি **একসাথে** গুনত। ⛔ তাতে *"কতটা কিনলাম"* আর
                 *"কতটা ফ্রি পেলাম"* দুইটা প্রশ্নের একটাই উত্তর ছিল, অথচ
                 দ্বিতীয়টাই ক্রয়ের আসল দর ঠিক করে। ⓘ ছবিতে চারটা সারি,
                 আর চারটাই আলাদা প্রশ্ন। --}}
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) px-3 py-2 text-2xs text-(--color-ink-muted)">
                <div class="flex justify-between">
                    <span>{{ __('purchase::field.total_item') }}</span>
                    <span class="num" x-text="lines.length"></span>
                </div>
                <div class="mt-1 flex justify-between">
                    <span>{{ __('purchase::field.total_bought_qty') }}</span>
                    <span class="num" x-text="qty(boughtQty)"></span>
                </div>
                <div class="mt-1 flex justify-between">
                    <span>{{ __('purchase::field.free_qty') }}</span>
                    <span class="num" x-text="qty(freeTotal)"></span>
                </div>
                <div class="mt-1 flex justify-between border-t border-(--color-border) pt-1">
                    <span>{{ __('purchase::field.total_free_plus_bought') }}</span>
                    <span class="num" x-text="qty(totalQty)"></span>
                </div>
            </section>

            {{-- ── ছয়টা বোতাম, ২×৩ ছকে — মালিকের স্ক্রিনশট ─────────────

                 ⭐ প্রতিটা বোতাম একটা প্যানেল খোলে, আর প্রতিটা প্যানেল
                 ইতিমধ্যেই ছিল — কেবল সবসময় খোলা অবস্থায়। ⓘ বোতামের পিছনে
                 নেওয়ায় খালি পর্দাটা স্ক্রিনশটের মতোই পরিষ্কার থাকে, আর যে
                 দিন ভাড়া বা জমা লাগে সেদিন এক ক্লিকে খোলে।

                 ⚠️ রংগুলো টোকেন থেকে, হাতে লেখা নয় — `#hex` লিখলে
                 `EveryScreenObeysTheThemeTest` লাল হয়, আর থিম বদলালে
                 বোতামগুলো একা আগের রঙে বসে থাকত। --}}
            {{-- ⓘ চারটা বোতাম **দুইটা করে দুই সারিতে** — মালিক, ৬ সেপ্টেম্বর ২০২৬।

                     ⛔ তিন কলামে চারটা বোতাম মানে দ্বিতীয় সারিতে একটা একা,
                     আর তার পাশে দুইটা ঘর খালি — চোখে ভাঙা লাগে।

                     ⓘ ছয়টা বোতাম ছিল বলে তিন কলাম মানানসই ছিল; দুইটা তুলে
                     দেওয়ার পর ছকটাও বদলাতে হয়। ⚠️ **বোতাম সরালে ছক না
                     বদলানো** — এই ভুলটা চোখে পড়ে না, কারণ কিছুই ভাঙে না,
                     শুধু ফাঁকা থাকে। --}}
                <div class="grid min-w-0 grid-cols-2 gap-1 text-center [&_button]:break-words">
                <button type="button" @click="depositOpen = ! depositOpen"
                        :aria-expanded="depositOpen"
                        class="rounded-(--radius-field) bg-(--color-success) px-1 py-2 text-2xs font-medium
                               leading-tight text-(--color-ink-inverse) hover:bg-(--color-success-hover)">
                    {{ __('purchase::action.add_deposit_panel') }}
                </button>

                <button type="button" @click="openNote()" :aria-expanded="noteOpen"
                        class="rounded-(--radius-field) px-1 py-2 text-2xs leading-tight font-medium
                               text-(--color-ink-inverse)"
                        style="background: var(--color-info)">
                    {{ __('purchase::action.add_note') }}
                </button>

                {{-- ⛔ "Chart Entry" আর "শিপমেন্ট" — দুইটা বোতামই মালিকের
                     নির্দেশে তুলে দেওয়া (৬ সেপ্টেম্বর ২০২৬)।

                     ⓘ **কেবল বোতাম গেছে, কিছুই মোছা হয়নি**: দুইটা প্যানেল,
                     তাদের ঘর, আর সার্ভারের দিক সব অক্ষত (`chartOpen`,
                     `shipmentOpen`, `openChart()`)। ⚠️ তাই ফিরিয়ে আনতে হলে
                     ছয় লাইনই যথেষ্ট, নতুন করে কিছু বানাতে হবে না।

                     ⓘ কাউন্টারের ছয়টা বোতাম এখন চারটা: পরিশোধ · মন্তব্য ·
                     পরিবহন · সব মুছুন। --}}
                <button type="button" @click="transportOpen = ! transportOpen"
                        :aria-expanded="transportOpen"
                        class="rounded-(--radius-field) py-2 text-2xs font-medium
                               text-(--color-warning-ink)"
                        style="background: var(--color-warning)">
                    {{ __('purchase::action.transportation') }}
                </button>

                <button type="button" @click="clearAll()"
                        class="rounded-(--radius-field) bg-(--color-danger) px-1 py-2 text-2xs leading-tight
                               font-medium text-(--color-ink-inverse) hover:bg-(--color-danger-hover)">
                    {{ __('purchase::action.clear_all') }}
                </button>
            </div>

            {{-- ⭐ বোতামেই টাকার অঙ্ক — মালিকের ছবি।

                 ⓘ ছোট জিনিস মনে হয়, কিন্তু কাজটা বড়: চূড়ান্ত চাপ দেওয়ার
                 মুহূর্তে চোখ বোতামেই থাকে, উপরের ছকে নয়। ⚠️ অঙ্কটা
                 বোতামে না থাকলে মানুষটাকে চোখ সরিয়ে মেলাতে হত, আর
                 বেশিরভাগ দিন সেটা করা হত না। --}}
            <x-ui.button type="submit" tone="primary" class="w-full"
                         ::class="(busy || lines.length === 0) && 'pointer-events-none opacity-50'">
                {{ __('purchase::action.receive_goods') }} ·
                <span class="num">৳<span x-text="money(netPayable)"></span></span>
            </x-ui.button>
        </aside>
