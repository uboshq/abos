            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-sunken)"
                     style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-badge-inventory-ink)">
                <div class="h-0.5 w-full"
                     style="background: linear-gradient(90deg, var(--color-success), var(--color-info))"></div>

                {{-- ⓘ মালিক: *"ei box ta ro ektu Slime koro"* — কার্ডটা
                     সরু। ⚠️ ঘরগুলো ছোট করা হয়নি, কেবল **চারপাশের ফাঁকা
                     জায়গা**: উপর-নিচের প্যাডিং আর লেবেলের নিচের ফাঁক।
                     ⓘ ঘর ছোট করলে তারিখের ঘরে আঙুল বসানো কঠিন হত, আর
                     কাউন্টারে ওটাই সবচেয়ে বেশি ছোঁয়া হয়। --}}
                <div class="px-3 py-2">
                {{-- ── ছয়টা ঘর — মালিকের ক্রমে, এক সারিতে ────────────────

                     ⚠️ সরবরাহকারীর বাক্সটা এখান থেকে **সরে গেছে**।

                     ⛔ এক দফায় ওটা ছয়টা ঘরের বাঁ পাশে বসানো হয়েছিল, আর
                     মালিক লাল দাগ দিয়ে দেখিয়েছেন ওটা ভুল: কাগজের মাথা
                     একটা সারি, আর সরবরাহকারী তার **নিচে নিজের বাক্সে**।
                     ⓘ কারণটাও পরিষ্কার — মাথার ছয়টা ঘর *"কোন কাগজ"*,
                     আর সরবরাহকারীর বাক্স *"কার কাছ থেকে"*। --}}
                {{-- ⭐ ছয়টাই **এক সারিতে** — মালিক: *"ei line gulo ek line
                     dorle tai koro"*।

                     ⓘ সরু পর্দায় ভাগ হয়ে নামে (২ → ৩ → ৬)।

                     ⛔ **লাইনটা ভাঙত লেবেলের দোষে, ঘরের নয়।** ১৪৪০-এ ছয়
                     ভাগে ~১০৫px করে পড়ে, আর তাতে *"Their invoice no."*
                     দুই লাইনে ভেঙে যেত — তখন ঐ একটা কলাম লম্বা হয়ে তার
                     ঘরটাকে নিচে নামিয়ে দিত, আর "এক লাইন" ভেঙে যেত।

                     ⭐ তাই লেবেলগুলোকে **ভাঙতে দেওয়াই হয় না**
                     (`[&>label>span]:truncate`) — লম্বা নামটা তিনটা বিন্দু
                     নিয়ে থামে, আর সারিটা এক লাইনেই থাকে। ⓘ পুরো লেখাটা
                     `title`-এ, তাই কিছু হারায় না।

                     ⚠️ প্রথমে ব্রেকপয়েন্ট বদলে (`2xl`) সারানোর চেষ্টা
                     করেছিলাম — কাজ হয়নি, কারণ মিডিয়া-কোয়েরির `rem` সবসময়
                     ১৬px ধরে, পাতার ২০px নয়। ⓘ ধরা পড়েছে ব্রাউজারে খুলে,
                     দুইটা চওড়ায় মিলিয়ে। --}}
                <div {{-- ⚠️ তিনটা কলাম **সমান নয়** — মেপে বসানো।

                         ⓘ মাঝের কলামে দুইটাই নম্বর (`PBL-2026-2027-0012`,
                         তাদের চালান নম্বর) — সবচেয়ে লম্বা লেখা। ⛔ সমান
                         কলামে ওটা `PBL-2026-2027-` হয়ে কেটে যেত, আর
                         **কাগজের পরিচয় অর্ধেক দেখা যাওয়া** এই রিপোর পুরনো
                         ফাঁদ (৩ সেপ্টেম্বর ২০২৬)। --}}
                    <div class="grid gap-2 content-start sm:grid-cols-2 lg:grid-cols-3
                            [&>label>span]:truncate">
                    {{-- ⭐ যেদিন মাল এল — মজুদ এই তারিখেই বসে।

                         ⚠️ খালি রাখলে বিলের তারিখই ধরা হয়, তাই প্রতিদিনের
                         সাধারণ ক্রয়ে ঘরটা ছুঁতে হয় না। ⓘ কিন্তু বিল আর
                         গাড়ি আলাদা দিনে হলে এই ঘরটাই মাস-শেষের মজুদ
                         মেলায়। --}}
                    <label class="block">
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('purchase::field.received_on') }}">
                            {{ __('purchase::field.received_on') }}
                        </span>
                        <x-ui.date dense name="received_on" :value="old('received_on')"
                                   class="w-full text-sm" />
                    </label>

                    {{-- ── আমাদের নিজের চালান নম্বর ─────────────────────

                         ⭐ ঘরটা **ভরা অবস্থায় খোলে**, আর বদলানোও যায়।

                         ⚠️ যা দেখা যাচ্ছে সেটা **প্রতিশ্রুতি নয়, পূর্বাভাস**
                         — সিরিজের পরের নম্বর, কেবল দেখানো
                         ([[NumberSeriesEngine::preview()]])। ⛔ `next()`
                         ডাকা হয় না: তাহলে পাতা খোলামাত্র একটা নম্বর খরচ
                         হয়ে যেত, কেউ শুধু দেখে চলে গেলেও, আর নিরীক্ষায়
                         *"৪৭ নম্বর বিলটা কোথায়"* প্রশ্নের উত্তর থাকত না।

                         ⓘ দুইজন একসাথে কাউন্টার খুললে দুইজনেই একই নম্বর
                         দেখবেন — যিনি আগে সেভ করবেন তিনি ওটা পাবেন,
                         পরেরজন পরেরটা। --}}
                    <label class="block">
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('purchase::field.pur_inv_no') }}">
                            {{ __('purchase::field.pur_inv_no') }}
                        </span>
                        <input type="text" name="bill_no" maxlength="32"
                               value="{{ old('bill_no', $billPreview) }}"
                               title="{{ __('purchase::message.bill_no_editable') }}"
                               placeholder="{{ __('purchase::message.on_confirm') }}"
                            {{-- ⓘ ঘরগুলো `--spacing-field-dense` — বিক্রয়ের
                                 কাউন্টারে যে মাপ, ঠিক সেটাই।

                                 ⛔ এখানে `--spacing-field` ছিল, আর তাতে প্রতিটা
                                 সারি ~১৯px উঁচু হত। ⚠️ দুইটা সারিতে মিলে গোটা
                                 বাক্সটা বিক্রয়ের বাক্সের চেয়ে লম্বা দেখাত, আর
                                 মালিক দুইটা পর্দা পাশাপাশি রেখে সেটাই ধরেছেন। --}}
                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                    </label>

                    {{-- ── গুদাম — কোন ভবনে, তাকে নয় ──────────────────

                         মালিকের কথা (৫ সেপ্টেম্বর ২০২৬): *"ডিরেক্ট
                         পারচেসে ওয়্যারহাউস দিয়ে দাও... গোডাউনে যদি আরো
                         ছোটখাটো প্লেসমেন্ট (তাক) থাকে, সে প্লেসমেন্টে
                         করবে।"*

                         ⭐ অর্থাৎ দুইটা আলাদা প্রশ্ন, দুই জায়গায়: **কোন
                         গুদামে** এখানে, আর **গুদামের ভিতরে কোথায়**
                         Inventory ▸ Stock Placement-এ। ⓘ ছোট দোকানে
                         দুইটাই একজনের কাজ, আর দ্বিতীয়টা এক ক্লিকের।

                         ⭐ ঘরটা **ভরা অবস্থায় খোলে** — কোম্পানির ডিফল্ট
                         গুদাম বসানো থাকে (মালিক: *"warehouse by defolt
                         purches e boslo"*)। ⓘ প্রস্তাব, তালা নয়।

                         ⛔ ডিফল্ট বসানো না থাকলে ঘরটা **খালি** থাকে, আর
                         নিচে কারণটা লেখা — নীরবে প্রথম গুদামটা নিলে মাল
                         ভুল জায়গায় বসত আর কেউ জানতই না। --}}
                    <label class="block">
                        {{-- ⛔ এখানে আগে একটা **স্থায়ী নীল নোটিশ** ছিল —
                             *"মাল ঢোকে বসানো হয়নি অবস্থায়…"* — আর মালিক
                             ঠিকই প্রশ্ন তুলেছেন: *"এটার জন্য Notice board
                             আছে কেন?"*

                             ⚠️ **যে লেখা রোজ ওঠে তা কেউ পড়ে না**, আর তখন
                             একই জায়গার **আসল সতর্কতাগুলোও** পড়া বন্ধ হয়ে
                             যায়। ⓘ কাউন্টারের লোক দিনে পঞ্চাশবার এই পর্দা
                             খোলেন; পঞ্চাশবার একই উপদেশ মানে ওটা আর উপদেশ
                             নয়, আসবাব।

                             ⛔ জায়গাটাও ভুল ছিল: লেখাটা **কেনার আগে**,
                             অথচ ঘটনাটা ঘটে **কেনার পরে**। ⭐ তাই কথাটা
                             এখন সংরক্ষণের বার্তায় (`direct_done`), যেখানে
                             মানুষটা সত্যিই সেটা নিয়ে কিছু করতে পারেন।

                             ⓘ আর এখানে কেবল `title`-এ — যিনি জানতে চান
                             তিনি মাউস রাখলেই পান, বাকিদের চোখে পড়ে না। --}}
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('inventory::field.warehouse') }}">
                            {{ __('inventory::field.warehouse') }}
                        </span>
                        {{-- ⚠️ কথাটা **নামের গায়ে নয়, ড্রপডাউনটার গায়ে** —
                             আর কারণটা একটা লাল পরীক্ষা শিখিয়েছে।

                             ⛔ প্রথমে আমি লেখাটা উপরের `<span>`-এর `title`-এ
                             বসিয়েছিলাম, আর তাতে ঘরের **নিজের নামটাই** ওখান
                             থেকে সরে গিয়েছিল। ⓘ ছয়টা লেবেলে `title` বসানো
                             আছে ইচ্ছে করে: এক লাইনে ধরাতে গিয়ে নামগুলো
                             `truncate` হয়, তাই মাউস রাখলে পুরোটা দেখা যায়।
                             ⚠️ ওটা কেড়ে নেওয়া মানে ছোট পর্দায় ঘরটার নামই
                             আর পড়া যেত না।

                             ⭐ আর এখানেই কথাটার আসল জায়গা: প্রশ্নটা
                             *"গুদাম মানে কী"* নয়, প্রশ্নটা **"এই গুদামে
                             পাঠালে মালটার কী হবে"** — আর ঠিক ওটাই এই
                             ড্রপডাউনটা ঠিক করে দেয়। --}}
                        <select name="warehouse_id" required
                                title="{{ __('purchase::message.goods_wait_for_placement') }}"
                                class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            <option value="">—</option>
                            @foreach ($warehouses as $house)
                                <option value="{{ $house->id }}"
                                        @selected(old('warehouse_id', $warehouse?->id) == $house->id)>
                                    {{ $house->name() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('purchase::field.billing_date') }}">
                            {{ __('purchase::field.billing_date') }}
                        </span>
                        <x-ui.date dense name="trx_date"
                                   :value="old('trx_date', now()->toDateString())"
                                   class="w-full text-sm" />
                    </label>

                    <label class="block">
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('purchase::field.their_invoice') }}">
                            {{ __('purchase::field.their_invoice') }}
                        </span>
                        <input type="text" name="supplier_bill_no" value="{{ old('supplier_bill_no') }}"
                               placeholder="{{ __('purchase::message.as_printed') }}"
                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    {{-- ── পরিশোধের শর্ত — একটাই ঘর, সাতটা বিকল্প ─────────

                         মালিকের সিদ্ধান্ত (৫ সেপ্টেম্বর ২০২৬):

                         ```
                         Cash · COD · ৩/৭/১৫/৩০ দিনের বাকি ·
                         Cr. Upto Closing date · a fixed date
                         ```

                         ⛔ **আগে এটা দুইটা বাক্স হয়ে যেত**: তারিখ বাছলে
                         পাশে *"or a fixed date"* নামে আরেকটা ঘর ফুটত, আর
                         তখন পরের ঘরটা **নিচের সারিতে ছিটকে যেত** — গোটা
                         মাথার সারিটা নড়ত। ⚠️ মালিকের কথা: *"date ditei di
                         box holo, eta hote parbe na, ek box ei somadhan
                         korbe"*।

                         ⭐ **তারিখটা এখন সবসময় নিচে লেখা থাকে**, আর
                         `a fixed date` বাছলে **ঐ লাইনটাই টাইপ করা যায়** —
                         নতুন কোনো ঘর গজায় না, যা আছে তা সম্পাদনাযোগ্য হয়।

                         ⓘ তিনটা লাভ: সারি নড়ে না · ব্যবহারকারী সবসময়
                         দেখেন কোন তারিখ বসছে · *"৩০ দিন মানে কবে"* কাউকে
                         মনে মনে গুনতে হয় না।

                         ── ⚠️ প্রতিটা বিকল্পই একটা তারিখে গিয়ে দাঁড়ায় ──
                         ```
                         Cash                  → বিলের তারিখ
                         COD                   → যেদিন মাল এল
                         N দিনের বাকি           → বিলের তারিখ + N
                         Cr. Upto Closing date → বিলের মাসের শেষ দিন
                         a fixed date          → নিজের বাছা তারিখ
                         ```
                         ⓘ তারিখটা **বাছার মুহূর্তেই পাকা** হয়ে `due_on`-এ
                         বসে, আর ধরনটা `payment_term`-এ। --}}
                    {{-- ⓘ `relative` — নিচের ভাসমান তারিখটা এই ঘরের সাপেক্ষে বসে। --}}
                    <label class="relative block">
                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)"
                              title="{{ __('purchase::field.terms') }}">
                            {{ __('purchase::field.terms') }}
                        </span>

                        <select x-model="termChoice" @change="termPicked()"
                                class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term['value'] }}"
                                        @selected($term['value'] === $paymentTermDefault)>
                                    {{ $term['label'] }}
                                </option>
                            @endforeach
                        </select>

                        {{-- ── দেয় তারিখ — সবসময় দেখা যায় ────────────────

                             ⭐ `a fixed date` বাছলে **এই লাইনটাই** লেখার ঘর
                             হয়ে যায় — পাশে দ্বিতীয় বাক্স নয়, নিচে নতুন সারি
                             নয়। ⓘ দুইটা রূপ একই জায়গা দখল করে, তাই কার্ডের
                             উচ্চতাও বদলায় না।

                             ⚠️ পড়ার রূপটায় তারিখটা কোম্পানির ছকে লেখা
                             (`d-m-Y`), ব্রাউজারের ছকে নয় — `05/06` দুইভাবে
                             পড়া যায়, আর দুইটাই বৈধ তারিখ। --}}
                        <div class="mt-0.5">
                            {{-- ⚠️ লেবেলটা লেখা নেই, কেবল তীর আর তারিখ।

                                 ⛔ *"পরিশোধের তারিখ · ০৮-০৯-২০২৬"* লিখলে
                                 ১৩০px-এ কেটে যেত (`Due on · 08-09-2…`), আর
                                 কাটা তারিখ **ভুল তারিখের চেয়েও খারাপ** —
                                 মানুষ অর্ধেকটা পড়ে বাকিটা ধরে নেন।

                                 ⓘ ঘরটার নামই "পরিশোধের শর্ত", তাই নিচের
                                 তারিখটা কীসের তা আলাদা করে বলার দরকার নেই।
                                 পুরো লেখাটা `title`-এ আছে। --}}
                            {{-- ⓘ তারিখটা **শর্তের ঘরের গায়ে**, নিচে আলাদা সারিতে নয়।

                                 ⛔ আলাদা সারিতে থাকলে ওটা গোটা বাক্সটাকে
                                 ~২০px উঁচু করে দিত, আর বিক্রয়ের কাউন্টারের
                                 বাক্সের সাথে উচ্চতা মিলত না — মালিক দুইটা
                                 পর্দা পাশাপাশি রেখে ঠিক সেটাই ধরেছেন
                                 (৬ সেপ্টেম্বর ২০২৬)।

                                 ⚠️ `absolute` বলে সে আর জায়গা নেয় না; ঘরটার
                                 নিচের কিনারায় ভেসে বসে। ⓘ লেবেলের সারিতে
                                 ডান পাশটা খালিই ছিল, তাই সেখানেই। --}}
                            <div x-show="termKind !== 'fixed'" x-cloak
                                 {{-- ⛔ একবার এটা `absolute` করে লেবেলের সারিতে
                                      ভাসানো হয়েছিল — উচ্চতা বাঁচাতে। ⚠️ সরু
                                      কলামে তখন ওটা *"Terms"* লেখার **উপরে**
                                      উঠে যেত, আর দুইটা লেখা একটার উপরে আরেকটা
                                      পড়ে কোনোটাই পড়া যেত না।

                                      ⓘ ২০px উচ্চতা বাঁচানোর চেয়ে দুইটা লেখা
                                      আলাদা থাকা বেশি জরুরি। --}}
                                 class="text-2xs text-(--color-ink-muted)"
                                 title="{{ __('purchase::field.due_on') }}">
                                → <span class="num" x-text="dueOnShown || '—'"></span>
                            </div>

                            <div x-show="termKind === 'fixed'" x-cloak>
                                <x-ui.date dense name="due_on_picked"
                                           bind-name="'due_on_picked'"
                                           bind-iso="dueOn"
                                           bind-model="dueOn" />
                            </div>
                        </div>

                        {{-- ⓘ যা সত্যিই সার্ভারে যায় — দুইটা: ধরন আর তারিখ।
                             ⚠️ দিনসংখ্যাটা যায় না; ওটা তারিখ হয়ে গেছে। --}}
                        <input type="hidden" name="payment_term" :value="termKind">
                        <input type="hidden" name="due_on" :value="dueOn">
                    </label>
                </div>

                {{-- x-show, x-if নয়।

                     x-if ভেতরের অংশটা DOM থেকে সরিয়ে-এনে বসায়, আর ওই
                     ক্লোন করা অংশ থেকে বাইরের x-ref দেখা যায় না — তাই
                     addToCart()-এ $refs.search অনির্ধারিত ছিল, আর
                     "Cannot read properties of undefined (reading 'focus')"
                     এররে Alpine ওখানেই থেমে যেত। থেমে যাওয়া মানে কার্টের
                     সারিগুলোর ::name বাঁধা হত না, ফলে সাবমিটে lines ফাঁকা
                     যেত আর সার্ভার "The lines field is required" বলত —
                     কার্ট চোখের সামনে ঠিক দেখালেও।

                     সরাসরি বিক্রয়ের পর্দা শুরু থেকেই x-show ব্যবহার করে;
                     এটাও তা-ই করে। --}}
                {{--
                    `picked` নাল থাকতে পারে, তাই ভিতরের প্রতিটা পড়া
                    `picked?.` দিয়ে।

                    ── কী ভাঙা ছিল ─────────────────────────────────────
                    `x-show` উপাদানটা **লুকায়**, কিন্তু Alpine ভিতরের
                    অভিব্যক্তিগুলো তবু মূল্যায়ন করে। শুরুতে `picked`
                    নাল, তাই পাতা খোলা মাত্রই কনসোলে:

                        Cannot read properties of null (reading 'name')

                    পর্দায় কিছু ভাঙত না, তাই কেউ টের পেত না। কিন্তু
                    ত্রুটিতে ভরা কনসোল **আসল ত্রুটিকে ঢেকে দেয়** — আর
                    ওটাই এই বাগের আসল দাম।

                    ধরা পড়েছে ২৬ আগস্ট ২০২৬, লাইভের ১৪৪টা পর্দা ঘুরে
                    কনসোল পড়ে — কোনো মানুষের চোখে নয়।
                --}}
                </div>
            </section>
