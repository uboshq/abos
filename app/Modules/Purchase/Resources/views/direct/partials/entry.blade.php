            {{-- ⭐ বাক্স নেই — শুধু খোঁজার বোতাম; পণ্য বাছলে লট (৩) আর পরিমাণের সারি (৪) দেখা দেয়, "কার্টে দিন"-এ আবার লুকায়
                 (মালিক, ২৬ নভেম্বর ২০২৬) --}}
            {{-- ⓘ ⋯ → সম্পাদনা: এই অংশটাই পপআপ হয়ে পর্দার মাঝে বসে, পেছনে ঝাপসা পর্দা; "হালনাগাদ করুন" বা ✕ চাপলে ফেরে --}}
            <div x-show="editingKey !== null" x-cloak @click="clearEntry()" class="fixed inset-0 z-50 bg-black/45"></div>
            <section data-direct-entry class="px-1 py-1" :class="editingKey !== null ? 'entry-as-popup' : ''">
                <div x-show="editingKey !== null" x-cloak class="mb-3 flex items-center justify-between border-b border-(--color-border) pb-2">
                    <h2 class="text-base font-semibold">{{ __('purchase::action.edit_line') }}</h2>
                    <button type="button" @click="clearEntry()" aria-label="{{ __('purchase::action.close_panel') }}"
                            class="rounded-(--radius-field) px-2 text-xl leading-none text-(--color-ink-muted) hover:bg-(--color-surface-hover)">&times;</button>
                </div>
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
                {{-- ⭐ খোঁজার ঘর মোটা, ভিতরে ধূসর লেখা "এখানে পণ্য খুঁজুন" — উপরে "পণ্য" লেবেল নেই (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v9) --}}
                {{-- ⭐ বাক্স নেই — কেবল খোঁজার বোতাম, আর তার পাশে নাম বা খোঁজার লেখা; উপরে ফাঁকা নেই (মালিক, ২৬ নভেম্বর ২০২৬) --}}
                <div class="flex items-start gap-3">
                <div class="relative min-w-0 flex-1">
                    <div class="flex min-h-10 items-center gap-2">
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
                        {{-- ⓘ লম্বা নাম দুই লাইনে ভাঙে, পুরোটা দেখা যায়; তার বেশি হলে "…" আর মাউস রাখলে পুরো নাম (মালিক, ২৬ নভেম্বর ২০২৬) --}}
                        <div x-show="picked" x-cloak class="flex min-w-0 flex-1 items-center gap-2">
                            <span class="line-clamp-2 min-w-0 py-0.5 text-lg font-semibold leading-normal" :title="(picked && picked.name)"
                                  x-text="(picked && picked.name)"></span>
                            <span x-show="(picked && picked.track_batch)" x-cloak
                                  class="ms-auto shrink-0 rounded-full bg-(--color-badge-warning-bg) px-2 text-2xs text-(--color-badge-warning-ink)">{{ __('purchase::lot.keeps_lot') }}</span>
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
                               {{-- ⛔ ফোকাসে তালিকা নয় — মালিক, ৩ অক্টোবর ২০২৬: *"ekta product add er por ro add
                                    er jonno list bose auto, eta biroktikor"*। ⓘ লিখলে, ক্লিকে বা নিচের তীরে খোলে --}}
                               @click="openBrowsing()"
                               @keydown.down="openBrowsing()"
                               @keydown.escape="browsing = false"
                               @keydown.enter.prevent="pickFirst()"
                               :placeholder="supplierId ? @js(__('purchase::message.search_product')) : @js(__('purchase::message.pick_supplier_first'))"
                               class="h-10 min-w-0 flex-1 border-0 bg-transparent px-1
                                      text-xl text-(--color-ink) placeholder:text-(--color-ink-placeholder)
                                      focus:outline-none">

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

                {{-- ⓘ পণ্যের খবর — নামের ঠিক নিচে, লটের বাক্সের বাঁয়ে (মালিক, ২৬ নভেম্বর ২০২৬): আলাদা সারি নয়, তাই নিচের ঘরগুলো উপরে ওঠে --}}
                <div x-show="picked" x-cloak class="mt-0.5 flex flex-wrap gap-x-4 ps-11 text-2xs text-(--color-ink-muted)">
                    <span>{{ __('purchase::message.on_hand') }}: <b class="num text-(--color-ink)" x-text="qty((picked && picked.on_hand))"></b></span>
                    <span x-show="(picked && picked.last_rate) > 0" x-cloak>{{ __('purchase::message.last_rate') }}:
                        <b class="num text-(--color-ink)" x-text="money((picked && picked.last_rate))"></b></span>
                    <span x-show="entry.sales_price" x-cloak>{{ __('purchase::field.sales_rate') }}:
                        <b class="num text-(--color-ink)" x-text="money(entry.sales_price)"></b></span>
                </div>
                </div>

                {{-- ⭐ লট — পণ্যের ডানে, হলুদ বাক্সে (নমুনা v9); কেবল লট রাখা পণ্যে। লিখলে সেটাই, খালি রাখলে সংরক্ষণে কাগজের
                     তারিখে নিজে বসে (`261126/01-LOT`, [[PurchaseLots]]) — আগের মতোই (মালিক, ২৬ নভেম্বর ২০২৬)।
                     ⓘ `x-if` — প্রতিটা পণ্য বাছায় ঘরগুলো নতুন করে বসে, তাই তারিখের ঘর আগের পণ্যের মেয়াদ ধরে রাখে না। --}}
                <template x-if="picked && picked.track_batch">
                    <div data-lot-fields class="grid w-[42%] shrink-0 grid-cols-3 gap-2 rounded-(--radius-field)
                                bg-(--color-badge-pending-bg) px-2 py-1.5">
                        <label class="block min-w-0">
                            <span class="block truncate text-2xs text-(--color-badge-pending-ink)">{{ __('inventory::field.batch_no') }}</span>
                            <input type="text" maxlength="60" autocomplete="off" data-lot-field x-model="entry.batch_no"
                                   :placeholder="lotHint" @keydown.enter.prevent="lotNext($event)"
                                   class="h-9 w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        </label>
                        <div class="min-w-0">
                            <span class="block truncate text-2xs text-(--color-badge-pending-ink)">{{ __('inventory::field.expiry_date') }}</span>
                            <x-ui.date dense name="entry_expiry_date" bind-iso="entry.expiry_date" bind-model="entry.expiry_date"
                                       data-lot-field x-on:keydown.enter.prevent="lotNext($event)" class="text-sm" />
                        </div>
                        <label class="block min-w-0">
                            <span class="block truncate text-2xs text-(--color-badge-pending-ink)">{{ __('inventory::field.mrp') }}</span>
                            <input type="number" step="0.01" min="0" inputmode="decimal" data-lot-field x-model="entry.mrp"
                                   @keydown.enter.prevent="lotNext($event)"
                                   class="num h-9 w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                        </label>
                    </div>
                </template>
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
                <div class="mt-2" x-show="picked" x-cloak data-entry-row
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:leave="transition ease-in duration-100" x-transition:leave-end="opacity-0">
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
                        {{-- ⭐ এক সারিতে সব ঘর, আর শেষে উপহার · কার্টে দিন · মুছুন — "এই লাইনে" বাক্স নেই (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v9) --}}
                        {{-- ⭐ ছক, ভাঙে না — ঘর ছোট, এক লাইনে (মালিক, ২৬ নভেম্বর ২০২৬: "এত বড় বক্স না")। ⓘ কলাম সুইচ ধরে গোনা --}}
                        @php
                            // ⓘ অনুপাতে (fr) — পর্দা যত চওড়াই হোক, সব ঘর আর তিন বোতাম এক লাইনে ধরে
                            $fr = fn (float $n) => 'minmax(0,'.$n.'fr)';
                            $cols = [$fr(1), $fr(1.25)];                                         // পরিমাণ, একক
                            if ($show['free_qty']) { array_push($cols, $fr(0.9), $fr(1.25)); }   // ফ্রি, ফ্রি-র একক
                            array_push($cols, $fr(0.9), $fr(1.2), $fr(0.9), $fr(0.9), $fr(1.2)); // মোট, ক্রয়দর, মার্কআপ, মার্জিন, বিক্রয়দর
                            if ($show['line_discount']) { $cols[] = $fr(1.2); }
                            if ($show['vat']) { $cols[] = $fr(1.5); }
                            array_push($cols, $fr(1.3), 'auto');                                  // টাকা, বোতাম
                        @endphp
                        {{-- ⭐ এই সারির যেকোনো ঘরে Enter = "কার্টে দিন" (মালিক, ২৬ নভেম্বর ২০২৬) --}}
                        <div @keydown.enter.prevent="addToCart()" class="grid items-end gap-1.5 [&>label]:min-w-0 [&>label>span]:truncate
                                    [&_input]:px-1.5 [&_select]:px-1"
                             style="grid-template-columns: {{ implode(' ', $cols) }}">
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.qty') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal" x-model="entry.qty" x-ref="entryQty"
                                       :disabled="! picked"
                                       class="num h-9 w-full rounded-(--radius-field) border
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
                                        class="h-9 w-full rounded-(--radius-field) border
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
                                           class="num h-9 w-full rounded-(--radius-field) border
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
                                                class="h-9 w-full rounded-(--radius-field) border
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
                                <div class="num flex h-9 items-center justify-end
                                            rounded-(--radius-field) bg-(--color-surface-sunken) px-2 text-sm"
                                     x-text="qty(entryTotalQty)"></div>
                            </label>


                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.purchase_rate') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.rate" @input="rateEdited()" :disabled="! picked" x-ref="entryRate"
                                       class="num h-9 w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.markup') }}
                                </span>
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="entry.markup" @input="priced('markup')" :disabled="! picked"
                                       class="num h-9 w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.margin') }}
                                </span>
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="entry.margin" @input="priced('margin')" :disabled="! picked"
                                       class="num h-9 w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.sales_rate') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.sales_price" @input="priced('sales_price')" x-ref="entrySalesPrice"
                                       :disabled="! picked"
                                       placeholder="{{ __('purchase::message.sales_rate_hint') }}"
                                       class="num h-9 w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            @if ($show['line_discount'])
                                {{-- ⓘ সারির ছাড় — টাকা বা শতাংশ, সংরক্ষণ টাকায় (আগে "এই লাইনে" বাক্সে ছিল) --}}
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('purchase::field.discount_on_line') }}</span>
                                    <span class="flex gap-1">
                                        <input type="number" step="0.01" inputmode="decimal" x-model="entry.discount" :disabled="! picked"
                                               class="num h-9 min-w-0 flex-1 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm disabled:opacity-50">
                                        <button type="button" @click="toggleDiscountMode()" :aria-pressed="entry.discount_mode === 'percent'"
                                                class="h-9 w-7 shrink-0 rounded-(--radius-field) border border-(--color-border)
                                                       text-center font-medium hover:bg-(--color-surface-hover)"
                                                x-text="entry.discount_mode === 'percent' ? '%' : '৳'">৳</button>
                                    </span>
                                </label>
                            @endif

                            @if ($show['vat'])
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('purchase::field.vat_mode') }}</span>
                                    <span class="flex gap-1">
                                        <select x-model="entry.vat_mode" :disabled="! picked"
                                                class="h-9 min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-1 text-sm disabled:opacity-50">
                                            <option value="product">{{ __('purchase::field.vat_mode_product') }}</option>
                                            <option value="amount">{{ __('purchase::field.vat_mode_amount') }}</option>
                                            <option value="none">{{ __('purchase::field.vat_mode_none') }}</option>
                                        </select>
                                        <input type="number" step="0.01" inputmode="decimal" x-model="entry.tax"
                                               x-show="entry.vat_mode === 'amount'" x-cloak aria-label="{{ __('purchase::field.tax') }}"
                                               class="num h-9 w-16 rounded-(--radius-field) border border-(--color-border)
                                                      bg-(--color-surface-card) px-1 text-end text-sm">
                                    </span>
                                </label>
                            @endif

                            {{-- ⓘ এই সারির টাকা — ছাড় ও ভ্যাটসহ নেট (আগে "এই লাইনে" বাক্সের বড় অঙ্ক) --}}
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('purchase::field.amount') }}</span>
                                <div class="num flex h-9 items-center justify-end rounded-(--radius-field)
                                            bg-(--color-surface-sunken) px-2 text-sm font-semibold"
                                     x-text="money(entryNet)"></div>
                            </label>

                            <div class="flex shrink-0 items-end gap-0.5">
                                {{-- ⓘ উপহার — পণ্যটা কার্টে বসিয়ে তার ঠিক নিচে উপহারের সারি খোলে, বিক্রয়ের মতো ([[giftForThisLine()]]) --}}
                                <button type="button" @click="giftForThisLine()" :disabled="! picked"
                                        class="h-9 rounded-(--radius-field) border px-1.5 text-sm font-semibold disabled:opacity-40"
                                        style="border-color: var(--color-module-purchase); color: var(--color-module-purchase);
                                               background: var(--color-badge-warning-bg)">
                                    {{ __('purchase::action.gift_short') }}
                                </button>

                                <button type="button" @click="addToCart()" :disabled="! picked"
                                        class="h-9 whitespace-nowrap rounded-(--radius-field) bg-(--color-success) px-2 text-sm font-semibold
                                               text-(--color-ink-inverse) hover:bg-(--color-success-hover) disabled:opacity-40">
                                    {{-- ⓘ কার্টের সারি উপরে খোলা থাকলে "হালনাগাদ করুন" — নতুন সারি নয়, সেটাই বদলায় --}}
                                    <span x-show="editingKey === null">＋ {{ __('purchase::action.add_to_cart') }}</span>
                                    <span x-show="editingKey !== null" x-cloak data-update-line>{{ __('purchase::action.update_line') }}</span>
                                </button>

                                <button type="button" @click="clearEntry()"
                                        class="h-9 whitespace-nowrap rounded-(--radius-field) bg-(--color-badge-danger-bg) px-1.5 text-sm font-semibold
                                               text-(--color-badge-danger-ink) hover:bg-(--color-surface-hover)">
                                    {{ __('purchase::action.clear_data') }}
                                </button>
                            </div>
                        </div>

                        @if ($show['line_discount'])
                            <p x-show="discountOverLine" x-cloak
                               class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-1.5 text-xs text-(--color-badge-danger-ink)">
                                {{ __('purchase::message.discount_over_line_screen') }}
                            </p>
                        @endif

                        {{-- ⛔ কার্টে না ঢোকার কারণ — দর ০ বা বিক্রয়দর ছাড়া (মালিক, ৩ অক্টোবর ২০২৬) --}}
                        <p x-show="entryError !== ''" x-cloak role="alert" data-entry-error x-text="entryError"
                           class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                  text-xs text-(--color-badge-danger-ink)"></p>

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
