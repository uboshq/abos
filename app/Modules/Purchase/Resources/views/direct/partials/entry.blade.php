            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-muted) p-3"
                     style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-badge-draft-ink)">
{{-- ⚠️ বার্তাটা সারির **নিচে**, ঘরটার ভিতরে নয়।

                     ⛔ আগে এটা গুদামের `<label>`-এর ভিতরে ছিল, আর তাতে
                     ওই একটা কলাম লম্বা হয়ে পুরো সারিটার উচ্চতা টেনে
                     নিত — চারটা ঘর তখন আর এক লাইনে থাকত না। ⓘ ধরা
                     পড়েছে ব্রাউজারে খুলে, কোড পড়ে নয়। --}}
                @if ($warehouse === null)
                    <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                              text-2xs text-(--color-badge-warning-ink)">
                        {{ __('purchase::message.no_default_warehouse') }}
                    </p>
                @endif


                {{-- ── পণ্য খোঁজা — কার্ডের মাথায়, বড় করে ────────────

                     ⭐ মালিকের স্ক্রিনশটে এটাই পর্দার সবচেয়ে বড় লেখা
                     (`Type or pick an item…`), আর পাশে একটা বর্গাকার
                     খোঁজ-বোতাম। ⓘ মাপটা ইচ্ছাকৃত: কাউন্টারে সবচেয়ে বেশি
                     যেটা ছোঁয়া হয় সেটাই সবচেয়ে বড় হওয়া দরকার।

                     ⚠️ বোতামটা সাজসজ্জা নয় — চাপলে কার্সর ঘরে ফেরে।
                     কাউন্টারে মাউস ধরাই যায় না, তাই ঘরটায় ফেরার একটা বড়
                     লক্ষ্য থাকা দরকার। --}}
                <div class="relative">
                    <div class="flex items-center gap-2">
                        {{-- ⓘ চিহ্নটা এখন কেবল ফোকাস করে না, **তালিকাও খোলে**।
                             ⛔ আগে চাপলে কিছুই হত না, কারণ খালি লেখায় তালিকা
                             খালি থাকত — মালিক: *"product sarch icon o kaj kore
                             na, Product aseo na"*। --}}
                        <button type="button"
                                @click="toggleBrowsing()"
                                aria-label="{{ __('purchase::field.search_item') }}"
                                class="flex size-(--spacing-command) shrink-0 items-center justify-center
                                       rounded-(--radius-field) border border-(--color-success)
                                       text-(--color-success) hover:bg-(--color-surface-hover)">
                            <x-ui.icon name="search" :size="18" />
                        </button>

                        {{-- ── বাছা পণ্যের নাম — খোঁজার ঘরের **পাশে** ──────────

                             মালিক (৬ সেপ্টেম্বর ২০২৬): *"icon er sate bosaw"* — *"Products er nam Search
                             Buton er Pase bosaw"*।

                             ⛔ আগে নামটা খোঁজার ঘরের **নিচে** আলাদা সারিতে বসত,
                             আর তাতে একটা গোটা সারির উচ্চতা যেত — অথচ খোঁজার
                             ঘরটা তখন খালিই পড়ে থাকত।

                             ⭐ এখন একই সারিতে: বাঁয়ে খোঁজার ঘর, ডানে যেটা বাছা
                             হয়েছে তার নাম · মজুদ · গতবারের দর। ⓘ পণ্য বাছার
                             পর খোঁজার ঘরটা সরু হয়ে নামটাকে জায়গা দেয়। --}}
                        <div x-show="picked" x-cloak class="flex min-w-0 shrink items-baseline gap-x-4">
                            <span class="font-semibold" x-text="(picked && picked.name)"></span>
                            <span class="text-2xs text-(--color-ink-muted)" x-show="picked" x-cloak>
                                {{ __('purchase::message.on_hand') }}:
                                <span class="num" x-text="qty((picked && picked.on_hand))"></span>
                            </span>
                            {{-- শেষ কত দামে কেনা হয়েছিল — নতুন দর এর সাথেই মেলানো হয় --}}
                            <span class="text-2xs text-(--color-ink-muted)" x-show="(picked && picked.last_rate) > 0" x-cloak>
                                {{ __('purchase::message.last_rate') }}:
                                <span class="num" x-text="money((picked && picked.last_rate))"></span>
                            </span>
                        </div>
                        {{-- ⭐ ঘরটা কেবল **খোঁজার সময়** — মালিকের নির্দেশ,
                             ৬ সেপ্টেম্বর ২০২৬: *"Search box takar kotha
                             dropdown-er sathe, bose thake onno jaygay; eta
                             product select er por-o ekhane dekhay, prod add
                             hole r zate na dekhay"*।

                             ⛔ আগে বাছার পরেও ঘরটা থেকে যেত, কেবল সরু হয়ে
                             (`w-40`) ডান কোণে সরে যেত। ⚠️ আর তালিকাটা ভাসে
                             কার্ডের **বাঁ কিনারা থেকে** — ফলে লেখার ঘর ডানে,
                             তার ফলের তালিকা বাঁয়ে। ⓘ চোখে দুইটা আলাদা জিনিস
                             মনে হত, অথচ একটা আরেকটার ফল।

                             ⭐ এখন ঘরটা পুরো প্রস্থ নেয় আর **তালিকাটা ঠিক তার
                             নিচেই** ঝোলে — দুইটা একসাথে, একটাই জিনিস। পণ্য
                             বাছা হয়ে গেলে সে সরে যায়, আর তখন নাম · মজুদ ·
                             শেষ দর — তিনটাই পুরো জায়গা পায়।

                             ⓘ আবার খুঁজতে বাঁয়ের চিহ্নটা (`browsing`) — সেটা
                             আগেও এভাবেই কাজ করত, কেবল ঘরটা লুকানো ছিল না বলে
                             কেউ চিহ্নটা ব্যবহার করত না। --}}
                        <input type="text" x-model="term" x-ref="search"
                               x-show="! picked || browsing" x-cloak
                               :class="picked ? 'w-40 shrink-0' : 'min-w-0 flex-1'"
                               @focus="browsing = true"
                               @keydown.escape="browsing = false"
                               @keydown.enter.prevent="pickFirst()"
                               :placeholder="supplierId ? @js(__('purchase::message.search_product')) : @js(__('purchase::message.pick_supplier_first'))"
                               class="h-(--spacing-command) min-w-0 flex-1 border-0 bg-transparent px-1
                                      text-lg text-(--color-ink) placeholder:text-(--color-ink-placeholder)
                                      focus:outline-none">

                        {{-- ── ছবির ছোট লাইনটা — সারির **ডান প্রান্তে** ──────

                             মালিক (৬ সেপ্টেম্বর ২০২৬): *"Pick an item to see
                             what is already in stock — lika dane mark kora
                             box er jaygay rako"*।

                             ⛔ আগে লাইনটা খোঁজার ঘরের **নিচে** বসত, আর একটা
                             গোটা সারির উচ্চতা নিত — অথচ ঘরটার ডান পাশে
                             অর্ধেক প্রস্থ খালি পড়ে থাকত।

                             ⓘ `ms-auto` — সে ডান কিনারায় সরে যায়। ⚠️ আর
                             পণ্য বাছার সাথে সাথেই মিলিয়ে যায়, কারণ তখন ঐ
                             জায়গাটা পণ্যের নাম নেয়। --}}
                        <span x-show="! picked" x-cloak
                              class="ms-auto hidden truncate text-2xs text-(--color-module-purchase) sm:block">
                            {{ __('purchase::message.pick_item_hint') }}
                        </span>
                    </div>

                    {{-- ⭐ ক্রয়দর বদলেছে — প্রশ্ন, বদল নয়।

                         মালিকের শর্ত, ৬ সেপ্টেম্বর ২০২৬: *"ক্রয়মূল্য কমলে বা
                         বাড়লে warning ও বিক্রয়মূল্য পরিবর্তন হবে"* — আর
                         কীভাবে, তাও তাঁর: **"জিজ্ঞেস করে বদলাবে।"**

                         ⛔ নিজে বদলে দিলে কাউন্টারে কেউ খেয়াল না করে বিক্রি
                         করে ফেলতেন। ⚠️ এই পর্দার সংখ্যা সরাসরি কাগজে যায়,
                         তাই নীরব বদল মানে **ভুল দামে ছাপা চালান**।

                         ⓘ সারিটা তিনটা সংখ্যাই দেখায় — পুরনো দর, নতুন দর,
                         আর নীতিটা মানলে দাম কত হবে। ⚠️ কেবল "দাম বদলাবে"
                         লিখলে মানুষ কী মেনে নিচ্ছেন তা জানতেন না।

                         ⓘ দুইটা পথই সমান দৃশ্যমান: মানা আর না-মানা। **না-মানা
                         লুকানো থাকলে ওটা প্রশ্ন নয়, ঘোষণা।** --}}
                    <div x-show="priceAsk" x-cloak
                         class="mt-2 flex flex-wrap items-center gap-2 rounded-(--radius-field)
                                border border-(--color-badge-pending-ink)/30
                                bg-(--color-badge-pending-bg) px-3 py-2 text-2xs
                                text-(--color-badge-pending-ink)">
                        <span>
                            {{ __('purchase::message.rate_moved') }}
                            <span class="num font-semibold" x-text="money((priceAsk && priceAsk.was))"></span>
                            →
                            <span class="num font-semibold" x-text="money((priceAsk && priceAsk.now))"></span>
                        </span>

                        <span>
                            {{ __('purchase::message.price_would_become') }}
                            <span class="num font-semibold" x-text="money((priceAsk && priceAsk.from))"></span>
                            →
                            <span class="num font-bold" x-text="money((priceAsk && priceAsk.to))"></span>
                        </span>

                        <button type="button" @click="takeSuggestedPrice()"
                                class="ms-auto rounded-(--radius-field) bg-(--color-success) px-3 py-1
                                       font-semibold text-white">
                            {{ __('purchase::action.take_new_price') }}
                        </button>

                        <button type="button" @click="keepOldPrice()"
                                class="rounded-(--radius-field) border border-current px-3 py-1 font-medium">
                            {{ __('purchase::action.keep_old_price') }}
                        </button>
                    </div>

                    <ul x-show="visible.length > 0" x-cloak
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-(--radius-field)
                               border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        <template x-for="p in visible" :key="p.id">
                            <li>
                                <button type="button" @click="pickFromList(p)"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2
                                               text-start text-sm hover:bg-(--color-surface-hover)">
                                    <span>
                                        <span x-text="p.name"></span>
                                        <span class="block text-2xs text-(--color-ink-muted)" x-text="p.code"></span>
                                    </span>
                                    <span class="num shrink-0 text-2xs text-(--color-ink-muted)">
                                        <span x-text="qty(p.on_hand)"></span>
                                    </span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>

                {{-- ══ সারি ২–৩ আর "এই লাইনে" ছক — পাশাপাশি ═════════════

                     ⭐ মালিকের ছবির বাঁ কার্ড ভিতরে দুই ভাগ: বাঁয়ে ঘরগুলো,
                     ডানে যোগফলের ছক। ⓘ ছকটা **সবসময় দেখা যায়**, পণ্য বাছার
                     আগেও — ছবিতে ওটা ৳0.00 নিয়ে বসে আছে, আর ওটাই ঠিক:
                     মানুষটা আগে থেকেই জানেন সংখ্যাগুলো কোথায় আসবে।

                     ⚠️ ঘরগুলো তবু বন্ধ থাকে পণ্য না বাছা পর্যন্ত
                     (`:disabled="! picked"`) — খোলা রাখলে কেউ পরিমাণ লিখে
                     ফেলতেন কোন পণ্যের জন্য তা না বলেই, আর "যোগ করুন" চাপলে
                     কিছুই হত না, কারণ ছাড়াই। --}}
                <div class="mt-3">
                    <div class="min-w-0">

                        {{-- ── সারি ২: পরিমাণ ────────────────────────────

                             ⭐ ছবির পাঁচটা ঘর: `QTY. · UOM · FREE QTY · UOM ·
                             TOTAL QTY.`। ⓘ শেষেরটা পড়ার জন্য — যোগফলটা হাতে
                             লিখতে দিলে একদিন কেউ ভুল লিখতেন, আর পর্দার
                             সংখ্যাটা তার নিজের ঘরের সাথেই মিলত না। --}}
                        {{-- ── নয়টা ঘর, এক সারিতে ──────────────────────────

                             মালিক (৬ সেপ্টেম্বর ২০২৬): *"ei Box gulo Cuto
                             kore ek Line Ano"*।

                             ⓘ পরিমাণ আর দাম — দুইটা আলাদা প্রশ্ন হলেও একটা
                             লাইনেই একটা পণ্যের পুরো গল্প: কত এল, কোন এককে,
                             কত ফ্রি, কত দরে কিনলাম, কত দরে বেচব।

                             ⚠️ লেবেলগুলো `truncate` — নয়টা ঘরে প্রতিটার ভাগে
                             ~৬৫px, আর *"Purchase rate"* ওতে ধরে না। ⓘ পুরো
                             লেখাটা `title`-এ থাকে, মাউস রাখলে দেখা যায়।

                             ⛔ সরু পর্দায় নয়টা এক সারিতে চাপালে প্রতিটা ঘর
                             আঙুলের চেয়ে ছোট হত — তাই `lg`-র নিচে তিনটা করে। --}}
                        <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-9
                                    [&>label>span]:truncate">
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.qty') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal" x-model="entry.qty"
                                       :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            {{-- ── একক — কেনা পরিমাণের ───────────────────

                                 ⭐ পথটা মাথা থেকে পা পর্যন্ত আগেই তৈরি ছিল
                                 (`lines.*.unit_id` → [[ReadsPackedQuantities]]
                                 → `entered_qty`/`entered_unit_id`), কেবল এই
                                 পর্দায় ঘরটা ছিল না — অর্থাৎ "১ বাক্স" লিখে
                                 কেনার উপায় এখানে ছিল না, যদিও ব্যবস্থাটা ছিল।

                                 ⚠️ তালিকা খালি হলে (কোম্পানি প্যাকে কেনে না,
                                 বা পণ্যটার সিঁড়িতে একটাই একক) ড্রপডাউনের বদলে
                                 পণ্যের নিজের এককের নাম **পড়ার ঘরে** বসে।
                                 ⓘ নিষ্ক্রিয় ড্রপডাউন দিলে সেটা মৃত নিয়ন্ত্রণ
                                 হত; নামটা লেখা থাকলে ঘরটা একটা উত্তর দেয়। --}}
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.unit') }}
                                </span>
                                <select x-model="entry.unit_id" :disabled="! picked"
                                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                               border-(--color-border) bg-(--color-surface-card) px-2 text-sm
                                               disabled:opacity-50">
                                    {{-- ⓘ খালি মানে **পণ্যের নিজের একক** — তাই লেখাটাও
                                         সেটাই, একটা ড্যাশ নয়। ⚠️ ড্যাশ দেখে মানুষ ভাবতেন
                                         একক বাছা হয়নি, অথচ ওটাই স্বাভাবিক অবস্থা। --}}
                                    <option value="" x-text="(picked && picked.unit) || '—'"></option>
                                    <template x-for="u in unitOptions" :key="u.id">
                                        <option :value="u.id" x-text="u.label"></option>
                                    </template>
                                </select>
                                {{-- ⛔ এখানে একটা পড়ার-জন্য `<div>` ছিল, যেটা প্যাক না
                                     থাকলে ড্রপডাউনের বদলে বসত — আর তখন ঘরটা দেখতে
                                     ড্রপডাউনই লাগত না, নিছক লেখা। ⚠️ মালিক ঠিক ওটাই
                                     ধরেছেন: *"UoM Box e Dropdawn Hobe"*।

                                     ⭐ এখন সবসময় ড্রপডাউন — প্যাক না থাকলে তার
                                     ভিতরে একটাই বিকল্প, পণ্যের নিজের একক। --}}
                            </label>

                            @if ($show['free_qty'])
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('purchase::field.free_qty') }}
                                    </span>
                                    <input type="number" step="0.01" inputmode="decimal" x-model="entry.free_qty"
                                           :disabled="! picked"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                                  disabled:opacity-50">
                                </label>

                                {{-- ⭐ ফ্রি-র নিজের একক — ছবির চতুর্থ ঘর।

                                     মিল কার্টনে বেচে, আর ফ্রি দেয় পিসে। ⛔ আগে
                                     সার্ভার ফ্রি পরিমাণ **লাইনেরই** এককে নামাত,
                                     তাই "১০ কার্টন, ১ পিস ফ্রি" লেখাই যেত না।
                                     ⓘ এখন `free_unit_id` যায়, আর কোন প্যাকে
                                     লেখা হয়েছিল সেটাও মনে থাকে
                                     (`entered_free_qty`) — নাহলে বিলটা আবার
                                     খুললে "১ কার্টন" ফিরত "১২ পিস" হয়ে। --}}
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('purchase::field.free_unit') }}
                                    </span>
                                    <select x-model="entry.free_unit_id" :disabled="! picked"
                                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                   border-(--color-border) bg-(--color-surface-card) px-2 text-sm
                                                   disabled:opacity-50">
                                        {{-- ⓘ খালি মানে **পণ্যের নিজের একক** — তাই লেখাটাও
                                         সেটাই, একটা ড্যাশ নয়। ⚠️ ড্যাশ দেখে মানুষ ভাবতেন
                                         একক বাছা হয়নি, অথচ ওটাই স্বাভাবিক অবস্থা। --}}
                                    <option value="" x-text="(picked && picked.unit) || '—'"></option>
                                        <template x-for="u in unitOptions" :key="u.id">
                                            <option :value="u.id" x-text="u.label"></option>
                                        </template>
                                    </select>
                                </label>
                            @endif

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.line_qty_total') }}
                                </span>
                                <div class="num flex h-(--spacing-field) items-center justify-end
                                            rounded-(--radius-field) bg-(--color-surface-sunken) px-2 text-sm"
                                     x-text="qty(entryTotalQty)"></div>
                            </label>


                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.purchase_rate') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.rate" @input="rateEdited()" :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.markup') }}
                                </span>
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="entry.markup" @input="priced('markup')" :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.margin') }}
                                </span>
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="entry.margin" @input="priced('margin')" :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.sales_rate') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.sales_price" @input="priced('sales_price')"
                                       :disabled="! picked"
                                       placeholder="{{ __('purchase::message.sales_rate_hint') }}"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>
                        </div>

                        <p x-show="needsRate" x-cloak
                           class="mt-2 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                                  text-xs text-(--color-badge-pending-ink)">
                            {{ __('purchase::message.rate_first') }}
                        </p>

                        {{-- ── সারি ৩: দর ────────────────────────────────

                             ── দর নির্ধারণের তিনটা ঘর ───────────────────
                             তিনটা একই সম্পর্কের তিনটা মুখ। যেটায় লেখা
                             হয় সেটাই নীতি, বাকি দুইটা তার ফল। markup
                             মাপা হয় ক্রয়দরের উপর, margin বিক্রয়দরের
                             উপর — ১০০-তে কিনে ১৫০-তে বেচা মানে ৫০%
                             markup আর ৩৩.৩৩% margin। --}}
                    </div>

                </div>
            </section>
