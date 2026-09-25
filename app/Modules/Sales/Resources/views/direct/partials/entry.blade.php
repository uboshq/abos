                <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-muted) p-3 shadow-sm"
                         style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-badge-draft-ink)">

                    {{-- বাঁ: খোঁজা ও ঘরগুলো।

                         @container — মাপটা এই কলামের নিজের প্রস্থে, পর্দার
                         নয়। ভিউপোর্ট ধরে মাপলে ডান পাশের প্যানেল জায়গা
                         নেওয়ায় পাঁচটা ঘর তিন-দুইয়ে ভেঙে চার সারি হয়ে যেত। --}}
                    {{-- ⚠️ `relative` — খোঁজার ফলের তালিকাটা এর সাপেক্ষে ভাসে।
                         নিচের *"খোঁজার ফল"* ব্লকের মন্তব্য দেখুন। --}}
                    <div class="@container relative min-w-0">
                        {{--
                            পণ্য — চিহ্ন বাঁয়ে, বাছা নামটা ডানে।

                            ── কী বদলাল (২ সেপ্টেম্বর ২০২৬) ──────────────
                            এখানে একটা চওড়া লেখার ঘর ছিল, আর তার নিচে
                            ফলের তালিকা। মালিক NEXUS-এর নমুনা পাঠিয়েছেন:
                            **চিহ্নটাই বোতাম**, আর তার পাশে বড় হরফে সেই
                            নামটা যেটা এইমাত্র বাছা হলো।

                            ── কেন নামটা এত বড় ───────────────────────────
                            পর্দার এই একটামাত্র জিনিস বলে **কোন পণ্যটা
                            বিক্রি হতে যাচ্ছে**। আগের মাপে ওটা পাশের
                            সংখ্যাগুলোর চেয়েও ছোট ছিল — ফলে সংখ্যাটা
                            যাচাই হত, নামটা হত না। NEXUS-এ এই ভুলটা
                            ধরা পড়েছিল, আর সারাইটাও ওখান থেকেই।

                            ── ঘরটা খোলা থাকে কেন ────────────────────────
                            লেখা শুরু করলেই তালিকা, আর বাছা হলেই বন্ধ —
                            কাউন্টারে একটার পর একটা পণ্য ওঠে, তাই প্রতিবার
                            চিহ্নে চাপ দেওয়াটা একটা বাড়তি ক্লিক হত।
                            তাই কি-বোর্ড সরাসরি ঘরেই যায়, আর চিহ্নটা
                            থাকে যিনি মাউস ধরে আছেন তাঁর জন্য।
                        --}}
                        <div class="flex items-start gap-3">
                            <button type="button" @click="pickerOpen ? pickerOpen = false : openPicker()"
                                    :aria-expanded="pickerOpen ? 'true' : 'false'"
                                    :class="pickerOpen
                                        ? 'border-(--color-brand-600) bg-(--color-brand-600) text-(--color-brand-ink)'
                                        : 'border-(--color-brand-500) bg-(--color-brand-50) text-(--color-brand-700) hover:bg-(--color-brand-100)'"
                                    {{-- ⭐ ৪৪px, আগে ৫৫px (`size-11`) — মালিকের চূড়ান্ত
                                         নির্দেশ, ৬ সেপ্টেম্বর ২০২৬: *"শুধু search
                                         বোতাম আগের চাইতে ২০% কমাবে"*।

                                         ── ⚠️ পথটা রাখা হলো, কারণ সংখ্যাটা তিনবার বদলেছে ──
                                             ৫৫px  →  ৩০px   *"search icon 50% cuto koro"*
                                                   →  ২৪px   ("২০% কমাও" — ধাপে ধাপে ধরে নিয়েছিলাম)
                                                   →  ৪৪px   *"আগের চাইতে ২০%"*

                                         ⛔ **মাঝের ধাপটা আমার পাঠের ভুল।** ⓘ "২০% কমাও"
                                         মানে ছিল **মূল মাপ থেকে ২০%**, চলতি মাপ থেকে নয়।
                                         ⚠️ ক্রেতার চিহ্নটাও একই নিয়মে ৪৫ → ৩৬px, আর দুইটা
                                         মিলিয়ে দেখলেই নিয়মটা পরিষ্কার — **দুইটাই নিজের
                                         আসল মাপের ৮০%**।

                                         ⭐ আর ৪৪px ঠিক ছোঁয়ার নিরাপদ মাপ, তাই ট্যাবলেটে
                                         POS এলেও এই চিহ্নটা নিয়ে ভাবতে হবে না। --}}
                                    class="grid size-[44px] shrink-0 place-items-center rounded-(--radius-card)
                                           border-2 transition-colors">
                                <span class="sr-only">{{ __('sales::message.type_or_pick') }}</span>
                                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-[20px] fill-current"
                                     x-show="! pickerOpen">
                                    <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                                </svg>
                                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-[20px] fill-current"
                                     x-show="pickerOpen" x-cloak>
                                    <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                                </svg>
                            </button>

                            <div class="min-w-0 flex-1">
                                {{--
                                    ঘরটা লুকানো থাকে — চিহ্নে চাপলে খোলে।

                                    ── মালিকের কথা (৩ সেপ্টেম্বর ২০২৬) ────────
                                    *"Search icon e click korle search bar
                                    open hobe, select er por dane bosbe"*।

                                    আগে ঘরটা সবসময় খোলা থাকত আর বাছা পণ্যের
                                    নামটা তার **placeholder**-এ বসত। ওটা দুই
                                    দিক থেকেই ভুল ছিল: খালি একটা ইনপুট বাক্স
                                    দেখে বোঝা যেত না কিছু বাছা হয়েছে কিনা,
                                    আর নামটা placeholder-এ থাকায় সেটা টাইপ
                                    শুরু করলেই উধাও হয়ে যেত।

                                    এখন দুইটা অবস্থা, আর কোনোটাই দ্ব্যর্থ নয়:

                                      বন্ধ  → চিহ্নের পাশে বাছা পণ্যের নাম
                                              (বা "পণ্য বাছুন" লেখা বোতাম)
                                      খোলা → লেখার ঘর, ফোকাস সহ

                                    ── কেন `x-init`-এর অটো-ফোকাস গেল ─────────
                                    ঘরটা এখন লুকানো, আর লুকানো ঘরে ফোকাস
                                    দেওয়া যায় না। ফোকাসটা এখন খোলার সাথে
                                    যায় (`openPicker()`), যেখানে ওটার মানে
                                    আছে।
                                --}}
                                {{--
                                    ── নাম আর কোড এক সারিতে ────────────────

                                    মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                                    *"PRD-0004 — ei id product er dane bosbe"*।

                                    ⚠️ কোডটা আগে **নামের নিচে** ছিল, আর নিচে
                                    ছিল মজুদের ছয়টা সংখ্যাও। ফলে কোডটা
                                    সংখ্যাগুলোর ভিড়ে মিশে যেত — অথচ **ওটাই
                                    একমাত্র পরিচয় যেটা দুইটা একই নামের পণ্যকে
                                    আলাদা করে**, আর ঠিক সেই কারণেই আজ
                                    ডুপ্লিকেশন ইঞ্জিনটা বানাতে হয়েছে।

                                    এখন নামের ঠিক ডানে, তাই চোখ একবারেই
                                    দুইটা পড়ে: *"কসমস বিস্কুট ৪০গ্রাম —
                                    PRD-0004, হ্যাঁ এটাই।"*
                                --}}
                                <div x-show="! pickerOpen" x-cloak class="flex items-baseline gap-2">
                                    <button type="button" @click="openPicker()"
                                            {{-- ⚠️ `text-xl` — ছোট করে আবার **ফিরিয়ে আনা
                                                 হয়েছে**, ৬ সেপ্টেম্বর ২০২৬।

                                                 ⓘ ঐ দিনই মালিক ২০% ছোট করতে বলেছিলেন
                                                 (`text-base`), তারপর দেখে বললেন *"Product
                                                 namer size ager tai ano"*। ⭐ চিহ্নটা
                                                 ৫৫px → ২৪px নামার পর নামটা এমনিতেই বড়
                                                 দেখাচ্ছিল — **আসল সমস্যা ছিল চিহ্নের মাপ,
                                                 নামের নয়**।

                                                 ⚠️ ছয় মাস পরে কেউ যেন "একবার তো ছোট করতে
                                                 বলা হয়েছিল" ভেবে আবার নামিয়ে না দেয়। --}}
                                            {{-- ⚠️ `h-(--spacing-field)` — খোলা অবস্থার লেখার
                                                 ঘরটার সাথে **হুবহু এক উচ্চতা** (৪৮px)।

                                                 ⛔ আগে উচ্চতা আসত `py-2` + `text-xl` থেকে,
                                                 আর সেটা ৪৮px-এর সমান হত না। ⓘ ফলে পিকার
                                                 খুললেই নিচের সব ঘর **১২px উপরে উঠত** —
                                                 মেপে দেখা, ৩৮৫ → ৩৭৩।

                                                 ⚠️ ক্ষতিটা তালিকা ভাসানোর পরেও থেকে গিয়েছিল:
                                                 তালিকা আর জায়গা নেয় না, কিন্তু **মাথার ঘরটাই**
                                                 মাপ বদলাত। ⓘ যিনি না তাকিয়ে টাইপ করেন, তাঁর
                                                 কাছে ১২px আর ২০০px একই জিনিস — ঘরটা সরে গেছে। --}}
                                            class="flex h-(--spacing-field) min-w-0 flex-1 items-center truncate
                                                   rounded-(--radius-field) border border-transparent px-3
                                                   text-start text-xl font-semibold
                                                   transition-colors hover:border-(--color-border)"
                                            :class="picked ? 'text-(--color-ink)' : 'text-(--color-ink-muted)'"
                                            x-text="(picked && picked.name) || @js(__('sales::message.type_or_pick'))">
                                    </button>

                                    <span x-show="picked" x-cloak
                                          class="num shrink-0 rounded-(--radius-field) border border-(--color-border)
                                                 px-2 py-0.5 text-2xs font-medium text-(--color-ink-muted)"
                                          x-text="(picked && picked.code)"></span>

                                    {{-- ⭐ লট বাছাই — পণ্যের নামের পাশে, মালিকের
                                         নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।

                                         ── ⓘ কেন এখানে, নিচের ঘরগুলোর সাথে নয় ─────
                                         ⭐ লট **পণ্যেরই পরিচয়** — কোন কার্টনটা যাচ্ছে।
                                         ⚠️ নিচে পরিমাণ-দর-ছাড়ের সাথে বসালে ওটা
                                         আরেকটা সংখ্যার ঘর মনে হত, অথচ ওটা সংখ্যা নয়,
                                         **কোনটা** — তাই নামের পাশেই।

                                         ── ⛔ কেবল লট ধরা পণ্যে ────────────────────
                                         ⓘ ডিপোর চাল-ডাল-সাবানে ঘরটা আসেই না —
                                         প্রতিটা সারিতে একটা বাড়তি বাছাই কেবল
                                         টাইপিং বাড়াত।

                                         ── ⚠️ ক্রমটা মেয়াদের ─────────────────────
                                         ⓘ যারটা আগে ফুরাবে সে উপরে, আর ক্রমটা সেবার
                                         ([[BatchAllocator::candidates()]]) হুবহু একই।
                                         ⛔ মালিক "লট বাছা বাধ্যতামূলক" বেছেছেন, আর
                                         তাতে ঝুঁকি ছিল তাড়াহুড়োয় উপরেরটাই বাছা হবে —
                                         ⭐ তাই উপরেরটাই যেন পুরনোটা হয়। --}}
                                    <template x-if="needsLot">
                                        <label class="shrink-0">
                                            <span class="sr-only">{{ __('sales::field.lot') }}</span>

                                            {{-- ⓘ লট বদলালেও ফ্রি নতুন করে বসে —
                                                 ⚠️ অনুপাত লটের নিজের, আর দুইটা
                                                 লট দুই অনুপাতে আসতে পারে। --}}
                                            <select x-model="entry.batchId"
                                                    @change="fillFreeFromTheRatio()"
                                                    class="h-(--spacing-field-dense) max-w-40 rounded-(--radius-field)
                                                           border border-(--color-border) bg-(--color-surface-app)
                                                           px-2 text-xs">
                                                <option value="">{{ __('sales::field.lot_pick') }}</option>

                                                <template x-for="lot in entryLots" :key="lot.id">
                                                    <option :value="lot.id" x-text="lotLabel(lot)"></option>
                                                </template>
                                            </select>
                                        </label>
                                    </template>
                                </div>

                                <label class="block" x-show="pickerOpen" x-cloak>
                                    <span class="sr-only">{{ __('sales::message.type_or_pick') }}</span>
                                    <input type="search" x-model="term" x-ref="search"
                                           @keydown.enter.prevent="pickFirst()"
                                           @keydown.escape="pickerOpen = false"
                                           :placeholder="customerId ? @js(__('sales::message.type_or_pick')) : @js(__('sales::message.pick_customer_first'))"
                                           class="h-(--spacing-field) w-full truncate rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-app)
                                                  px-3 text-xl font-semibold
                                                  placeholder:font-semibold placeholder:text-(--color-ink-muted)">
                                </label>

                            </div>
                        </div>

                        {{-- বাছাই করা পণ্যের মজুদ — নমুনা দাবি করে এটা পণ্য
                             বাছার সাথে সাথেই দেখা যাবে --}}
                        <p class="mt-1 text-2xs text-(--color-ink-muted)" x-show="! picked" x-cloak>
                            {{ __('sales::message.pick_item_to_see_stock') }}
                        </p>

                        {{--
                            ── মজুদের পাঁচটা সংখ্যা — ওজন অনুযায়ী ─────────────

                            মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                            *"available stock green kore ektu boro kore
                            dekhabe, baki gulo ek ekta ek ek color, normal
                            size"* — আর পরে: *"বিক্রয়যোগ্য মজুদ-এর সাথে
                            **ফ্রি একই মাপ ও রঙ** হবে"*।

                            ── কেন এই দুইটাই বড় ──────────────────────────
                            ⭐ কাউন্টারে **এই দুইটা সংখ্যাই সিদ্ধান্ত নেয়** —
                            "এখন বেচতে পারব কত" আর "ফ্রি দিতে পারব কত"।
                            বাকি তিনটা ব্যাখ্যা: কত আটকানো, কত ধরে রাখা, আর
                            গুদামে মোট কত।

                            ⚠️ পাঁচটাই সমান দেখালে চোখকে প্রতিবার **পাঁচটা
                            পড়ে দুইটা বেছে নিতে** হত — প্রতিটা পণ্যে।

                            ⓘ প্রতিটা রঙ থিমের টোকেন থেকে, হার্ডকোড নয়।
                        --}}
                        <div class="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1"
                             x-show="picked" x-cloak>
                            @foreach ([
                                ['available', 'sales::field.available_short', 'text-sm text-(--color-success)', true],
                                ['free_available', 'sales::field.free_available', 'text-sm text-(--color-success)', true],
                                ['reserved', 'sales::field.reserved_short', 'text-2xs text-(--color-warning-hover)', false],
                                ['hold', 'sales::field.hold_short', 'text-2xs text-(--color-accent-pink)', false],
                                ['main', 'sales::field.main_stock', 'text-2xs text-(--color-brand-700)', false],
                            ] as [$key, $label, $tone, $big])
                                {{-- ⚠️ সংখ্যার পিছনে একক — মালিকের প্রশ্ন
                                     (৩ সেপ্টেম্বর ২০২৬): *"Available 1775
                                     uom kothay?"*।

                                     ── কেন প্রশ্নটা ন্যায্য ──────────────────
                                     "১৭৭৫" একা কোনো তথ্য নয়। ১৭৭৫ **পিস**
                                     আর ১৭৭৫ **কার্টন** এক জিনিস নয়, আর
                                     প্যাক-এন্ট্রি চালু থাকলে বিক্রেতা ঠিক
                                     উপরের ঘরেই "বাক্স" বেছে নিচ্ছেন —
                                     ⚠️ তখন **একই পর্দায় দুইটা একক**, একটা
                                     লেখা, আরেকটা অনুমান।

                                     ⓘ এককটা সবসময় পণ্যের **মূল একক**, কারণ
                                     মজুদের পাঁচটা সংখ্যাই মূল এককে রাখা হয়
                                     — বাছা প্যাক যা-ই হোক।

                                     ⓘ হালকা ও ছোট, ইচ্ছে করেই: সিদ্ধান্ত
                                     নেয় সংখ্যাটা, একক কেবল তার মাপকাঠি। --}}
                                <span class="{{ $big ? 'text-sm' : 'text-2xs' }}">
                                    <span class="text-(--color-ink-muted)">{{ __($label) }}</span>
                                    <span class="num font-bold {{ $tone }}"
                                          x-text="qty(picked && picked.{{ $key }})"></span>
                                    <span class="text-2xs text-(--color-ink-muted)"
                                          x-show="(picked && picked.unit)" x-cloak
                                          x-text="(picked && picked.unit)"></span>
                                </span>
                            @endforeach
                        </div>

                        {{-- খোঁজার ফল — **ভাসমান**, প্রবাহের ভিতরে নয়।

                             ── ⛔ কী ভাঙা ছিল, ৬ সেপ্টেম্বর ২০২৬ ──────────────
                             মালিক: *"products search korle full box expand hocche
                             keno"*। ⓘ তালিকাটা প্রবাহের ভিতরে আঁকা হত, তাই সে
                             জায়গা **দখল করত**: পণ্য খুঁজতে গেলেই কার্ডটা লম্বা
                             হয়ে যেত আর নিচের এন্ট্রির সারিটা ঠেলে নামত।

                             ⚠️ ক্ষতিটা চোখের নয়, হাতের: পরিমাণের ঘরটা প্রতিবার
                             **অন্য জায়গায়** চলে যেত, তাই যিনি না তাকিয়ে টাইপ
                             করেন তাঁর ছন্দ ভাঙত। ⓘ পর্দার নিচের সব কিছুও
                             একসাথে লাফাত।

                             ⭐ এখন সে ভাসে — কার্ডের উপরে, নিজের জমিন ও ছায়া
                             নিয়ে। ⓘ কার্ডের উচ্চতা আর বদলায় না, তাই **কোনো ঘর
                             নড়ে না**।

                             ⚠️ `z-30` — কার্টের টেবিলের উপরে থাকতে হবে, নাহলে
                             তালিকাটা তার নিচে ঢুকে যেত। --}}
                        <div class="absolute inset-x-0 top-full z-30 mt-1 max-h-40 space-y-0.5
                                    overflow-y-auto rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card) p-1 shadow-lg"
                             x-show="pickerOpen" x-cloak>
                            <template x-for="p in visible" :key="p.id">
                                <button type="button" @click="pick(p)"
                                        class="flex w-full items-baseline justify-between gap-2 rounded-(--radius-field)
                                               px-2 py-1 text-start text-sm transition-colors
                                               hover:bg-(--color-surface-hover)">
                                    <span class="min-w-0 flex-1 truncate" x-text="p.name"></span>

                                    {{-- ⭐ মজুদ ও ফ্রি — মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬:
                                         *"Stock 8979 ctn, free 675 ctn এইভাবে লেখা থাকবে,
                                         সংখ্যাগুলো বোল্ড করে সবুজ কালার, লো স্টক হলে লাল"*।

                                         ⛔ আগে সারিতে ছিল কেবল **একটা কাঁচা সংখ্যা** —
                                         কীসের সংখ্যা তা লেখা ছিল না, আর এককও ছিল না।
                                         ⚠️ ১৭৯২ দেখে বোঝার উপায় ছিল না ওটা পিস না কার্টন,
                                         আর ফ্রি মজুদ আছে কি না তা **তালিকায় দেখাই যেত না** —
                                         পণ্যটা বেছে তবে জানা যেত।

                                         ⭐ রংটা সিদ্ধান্তের: সবুজ মানে নেওয়া যায়, লাল মানে
                                         **বাছার আগেই থামো**। ⓘ সীমাটা পণ্যের নিজের
                                         `reorder_level` — কোডে বসানো কোনো সংখ্যা নয়।

                                         ⚠️ ফ্রি-র ঘরটা শূন্য হলে দেখানো হয় না: প্রতিটা
                                         সারিতে "free 0" লিখলে চোখ ওটা পড়া বন্ধ করে দেয়,
                                         আর তখন যেখানে সত্যিই ফ্রি আছে সেটাও চোখ এড়ায়। --}}
                                    <span class="shrink-0 text-2xs text-(--color-ink-muted)">
                                        {{ __('sales::field.stock_short') }}
                                        <span class="num font-bold"
                                              :class="$num(p.available) > 0
                                                  && $num(p.available) <= $num(p.reorder || 0)
                                                      ? 'text-(--color-danger)'
                                                      : ($num(p.available) <= 0
                                                          ? 'text-(--color-danger)'
                                                          : 'text-(--color-success)')"
                                              x-text="qty(p.available)"></span>
                                        <span x-text="p.unit"></span>
                                    </span>

                                    <span class="shrink-0 text-2xs text-(--color-ink-muted)"
                                          x-show="$num(p.free) > 0" x-cloak>
                                        {{ __('sales::field.free_short') }}
                                        <span class="num font-bold text-(--color-success)"
                                              x-text="qty(p.free)"></span>
                                        <span x-text="p.unit"></span>
                                    </span>
                                </button>
                            </template>
                        </div>

                        {{--
                            ঘরগুলো স্থির মাপে, টেনে লম্বা হয় না।

                            ── মালিকের কথা (৩ সেপ্টেম্বর ২০২৬) ─────────────────
                            *"uporer box gulo zate fixt thake zate na bare …
                            ei box gulo ekho bare eta fix koro"* — ঘরগুলো
                            জায়গা পেলেই বেড়ে যাচ্ছিল।

                            ── কেন `grid` থেকে `flex` ─────────────────────────
                            `grid-cols-5` প্রতিটা কলামকে **সমান ভাগ** দেয়, আর
                            কলামটা চওড়া হলে ঘরও চওড়া হয়। ১৪০০px পর্দায় একটা
                            "পরিমাণ" ঘর দুই ইঞ্চি চওড়া হয়ে দাঁড়াত — অথচ ওতে
                            বসে বড়জোর পাঁচটা অঙ্ক।

                            `flex-wrap` + প্রতিটা ঘরের নিজের `w-*` মানে জায়গা
                            বাড়লে ঘর বাড়ে না, **সারিতে বেশি ঘর ধরে**; আর জায়গা
                            কমলে নিচে নেমে যায়। ফোনেও তাই কিছু ভাঙে না।
                        --}}
                        {{-- প্রথম সারি: পরিমাণ · একক · ফ্রি · একক · মোট --}}
                        {{-- পাঁচ কলাম, আর উপরে একটা সীমা।

                         ── কেন `flex` থেকে `grid` (৩ সেপ্টেম্বর ২০২৬) ──────
                         মালিক দুইটা কথা বলেছেন যেগুলো একসাথে মেলানো দরকার:
                         **"সব এক লাইনে"** আর **"ঘরগুলো যেন না বাড়ে"**।

                         `flex-wrap` + স্থির প্রস্থ প্রথমটা দিতে পারে না:
                         পর্দা সরু হলেই সারি ভেঙে যায়, আর কত ঘর ধরবে তা
                         পর্দার প্রস্থের উপর নির্ভর করে। মাপা গেছে ১৫০০px-এ
                         পাঁচটার মধ্যে চারটা ধরত।

                         `grid-cols-5` **সবসময় পাঁচটাই** রাখে — জায়গা কম
                         হলে ঘরগুলো ছোট হয়, সারি ভাঙে না। আর `max-w-3xl`
                         নিশ্চিত করে জায়গা বেশি হলেও ঘরগুলো একটা মাপের পরে
                         আর বাড়ে না — দ্বিতীয় কথাটা এখানেই রক্ষা পায়।

                         ⚠️ ফোনে পাঁচ কলাম মানে ঘরপ্রতি ৬০px — সংখ্যাও ধরে
                         না। তাই সেখানে দুই, ট্যাবলেটে তিন। --}}
                    {{-- ⭐ কলামগুলো আর সমান নয় — মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬:
                         *"এই বক্সগুলো মার্ক করা মাপে কেটে ছোট করো, ডানে একই
                         লাইনে Add to Cart বোতাম আনো।"*

                         ⛔ আগে ছয়টা কলামই `1fr` — সমান। ⚠️ ফলে যে ঘরে `1`
                         বসে আর যে ঘরে `pcs` বসে, দুইটা **একই চওড়া** পেত,
                         আর সেই চওড়াটা ঠিক হত সবচেয়ে লম্বা লেবেল দেখে।
                         ⓘ তিনি ছবিতে ঠিক ঐ চারটা ঘর লাল বাক্সে ঘিরে
                         দেখিয়েছেন — পরিমাণ, একক, ফ্রি পরিমাণ, একক।

                         ⭐ এখন প্রতিটা ঘর তার **নিজের লেখার মাপে**:
                             পরিমাণ · ফ্রি পরিমাণ   ৩.৫rem  = ৭০px, চার অঙ্ক ধরে
                             একক (দুইটা)           ৪rem    = ৮০px, "pcs"/"ctn" ধরে
                             মোট পরিমাণ            ≥৪.৫rem  বাকি জায়গা ভাগ করে
                             বিক্রয় দর              ≥৫.৫rem  একটা দাম ধরে
                             কার্টে যোগ করুন         auto     লেখার সমান

                         ⚠️ সব মিলিয়ে সারিটার দরকার **~৬৫১px**। কার্ডটা তার
                         চেয়ে সরু হলে (যেমন ছোট ল্যাপটপে ~৫৯৮px) সারিটা
                         **নিজে স্ক্রল করে** — চেপে গিয়ে অপাঠ্য হয় না।

                         ⚠️ এই থিমে `1rem = 20px`, তাই ৪rem = ৮০px।

                         ⓘ `lg:` -এর নিচে ছক আগের মতোই — সরু পর্দায় সাতটা
                         ঘর এক সারিতে রাখলে প্রতিটা এত সরু হত যে সংখ্যাই
                         পড়া যেত না। --}}
                    {{-- ⛔ নমনীয় দুইটা ঘরের **সর্বনিম্ন মাপ** আছে — নাহলে
                         বোতামটা সারিতে ঢোকার পর ওরা চেপে যেত। ⓘ ব্রাউজারে
                         মেপে দেখা গেছে দরের ঘরটা **৫১px**-এ নেমেছিল, যেখানে
                         একটা দামও লেখা যায় না।

                         ⚠️ আর `overflow-x-auto` মোড়কে: সর্বনিম্ন মাপ দিলে
                         সরু পর্দায় সারিটা ধরবে না, আর তখন **চেপে অপাঠ্য
                         হওয়ার বদলে সে স্ক্রল করবে**। ⓘ পাতার শরীর কখনো
                         আড়াআড়ি স্ক্রল করে না, কেবল এই সারিটাই। --}}
                    <div class="mt-3 max-w-screen-2xl overflow-x-auto">
                    {{-- ⚠️ `min-w-max` নয়: ওটা দিলে নমনীয় ঘর দুইটা সবসময়
                         তাদের **সবচেয়ে বড়** মাপ নিত (মাপা: ২১৬px), আর সারিটা
                         চওড়া পর্দাতেও অকারণে স্ক্রল করত। ⓘ সর্বনিম্ন মাপই
                         যথেষ্ট — জায়গা থাকলে তারা বাড়ে, না থাকলে মোড়কটা
                         স্ক্রল করে। --}}
                    <div class="grid items-end gap-2 grid-cols-2 sm:grid-cols-3
                                lg:grid-cols-[3.5rem_4rem_3.5rem_4rem_minmax(4.5rem,1fr)_minmax(5.5rem,1fr)_auto]
                                gap-x-1.5">
                            <x-sales::entry-field label="sales::field.qty" width="w-full">
                                {{-- ⭐ পরিমাণ বদলালেই ফ্রি অনুপাত ধরে বসে —
                                     মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।

                                     ⚠️ `@change`, `@input` নয় — ⓘ প্রতিটা
                                     কি-স্ট্রোকে সার্ভারে গেলে "২" লিখে "২৪"
                                     করার পথে তিনটা অনুরোধ যেত, আর মাঝেরগুলোর
                                     উত্তর কাজে লাগত না। ⛔ `@change` ঘরটা
                                     ছাড়ার পর একবারই ডাকে। --}}
                                <input type="number" step="0.01" min="0" x-model="entry.qty"
                                       @change="fillFreeFromTheRatio()"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            {{--
                                ── একক — বাছাই, যখন পণ্যটার একাধিক প্যাক আছে ──

                                ── কী বাদ পড়েছিল (মাপা ৩ সেপ্টেম্বর ২০২৬) ─────
                                ঘরটা ছিল কেবল পড়ার — পণ্যের নিজের একক দেখাত।
                                অথচ প্যাক-এন্ট্রির **পুরো ইঞ্জিন আগে থেকেই
                                আছে** ([[PackConversion]]), কন্ট্রোল প্যানেলে
                                সুইচও আছে, আর **ছয়টা ফর্মে ড্রপডাউনটা চলছেও**।

                                ⚠️ **কেবল কাউন্টারের পর্দাটাই বাদ পড়েছিল** —
                                অর্থাৎ ঠিক যেখানে তাড়াহুড়ো সবচেয়ে বেশি, সেখানে
                                বিক্রেতাকে মাথায় গুণে "২ বাক্স"-কে "২০০ পিস"
                                করতে হত, **আর দরটাও নিজে ভাগ করে বসাতে হত**।

                                ── কেন দুইটা ঘর, একটা বাছাই ──────────────────
                                সার্ভার লাইনপ্রতি **একটাই** `unit_id` নেয়, তাই
                                ফ্রি পরিমাণও একই এককে যায়। দ্বিতীয় ঘরটা তাই
                                প্রথমটার প্রতিধ্বনি — দুইটা আলাদা বাছাই দিলে
                                "২ বাক্স বিক্রি, ৫ পিস ফ্রি" সার্ভারে প্রকাশই
                                করা যেত না।

                                ⓘ সুইচ বন্ধ থাকলে `packs` খালি, আর ঘরটা আগের
                                মতোই পড়ার-জন্য থাকে — এক এককে বেচা ব্যবসার
                                প্রতিটা সারিতে বাড়তি ড্রপডাউন কেবল বোঝা।
                            --}}
                            <x-sales::entry-field label="sales::field.uom" width="w-full">
                                <template x-if="entryUnits.length > 0">
                                    <select x-model="entry.unitId"
                                            class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                   border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                        <template x-for="u in entryUnits" :key="u.id">
                                            <option :value="u.id" x-text="u.name"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="entryUnits.length === 0">
                                    <input type="text" readonly :value="(picked && picked.unit) || ''"
                                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                                </template>
                            </x-sales::entry-field>

                            @if ($show['free_qty'])
                                <x-sales::entry-field label="sales::field.free_qty" width="w-full">
                                    <input type="number" step="0.01" min="0" x-model="entry.freeQty"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>

                                <x-sales::entry-field label="sales::field.uom" width="w-full">
                                    <input type="text" readonly :value="(picked && picked.unit) || ''"
                                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                                </x-sales::entry-field>

                                {{-- সীমা ছাড়ালে সারিটা কার্টে যায় না, আর এই লাইনটাই
                                     একমাত্র চিহ্ন — নিচে কিছুই বদলায় না।

                                     ⚠️ বার্তায় সংখ্যাটাই থাকে — ⛔ "বেশি হয়েগেছে" বললে
                                     মানুষ কমাতে কমাতে চেষ্টা করতেন। --}}
                                <div x-show="freeWarning" x-cloak
                                     class="col-span-full rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                            px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
                                     x-text="freeWarning" role="alert"></div>
                            @endif

                            {{-- ⭐ বাকির সীমা ছাড়ালে — মালিকের নির্দেশ,
                                 ২৫ সেপ্টেম্বর ২০২৬।

                                 ⚠️ এটা ফ্রি-র বার্তার **বাইরে**, আর কারণটা
                                 সূক্ষ্ম: ওটা `@if ($show['free_qty'])`-এর
                                 ভিতরে বসে। ⛔ ভিতরে রাখলে যে কোম্পানি ফ্রি
                                 বন্ধ রেখেছে, তাদের পর্দায় সারিটা নীরবে
                                 কার্টে যেত না আর **একটাও কারণ দেখাত না**।

                                 ⓘ হিসাবটা গোটা ঝুড়ি ধরে, তাই বার্তাটা এই
                                 সারির নয় — পুরো বিলের। --}}
                            <div x-show="creditWarning" x-cloak
                                 class="col-span-full rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                        px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
                                 x-text="creditWarning" role="alert"></div>

                            {{-- ⓘ লটের বার্তা — বাছা হয়নি, বা ঐ লট কার্টে আগেই আছে।
                                 ⚠️ আলাদা ঘর, কারণ দুইটা বার্তা একসাথে দেখা যেতে পারে:
                                 সীমা ছাড়িয়েছে **আর** লট বাছা হয়নি। ⛔ একটা ঘরে
                                 দুইটা বসালে দ্বিতীয়টা প্রথমটাকে মুছে দিত, আর
                                 বিক্রেতা একটা কারণ সারিয়ে আবার আটকে যেতেন। --}}
                            <div x-show="lotWarning" x-cloak
                                 class="col-span-full rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                        px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
                                 x-text="lotWarning" role="alert"></div>

                            {{-- ⭐ *"আর ৪ নিলে ১ ফ্রি"* — মালিকের নির্দেশ,
                                 ২৫ সেপ্টেম্বর ২০২৬: *"warning masses dibe
                                 but atkabe na"*।

                                 ⚠️ রঙটা **হলুদ, লাল নয়** — ⓘ এটা বাধা নয়,
                                 সুযোগ। ⛔ লাল দিলে বিক্রেতা ভাবতেন কিছু ভুল
                                 হয়েছে আর সংখ্যাটা পড়তেনই না; অথচ ঐ সংখ্যাটা
                                 দিয়েই তিনি গ্রাহককে আরেকটু নিতে রাজি করাতে
                                 পারেন।

                                 ⓘ `role="status"`, `alert` নয় — একই কারণে। --}}
                            <div x-show="freeHint" x-cloak
                                 class="col-span-full rounded-(--radius-field) bg-(--color-badge-pending-bg)
                                        px-3 py-1.5 text-xs text-(--color-badge-pending-ink)"
                                 x-text="freeHint" role="status"></div>

                            {{-- মোট পরিমাণ নিজে থেকেই — বিক্রয় + ফ্রি।

                                 হাতে লিখতে দিলে কেউ ভুল যোগ করত, আর গুদাম
                                 থেকে ভুল সংখ্যক মাল বেরোত। --}}
                            <x-sales::entry-field label="sales::field.total_qty" width="w-full">
                                <input type="text" readonly :value="qty(entryTotalQty)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm font-semibold">
                            </x-sales::entry-field>
                            <x-sales::entry-field label="sales::field.sales_rate" width="w-full">
                                <input type="number" step="0.0001" min="0" x-model="entry.rate"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>
                            {{-- ⭐ "কার্টে যোগ করুন" এখন এন্ট্রির সারিতেই, একদম
                                 ডানে — মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬।

                                 ⓘ ঘরগুলো ভরে বোতামটা চাপা — কাজটা বাঁ থেকে ডানে
                                 একটানা, আর হাতকে ডান প্যানেলে যেতে হয় না।
                                 ⚠️ আগে বোতামটা ছিল সবুজ প্যানেলের ভিতরে, পর্দার
                                 অন্য প্রান্তে; তিনি ছবিতে একটা তীর এঁকে ঠিক এই
                                 জায়গাটাই দেখিয়েছেন।

                                 ⓘ `self-end` — লেবেলবিহীন বোতামটা যেন পাশের
                                 ঘরগুলোর **নিচের কিনারায়** বসে, লেবেলের সারিতে
                                 উঠে না যায়। --}}
                            <button type="button" @click="addToCart()" :disabled="! picked"
                                    class="h-(--spacing-field-dense) self-end whitespace-nowrap rounded-(--radius-field)
                                           bg-(--color-success) px-2 text-2xs font-semibold leading-tight
                                           text-white disabled:opacity-50">
                                {{ __('sales::action.add_to_cart') }}
                            </button>

                        </div>
                    </div>

                        {{--
                            ── উপহারের ঘর — যে পণ্যটা এখন হাতে, তার সাথেই ────

                            খোলে "উপহার" বোতামে, আর বোতামটা পণ্য না বাছা
                            পর্যন্ত নিষ্ক্রিয়। **কোন পণ্যের জন্য — সেটা
                            জিজ্ঞেস করা হয় না**, যেটা হাতে আছে সেটাই।

                            ⚠️ "কার্টে যোগ করুন" চাপার আগেই উপহারটা বসাতে
                            হয়, কারণ যোগ করার সাথে সাথে পণ্যটা হাত থেকে
                            ছুটে যায়। বোতামের ক্রমটাও তাই — উপহার আগে,
                            কার্টে যোগ পরে (মালিকের দেওয়া ক্রম)।
                        --}}
                        @if ($show['gift'])
                            {{-- ⚠️ `x-if`, `x-show` নয় — কারণটা ব্রাউজারে ধরা পড়েছে।

                                 `x-show` কেবল **লুকায়**, ঘরগুলো DOM-এ থেকে যায়।
                                 তাই ভেতরের `x-model="giftDraft.productId"` তখনো
                                 চলত, আর `giftDraft` খালি থাকায় প্রতি রেন্ডারে
                                 কনসোলে ঢালত:

                                     Cannot read properties of null (reading 'productId')

                                 ⚠️ **পর্দায় কিছুই ভাঙা দেখাত না** — কিন্তু Alpine
                                 একটা বাঁধনে হোঁচট খেলে সেই চক্রের পরের বাঁধনগুলো
                                 থেমে যায়। অর্থাৎ **অন্য একটা ঘর কাজ করা বন্ধ করে
                                 দিত, আর কারণটা কোথাও লেখা থাকত না।**

                                 `x-if` ব্লকটাকে তৈরিই করে না যতক্ষণ না `giftDraft`
                                 আছে — বাঁধনও থাকে না। --}}
                            <template x-if="giftDraft">
                            <div class="mt-3 rounded-(--radius-card) border border-(--color-badge-pending-ink)/30
                                        bg-(--color-badge-pending-bg)/50 p-2">
                                {{-- ⓘ লেবেলটা নিজের ঘরে, তারপর ড্যাশ — নাহলে
                                     `EveryFieldSwitch…` পাহারাটা ওটাকে খুঁজে পায়
                                     না। সে দেখে লেখাটার **পরেই একটা ট্যাগ** আছে
                                     কিনা, আর কারণটা যুক্তিসঙ্গত: এক ঘরের লেখা
                                     আরেক ঘরের লেখার ভিতরে মিলে গেলে সুইচ বন্ধ
                                     করেও "আছে" দেখাত। --}}
                                <p class="mb-2 text-2xs font-semibold text-(--color-badge-pending-ink)">
                                    <span>🎁 {{ __('sales::field.gift_item') }}</span> —
                                    <span x-text="picked ? picked.name : ''"></span>
                                </p>

                                <div class="flex flex-wrap items-end gap-2">
                                    <label class="min-w-48 flex-1">
                                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)">
                                            {{ __('sales::field.item_name') }}
                                        </span>
                                        <select x-model="giftDraft.productId"
                                                class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                       border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                            <option value="">-</option>
                                            <template x-for="p in catalogue" :key="p.id">
                                                <option :value="p.id" x-text="p.name"></option>
                                            </template>
                                        </select>
                                    </label>

                                    <label class="w-24">
                                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)">
                                            {{ __('sales::field.quantity') }}
                                        </span>
                                        <input type="number" step="0.01" min="0.01" x-model="giftDraft.qty"
                                               @keydown.enter.prevent="commitGift()"
                                               class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
                                    </label>

                                    <label class="min-w-40 flex-1">
                                        <span class="mb-0.5 block text-2xs text-(--color-ink-muted)">
                                            {{ __('sales::field.remarks') }}
                                        </span>
                                        <input type="text" x-model="giftDraft.remarks"
                                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                    </label>

                                    <button type="button" @click="commitGift()"
                                            :disabled="! giftDraft.productId || ! ($num(giftDraft.qty) > 0)"
                                            class="h-(--spacing-field-dense) whitespace-nowrap rounded-(--radius-field)
                                                   bg-(--color-badge-pending-ink) px-4 text-xs font-semibold text-white
                                                   disabled:opacity-40">
                                        {{ __('sales::action.add_to_cart') }}
                                    </button>

                                    <button type="button" @click="giftDraft = null"
                                            class="h-(--spacing-field-dense) whitespace-nowrap rounded-(--radius-field)
                                                   border border-(--color-border) px-3 text-xs">
                                        &times;
                                    </button>
                                </div>
                            </div>
                            </template>

                            {{-- এন্ট্রিতে বসানো উপহারগুলো — কার্টে যাওয়ার আগে --}}
                            <div x-show="entry.gifts.length > 0" x-cloak class="mt-2 flex flex-wrap gap-1">
                                <template x-for="(g, n) in entry.gifts" :key="g.key">
                                    <span class="inline-flex items-center gap-1 rounded-full
                                                 bg-(--color-badge-pending-bg) px-2 py-0.5 text-2xs
                                                 text-(--color-badge-pending-ink)">
                                        🎁 <span x-text="productName(g.productId)"></span>
                                        <span class="num" x-text="qty($num(g.qty || 0))"></span>
                                        <button type="button" @click="entry.gifts.splice(n, 1)"
                                                class="text-(--color-danger)">&times;</button>
                                    </span>
                                </template>
                            </div>
                        @endif

                    </div>
                </section>
