{{--
    সরাসরি ক্রয় চালান — গাড়ি আসে, মাল নামে, কাগজ হাতে।

    ── বিক্রয়ের পর্দার আয়না, ইচ্ছাকৃতভাবে ─────────────────────────────
    বাঁ দিকে খোঁজা ও এন্ট্রি, নিচে কার্ট, ডানে যোগফল — হুবহু সরাসরি
    বিক্রয়ের বিন্যাস। দুইটা পর্দা আলাদা দেখালে ডিপোর মানুষটাকে দুইবার
    শিখতে হত, অথচ কাজ দুইটা একই আকারের: পণ্য বাছা, সংখ্যা বসানো, দাম
    ঠিক করা, শেষে নিশ্চিত করা।

    ── এই পর্দার নিজের জিনিস: দর নির্ধারণ ──────────────────────────────
    বিক্রয়ে দাম আগে থেকেই ঠিক থাকে। ক্রয়ে ঠিক উল্টো — মাল ঢোকার
    মুহূর্তেই বিক্রয়মূল্য বসাতে হয়, আর ক্রয়দর তখন চোখের সামনে। তাই
    এখানে তিনটা বাড়তি ঘর: markup, margin আর বিক্রয়মূল্য। যেকোনো একটায়
    লিখলে বাকি দুইটা নিজে বসে (resources/js/pricing.js), আর যে ঘরে
    কার্সর আছে সেটায় কখনো হাত পড়ে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.direct') }}</x-slot:title>

    @if ($errors->any())
        <div role="alert"
             class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('purchase.direct.store') }}"
          x-data="directPurchase(
              {{ Illuminate\Support\Js::from($products) }},
              {{ $show['vat'] ? 'true' : 'false' }},
              {{ Illuminate\Support\Js::from(route('purchase.direct.last_rates', ['supplier' => 0])) }}
          )"
          @submit="guard($event)"
          x-effect="saveDraft()"
          {{-- ── দুইটা কার্ড, ৭০ ও ৩০ ─────────────────────────────────

               মালিকের ছবির অনুপাত। ⓘ আগে ডান কলামটা স্থির `17rem` ছিল,
               আর সেটা ছোট পর্দায় ঠিক থাকলেও ১৪৪০-এ কার্ডটা সরু ফিতার
               মতো দেখাত — বাঁয়ে আটটা ঘর, ডানে একটা কলাম টাকার অঙ্ক।

               ⚠️ ভাগটা `xl`-এর নিচে ভাঙে না: ৭:৩ মানে ১০২৪-এ ডান
               কার্ডটা ৩০০ পিক্সেলেরও কম, আর ওতে "TO PAY THIS SUPPLIER"
               দুই লাইনে ভেঙে যেত। ছোট পর্দায় তাই দুইটা কার্ড উপর-নিচে। --}}
          class="grid gap-3 lg:grid-cols-[minmax(0,78fr)_minmax(0,20fr)]">
        @csrf

        {{-- ── খসড়া পাওয়া গেছে ───────────────────────────────────────

             ⚠️ প্রস্তাব, ফেরানো নয়। ⓘ নিজে থেকে ফিরিয়ে দিলে কেউ নতুন
             ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে।

             ⓘ বারটা ফর্মের ভিতরে, তাই `x-data`-র স্কোপেই আছে — আর
             দুইটা বোতামেই `type="button"`, নাহলে ওগুলো ফর্মটাই সাবমিট
             করে দিত। --}}
        <div x-show="draftFound" x-cloak
             class="lg:col-span-2 flex flex-wrap items-center gap-2 rounded-(--radius-card)
                    border border-(--color-border) bg-(--color-badge-warning-bg)
                    px-3 py-2 text-sm text-(--color-badge-warning-ink)">
            <span class="font-medium">{{ __('purchase::message.draft_found') }}</span>
            <span class="num text-2xs opacity-80" x-text="draftAt"></span>

            <span class="ms-auto flex gap-2">
                <button type="button" @click="restoreDraft()"
                        class="rounded-(--radius-field) bg-(--color-surface-card) px-2 py-1
                               text-2xs font-medium text-(--color-ink)">
                    {{ __('purchase::action.draft_restore') }}
                </button>
                <button type="button" @click="discardDraft()"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)">
                    {{ __('purchase::action.draft_discard') }}
                </button>
            </span>
        </div>

        {{-- ══ বাঁ অংশ — মাথা, পণ্য, "এই লাইনে", প্যানেল আর কার্ট ══════

             ⚠️ মোটের কার্ডটা এই মোড়কের **বাইরে**, আর তাই সে **একদম উপর
             থেকে** শুরু হয় — মালিকের নির্দেশ, ৫ সেপ্টেম্বর ২০২৬।

             ⛔ এক দফায় কাগজের মাথাটা পুরো চওড়ায় নেওয়া হয়েছিল
             (`lg:col-span-2`), আর তাতে মোটের কার্ডটা এক সারি নিচে নেমে
             গিয়েছিল। ⓘ ওটা ভুল ছিল: *"Bill total box ek dom upor thekei
             bosbe ager moto"* — টাকার ছকটা চোখের সোজাসুজি থাকা দরকার,
             কাগজের মাথার নিচে নয়। --}}
        <div class="min-w-0 space-y-3">

            {{-- ══ কাগজের মাথা — বাঁ অংশের পুরো চওড়ায় ══════════════════

                 মালিকের নতুন নকশা (৫ সেপ্টেম্বর ২০২৬, তাঁর আঁকা ছবি):

                 ```
                 ┌─ পুরো চওড়ায় ─────────────────────────────────────┐
                 │ ┌ সরবরাহকারী ┐   যেদিন এল · চালান নম্বর · গুদাম   │
                 │ │             │   বিলের তারিখ · বিল নম্বর · মেয়াদ  │
                 │ └────────────┘                                  │
                 └────────────────────────────────────────────────┘
                 ┌─ পণ্য খোঁজা ও বাছা ──────┐ ┌─ এই লাইনে ─┐
                 ```

                 ⭐ দুইটা বদল আগের খসড়ার চেয়ে: সরবরাহকারী ঘরগুলোর **নিচে
                 নয়, বাঁয়ে** — নিজের একটা বাক্সে; আর **সারি দুইটার ক্রম
                 উল্টো** (`Received on` এখন উপরে)।

                 ⓘ কার্ডটা পুরো চওড়ায়, অর্থাৎ মোটের কার্ডের উপর দিয়েও —
                 কারণ কাগজের মাথা গোটা বিলের কথা, কোনো এক কলামের নয়। --}}
            {{-- ── বাঁয়ে কে, ডানে কাগজ — বিক্রয়ের কাউন্টারের হুবহু ছক ──

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"Dir Sales Er zekane
                 Customer Ache Sekhane Suppliyer daw"*।

                 ⭐ দুইটা কাউন্টার একই কাজের দুই দিক — একটায় মাল ঢোকে,
                 আরেকটায় বেরোয়। ⓘ চেহারা আলাদা হলে যিনি একটা চালাতে
                 জানেন, তাঁকে আরেকটা নতুন করে শিখতে হয়।

                 ⓘ ঘরের সংখ্যা আলাদা বলে ভাগটাও আলাদা: বিক্রয়ে চারটা
                 ঘর দুইটা করে, ক্রয়ে ছয়টা **তিনটা করে** — মালিকের
                 নির্দেশ। --}}
            {{-- ── এক ছক, তিন কলাম ────────────────────────────────────

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"This Line Box Upore utaw"*।

                 ⛔ আগে পাতাটায় **দুইটা আলাদা ছক** ছিল — একটায়
                 সরবরাহকারী+কাগজ, আরেকটায় পণ্য+"এই লাইন"। ⚠️ দুইটা
                 আলাদা ছকের মধ্যে কোনো জিনিস উপরের সারিতে টেনে তোলা
                 যায় না — CSS ছকে জায়গা বদলানো যায় কেবল **একই ছকের
                 ভিতরে**।

                 ⭐ তাই দুইটা এক করা হলো: সরবরাহকারী · কাগজ · "এই
                 লাইন" — তিনটাই উপরের সারিতে, আর পণ্যের বাক্স নিচে
                 প্রথম দুই কলাম জুড়ে। ⓘ বিক্রয়ের কাউন্টারে ঠিক এই
                 ছকটাই চলছে। --}}
            <div {{-- ⓘ `items-start` নেই — দুইটা বাক্স **সমান উচ্চতায়** দাঁড়ায়।

                 ⚠️ খালি অবস্থায় সরবরাহকারীর বাক্সে একটাই সারি, আর কাগজের
                 বাক্সে দুইটা — ছেড়ে দিলে বাঁ পাশে একটা খাটো বাক্স আর ডান
                 পাশে লম্বা, চোখে জোড়াটা ভেঙে যায়।

                 ⓘ সরবরাহকারী বাছলে ভিতরে চারটা সারি নামে, আর তখন
                 উচ্চতাটা এমনিতেই মিলে যায়। --}}
                class="grid gap-3 lg:grid-cols-[1.15fr_1.3fr] xl:grid-cols-[1.15fr_1.5fr_0.85fr]">
            <div class="min-w-0">
        {{-- ⓘ শিরোনামটা নেই — মালিক: *"Supplier details likte hobe na"*।

             ⚠️ কারণটাও ঠিক: ঘরটায় *"সরবরাহকারী খুঁজুন…"* লেখা আছে, আর
             নিচে তাঁর মোবাইল-ঠিকানা। ⛔ উপরে আবার "সরবরাহকারীর বিস্তারিত"
             লিখলে একই কথা দুইবার, আর তার দাম একটা গোটা সারির উচ্চতা।

             ⓘ ভাষার চাবিটা রেখে দিয়েছি — বাক্সটা ছাপার কাগজে বা অন্য
             পর্দায় লাগলে শব্দটা দুই ভাষায় আবার খুঁজতে হবে না। --}}
        {{-- ⓘ `h-full` — বাক্সটা তার মোড়কের পুরো উচ্চতা নেয়।

             ⛔ মোড়কটা (`<div class="min-w-0">`) ছকের ঘর, আর সে পাশের
             কাগজের বাক্সের সমান লম্বা হত — কিন্তু **ভিতরের সেকশনটা নিজের
             মাপেই থাকত** (৭২px বনাম ২৫৯px)। ⚠️ ফলে বাঁ পাশে একটা খাটো
             বাক্স আর তার নিচে ফাঁকা জায়গা, আর মালিক ঠিক ঐ ফাঁকটাই লাল
             দাগ দিয়ে দেখিয়েছেন।

             ⭐ মালিকের কথা: *"supp box date box er soman uchu hobe"*। --}}
        <section data-boxed class="h-full rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-selected) p-3"
                 style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-brand-500)">

            {{-- ── সরবরাহকারী — মালিকের ক্রমের সাত নম্বর ────────────

                 *"…Warehouse, Supplier Details — ei krome bosaw"*

                 ⭐ ছয়টা ছোট ঘরের **নিচে**, একটাই চওড়া খোঁজার বার।
                 ⓘ কারণটা কাজের: কাউন্টারে সবচেয়ে বেশি যেটা ছোঁয়া হয়
                 সেটাই সবচেয়ে বড় হওয়া দরকার।

                 ⚠️ সরবরাহকারী বদলালেই গতবারের দরগুলো নতুন করে আসে।
                 দরগুলো সরবরাহকারী-ভেদে আলাদা; বাছাই বদলে পুরনো তালিকা
                 রেখে দিলে কার্টের সারিতে **অন্য একজনের দর** বসে থাকত —
                 নীরবে, আর ঠিক তখনই যখন মানুষটা ওই সংখ্যাটা দেখে
                 দরাদরি করছেন। --}}
            {{-- ── ক্রেতার ঘরের মতো — চিহ্ন বাঁয়ে, নাম ডানে ─────────────

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"Supplier sarch icon kaj korena,
                 Cust er moto koro"*।

                 ⛔ এখানে একটা `<select>` ছিল, আর চিহ্নটা কেবল **ফোকাস**
                 করত — চেপে কিছুই খুলত না। ⚠️ তিন হাজার সরবরাহকারীর তালিকায়
                 `<select>` কার্যত অব্যবহার্য: প্রথম অক্ষরের পরে ব্রাউজার আর
                 কিছু মেলায় না।

                 ⭐ এখন বিক্রয়ের কাউন্টারের হুবহু নিয়ম: চিহ্নে চাপলে তালিকা
                 খোলে, টাইপ করলে ছাঁকে, বাছলে বন্ধ হয় আর নামটা পাশে বসে।

                 ⓘ `<select>` গেছে, কিন্তু **মানটা যায়নি** — লুকানো ঘরে
                 `supplier_id` আগের মতোই সার্ভারে যায়। --}}
            {{-- ── মালিকের ছক (৬ সেপ্টেম্বর ২০২৬) ────────────────────────

                     [চিহ্ন]  সরবরাহকারীর নাম
                     Mobile: +8801711000002
                     চরপাড়া, ময়মনসিংহ
                     Received by: Al-Amin Shuvo (Owner)

                 ⓘ নিচের তিনটা সারি **বাক্সের একদম বাঁ কিনারা থেকে** — চিহ্নের
                 নিচে ইন্ডেন্ট করা নয়। ⚠️ ইন্ডেন্ট থাকলে ঠিকানার মতো লম্বা
                 লেখা আগেই কেটে যেত, আর বাঁ পাশে একটা খালি খাঁজ পড়ে থাকত।

                 ⭐ ঠিকানার কোনো লেবেল নেই — মালিক: *"sudu Address liko"*।
                 ⓘ ঠিকানা দেখলেই ঠিকানা বোঝা যায়; নামটা লিখে জায়গা নষ্ট
                 করার দরকার নেই। ⚠️ মোবাইল আর "কে বুঝে নিলেন" — দুইটাতেই
                 লেবেল থাকে, কারণ কাঁচা সংখ্যা বা কাঁচা নাম নিজে থেকে কিছু
                 বলে না।

                 ⛔ *"স্বত্বাধিকারী"* সারিটা বাদ — তিনি তিনটা সারিই চেয়েছেন। --}}
            <div class="mt-2 flex items-center gap-2">
                <button type="button"
                        @click="supplierPickerOpen = ! supplierPickerOpen"
                        :aria-expanded="supplierPickerOpen ? 'true' : 'false'"
                        aria-label="{{ __('purchase::field.supplier') }}"
                        :class="supplierPickerOpen
                            ? 'border-(--color-brand-600) bg-(--color-brand-600) text-(--color-brand-ink)'
                            : 'border-(--color-brand-500) bg-(--color-surface-card) text-(--color-brand-700) hover:bg-(--color-surface-hover)'"
                        {{-- ⓘ চিহ্নটা ৩০% ছোট — মালিকের নির্দেশ (৯ → ৬)। --}}
                        class="grid size-6 shrink-0 place-items-center rounded-(--radius-field)
                               border-2 transition-colors">
                    <x-ui.icon name="search" :size="12" />
                </button>

                {{-- ⭐ এক ধাপ ছোট — মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬:
                     *"Supplier নামের হরফ এক ধাপ ছোট, তখন Akij Food & Beverage
                     পুরোটা ধরবে"*।

                     ⛔ চওড়া পর্দায় `xl:text-xl` উঠে যেত, আর তখন লম্বা নামগুলো
                     `truncate`-এ কেটে **তিনটা বিন্দু** হয়ে যেত। ⚠️ সরবরাহকারীর
                     নামটা কাটা মানে কাগজটা কার সাথে, সেটাই আধা-জানা।

                     ⓘ তাই ধাপটা তুলে দেওয়া হলো — সব মাপে `text-lg`। বড় পর্দায়
                     জায়গা আছে বলেই হরফ বড় করতে হবে, এমন নয়; **নামটা পুরো
                     দেখা যাওয়াই বড় হওয়ার চেয়ে দামি**। --}}
                <span class="min-w-0 flex-1 truncate text-lg font-semibold text-(--color-ink)"
                      :title="supplier?.name"
                      x-text="supplier?.name || @js(__('purchase::message.search_supplier'))"></span>
            </div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-sm text-(--color-ink)">
                <span class="text-(--color-ink-muted)">{{ __('purchase::field.mobile') }}:</span>
                <span class="num font-semibold" x-text="supplier?.phone || '—'"></span>
            </div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-sm text-(--color-ink)"
                 :title="supplier?.address"
                 x-text="supplier?.address || '—'"></div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-2xs text-(--color-ink-muted)">
                {{ __('purchase::field.received_by') }}:
                <span class="font-semibold text-(--color-ink)">{{ $receivedBy }}</span>
            </div>

            <input type="hidden" name="supplier_id" x-model="supplierId">

            {{-- ছাঁকনি — টাইপের সাথে সাথে, আর বাছা হলেই বন্ধ --}}
            <div x-show="supplierPickerOpen" x-cloak
                 @keydown.escape="supplierPickerOpen = false"
                 class="mt-2 rounded-(--radius-field) border-2 border-(--color-brand-500)
                        bg-(--color-surface-card) p-1.5 text-(--color-ink)">
                <input type="search" x-model="supplierTerm"
                       x-effect="supplierPickerOpen && $nextTick(() => $el.focus())"
                       placeholder="{{ __('purchase::message.search_supplier') }}"
                       class="h-(--spacing-field-dense) w-full rounded-(--radius-field)
                              border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">

                <ul class="mt-1.5 max-h-56 overflow-y-auto">
                    <template x-for="row in supplierMatches" :key="row.id">
                        <li>
                            <button type="button" @click="chooseSupplier(row.id)"
                                    :class="row.id === supplierId
                                        ? 'bg-(--color-surface-selected) font-semibold' : ''"
                                    class="w-full rounded-(--radius-field) px-2 py-1.5 text-start
                                           hover:bg-(--color-surface-hover)">
                                <span class="block truncate text-sm" x-text="row.name"></span>
                                <span class="num block text-2xs text-(--color-ink-muted)"
                                      x-text="row.phone || ''"></span>
                            </button>
                        </li>
                    </template>

                    <li x-show="supplierMatches.length === 0" x-cloak
                        class="px-2 py-2 text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.no_supplier_match') }}
                    </li>
                </ul>
            </div>

            {{-- ⓘ এখানে আগে একটা `<dl>` ছিল — মোবাইল · স্বত্বাধিকারী ·
                 ঠিকানা · কে বুঝে নিলেন, চারটা `লেবেল : মান` জোড়া।

                 ⚠️ কথাগুলো হারায়নি — উপরে সরবরাহকারীর নামের নিচে
                 সারি হয়ে বসেছে, ক্রেতার ঘরের মতো। ⛔ দুই জায়গায়
                 রাখলে একই কথা দুইবার হত, আর একদিন একটা বদলাত আর
                 অন্যটা বদলাত না। --}}
        </section>
            </div>
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
            {{-- ⓘ এখানে আগে একটা ছক বন্ধ হয়ে আরেকটা শুরু হত।
                 ⛔ ঐ দুইটা লাইনই "এই লাইন"-কে উপরে উঠতে দিত না। --}}

            {{-- ══ নিচের অংশ — দুইটা বাক্স, আর তাদের পাশে "এই লাইনে" ══════

                 মালিকের লাল দাগ (৫ সেপ্টেম্বর ২০২৬):

                 ```
                 ┌─ ছয়টা ঘর ─────────────────────────────┐
                 ├─ সরবরাহকারীর বাক্স ──┐ ┌─ এই লাইনে ──┐
                 ├─ পণ্যের বাক্স ────────┤ │            │
                 └──────────────────────┘ └────────────┘
                 ```

                 ⭐ **"এই লাইনে" ছকটা দুইটা বাক্স জুড়ে** — তাই ওটা এই
                 গ্রিডের দ্বিতীয় কলাম, কোনো বাক্সের ভিতরে নয়।

                 ⚠️ `items-start` ছাড়া ছকটা টেনে লম্বা হয়ে দুইটা বাক্সের
                 সমান হয়ে যেত, আর ভিতরের সারিগুলো ফাঁকা জায়গায় ভেসে থাকত। --}}
            {{-- ── "এই লাইন" — কাগজের ঘরের ডানে, উপরের সারিতেই ────────

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"Bill total box upore uta,
                 This line Bame capiye"*।

                 ⭐ বিক্রয়ের কাউন্টারের ছক হুবহু এটাই: বাঁয়ে কে, মাঝে
                 কাগজ, ডানে চলতি লাইনের অঙ্ক — আর সবচেয়ে ডানে বিলের
                 মোট, একদম উপরে।

                 ⓘ আগে একবার এটা তৃতীয় কলামে বসিয়ে সব চেপে গিয়েছিল।
                 ⚠️ তখন বাক্সগুলো ২০২px উঁচু আর চওড়া ছিল; এখন ওরা
                 বিক্রয়ের মাপে (১৪৯px), তাই তিনটা কলাম ধরে। --}}
            {{-- ⓘ `lg:col-start-3 lg:row-start-1` — তৃতীয় কলামের **প্রথম
                 সারিতে**, অর্থাৎ সরবরাহকারী ও কাগজের বাক্সের পাশে।

                 ⚠️ DOM-এ এটা পণ্যের বাক্সের পরে, কিন্তু পর্দায় উপরে —
                 জায়গাটা ছকেই ঠিক করা, মার্কআপ নাড়িয়ে নয়। ⓘ নাড়ালে
                 দুইশো লাইন সরত, আর আজ ঐ পথে দুইবার পর্দা ভেঙেছে। --}}
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
            <div class="min-w-0 space-y-3 lg:col-span-2 xl:col-span-2">

            {{-- ── পণ্যের বাক্স — মালিকের দাগানো *"Products box"* ──────── --}}
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
                                @click="browsing = ! browsing; $refs.search.focus()"
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
                            <span class="font-semibold" x-text="picked?.name"></span>
                            <span class="text-2xs text-(--color-ink-muted)" x-show="picked" x-cloak>
                                {{ __('purchase::message.on_hand') }}:
                                <span class="num" x-text="qty(picked?.on_hand)"></span>
                            </span>
                            {{-- শেষ কত দামে কেনা হয়েছিল — নতুন দর এর সাথেই মেলানো হয় --}}
                            <span class="text-2xs text-(--color-ink-muted)" x-show="picked?.last_rate > 0" x-cloak>
                                {{ __('purchase::message.last_rate') }}:
                                <span class="num" x-text="money(picked?.last_rate)"></span>
                            </span>
                        </div>
                        {{-- ⓘ পণ্য বাছার আগে ঘরটা পুরো প্রস্থ নেয়, বাছার পরে
                             সরু হয়ে নামটাকে জায়গা দেয়। --}}
                        <input type="text" x-model="term" x-ref="search"
                               :class="picked ? 'w-40 shrink-0' : 'min-w-0 flex-1'"
                               @focus="browsing = true"
                               @keydown.escape="browsing = false"
                               @keydown.enter.prevent="pickFirst()"
                               placeholder="{{ __('purchase::message.search_product') }}"
                               class="h-(--spacing-command) min-w-0 flex-1 border-0 bg-transparent px-1
                                      text-lg text-(--color-ink) placeholder:text-(--color-ink-placeholder)
                                      focus:outline-none">
                    </div>

                    {{-- ছবির ছোট লাইনটা — পণ্য না বাছা পর্যন্ত কেন ঘরগুলো
                         ফাঁকা, সেটা এই এক বাক্যেই বলা। --}}
                    <p class="mt-1 text-2xs text-(--color-module-purchase)" x-show="! picked" x-cloak>
                        {{ __('purchase::message.pick_item_hint') }}
                    </p>

                    <ul x-show="visible.length > 0" x-cloak
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-(--radius-field)
                               border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        <template x-for="p in visible" :key="p.id">
                            <li>
                                <button type="button" @click="browsing = false; pick(p)"
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
                                    <option value="" x-text="picked?.unit || '—'"></option>
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
                                    <option value="" x-text="picked?.unit || '—'"></option>
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
                                       x-model="entry.rate" @input="priced('rate')" :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.markup') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.markup" @input="priced('markup')" :disabled="! picked"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm
                                              disabled:opacity-50">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.margin') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
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
            </div>

            {{-- ══ এই লাইনে — ছবি ২-এর ছক ═══════════════════════

                 ⭐ ছাড় আর ভ্যাট ছকের **ভিতরে**, বাইরে নয় — মালিকের
                 দ্বিতীয় ছবির সবচেয়ে বড় পার্থক্যটা এটাই। ⓘ কারণটাও
                 স্পষ্ট: *"এই লাইনটা শেষে কত দাঁড়াল"* প্রশ্নের প্রতিটা
                 উপাদান তখন এক কলামে নামে — টাকা → ছাড় → ভ্যাট → নেট।
                 ⛔ বাইরে থাকলে মানুষটা উপরে ছাড় বসিয়ে নিচে যোগফল
                 দেখতে চোখ সরাতেন, প্রতিটা লাইনে একবার করে। --}}
            {{-- ── ⚠️ প্যানেলের জমিন `surface-*`, ব্যাজের রং নয় ─────────

                 ⛔ এখানে জমিন ছিল `--color-badge-success-bg`, আর ওটা দুইটা
                 কারণে ভুল:

                 ১ · **ডার্ক মোডে ব্যাজের জমিন বদলায় না** — দুই থিমেই
                     `#ecfdf5`, তাই আঁধারে হালকা সবুজের উপর হালকা লেখা।

                 ২ · **ব্যাজ একটা ছোট পিল** — সে নিজের জমিন *আর* নিজের কালি
                     একসাথে আনে, আর ভিতরে আর কিছু বসে না। ⚠️ প্যানেলে ভিতরের
                     বোতাম-লেবেল-সংখ্যা সবাই **পাতার কালি** পায়, ব্যাজেরটা
                     নয় — ফলে জোড়াটা ভেঙে যায়।

                 ⓘ `nexus-25` আজ বিক্রয়ের পর্দায় ঠিক এটাই মেপেছে: সবুজ
                 প্যানেলের ভিতরে একটা বোতামের কনট্রাস্ট দাঁড়িয়েছিল **১.০৩:১**
                 — কার্যত অদৃশ্য। ⛔ টোকেনটা যাচাই হয়েছিল কার্ডের জমিনে,
                 অথচ বোতামটা বসে প্যানেলের জমিনে।

                 ⭐ তাই সবুজ থাকে **কিনারায় আর শিরোনামে** — যেখানে সে চেনা
                 দেয় — আর জমিনটা থিমের সাথে বদলায়। --}}
            </div>

                {{-- ══ দরের তালিকা — এই সরবরাহকারীর ════════════════════

                     ⭐ তথ্যটা আজই আসে (`purchase.direct.last_rates`), কিন্তু
                     আজ পর্যন্ত সেটা কেবল **কার্টে বসে যাওয়া সারির নিচে** দেখা
                     যেত — অর্থাৎ পণ্যটা তোলার পরে। ⛔ আর দরাদরিটা হয় তোলার
                     আগে। ⓘ তালিকাটা ঠিক ওই ফাঁকটা ভরে, আর একটাও নতুন দরজা
                     লাগে না। --}}
                <div x-show="chartOpen" x-cloak
                     class="mt-3 rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-sunken) p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-2xs font-semibold tracking-wide uppercase">
                            {{ __('purchase::action.rate_chart') }}
                        </span>
                        <button type="button" @click="chartOpen = false"
                                class="text-2xs text-(--color-ink-muted) hover:text-(--color-ink)">
                            {{ __('purchase::action.close_panel') }}
                        </button>
                    </div>

                    <p x-show="! supplierChosen" x-cloak class="text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.rate_chart_needs_supplier') }}
                    </p>

                    <p x-show="supplierChosen && chartRows.length === 0" x-cloak
                       class="text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.rate_chart_empty') }}
                    </p>

                    <div x-show="chartRows.length > 0" x-cloak class="max-h-56 overflow-y-auto">
                        <table class="ui-grid is-compact w-full text-2xs">
                            <thead class="bg-(--color-surface-card) text-(--color-ink-muted)">
                                <tr>
                                    <th class="text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="text-end">{{ __('purchase::field.rate') }}</th>
                                    <th class="text-end">{{ __('purchase::field.date') }}</th>
                                    <th><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="row in chartRows" :key="row.id">
                                    <tr class="border-t border-(--color-border)">
                                        <td x-text="row.name"></td>
                                        <td class="num" x-text="money(row.rate)"></td>
                                        <td class="num text-(--color-ink-muted)" x-text="row.on"></td>
                                        <td class="text-end">
                                            <button type="button" @click="pickFromChart(row)"
                                                    class="rounded-(--radius-field) border border-(--color-border)
                                                           px-2 py-0.5 hover:bg-(--color-surface-hover)">
                                                {{ __('purchase::field.search_item') }}
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

            {{-- ── কার্ট ─────────────────────────────────────────────── --}}
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
                                        <input type="hidden" :name="`lines[${index}][product_id]`" :value="line.id">
                                        <input type="hidden" :name="`lines[${index}][sales_price]`"
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
                                                <span x-text="`· ${lastRateFor(line).on}`"></span>
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
                                               :name="`lines[${index}][rate]`" x-model="line.rate"
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
                                               :name="`lines[${index}][qty]`" x-model="line.qty"
                                               class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        <input type="hidden" :name="`lines[${index}][unit_id]`" :value="line.unit_id">
                                        <span class="block text-end text-2xs text-(--color-ink-muted)"
                                              x-text="unitName(line.unit_id, line)"></span>
                                    </td>

                                    @if ($show['free_qty'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="`lines[${index}][free_qty]`" x-model="line.free_qty"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                            <input type="hidden" :name="`lines[${index}][free_unit_id]`"
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
                                                   :name="`lines[${index}][discount]`" x-model="line.discount"
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
                                                       :name="`lines[${index}][tax]`" x-model="line.tax"
                                                       class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                            </template>

                                            <template x-if="line.vat_mode === 'none'">
                                                <input type="hidden" :name="`lines[${index}][tax]`" value="0">
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
                            <template x-for="line in lines" :key="`g${line.key}`">
                                <template x-for="(gift, gi) in line.gifts" :key="gift.key">
                                    <tr class="bg-(--color-surface-sunken)/50">
                                        <td></td>
                                        <td colspan="3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-(--radius-field) bg-(--color-badge-info-bg)
                                                             px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                                    {{ __('purchase::field.gift') }}
                                                </span>

                                                <select :name="`gifts[${line.key}-${gi}][product_id]`"
                                                        x-model="gift.product_id" required
                                                        class="h-(--spacing-field-dense) rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-card)
                                                               px-1 text-xs">
                                                    {{-- ⓘ খালি মানে **পণ্যের নিজের একক** — তাই লেখাটাও
                                         সেটাই, একটা ড্যাশ নয়। ⚠️ ড্যাশ দেখে মানুষ ভাবতেন
                                         একক বাছা হয়নি, অথচ ওটাই স্বাভাবিক অবস্থা। --}}
                                    <option value="" x-text="picked?.unit || '—'"></option>
                                                    <template x-for="p in catalogue" :key="p.id">
                                                        <option :value="p.id" x-text="p.name"></option>
                                                    </template>
                                                </select>

                                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                                       :name="`gifts[${line.key}-${gi}][qty]`" x-model="gift.qty"
                                                       placeholder="{{ __('purchase::field.qty') }}"
                                                       class="num h-(--spacing-field-dense) w-16 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card)
                                                              px-1 text-end text-xs">

                                                <input type="text" maxlength="191"
                                                       :name="`gifts[${line.key}-${gi}][remarks]`"
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
                                                       :name="`gifts[${line.key}-${gi}][against_product_id]`"
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

            {{-- ══ পরিশোধ — কার্টের নিচে, চওড়া স্ট্রিপে ═══════════════

                 মালিকের নির্দেশ (৫ সেপ্টেম্বর ২০২৬): বিক্রয়ের পর্দার
                 মতোই — *"etar moto payment o hobe"*।

                 ⛔ আগে এটা মোটের কার্ডের ভিতরে একটা সরু কলামে ছিল, আর
                 ঘরগুলো একটার নিচে একটা নামত। ⚠️ ২৪৮px-এ পাঁচটা ঘর মানে
                 পাঁচটা সারি — কাউন্টারে টাকা নেওয়ার সময় ওটা সবচেয়ে ধীর
                 জায়গা হত।

                 ⭐ এখন কার্টের নিচে পুরো চওড়ায়, আর সব ঘর **এক সারিতে**:
                 তারিখ · উপায় · কোন খাত · রেফারেন্স · অঙ্ক · বিবরণ · যোগ।

                 ⓘ `flex-wrap` ইচ্ছাকৃত, `grid` নয় — রেফারেন্সের ঘরটা কেবল
                 চেক/bKash-এ দেখা যায়, আর গ্রিডে ওটা লুকালে একটা ফাঁকা
                 কলাম পড়ে থাকত। --}}
            <section data-boxed x-show="depositOpen" x-cloak
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">

                {{-- যোগ হয়ে যাওয়া পরিশোধগুলো --}}
                <template x-for="(row, i) in deposits" :key="i">
                    <div class="mb-1 flex items-center gap-2 rounded-(--radius-field)
                                bg-(--color-surface-sunken) px-2 py-1 text-2xs">
                        <span class="min-w-0 flex-1 truncate">
                            <span x-text="methodName(row.methodId)"></span>
                            <span class="text-(--color-ink-muted)"
                                  x-show="row.reference"
                                  x-text="' · ' + row.reference"></span>
                            <span class="text-(--color-ink-muted)"
                                  x-show="row.narration"
                                  x-text="' · ' + row.narration"></span>
                        </span>
                        <span class="num font-medium" x-text="money(Number(row.amount))"></span>
                        <button type="button" @click="dropDeposit(i)"
                                class="px-1 text-(--color-danger)"
                                aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>

                        {{-- ⓘ সার্ভারে যা যায় — নামের ভিতরে সূচক, তাই
                             PHP-তে সারিগুলো আলাদা থাকে। --}}
                        <input type="hidden" :name="`deposits[${i}][amount]`" :value="row.amount">
                        <input type="hidden" :name="`deposits[${i}][payment_method_id]`" :value="row.methodId">
                        <input type="hidden" :name="`deposits[${i}][account_id]`" :value="row.accountId">
                        <input type="hidden" :name="`deposits[${i}][reference]`" :value="row.reference">
                        <input type="hidden" :name="`deposits[${i}][ref_date]`" :value="row.refDate">
                        <input type="hidden" :name="`deposits[${i}][narration]`" :value="row.narration">
                    </div>
                </template>

                <div class="flex flex-wrap items-end gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.ref_date') }}
                        </span>
                        <x-ui.date dense name="deposit_ref_date"
                                   bind-name="'deposit_ref_date'"
                                   bind-iso="depositDraft.refDate"
                                   bind-model="depositDraft.refDate" />
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.instrument') }}
                        </span>
                        <select x-model="depositDraft.methodId" @change="methodPicked()"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            <option value="">{{ __('purchase::field.paid_how') }}</option>
                            @foreach ($depositMethods as $method)
                                <option value="{{ $method['id'] }}">{{ $method['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    {{-- ⚠️ খাতের তালিকা উপায় বাছার পরেই। উপায় না বেছে খাত
                         দেখালে কেউ নগদের খাতে চেকের টাকা বসিয়ে দিতেন, আর
                         মাস শেষে নগদ মিলত না। --}}
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.paid_from') }}
                        </span>
                        <select x-model="depositDraft.accountId" :disabled="! depositDraft.methodId"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm
                                       disabled:opacity-50">
                            <option value="">{{ __('purchase::field.paid_from') }}</option>
                            <template x-for="a in depositAccounts" :key="a.id">
                                <option :value="a.id" x-text="a.label"></option>
                            </template>
                        </select>
                    </label>

                    {{-- রেফারেন্স — কেবল যে উপায়ে সেটা লাগে --}}
                    <label class="min-w-0 flex-1" x-show="depositNeedsReference" x-cloak>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.reference') }}
                        </span>
                        <input type="text" x-model="depositDraft.reference"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.amount') }}
                        </span>
                        <input type="number" step="0.01" inputmode="decimal"
                               x-model="depositDraft.amount"
                               class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.narration') }}
                        </span>
                        <input type="text" maxlength="255" x-model="depositDraft.narration"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    <button type="button" @click="addDeposit()" :disabled="! depositReady"
                            class="h-(--spacing-field) shrink-0 rounded-(--radius-field)
                                   bg-(--color-brand-500) px-4 text-sm font-medium
                                   text-(--color-brand-ink) hover:bg-(--color-brand-600)
                                   disabled:opacity-40">
                        {{ __('purchase::action.add_deposit') }}
                    </button>
                </div>

                {{-- ── এই উপায়ের কোনো খাত নেই ───────────────────────────

                     ⚠️ ছাঁকনিটা ঠিকমতো কাজ করলে এই অবস্থাটা আসবেই: যে
                     কোম্পানির ব্যাংক হিসাব ছকে বসানো নেই, সে "ব্যাংক
                     ট্রান্সফার" বাছলে **একটাও খাত পাবে না**।

                     ⛔ বার্তাটা না থাকলে পর্দাটা চুপ করে থাকত — খালি
                     তালিকা, নিষ্ক্রিয় "যোগ" বোতাম, আর কোনো কারণ নয়।
                     মানুষটা ভাবতেন পর্দা নষ্ট, অথচ অনুপস্থিত জিনিসটা
                     তাঁর নিজের হিসাবের ছকে। --}}
                <p x-show="depositDraft.methodId && depositAccounts.length === 0" x-cloak
                   class="mt-2 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                          text-2xs text-(--color-badge-warning-ink)">
                    {{ __('purchase::message.no_account_for_method') }}
                </p>
            </section>

            {{-- ⭐ পরিবহন · শিপমেন্ট · নোট — **বাঁ দিকে, কার্টের নিচে**।

                 মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬: *"Direct Pur e ei buton
                 gulo upore khule, egulo sob bame khulbe Payment/Deposit er moto"*।

                 ⛔ তিনটাই ছিল ডান `<aside>`-এর ভিতরে, তাই খুললে **উপরের
                 সংখ্যাগুলো ঠেলে নামত** — আর ঐ সংখ্যাগুলোই ব্যবহারকারী
                 দেখতে দেখতে টাইপ করেন।

                 ⚠️ ভাড়া লিখলে "নিট পরিশোধযোগ্য" বদলায়, আর ওটা ডান
                 প্যানেলেই — অর্থাৎ প্যানেলটা **ঠিক সেই সংখ্যাটাকেই সরিয়ে
                 দিত যেটা সে বদলাচ্ছে**। ⓘ বিক্রয়ের পর্দায় এই যুক্তিটা
                 আগেই লেখা আছে (`sales::direct.partials.panels`), আর জমার
                 সেকশনটা এখানেও তাই বাঁয়েই ছিল।

                 ⭐ এখন ছয়টা বোতামই এক নিয়ম মানে: **সবাই বাঁয়ে খোলে**।
                 ⓘ এক পর্দায় দুই রকম নিয়ম (কিছু বাঁয়ে, কিছু ডানে) শেখার
                 বোঝা বাড়ায়, আর কোনটা কোথায় খুলবে তা আগে থেকে বোঝা যায় না।

                 ⚠️ ইন্ডেন্ট বদলানো হয়নি — ব্লকগুলো হুবহু সরানো হয়েছে,
                 যাতে কোনটা বদলেছে আর কোনটা কেবল জায়গা বদলেছে তা diff-এ
                 আলাদা করে দেখা যায়। --}}

                {{-- ── কে মালটা আনল ───────────────────────────────────

                     মালিকের কথা: *"পরিবহনকারী মানে মাল আনার খরচ — নেওয়ার
                     খরচও আছে এতে।"* ⓘ অর্থাৎ ঘরটা কেবল নাম নয়, খরচসহ।

                     ⛔ এতদিন ক্রয়ের দিকে এর একটাও ছিল না — মেপে দেখা
                     গেছে `app/Modules/Purchase`-এ `transport_cost` শব্দটা
                     শূন্যবার। তাই ভাড়াটা হয় কোথাও লেখাই হত না, নয়তো
                     আলাদা খরচ হয়ে বসত, আর **প্রতিটা পণ্যের লাভ ঠিক ভাড়ার
                     পরিমাণে বেশি দেখাত**।

                     ⚠️ আজ ঘরটা কেবল **রাখে** — ভাড়া ক্রয়মূল্যে ঢোকার
                     অংশটা আলাদা কাজ। ⓘ পর্দাতেও সেটা বলা আছে, নাহলে কেউ
                     ধরে নিতেন লাভের অঙ্কে ওটা ইতিমধ্যে ধরা হয়েছে।

                     ⭐ ৫ সেপ্টেম্বর ২০২৬ — ব্লকটা এখন **একটা বোতামের পিছনে**
                     (`Transportation`), সবসময় খোলা নয়। ⓘ মালিকের স্ক্রিনশটে
                     ডান কার্ডের নিচে ছয়টা বোতাম, আর ভাড়া তার একটা। ⚠️
                     বেশিরভাগ চালানে ভাড়া থাকেই না; সবসময় খোলা রাখলে পাঁচটা
                     খালি ঘর প্রতিদিন চোখে পড়ত, আর মানুষ ওগুলোকে "ঐচ্ছিক
                     আবর্জনা" পড়তে শিখত। --}}
                <div x-show="transportOpen" x-cloak
                     class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    <div class="text-2xs font-medium text-(--color-ink-muted)">
                        {{ __('purchase::field.carrier') }}
                    </div>

                    {{-- ⚠️ তালিকা খালি থাকলে কারণটা বলা হয়, আর হাতে নাম
                         লেখার ঘরটা তখনো আছে — তাই পর্দাটা অচল হয় না।

                         ⓘ এই অবস্থাটা কল্পনা নয়: আজ কোনো সরবরাহকারী
                         TRANSPORT ধরনে নেই, তাই **প্রথম দিন থেকেই** তালিকা
                         খালি থাকবে। --}}
                    <select name="carrier_id" x-model="carrierId"
                            x-show="carriers.length > 0" x-cloak
                            class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
                        <option value="">—</option>
                        <template x-for="c in carriers" :key="c.id">
                            <option :value="c.id" x-text="c.label"></option>
                        </template>
                    </select>

                    <p x-show="carriers.length === 0" x-cloak
                       class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                              text-2xs text-(--color-badge-warning-ink)">
                        {{ __('purchase::message.no_carrier_party') }}
                    </p>

                    {{-- হাতে নাম — একবারের ভাড়া গাড়ির জন্য।

                         ⓘ তালিকা থেকে কেউ বাছা হলে ঘরটা লুকায়: দুইটা
                         একসাথে ভরলে কোনটা সত্যি তা কেউ বলতে পারত না। --}}
                    <input type="text" name="carrier_name" x-model="carrierName"
                           x-show="! carrierId" x-cloak
                           placeholder="{{ __('purchase::field.carrier_name') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                    <div class="flex gap-1">
                        <input type="number" step="0.01" inputmode="decimal"
                               name="transport_cost" x-model="transportCost"
                               placeholder="{{ __('purchase::field.transport_cost') }}"
                               class="num h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-end text-2xs">
                        <input type="text" name="vehicle_no" x-model="vehicleNo"
                               placeholder="{{ __('purchase::field.vehicle_no') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-2xs">
                    </div>

                    <input type="text" name="driver_name" x-model="driverName"
                           placeholder="{{ __('purchase::field.driver_name') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                    {{-- ⚠️ ভাড়া লিখলে কে আনল সেটা বলতেই হবে — নাহলে
                         টাকাটা কার খাতায় দেনা হবে কেউ জানে না। --}}
                    <p x-show="transportNeedsWho" x-cloak
                       class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                              text-2xs text-(--color-badge-warning-ink)">
                        {{ __('purchase::message.transport_needs_carrier') }}
                    </p>

                    {{-- ⭐ সৎ থাকা: সংখ্যাটা আজ ক্রয়মূল্যে যায় না, আর
                         পর্দা সেটাই বলে। ⓘ না বললে কেউ ধরে নিতেন লাভের
                         অঙ্কে ধরা হয়েছে, আর দর ঠিক করতেন তার উপর। --}}
                    <p x-show="Number(transportCost) > 0" x-cloak
                       class="text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.transport_not_in_cost_yet') }}
                    </p>
                </div>

                {{-- ══ আমদানি চালান — `Shipment` বোতামের পিছনে ══════════

                     ⭐ মালিকের সিদ্ধান্ত (৫ সেপ্টেম্বর ২০২৬): ছবির
                     `Shipment` বোতামটা **আমদানি চালানের তথ্য** খোলে —
                     ঋণপত্র ও বিল অব এন্ট্রির নম্বর, জাহাজ, আর বন্দর।

                     ⛔ আজ পর্যন্ত `pur_bills`-এ ওদের একটারও ঘর ছিল না,
                     তাই নম্বরগুলো হয় `narration`-এ গদ্য হয়ে বসত, নয়তো
                     বসতই না। ⚠️ আর যেদিন ব্যাংক বা কাস্টমস জিজ্ঞেস করে
                     *"কোন LC-র মাল"*, গদ্য থেকে সেটা খুঁজে বের করা যায়
                     না — খোঁজা যায় না, রিপোর্টও হয় না।

                     ⚠️ **ঘরগুলো কেবল রাখে।** শুল্ক বা বন্দর খরচ পণ্যের
                     ক্রয়মূল্যে যোগ হয় না, ঠিক যেমন ভাড়াও হয় না —
                     আর প্যানেলেই কথাটা লেখা আছে। ⛔ না লিখলে কেউ এটাকে
                     landed cost পড়তেন, আর দর ঠিক করতেন তার উপর।

                     ⓘ চারটাই **লেখার ঘর**, ড্রপডাউন নয়: বন্দর ও জাহাজের
                     তালিকা গ্রাহকভেদে বদলায়, আর সেরকম তালিকা কোডের
                     ধ্রুবক হয় না — সেটিংসের সারি হয়। ⚠️ সারি বানানোর
                     আগেই ঘরগুলো দরকার, আর ফাঁকা লেখার ঘর ভুল
                     ড্রপডাউনের চেয়ে সৎ। --}}
                <div x-show="shipmentOpen" x-cloak
                     class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    <div class="text-2xs font-medium text-(--color-ink-muted)">
                        {{ __('purchase::field.shipment') }}
                    </div>

                    <input type="text" name="lc_no" maxlength="64" value="{{ old('lc_no') }}"
                           placeholder="{{ __('purchase::field.lc_no') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                    <div class="flex gap-1">
                        <input type="text" name="be_no" maxlength="64" value="{{ old('be_no') }}"
                               placeholder="{{ __('purchase::field.be_no') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-2xs">

                        {{-- ⓘ কোম্পানির ছকেই তারিখটা আঁকা হয়, ব্রাউজারের
                             ছকে নয় — বাকি প্রতিটা তারিখের ঘরের মতো। --}}
                        <div class="min-w-0 flex-1">
                            <x-ui.date dense name="be_date" :value="old('be_date')" class="w-full text-2xs" />
                        </div>
                    </div>

                    <div class="flex gap-1">
                        <input type="text" name="vessel" maxlength="120" value="{{ old('vessel') }}"
                               placeholder="{{ __('purchase::field.vessel') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-2xs">

                        <input type="text" name="port_of_entry" maxlength="120"
                               value="{{ old('port_of_entry') }}"
                               placeholder="{{ __('purchase::field.port_of_entry') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-2xs">
                    </div>

                    {{-- ⭐ সৎ থাকা: শুল্ক ও বন্দর খরচ আজ ক্রয়মূল্যে যায় না,
                         আর পর্দা সেটাই বলে — ভাড়ার ঘরটার মতোই। --}}
                    <p class="text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.shipment_not_in_cost_yet') }}
                    </p>
                </div>

                {{-- ── মন্তব্য — `Add Note` বোতামের পিছনে ────────────────

                     ⭐ ঘরটা মালিকের প্রথম ছবিতে ডান কার্ডে `REMARKS` নামে
                     ছিল, আর স্ক্রিনশটে সেটাই `Add Note` বোতাম হয়ে গেছে।
                     ⓘ দুইটা একই জিনিস, কেবল দ্বিতীয়টা খালি পর্দায় জায়গা
                     নেয় না।

                     ⚠️ `narration` নামটা কন্ট্রোলারের নিয়মের সাথে মেলানো
                     (`'narration' => ['nullable','string','max:500']`) — অন্য
                     নাম দিলে লেখাটা নীরবে হারাত, কারণ যাচাই ওটাকে চিনত না। --}}
                <div x-show="noteOpen" x-cloak
                     class="mt-3 border-t border-(--color-border) pt-3">
                    <label class="block">
                        <span class="mb-1 block text-2xs font-medium text-(--color-ink-muted)">
                            {{ __('purchase::field.remarks') }}
                        </span>
                        <textarea name="narration" rows="2" maxlength="500" x-ref="note"
                                  placeholder="{{ __('purchase::field.optional') }}"
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-card) p-2 text-2xs">{{ old('narration') }}</textarea>
                    </label>
                </div>

        </div>

        {{-- ══ ডান কার্ড — সরবরাহকারী ও রসিদ ═══════════════════════════

             ⭐ মালিকের ছবির ডান কার্ড। ⓘ সরবরাহকারীর ঘরটা আগে বাঁ পাশের
             ডকুমেন্ট স্ট্রিপে ছিল, আর সেটা ভুল জায়গা ছিল না — কিন্তু তখন
             *"কার কাছ থেকে কিনছি"* আর *"তাঁকে কত দিতে হবে"* পর্দার দুই
             প্রান্তে থাকত। এক কার্ডে এনে দুইটা প্রশ্ন পাশাপাশি বসল।

             ⚠️ মাথার রেখাটা সবুজ→বেগুনি: টাকার দিক। টোকেনে আঁকা, হাতে
             লেখা রঙে নয় (`EveryScreenObeysTheThemeTest`)। --}}
        {{-- ⓘ কার্ডগুলোর মাঝের ফাঁক `space-y-2` — মালিকের লাল দাগানো
             তিনটা ফাঁকা জায়গা। ⚠️ শূন্য করা হয়নি: পাড় দুইটা তখন গায়ে
             গায়ে লেগে একটা মোটা রেখা হয়ে যেত, আর কার্ড দুইটা আলাদা
             বোঝা যেত না। --}}
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
            <div class="grid min-w-0 grid-cols-3 gap-1 text-center [&_button]:break-words">
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

                <button type="button" @click="openChart()" :aria-expanded="chartOpen"
                        class="rounded-(--radius-field) px-1 py-2 text-2xs leading-tight font-medium
                               text-(--color-ink-inverse)"
                        style="background: var(--color-module-supplier)">
                    {{ __('purchase::action.rate_chart') }}
                </button>

                <button type="button" @click="transportOpen = ! transportOpen"
                        :aria-expanded="transportOpen"
                        class="rounded-(--radius-field) py-2 text-2xs font-medium
                               text-(--color-warning-ink)"
                        style="background: var(--color-warning)">
                    {{ __('purchase::action.transportation') }}
                </button>

                <button type="button" @click="shipmentOpen = ! shipmentOpen"
                        :aria-expanded="shipmentOpen"
                        class="rounded-(--radius-field) px-1 py-2 text-2xs leading-tight font-medium
                               text-(--color-ink-inverse)"
                        style="background: var(--color-module-backup)">
                    {{ __('purchase::action.shipment') }}
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
    </form>

    @push('scripts')
        <script>
            /*
             * সরাসরি ক্রয়ের পর্দা।
             *
             * দর নির্ধারণের অঙ্কটা এখানে লেখা নেই — window.abos.reprice
             * (resources/js/pricing.js) সেটা করে, আর ওটার নিজের ১৪টা
             * পরীক্ষা আছে। Blade-এর ভেতরে লিখলে ওই অঙ্কটার কোনো পরীক্ষা
             * লেখা যেত না, অথচ সেটাই প্রতিটা পণ্যের বিক্রয়মূল্য ঠিক করে।
             */
            function directPurchase(catalogue, vatEnabled, lastRatesUrl) {
                return {
                    catalogue,
                    vatEnabled,
                    lastRatesUrl,
                    busy: false,
                    term: '',
                    picked: null,
                    entry: {},
                    lines: [],
                    paidNow: '',
                    nextKey: 1,

                    /* সরবরাহকারীর আগের বকেয়া — সার্ভার থেকে আসে বাছাইয়ের
                       মুহূর্তে ([[loadLastRates]])।

                       ⓘ ঋণাত্মক মানে অগ্রিম, আর তখন পর্দার লেবেলটাই
                       বদলে যায় — "আগের বকেয়া" নয়, "আগের অগ্রিম"। */
                    previousDue: 0,

                    /* ── জমা ─────────────────────────────────────────────
                       এক বিলের টাকা এক পথে যায় না — কিছু নগদ, বাকিটা চেকে
                       বা bKash-এ। প্রতিটা সারি নিজের উপায় ও নিজের খাত নিয়ে
                       বসে, আর সার্ভারে আলাদা পরিশোধ হয়। */
                    deposits: [],
                    depositMethods: @js($depositMethods),
                    /* ⚠️ খাতের ধরনটা `is_cash`/`is_bank` থেকে নেওয়া যায় না।

                       মেপে দেখা গেছে বিকাশের খাতটা দুইটার কোনোটাই নয়, তাই
                       ওই হিসাবে MFS-এর ছাঁকনি **একটাও খাত পেত না** আর
                       নীরবে সব খাত দেখাত — অর্থাৎ ছাঁকনিটা ছিল, কাজ করত না।

                       ⭐ আসল উত্তর মায়ের কোডে: ১১০১ নগদ · ১১০২ ব্যাংক ·
                       ১১০৫ মোবাইল মানি। বিক্রয়ের দিকেও এভাবেই করা। */
                    moneyAccounts: @js($moneyAccounts->map(fn ($a) => [
                        'id' => (string) $a->id,
                        'label' => $a->label(),
                        'parent' => (string) ($a->parent?->code ?? ''),
                    ])->values()),
                    depositDraft: { methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '' },

                    /* ── কে মালটা আনল ─────────────────────────────────
                       তালিকাটা পক্ষের ধরন ধরে ছাঁকা (TRANSPORT), তাই এখানে
                       কোনো প্রতিষ্ঠানের নাম লেখা নেই। */
                    carriers: @js($carriers),
                    carrierId: '',
                    carrierName: '',
                    transportCost: '',
                    vehicleNo: '',
                    driverName: '',

                    /* এই সরবরাহকারীর কাছ থেকে কোন পণ্য গতবার কত দরে —
                       পণ্যের আইডি ধরে। সরবরাহকারী বাছার সাথে সাথে একবারে
                       আসে; সারি ধরে ধরে নয়, নাহলে বিশ লাইনের কার্টে বিশটা
                       রাউন্ড-ট্রিপ হত আর কাউন্টারে সেটা টের পাওয়া যেত। */
                    lastRates: {},
                    supplierChosen: false,

                    /* ── প্যাকের তালিকা — পণ্যের আইডি ধরে ────────────────
                       ⓘ উৎস `PackConversion::optionsFor`, আর সুইচটা
                       `inventory.pack_entry_enabled`। ⚠️ লাইন-এডিটরও হুবহু
                       এই ডাকটাই ব্যবহার করে; দুই পর্দায় দুই তালিকা হলে
                       একই পণ্য এখানে বাক্সে আর বিলে পিসে লিখতে হত। */
                    packs: @js($packs),

                    /* ── সরবরাহকারীর কার্ডের চারটা লাইন ────────────────
                       ⓘ তথ্যগুলো আগেই ছিল, দেখানো হত না। ⚠️ আইডিটা
                       স্ট্রিং করা হয়েছে ইচ্ছে করে: `<select>`-এর মান
                       সবসময় স্ট্রিং, আর `===` মেলাতে গিয়ে একদিন
                       কার্ডটা চিরকাল খালি থাকত। */
                    suppliers: @js($suppliers->map(fn ($s) => [
                        'id' => (string) $s->id,
                        /* ⓘ নামটা আগে লাগত না — `<select>` নিজেই দেখাত।
                           ⚠️ এখন তালিকাটা আমরা আঁকি, তাই নামটাও পাঠাতে হয়। */
                        'name' => $s->name(),
                        'phone' => $s->phone,
                        'address' => $s->address(),
                        'proprietor' => $s->contact_person,
                    ])->values()),
                    supplierId: '',

                    /* ⓘ দুইটা তালিকার খোলা-বন্ধ — বিক্রয়ের কাউন্টারের মতো।
                       ⚠️ `browsing` মানে "চিহ্নে চাপা হয়েছে", অর্থাৎ কিছু
                       টাইপ না করলেও পুরো তালিকা দেখানো হবে। */
                    supplierPickerOpen: false,
                    supplierTerm: '',
                    browsing: false,

                    /* ── দুইটা প্যানেল ──────────────────────────────────
                       ⓘ দুইটাই বন্ধ অবস্থায় শুরু হয়, আর একসাথে দুইটাই খোলা
                       থাকতে পারে — কেউ দরের তালিকা খুলে রেখে খরচের হিসাব
                       দেখতে চাইলে বাধা দেওয়ার কারণ নেই। */
                    /* ── কত দিনের বাকিতে ────────────────────────────────
                       ⓘ তালিকাটা `mdm_payment_terms`-এর সারি। ⚠️ বাছাইটা
                       নিজে সংরক্ষিত হয় না — তার ফল (`due_on`) হয়। */
                    /* ⓘ ঘরটার একটাই মান: হয় দিনের সংখ্যা ('7'), হয় খালি
                       ('নগদ'), নয় 'date' — আর শেষেরটা বাছলে ঘরটাই
                       তারিখের ঘর হয়ে যায়। ⚠️ দুইটা আলাদা মান রাখলে
                       আবার দুইটা বাক্স হয়ে যেত, শুধু চোখের আড়ালে। */
                    /* ⓘ ঘরটার একটাই মান: `cash` · `credit:7` ·
                       `month_end` · `fixed`। ⚠️ কোলনের পরের সংখ্যাটা কেবল
                       `credit`-এ, আর সার্ভারে যায় কেবল কোলনের **আগের**
                       অংশটা — দিনসংখ্যাটা তারিখ হয়ে যায়। */
                    termChoice: @js($paymentTermDefault),
                    dueOn: '',

                    chartOpen: false,
                    depositOpen: false,
                    transportOpen: false,
                    noteOpen: false,
                    shipmentOpen: false,

                    /* ── খসড়া ────────────────────────────────────────────

                       গাড়ি গেটে দাঁড়ানো, বিশ লাইন টাইপ করা — পর্দা হারানো
                       মানে পুরোটা আবার। বিক্রয়ে এই ব্যবস্থা আগেই আছে, আর
                       এখানে সেটার গড়নই নেওয়া হয়েছে, ওদের শেখা ভুলগুলোসহ।

                       ⚠️ চাবিটা **ক্রয়ের নিজস্ব** — বিক্রয়েরটা থেকে আলাদা।
                       এক চাবি হলে বিক্রয়ের কার্ট ক্রয়ের পর্দায় খুলত, আর
                       কেউ না বুঝে সেটার উপরেই কিনতে বসতেন। */
                    draftKey: 'abos.direct-purchase.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',
                    draftFound: false,
                    draftAt: '',

                    /* সরবরাহকারী · গুদাম · বিল নম্বর — এগুলো Alpine-এর ঘরে
                       নেই, সাধারণ DOM ঘর। তাই খসড়ায় বসানোর সময় পর্দা থেকেই
                       পড়া হয়, আর ফেরানোর সময় পর্দাতেই লেখা হয়। */
                    /* ⚠️ `$root`, `$el` নয়।

                       `$el` মানে **যে এলিমেন্টের ভিতর থেকে ডাকা হয়েছে**।
                       `init()`-এ ওটা ফর্ম, কিন্তু `@click="restoreDraft()"`
                       থেকে ডাকলে ওটা **বোতামটা** — আর বোতামের ভিতরে
                       `[name=...]` কিছুই নেই।

                       ⛔ ফলটা নীরব ছিল: খসড়া ফিরত, সারিগুলোও ফিরত, কিন্তু
                       সরবরাহকারী আর বিল নম্বর **ফাঁকা** থেকে যেত — আর কেউ
                       ভুল সরবরাহকারীর নামে বিলটা নিশ্চিত করে ফেলতে পারতেন।
                       ⓘ ব্রাউজারে চাপ দিয়ে ধরা পড়েছে, কোড পড়ে নয়। */
                    box(name) {
                        return this.$root.querySelector('[name="' + name + '"]');
                    },

                    boxValue(name) {
                        const el = this.box(name);

                        return el ? el.value : '';
                    },

                    setBox(name, value) {
                        const el = this.box(name);

                        if (el) el.value = value ?? '';
                    },

                    /* ⚠️ কার্ট খালি হলে খসড়া মুছে যায়, রাখা হয় না — খালি
                       খসড়া ফেরানোর প্রস্তাব মানে প্রতিদিন একটা অর্থহীন
                       প্রশ্ন। */
                    saveDraft() {
                        /* ⚠️ প্রস্তাব পর্দায় থাকা অবস্থায় লেখা বা মোছা নয়।

                           এই লাইনটা ছাড়া বাগটা নীরব হত: পাতা খোলার সাথে
                           সাথেই `x-effect` একবার চলে, আর তখন কার্ট খালি —
                           অর্থাৎ খসড়াটা মুছে যেত ঠিক সেই মুহূর্তে যখন
                           ফেরানোর প্রস্তাব দেখানো হচ্ছে। বোতামটা থাকত,
                           চাপলে কিছুই ফিরত না। ⓘ বিক্রয়ে এটা শেখা হয়েছে। */
                        if (this.draftFound) return;

                        try {
                            if (this.lines.length === 0) {
                                localStorage.removeItem(this.draftKey);

                                return;
                            }

                            localStorage.setItem(this.draftKey, JSON.stringify({
                                at: new Date().toISOString(),
                                supplierId: this.boxValue('supplier_id'),
                                warehouseId: this.boxValue('warehouse_id'),
                                supplierBillNo: this.boxValue('supplier_bill_no'),
                                lines: this.lines,
                                paidNow: this.paidNow,
                                deposits: this.deposits,
                                carrierId: this.carrierId,
                                carrierName: this.carrierName,
                                transportCost: this.transportCost,
                                vehicleNo: this.vehicleNo,
                                driverName: this.driverName,
                                nextKey: this.nextKey,
                            }));
                        } catch (e) {
                            /* ⚠️ চুপ করে থাকা ইচ্ছাকৃত — localStorage বন্ধ
                               থাকতে পারে (ব্যক্তিগত উইন্ডো, সাইট-ডেটা বন্ধ
                               করা ব্রাউজার), আর তখন লেখা ব্যতিক্রম ছোঁড়ে।
                               কিন্তু ওটা ক্রয় থামানোর কারণ নয়। */
                        }
                    },

                    /** পাতা খোলার সময় — আছে কিনা দেখা, নিজে থেকে ফেরানো নয়। */
                    lookForDraft() {
                        try {
                            const parked = localStorage.getItem(this.draftKey + '.pending');

                            if (parked) {
                                localStorage.removeItem(this.draftKey + '.pending');

                                /* ⚠️ সার্ভার ফিরিয়ে দিয়েছে — তাই প্রশ্ন নয়,
                                   সরাসরি ফেরানো। ব্যবহারকারী "নতুন ক্রয়"
                                   চাননি, তিনি এইটাই পাঠিয়েছিলেন। */
                                if (@js($errors->any())) {
                                    this.applyDraft(parked);

                                    return;
                                }
                            }

                            const raw = localStorage.getItem(this.draftKey);

                            if (! raw) return;

                            const d = JSON.parse(raw);

                            if (! d || ! Array.isArray(d.lines) || d.lines.length === 0) return;

                            this.draftFound = true;
                            this.draftAt = d.at ? new Date(d.at).toLocaleString() : '';
                        } catch (e) {
                            localStorage.removeItem(this.draftKey);
                        }
                    },

                    /* ⚠️ ফেরানো **কেবল চাপ দিলে** — নিজে থেকে নয়।

                       নিজে থেকে ফেরালে সবচেয়ে বিপজ্জনক জিনিসটা ঘটত: কেউ নতুন
                       ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে,
                       আর তার উপরেই নতুন সারি যোগ করে নিশ্চিত করতেন। ⛔ **ভুল
                       সরবরাহকারীর নামে ভুল মাল, আর ভুল দেনা।** */
                    restoreDraft() {
                        this.applyDraft(localStorage.getItem(this.draftKey));
                        this.draftFound = false;
                    },

                    discardDraft() {
                        try {
                            localStorage.removeItem(this.draftKey);
                        } catch (e) {
                            // মুছতে না পারলেও প্রস্তাবটা সরিয়ে দেওয়াই যথেষ্ট
                        }

                        this.draftFound = false;
                    },

                    /** খসড়াটা পর্দায় বসানো — কোথা থেকে এল তা জানার দরকার নেই। */
                    applyDraft(raw) {
                        try {
                            const d = JSON.parse(raw || '{}');

                            this.setBox('supplier_id', d.supplierId);
                            this.setBox('warehouse_id', d.warehouseId);
                            this.setBox('supplier_bill_no', d.supplierBillNo);

                            this.lines = Array.isArray(d.lines) ? d.lines : [];
                            this.paidNow = d.paidNow ?? '';
                            this.deposits = Array.isArray(d.deposits) ? d.deposits : [];
                            this.carrierId = d.carrierId ?? '';
                            this.carrierName = d.carrierName ?? '';
                            this.transportCost = d.transportCost ?? '';
                            this.vehicleNo = d.vehicleNo ?? '';
                            this.driverName = d.driverName ?? '';
                            this.nextKey = d.nextKey ?? (this.lines.length + 1);

                            /* ⚠️ গতবারের দরগুলো আবার আনতে হয়। সরবরাহকারীর
                               ঘরটা কোড দিয়ে বসানো হয়েছে, তাই `change` ঘটে
                               না — আর তখন কার্টে সারি আছে অথচ "গতবার কত"
                               কলামটা ফাঁকা থাকত, ঠিক দরাদরির মুহূর্তে। */
                            if (d.supplierId) this.supplierPicked(d.supplierId);
                        } catch (e) {
                            // ভাঙা খসড়া — ফেরানোর চেয়ে বাদ দেওয়াই নিরাপদ
                        }
                    },

                    /* ── সাবমিটে খসড়া মোছা হয় না, সরিয়ে রাখা হয় ──────────

                       ⚠️ বিক্রয়ে এটা মালিকের অভিযোগে শেখা (৪ সেপ্টেম্বর
                       ২০২৬): *"এই warning-এ আমার সব entry হারিয়ে গেল"*।

                       সাবমিটে খসড়া মুছে দিলে, আর তার পরেই সার্ভার বিলটা
                       ফিরিয়ে দিলে (মজুদ কম, নম্বর নেওয়া, যাচাই — যা-ই হোক)
                       পাতাটা **খালি হয়ে ফিরত**: বিশ লাইনের কার্ট,
                       সরবরাহকারী, জমা — সব শেষ। ⓘ তাই খসড়াটা `.pending`-এ
                       সরে যায়, আর পাতাটা ভুলের বার্তাসহ ফিরলে ওটা নিজে
                       থেকেই ফিরে আসে, প্রশ্ন ছাড়াই। */
                    parkDraft() {
                        try {
                            const raw = localStorage.getItem(this.draftKey);

                            if (raw) {
                                localStorage.setItem(this.draftKey + '.pending', raw);
                                localStorage.removeItem(this.draftKey);
                            }
                        } catch (e) {
                            // সরাতে না পারলে খসড়াটা যেখানে আছে সেখানেই থাক
                        }
                    },

                    get visible() {
                        const t = this.term.trim().toLowerCase();

                        /*
                         * ⛔ আগে এখানে খালি লেখায় **খালি তালিকা** ফিরত, তাই
                         * খোঁজার চিহ্নে চেপে কিছুই হত না — মালিক ঠিকই
                         * বলেছেন *"Product aseo na"*।
                         *
                         * ⭐ এখন চিহ্নে চাপলে (`browsing`) পুরো তালিকা,
                         * আর টাইপ করলে ছাঁকা — বিক্রয়ের কাউন্টারের নিয়ম।
                         */
                        if (t === '') return this.browsing ? this.catalogue.slice(0, 30) : [];

                        return this.catalogue.filter(p =>
                            p.name.toLowerCase().includes(t) || p.code.toLowerCase().includes(t)
                        ).slice(0, 30);
                    },

                    blankEntry() {
                        return {
                            qty: '', free_qty: '', rate: '', discount: '', tax: '',
                            markup: '', margin: '', sales_price: '', anchor: '',

                            /* ── প্যাকের দুইটা ঘর ─────────────────────────
                               ফাঁকা মানে পণ্যের নিজের একক, অর্থাৎ আগের মতোই।
                               ⓘ ফ্রি-রটা আলাদা: মিল কার্টনে বেচে, ফ্রি দেয়
                               পিসে। */
                            unit_id: '', free_unit_id: '',

                            /* ছাড়টা কীভাবে লেখা হচ্ছে — টাকায় নাকি শতাংশে।
                               ⚠️ **সংরক্ষিত হয় সবসময় টাকায়**; এটা কেবল লেখার
                               ভঙ্গি। ⓘ ডিফল্ট টাকা, কারণ সরবরাহকারীর কাগজে
                               ছাড়টা টাকাতেই ছাপা থাকে। */
                            discount_mode: 'amount',

                            /* ভ্যাট কোন নিয়মে — pick()-এ পণ্য দেখে বসে */
                            vat_mode: 'amount',
                        };
                    },

                    init() {
                        this.entry = this.blankEntry();

                        /* ⚠️ খসড়া খোঁজা **সবার আগে** — নাহলে নিচের
                           `loadLastRates` খসড়ার সরবরাহকারীকে নয়, পর্দার
                           পুরনো মানটাকে ধরে বসত। */
                        this.lookForDraft();

                        /* যাচাই ব্যর্থ হয়ে পাতাটা ফিরে এলে সরবরাহকারী আগে
                           থেকেই বাছা থাকে, অথচ `change` আর ঘটে না। তখন
                           গতবারের দরগুলো উধাও থাকত — ঠিক যখন মানুষটা ভুল
                           শুধরে আবার দেখছেন। */
                        const chosen = this.$root.querySelector('[name="supplier_id"]');

                        /* ⚠️ `supplierPicked`, `loadLastRates` নয় — কার্ডের
                           চারটা লাইন (মোবাইল · ঠিকানা · স্বত্বাধিকারী)
                           `supplierId` ধরে বসে। ⓘ কেবল দরগুলো আনলে যাচাই
                           ব্যর্থ হয়ে ফেরা পাতায় সরবরাহকারী বাছা থাকত অথচ
                           কার্ডটা ফাঁকা — আর মানুষটা ভাবতেন বাছাই হারিয়ে
                           গেছে। */
                        if (chosen && chosen.value) this.supplierPicked(chosen.value);

                        /* ⚠️ ডিফল্ট মেয়াদটা পাতা খোলার সময়েই তারিখ হয়ে বসে।
                           ⛔ না বসালে ঘরটা "৩ দিন" দেখাত অথচ `due_on` খালি
                           যেত — পর্দা এক কথা বলত, খাতা আরেক।

                           ⓘ অপশনগুলো সার্ভারে আঁকা, তাই এখানে আর কোনো
                           `$nextTick`-এর কসরত লাগে না — মানটা আগে থেকেই বসা। */
                        this.termPicked();
                    },

                    /**
                     * এই সরবরাহকারীর গতবারের দরগুলো আনা।
                     *
                     * ⚠️ ব্যর্থ হলে তালিকাটা **খালি** করা হয়, পুরনোটা রাখা
                     * হয় না। রেখে দিলে পর্দায় অন্য একজনের দর "এই
                     * সরবরাহকারীর গতবার" নামে বসে থাকত — চুপচাপ, আর ঠিক
                     * দরাদরির মুহূর্তে।
                     */
                    async loadLastRates(supplierId) {
                        const id = Number(supplierId) || 0;

                        this.supplierChosen = id > 0;
                        this.lastRates = {};

                        /* ⚠️ বকেয়াটাও এখানেই শূন্য হয়, দরগুলোর সাথে।
                           সরবরাহকারী বদলে পুরনো সংখ্যাটা রেখে দিলে পর্দায়
                           **অন্য একজনের বকেয়া** এই একজনের নামে বসে থাকত —
                           ঠিক যে ভুলটা দরের বেলায় উপরে ঠেকানো হয়েছে। */
                        this.previousDue = 0;

                        if (id <= 0) return;

                        try {
                            const res = await fetch(this.lastRatesUrl.replace(/0$/, String(id)), {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (! res.ok) return;

                            const payload = await res.json();

                            this.lastRates = payload.rates ?? {};
                            this.previousDue = Number(payload.due) || 0;
                        } catch (e) {
                            /* নীরবে ছেড়ে দেওয়া — সংখ্যাটা সুবিধার, বাধ্যতামূলক
                               নয়। ওটা না এলে ক্রয় থেমে যাওয়া অনেক বড় ক্ষতি। */
                        }
                    },

                    /** এই সারির পণ্যের গতবারের দর — না থাকলে null। */
                    lastRateFor(line) {
                        return this.lastRates[line.id] || null;
                    },

                    /** এই সারির সাথে একটা উপহার। */
                    addGift(line) {
                        if (! Array.isArray(line.gifts)) line.gifts = [];

                        line.gifts.push({
                            key: this.nextKey++,
                            product_id: '',
                            qty: '',
                            remarks: '',
                        });
                    },

                    pick(product) {
                        this.picked = product;
                        this.entry = this.blankEntry();
                        this.entry.qty = '1';

                        /*
                         * শেষ ক্রয়দর আর চলতি বিক্রয়মূল্য বসিয়ে দেওয়া হয়,
                         * কিন্তু নোঙর ফাঁকাই থাকে।
                         *
                         * নোঙর বসালে ঘরগুলো নিজে থেকেই একটা দর "বলত" যা
                         * কেউ বেছে নেয়নি, আর সেটাই সেভ হয়ে যেত। মানুষটা
                         * তিনটার একটায় হাত দিলে তবেই অঙ্ক শুরু হয়।
                         */
                        if (product.last_rate > 0) this.entry.rate = String(product.last_rate);
                        if (product.sales_price > 0) this.entry.sales_price = String(product.sales_price);

                        /*
                         * ── ভ্যাটের ধরনটা পণ্য দেখে বসে ───────────────────
                         *
                         * পণ্যের নিজের হার বসানো থাকলে "পণ্য অনুযায়ী", নাহলে
                         * "অঙ্ক লিখুন"।
                         *
                         * ⚠️ কেন সবসময় "পণ্য অনুযায়ী" নয়: হার বসানো না থাকলে
                         * ওই ধরনটা সবসময় ০ দিত, আর ঘরটা হত একটা **নীরব
                         * শূন্য** — মানুষ ভাবতেন ভ্যাট ধরা হয়েছে, অথচ হয়নি।
                         *
                         * ⓘ আর কেন সবসময় "অঙ্ক লিখুন" নয়: তাহলে যে পণ্যের হার
                         * সত্যিই বসানো আছে তার ভ্যাটও প্রতিবার হাতে লিখতে হত,
                         * আর একদিন কেউ ভুল লিখতেন। ⭐ হারটা যাঁর আছে, তাঁর
                         * ব্যবস্থাটাই কাজে লাগে।
                         */
                        this.entry.vat_mode = (Number(product.tax_rate) || 0) > 0 ? 'product' : 'amount';

                        this.term = '';
                    },

                    /** সরবরাহকারী বাছা হলো — দর, বকেয়া আর কার্ডের লাইনগুলো। */
                    supplierPicked(id) {
                        this.supplierId = String(id || '');
                        this.loadLastRates(id);
                    },

                    /** কার্ডের চারটা লাইনের উৎস — বাছা সরবরাহকারীর সারি। */
                    get supplier() {
                        return this.suppliers.find(s => s.id === this.supplierId) || null;
                    },

                    /** টাইপের সাথে ছাঁকা — খালি হলে সবাই। */
                    get supplierMatches() {
                        const t = this.supplierTerm.trim().toLowerCase();

                        if (t === '') return this.suppliers.slice(0, 40);

                        return this.suppliers.filter(x =>
                            (x.name || '').toLowerCase().includes(t)
                            || (x.phone || '').toLowerCase().includes(t)
                        ).slice(0, 40);
                    },

                    chooseSupplier(id) {
                        this.supplierPicked(id);
                        this.supplierId = String(id);
                        this.supplierTerm = '';
                        this.supplierPickerOpen = false;
                    },

                    /**
                     * ⭐ 🎁 GIFT ITEM — সারিটা কার্টে বসিয়ে তার সাথেই উপহার।
                     *
                     * ── কেন দুইটা কাজ এক বোতামে ─────────────────────────
                     * উপহার সবসময় **কোনো একটা পণ্যের বিপরীতে** বসে
                     * (`against_product_id`), আর জোড়াটা ছাড়া *"সাবানের আসল
                     * ক্রয়দর কত পড়ল"* হিসাবটাই করা যায় না। ⛔ কিন্তু চলতি
                     * এন্ট্রিটা এখনো কার্টে নেই, তাই জোড়া লাগানোর মতো কিছুই
                     * নেই — বোতামটা তাই আগে সারিটা বসায়, তারপর তার নিচে
                     * উপহারের ঘর খোলে।
                     *
                     * ⓘ কার্টের সারিতে "উপহার যোগ" বোতামটা আগের মতোই আছে —
                     * পরে মনে পড়লে ওখান থেকেও যোগ করা যায়।
                     */
                    giftForThisLine() {
                        if (! this.picked) return;

                        this.addToCart();

                        const line = this.lines[this.lines.length - 1];

                        if (line) this.addGift(line);
                    },

                    /** এককের নাম — আইডি না থাকলে পণ্যের নিজেরটা। */
                    unitName(unitId, line) {
                        const options = this.packs[line.id] ?? [];
                        const found = options.find(u => String(u.id) === String(unitId));

                        return found ? found.label : (line.unit || '');
                    },

                    /* ── দরের তালিকা ────────────────────────────────────
                       ⓘ `lastRates` ইতিমধ্যেই সরবরাহকারী বাছার মুহূর্তে চলে
                       আসে — তালিকাটা নতুন কোনো ডাক করে না, কেবল যা আছে তা
                       পড়ার মতো করে সাজায়। */
                    openChart() {
                        this.chartOpen = ! this.chartOpen;
                    },

                    get chartRows() {
                        return Object.entries(this.lastRates).map(([id, row]) => {
                            const product = this.catalogue.find(p => String(p.id) === String(id));

                            return {
                                id,
                                name: product?.name ?? '',
                                rate: row.rate,
                                on: row.on,
                            };
                        }).filter(row => row.name !== '');
                    },

                    /** তালিকা থেকে সরাসরি এন্ট্রিতে — গতবারের দর বসানো অবস্থায়। */
                    pickFromChart(row) {
                        const product = this.catalogue.find(p => String(p.id) === String(row.id));

                        if (! product) return;

                        this.pick(product);
                        this.entry.rate = String(row.rate);
                        this.chartOpen = false;
                    },


                    pickFirst() {
                        const first = this.visible[0];
                        if (first) this.pick(first);
                    },

                    /** তিনটা ঘরের একটায় লেখা হল — বাকিগুলো নতুন করে বসে। */
                    priced(edited) {
                        Object.assign(this.entry, window.abos.reprice(this.entry, edited));
                    },

                    // ── চলতি লাইনের অঙ্ক ────────────────────────────────

                    /** এই পণ্যের প্যাকের তালিকা — না থাকলে খালি। */
                    get unitOptions() {
                        return this.packs[this.picked?.id] ?? [];
                    },

                    get entryBase() {
                        return (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
                    },

                    /**
                     * ছাড় — সবসময় টাকায়।
                     *
                     * ── কেন শতাংশটা এখানেই টাকা হয়ে যায় ─────────────────
                     * `pur_bill_lines.discount` একটা টাকার কলাম, আর সার্ভার
                     * সরাসরি বিয়োগ করে ([[CalculatesLineTotals::lineFigures]])।
                     * ⛔ একই কলামে কখনো টাকা কখনো শতাংশ বসলে একদিন কেউ ৫
                     * লিখতেন আর ৫ টাকা বাদ যেত, যেখানে তিনি ৫% বুঝিয়েছিলেন —
                     * আর কোনো ত্রুটি হত না, কেবল সংখ্যাটা ভুল হত।
                     *
                     * ⭐ শতাংশটা হারায় না, রূপান্তরিত হয় — আর পর্দায় টাকার
                     * অঙ্কটা পাশেই দেখা যায়, অর্থাৎ **যা দেখা যাচ্ছে সেটাই
                     * সেভ হয়**।
                     */
                    get entryDiscount() {
                        const typed = Number(this.entry.discount) || 0;

                        if (this.entry.discount_mode !== 'percent') return typed;

                        return this.entryBase * typed / 100;
                    },

                    get discountOverLine() {
                        return this.entryDiscount > this.entryBase;
                    },

                    /**
                     * ভ্যাট — তিনটা ধরনের যেটা বাছা হয়েছে।
                     *
                     * ⚠️ অঙ্কটা সার্ভারের সূত্রেরই নকল ([[Tax::amountOn]]):
                     * ছাড়ের **পরের** টাকার উপর, আর দামের ভিতরের ভ্যাটে
                     * উল্টো হিসাব। ⓘ দুই জায়গায় দুই সূত্র হলে পর্দা এক
                     * সংখ্যা দেখাত আর খতিয়ানে আরেকটা বসত।
                     */
                    taxOn(net, mode, typed, product) {
                        if (! this.vatEnabled) return 0;
                        if (mode === 'none') return 0;
                        if (mode === 'amount') return Number(typed) || 0;

                        const rate = Number(product?.tax_rate) || 0;

                        if (rate <= 0) return 0;

                        /* দামের ভিতরে থাকলে মোট বাড়ে না — ১১৫-তে ১৫% মানে
                           ১১৫ − (১১৫ ÷ ১.১৫) = ১৫, ১১৫ × ০.১৫ নয়। */
                        return product?.tax_inclusive
                            ? net - (net / (1 + rate / 100))
                            : net * rate / 100;
                    },

                    get entryTax() {
                        return this.taxOn(
                            this.entryBase - this.entryDiscount,
                            this.entry.vat_mode,
                            this.entry.tax,
                            this.picked,
                        );
                    },

                    get entryNet() {
                        const net = this.entryBase - this.entryDiscount;

                        /* ভিতরের ভ্যাটে মোট বাড়ে না; দরেই ওটা আছে। */
                        return this.picked?.tax_inclusive && this.entry.vat_mode === 'product'
                            ? net
                            : net + this.entryTax;
                    },

                    get entryTotalQty() {
                        return (Number(this.entry.qty) || 0) + (Number(this.entry.free_qty) || 0);
                    },

                    toggleDiscountMode() {
                        this.entry.discount_mode =
                            this.entry.discount_mode === 'percent' ? 'amount' : 'percent';
                    },

                    addToCart() {
                        if (! this.picked) return;

                        this.lines.push({
                            key: this.nextKey++,
                            id: this.picked.id,
                            name: this.picked.name,
                            qty: this.entry.qty || '1',
                            free_qty: this.entry.free_qty || '',
                            rate: this.entry.rate || '0',

                            /* ⭐ ছাড়টা **টাকায়** বসে, শতাংশে নয় — যা পর্দায়
                               দেখা যাচ্ছিল ঠিক সেটাই। ⓘ শতাংশটা এন্ট্রির
                               ভঙ্গি ছিল, সারির তথ্য নয়। */
                            discount: this.entryDiscount ? String(this.entryDiscount.toFixed(4)) : '',

                            /* ভ্যাটের ধরনটা সারির সাথে যায়: সারিটা কার্টে
                               বসার পরেও পর্দা জানে ঘরটা দেখাতে হবে নাকি
                               সার্ভারকে কষতে দিতে হবে। */
                            vat_mode: this.entry.vat_mode,
                            tax: this.entry.tax || '',

                            /* প্যাকের দুইটা ঘর — না বসালে সারিটা কার্টে
                               গিয়ে একক হারাত, আর "১ বাক্স" পিস হয়ে যেত। */
                            unit_id: this.entry.unit_id || '',
                            free_unit_id: this.entry.free_unit_id || '',
                            unit: this.picked.unit || '',
                            tax_rate: this.picked.tax_rate || 0,
                            tax_inclusive: this.picked.tax_inclusive || false,

                            sales_price: this.entry.sales_price || '',

                            /* উপহারের তালিকা সারির সাথেই জন্মায়, চাহিদামতো
                               নয় — `line.gifts` না থাকলে Alpine-এর x-for
                               undefined-এ হোঁচট খেত, আর হ্যান্ডলারটা মাঝপথে
                               থেমে যেত। */
                            gifts: [],
                        });

                        this.clearEntry();

                        /* ?. — একটা ঘর খুঁজে না পাওয়া কখনো পুরো পর্দা
                           থামানোর কারণ হওয়া উচিত নয়। এখানে ঠিক তা-ই
                           হয়েছিল: focus() এররে Alpine থেমে যেত, কার্টের
                           ঘরগুলোর name বাঁধা হত না, আর সাবমিটে সার্ভার
                           কোনো লাইনই পেত না। */
                        this.$nextTick(() => this.$refs.search?.focus());
                    },

                    clearEntry() {
                        this.picked = null;
                        this.entry = this.blankEntry();
                        this.term = '';
                    },

                    clearAll() {
                        this.lines = [];
                        this.paidNow = '';

                        /* ⚠️ জমাগুলোও — নাহলে নতুন বিলে আগের বিলের টাকা
                           বসে থাকত, আর কেউ সেটা খেয়াল না করে নিশ্চিত করে
                           ফেলতেন। */
                        this.deposits = [];
                        this.depositDraft = {
                            methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '',
                        };

                        this.carrierId = '';
                        this.carrierName = '';
                        this.transportCost = '';
                        this.vehicleNo = '';
                        this.driverName = '';

                        /* ⚠️ তিনটা প্যানেলও বন্ধ হয় — খোলা রেখে দিলে নতুন
                           ক্রয়ের পর্দায় আগের সরবরাহকারীর দরের তালিকা খুলে
                           বসে থাকত, আর কেউ ওই দর ধরে দরাদরি করতেন। */
                        this.chartOpen = false;
                        this.depositOpen = false;
                        this.transportOpen = false;
                        this.noteOpen = false;
                        this.shipmentOpen = false;

                        this.clearEntry();
                    },

                    // ── কার্টের অঙ্ক ────────────────────────────────────

                    /* ⚠️ সারির ভ্যাটটা আর সরাসরি `line.tax` নয়।

                       সারিটা নিজের ধরন মনে রাখে, তাই "পণ্য অনুযায়ী" হলে
                       অঙ্কটা পণ্যের হার থেকে কষতে হয় — ঠিক যেভাবে সার্ভার
                       কষবে। ⛔ আগের মতো `line.tax` পড়লে ওই সারিগুলোর ভ্যাট
                       পর্দায় ০ দেখাত, অথচ খতিয়ানে বসত পুরো অঙ্ক, আর
                       "মোট দেয়" দুই জায়গায় দুই রকম হত। */
                    lineTax(line) {
                        const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);

                        return this.taxOn(base - (Number(line.discount) || 0), line.vat_mode, line.tax, line);
                    },

                    lineNet(line) {
                        const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);
                        const net = base - (Number(line.discount) || 0);

                        /* দামের ভিতরের ভ্যাটে মোট বাড়ে না; দরেই ওটা আছে। */
                        return line.tax_inclusive && line.vat_mode === 'product'
                            ? net
                            : net + this.lineTax(line);
                    },

                    /** ছবির `Total Qty` — কেনা আর ফ্রি একসাথে। */
                    lineTotalQty(line) {
                        return (Number(line.qty) || 0) + (Number(line.free_qty) || 0);
                    },

                    get subTotal() {
                        return this.lines.reduce(
                            (s, l) => s + (Number(l.qty) || 0) * (Number(l.rate) || 0) - (Number(l.discount) || 0), 0,
                        );
                    },

                    get taxTotal() {
                        if (! this.vatEnabled) return 0;

                        return this.lines.reduce((s, l) => s + this.lineTax(l), 0);
                    },

                    /**
                     * মন্তব্যের ঘরটা খুলে কার্সর ভিতরে।
                     *
                     * ⚠️ `$nextTick` ছাড়া `focus()` কিছুই করত না: ওই মুহূর্তে
                     * ঘরটা এখনো `x-show`-এর নিচে লুকানো, আর লুকানো ঘরে
                     * কার্সর বসে না। ⓘ বোতামটা তখন খুলত ঠিকই, কিন্তু
                     * লিখতে আরেকটা ক্লিক লাগত।
                     */
                    openNote() {
                        this.noteOpen = ! this.noteOpen;

                        if (this.noteOpen) this.$nextTick(() => this.$refs.note?.focus());
                    },

                    /** ছবির `Total Purchase Qnty` — কেবল কেনা, ফ্রি ছাড়া। */
                    get boughtQty() {
                        return this.lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
                    },

                    /** রসিদের ছকের "ফ্রি পাওয়া গেল" — কেবল পরিমাণ, টাকা নয়। */
                    get freeTotal() {
                        return this.lines.reduce((s, l) => s + (Number(l.free_qty) || 0), 0);
                    },

                    /* ⏳ খরচ ও রাউন্ডিং এখানে যোগ হবে — কিন্তু সেবা ও
                       ডাটাবেসের ঘর বসার পরেই, একসাথে। কারণটা কার্ডের
                       কমেন্টে লেখা: পর্দায় যোগ করে খতিয়ানে না বসালে
                       সংখ্যাটা নীরবে মিথ্যা হয়। */
                    get netPayable() {
                        return this.subTotal + this.taxTotal;
                    },

                    /* এই বিলে কত বাকি — আগে এটার নাম ছিল `balanceDue`।
                       ⓘ নামটা বদলেছে কারণ পর্দায় এখন **দুইটা** বকেয়া:
                       এই বিলেরটা, আর সরবরাহকারীকে মোট। এক নামে দুই অর্থ
                       থাকলে দুইজন মানুষ দুইটা উত্তর পান। */
                    get invoiceDue() {
                        return this.balanceDue;
                    },

                    /*
                     * ⭐ সরবরাহকারীকে মোট কত — ছবির `DUE`।
                     *
                     * `DUE = এই বিলে বাকি + আগের বকেয়া`
                     *
                     * ⚠️ আগের বকেয়া ঋণাত্মক হতে পারে — অগ্রিম দেওয়া
                     * থাকলে। ⓘ তখন যোগফলটা এমনিতেই কমে, আর সেটাই ঠিক:
                     * অগ্রিম টাকাটা এই বিলের দায় মেটায়।
                     */
                    get totalDue() {
                        return this.invoiceDue + this.previousDue;
                    },


                    /* ⚠️ দুইটাই বাদ যায় — জমার সারিগুলো **আর** পুরনো একক
                       ঘরটা। ⓘ পর্দায় আজ কেবল সারিগুলোই ভরা হয়, কিন্তু
                       `paidNow` এখনো কোডে আছে (API ও ইমপোর্টের জন্য), আর
                       যোগফল থেকে বাদ না দিলে কোনো একদিন বকেয়া ভুল দেখাত। */
                    get balanceDue() {
                        const paid = this.paidTotal + (Number(this.paidNow) || 0);
                        const due = this.netPayable - paid;

                        return due > 0 ? due : 0;
                    },

                    get totalQty() {
                        return this.lines.reduce(
                            (s, l) => s + (Number(l.qty) || 0) + (Number(l.free_qty) || 0), 0,
                        );
                    },

                    /*
                     * পাঠানোর আগে দুইটা প্রশ্ন।
                     *
                     * বেশি টাকা দেওয়া মানে সরবরাহকারীর কাছে অগ্রিম জমা —
                     * সেটা বৈধ, কিন্তু বেশিরভাগ সময় ওটা টাইপো। আর দুইবার
                     * পাঠানো মানে দুইটা চালান, দুইবার মাল।
                     */
                    /* ভাড়া আছে অথচ কে আনল বলা নেই — সার্ভারও এটাই আটকায়,
                       কিন্তু পর্দায় আগে বলাটাই ভদ্রতা: সাবমিটের পর ভুল
                       দেখানো মানে বিশ লাইন টাইপ করার পর জানা। */
                    get transportNeedsWho() {
                        return Number(this.transportCost) > 0
                            && ! this.carrierId
                            && this.carrierName.trim() === '';
                    },

                    /** ধরনটার প্রথম অংশ — `credit:7` থেকে `credit`। */
                    get termKind() {
                        return String(this.termChoice || '').split(':')[0];
                    },

                    /** কোম্পানির ছকে দেখানোর জন্য — `2026-09-20` → `20-09-2026`। */
                    get dueOnShown() {
                        const parts = String(this.dueOn || '').split('-');

                        return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : '';
                    },

                    /**
                     * শর্তটাকে একটা তারিখে অনুবাদ করা।
                     *
                     * ⚠️ গোনাটা **বিলের তারিখ থেকে**, আজ থেকে নয় — পুরনো
                     * তারিখের বিল তোলা হলে পরিশোধের তারিখও পিছিয়ে বসে।
                     *
                     * ⓘ `a fixed date`-এ কিছু গোনা হয় না: তারিখটা মানুষটা
                     * নিজে বাছেন, আর নিচের লাইনটাই তখন লেখার ঘর।
                     */
                    termPicked() {
                        const [kind, days] = String(this.termChoice || '').split(':');

                        if (kind === 'fixed') {
                            /* ⚠️ আগের হিসাব করা তারিখটা মুছে দেওয়া হয়,
                               নাহলে মানুষটা তারিখের ঘরে একটা **আগের
                               হিসাবের** তারিখ বসা দেখতেন আর ভাবতেন
                               তিনিই বসিয়েছেন। */
                            this.dueOn = '';

                            return;
                        }

                        const from = new Date(this.boxValue('trx_date') || Date.now());

                        if (Number.isNaN(from.getTime())) {
                            this.dueOn = '';

                            return;
                        }

                        if (kind === 'month_end') {
                            /*
                             * ⭐ বিলের **মাসের** শেষ দিন — মালিকের
                             * `Cr. Upto Closing date`।
                             *
                             * ⚠️ `new Date(y, m + 1, 0)` মানে "পরের মাসের
                             * শূন্যতম দিন", অর্থাৎ চলতি মাসের শেষ দিন।
                             * ⛔ `addDays(30)` দিয়ে গুনলে ফেব্রুয়ারিতে
                             * মার্চে গিয়ে পড়ত, আর লিপ ইয়ারে আরও একদিন।
                             *
                             * ⓘ মাসটা **বিলের তারিখের** মাস, আজকের নয় —
                             * পুরনো তারিখের বিল বসালে ঐ মাসের শেষ।
                             */
                            this.dueOn = this.isoDate(
                                new Date(from.getFullYear(), from.getMonth() + 1, 0)
                            );

                            return;
                        }

                        if (kind === 'cash') {
                            this.dueOn = this.isoDate(from);

                            return;
                        }

                        from.setDate(from.getDate() + (Number(days) || 0));
                        this.dueOn = this.isoDate(from);
                    },

                    /**
                     * তারিখটা `YYYY-MM-DD` হয়ে।
                     *
                     * ⚠️ `toISOString()` নয় — ওটা UTC-তে নামায়, আর
                     * বাংলাদেশে সন্ধ্যার পর তারিখটা একদিন পিছিয়ে যেত।
                     */
                    isoDate(d) {
                        return [
                            d.getFullYear(),
                            String(d.getMonth() + 1).padStart(2, '0'),
                            String(d.getDate()).padStart(2, '0'),
                        ].join('-');
                    },

                    /** বাছা উপায়টার সারি — id ধরে। */
                    get depositMethod() {
                        return this.depositMethods.find(
                            m => String(m.id) === String(this.depositDraft.methodId)
                        ) || null;
                    },

                    get depositNeedsReference() {
                        return !! this.depositMethod?.needsReference;
                    },

                    /* ── কোন উপায়ে কোন খাত ─────────────────────────────

                       ⚠️ ক্রয়ের চেক বিক্রয়ের চেকের উল্টো, আর নকল করলে ভুল
                       হত। বিক্রয়ে চেক **পাওয়া** যায়, তাই ওদিকে ১১০৪ (হাতে
                       আসা চেক) — ব্যাংক নয়, কারণ পাওয়া চেক এখনো টাকা নয়।
                       ক্রয়ে চেক **দেওয়া** হয়, আর তখন ব্যাংকের খাতাই কমে।

                       ⛔ হিসাবের দিক থেকে ইস্যু করা চেকের আসল ঘর `2115`
                       (ইস্যু করা চেক, একটা দায়) — পাশ হওয়া পর্যন্ত ব্যাংক
                       কমার কথা নয়। ⓘ কিন্তু আজকের [[PaymentService::confirm]]
                       যে খাত বাছা হয় সেটাই কমায়, `instrument` যা-ই হোক —
                       অর্থাৎ ফাঁকটা এই প্যানেলের আগেও ছিল, আর চেক-রেজিস্টার
                       ([[ChequeService]]) ক্রয়ের পরিশোধে যুক্ত হলে তবেই
                       সারবে। **এখানে লিখে রাখা হলো, যাতে দিনটা এলে জায়গাটা
                       খুঁজতে না হয়।**

                       ⚠️ উপায় না বাছা পর্যন্ত **একটাও খাত নয়** — খালি
                       তালিকা আর "সব খাত" দুইটা আলাদা অবস্থা। সব দেখালে কেউ
                       নগদের খাতে চেকের টাকা বসিয়ে দিতেন। */
                    depositKindParents: {
                        cash: @js(App\Modules\Accounts\Services\StandardChart::CASH_IN_HAND),
                        bank: @js(App\Modules\Accounts\Services\StandardChart::BANK),
                        mfs: @js(App\Modules\Accounts\Services\StandardChart::MOBILE_MONEY),
                        cheque: @js(App\Modules\Accounts\Services\StandardChart::BANK),
                    },

                    get depositAccounts() {
                        if (! this.depositDraft.methodId) return [];

                        const parent = this.depositKindParents[this.depositMethod?.kind];

                        /* ⓘ উপায়ের `kind` অচেনা হলে ছাঁকনিটা চুপ করে থাকে,
                           সব খাত দেখায় — ভুল কনফিগে পর্দাটা অচল হওয়ার চেয়ে
                           সেটা ভালো। */
                        if (! parent) return this.moneyAccounts;

                        return this.moneyAccounts.filter(a => a.parent === parent);
                    },

                    /** উপায় বাছার সাথে সাথে তার নিজের খাতটা বসে যায়। */
                    methodPicked() {
                        this.depositDraft.accountId = this.depositMethod?.accountId || '';
                    },

                    get depositReady() {
                        return this.depositDraft.methodId !== ''
                            && this.depositDraft.accountId !== ''
                            && Number(this.depositDraft.amount) > 0;
                    },

                    addDeposit() {
                        if (! this.depositReady) return;

                        this.deposits.push({ ...this.depositDraft });

                        this.depositDraft = {
                            methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '',
                        };
                    },

                    dropDeposit(index) {
                        this.deposits.splice(index, 1);
                    },

                    /** তালিকায় নাম দেখানোর জন্য — id নয়। */
                    methodName(id) {
                        return this.depositMethods.find(
                            m => String(m.id) === String(id)
                        )?.label || '';
                    },

                    /* ⓘ সার্ভারও এই যোগটা নিজে করে — পর্দার সংখ্যা বিশ্বাস
                       করে খাতায় কিছু বসানো হয় না। */
                    get paidTotal() {
                        return this.deposits.reduce(
                            (sum, row) => sum + (Number(row.amount) || 0), 0
                        );
                    },

                    guard(event) {
                        if (this.busy || this.lines.length === 0) {
                            event.preventDefault();

                            return;
                        }

                        const paid = this.paidTotal + (Number(this.paidNow) || 0);

                        if (paid > this.netPayable
                            && ! window.confirm(@js(__('purchase::message.paid_more_confirm')))) {
                            event.preventDefault();

                            return;
                        }

                        /* ⚠️ ভাড়া লিখে কে আনল না বললে সার্ভার ফিরিয়ে দেবে।
                           এখানে আগেই থামানো হয়, নাহলে পুরো ফর্মটা গিয়ে
                           ভুলসহ ফিরত — আর সেটা কাউন্টারে এক মিনিটের ক্ষতি। */
                        if (this.transportNeedsWho) {
                            event.preventDefault();

                            return;
                        }

                        /* ⚠️ মোছা নয়, সরিয়ে রাখা — সার্ভার ফিরিয়ে দিলে
                           পাতাটা যেন খালি হয়ে না ফেরে। */
                        this.parkDraft();

                        this.busy = true;
                    },

                    money(v) {
                        return Number(v || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    qty(v) {
                        return String(Number(v || 0));
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
