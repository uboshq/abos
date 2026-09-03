{{--
    সরাসরি বিক্রয় — নমুনার হুবহু বিন্যাস।

        উপরে সরু স্ট্রিপ   তারিখ · বিল নম্বর · মেয়াদ · DO · গুদাম
        উপরের সারি        স্ট্রিপ · "এই লাইন" · পণ্যের ছবি — এক সারিতে
        এন্ট্রি এলাকা       পণ্য খোঁজা ও লাইনের ঘরগুলো, পুরো প্রস্থে
        কার্ট              SL# থেকে টাকা পর্যন্ত ন'টা কলাম
        ডান পাশের প্যানেল   ক্রেতা, এই চালান, দিতে হবে, পার্টির বকেয়া, গোনা

    ── কেন ডান পাশটা আলাদা কলাম, নিচে নয় ────────────────────────────────
    টাকার অঙ্কগুলো সবসময় চোখের সামনে থাকতে হয়, কার্ট যত লম্বাই হোক।
    নিচে রাখলে দশ লাইনের বিলে Confirm বোতামটা ভাঁজের নিচে চলে যেত, আর
    কাউন্টারের লোককে স্ক্রল করে খুঁজতে হত — যে কাজটা করতে তিনি এসেছেন।

    ── "এই লাইন" প্যানেলটা কেন ────────────────────────────────────────
    কার্টে যোগ করার আগেই লাইনটার টাকা কত হচ্ছে সেটা দেখা যায়। না দেখালে
    ভুল দর বা ভুল ছাড় ধরা পড়ত কার্টে যোগ করার পরে, আর তখন সারিটা মুছে
    আবার লিখতে হত।
--}}
@php
    $vatEnabled = $show['vat'] ?? true;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.direct') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

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

    <form method="POST" action="{{ route('sales.direct.store') }}"
          x-data="directSale({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($customerTerms) }}, {{ $walkinId }}, {{ $vatEnabled ? 'true' : 'false' }}, {{ Illuminate\Support\Js::from($packs) }})"
          @bulk-applied.window="absorbBulk($event.detail.rows)"

          {{--
              ── অসমাপ্ত চালান ধরে রাখা ─────────────────────────────────

              `x-init` পাতা খোলার সময় দেখে খসড়া আছে কিনা — **ফেরায় না**,
              কেবল উপরে প্রস্তাব দেখায়।

              `x-effect` প্রতিবার কিছু বদলালেই লিখে রাখে। Alpine নিজেই
              বুঝে নেয় কোন কোন মান পড়া হয়েছে, তাই আলাদা করে watcher
              লিখতে হয় না — আর নতুন একটা ঘর যোগ করলে সেটাও **নিজে থেকেই**
              খসড়ায় ঢোকে, কেউ ভুলে গেলেও।

              ⚠️ `@submit`-এ খসড়া মুছে যায়। **কারণটা ওজন করে নেওয়া:**
              সফল বিক্রির পরে খসড়া থেকে গেলে **প্রতিটা বিক্রির পরেই**
              "ফিরিয়ে আনব?" প্রশ্নটা আসত — আর দিনে পঞ্চাশবার "বাদ দিন"
              চাপতে চাপতে একদিন কেউ **সত্যিকারের একটা খসড়া** বাদ দিয়ে
              ফেলতেন। ⓘ দাম: সার্ভার যদি চালানটা ফিরিয়ে দেয়, কার্টটা
              যায় — কিন্তু সেটা আজও যেত, এই বদলে নতুন কিছু হারায়নি।
          --}}
          x-init="lookForDraft()"
          x-effect="saveDraft()"
          @submit="dropDraft()"

          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{--
            ── "একটা অসমাপ্ত চালান পড়ে আছে" ───────────────────────────────

            ⚠️ প্রস্তাব, নিজে থেকে ফেরানো নয় — আর পার্থক্যটা টাকার।

            নিজে থেকে ফিরিয়ে আনলে কেউ নতুন বিক্রি শুরু করতে এসে **আগের
            অসমাপ্ত চালানটা না বুঝেই পেয়ে যেতেন**, তার উপরে নতুন লাইন যোগ
            করে নিশ্চিত করে ফেলতেন — **ভুল ক্রেতার নামে ভুল মাল**।

            তাই দুইটা স্পষ্ট বোতাম, আর তারিখ-সময়সহ — কোন সময়ের খসড়া তা
            না জানলে ফেরানো কি বাদ দেওয়া, কোনোটাই নিরাপদ সিদ্ধান্ত নয়।
        --}}
        <div x-show="draftFound" x-cloak
             class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                    border-(--color-warning) bg-(--color-badge-pending-bg) p-3 xl:col-span-2">
            <span class="text-sm font-semibold text-(--color-badge-pending-ink)">
                {{ __('sales::message.draft_found') }}
            </span>

            <span class="num text-2xs text-(--color-ink-muted)" x-text="draftAt"></span>

            <button type="button" @click="restoreDraft()"
                    class="ms-auto rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5
                           text-xs font-semibold text-white">
                {{ __('sales::action.draft_restore') }}
            </button>

            <button type="button" @click="dropDraft()"
                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">
                {{ __('sales::action.draft_discard') }}
            </button>
        </div>

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="min-w-0 space-y-3">
            {{--
                ── কাগজের পরিচয় — দুইটা বাক্স, পাশাপাশি, এক সারিতে ─────────

                ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ───────────────────────
                *"Customar ei linei alada box koro — tahole customer details
                er sathe baki line gulo mota hobe na"*।

                ⚠️ **এই কারণটা আমি নিজে ধরতে পারিনি, আর ওটাই আসল কথা।**
                আগের ধাপে দুইটা এক বাক্সে ঢোকানো হয়েছিল। এক বাক্স মানে
                এক উচ্চতা: ক্রেতার তিন সারি (নাম · এলাকা+ফোন · ঠিকানা)
                বাক্সটাকে উঁচু করত, আর **তারিখ-নম্বর-মেয়াদ-DO-র পাশে বিশাল
                ফাঁকা জায়গা তৈরি হত** — ঘরগুলো "মোটা" দেখাত।

                এখন দুইটা আলাদা `<section>`, একই সারিতে `flex` দিয়ে।
                `items-start` বলে দেয় **যার যতটুকু উচ্চতা দরকার সে ততটুকুই
                নেবে** — একজনের লম্বা পরিচয় অন্যজনের ঘরগুলোকে টানবে না।

                ── আজকের চার ধাপ, যাতে কেউ পিছিয়ে না যায় ───────────────────
                সকাল: ক্রেতা ডান প্যানেল → বাঁ স্ট্রিপে।
                দুপুর: স্ট্রিপ থেকেও উপরে, নিজের বাক্সে, পুরো প্রস্থে।
                বিকাল: দুইটা বাক্স এক করা হলো (এক সারি, এক বর্ডার)।
                সন্ধ্যা: **আবার দুইটা — কিন্তু পাশাপাশি, উপর-নিচ নয়।**

                ⓘ পার্থক্যটা সূক্ষ্ম কিন্তু আসল: সমস্যাটা "দুইটা বাক্স" ছিল না,
                ছিল **দুইটা বাক্স একটার নিচে আরেকটা**। পাশাপাশি বসলে দুইটা
                বাক্স উচ্চতাও বাঁচায়, আর প্রত্যেকে নিজের মাপে থাকে।

                ⚠️ দুইটাই `<form>`-এর ভেতরে থাকতেই হবে — লুকানো
                `customer_id` আর `warehouse_id` এদের ভেতরে, আর বাইরে গেলে
                চালান ক্রেতা বা গুদাম ছাড়াই সেভ হবে, **কোনো ভুলবার্তা ছাড়াই**।
            --}}
            {{--
                ── ⚠️ এই ছকটা নিচের ছকের হুবহু নকল, আর সেটাই এর একমাত্র কাজ ──

                ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ───────────────────────
                লাল কলম দিয়ে প্রতিটা বাক্সের সীমা এঁকে: *"box gulo mark kora
                ache — dekho konta kon porjonto zabe"*।

                মেপে দেখা গেল উপরের সারির ভাগটা নিচের সারির ভাগের সাথে
                **মেলে না**: কাগজের বাক্স ৬২০px-এ শেষ হত, অথচ ঠিক তার নিচের
                এন্ট্রি বাক্স ৭৬৫px-এ। **দুইটা খাড়া রেখা, ১৪৫px দূরে** —
                চোখে ওটা এলোমেলো দেখায়, আর কারণটা ধরা কঠিন।

                ── কেন মিলত না ─────────────────────────────────────────────
                এটা ছিল `flex` + `basis`, আর নিচেরটা `grid` — অর্থাৎ উপরের
                ভাগটা **জিনিসের মাপ দেখে** ঠিক হত, নিচেরটা **কলামের মাপ
                দেখে**। দুইটা আলাদা নিয়ম, তাই মেলার কোনো কারণই ছিল না।

                ⚠️ **তাই এখানে ছকের সংজ্ঞাটা নিচেরটার সাথে অক্ষরে অক্ষরে
                এক** — `lg:grid-cols-[1fr_11rem]` আর
                `2xl:grid-cols-[1fr_13rem_9rem]`। **একটা বদলালে অন্যটাও
                বদলাতে হবে**, নইলে রেখা দুইটা আবার আলাদা হয়ে যাবে।

                ক্রেতার বাক্স `lg:col-span-2` — অর্থাৎ সে নিচের "এই লাইন"
                আর ছবির ঘর, **দুইটা কলাম মিলিয়ে ততটাই** চওড়া। আর সরু
                পর্দায় (মাত্র দুই কলাম) ওটা নিজে থেকেই নিচের সারিতে পুরো
                প্রস্থ নিয়ে নামে — চেপে যাওয়ার বদলে।
            --}}
            <div class="grid gap-3 lg:grid-cols-[1fr_11rem] 2xl:grid-cols-[1fr_22.75rem] lg:items-start">

                {{-- বাক্স ১ · কাগজটা কী — তারিখ · নম্বর · মেয়াদ · DO --}}
            {{-- বাঁ কলাম: কাগজের পরিচয় → পণ্য খোঁজা → লাইনের ঘর → কার্ট

                 ⚠️ কাগজের বাক্সটা এই কলামের **ভেতরে**, আলাদা ছক-ঘরে নয়।

                 ── কেন (৩ সেপ্টেম্বর ২০২৬) ──────────────────────────────
                 আলাদা ঘরে থাকলে প্রথম সারির উচ্চতা ঠিক হত ডান পাশের "এই
                 লাইন" দেখে — আর ও দুইটার চেয়ে উঁচু। ফলে কাগজের বাক্সের
                 নিচে **~১৩০px ফাঁকা** পড়ে থাকত, আর পণ্য খোঁজার ঘরটা
                 অকারণে নিচে নেমে যেত।

                 এক কলামে সব থাকলে ওরা একটার পিঠে আরেকটা বসে, আর ডান
                 পাশের উচ্চতা কিছুই টানে না। --}}
            <div class="min-w-0 space-y-3 lg:col-start-1">
                <section data-boxed class="min-w-0 rounded-(--radius-card)
                                border-t-2 border-(--color-success) border-x border-b
                                border-(--color-border) bg-(--color-surface-card) p-2">
                    <div class="@container">
                        {{-- ধাপগুলো কনটেইনারের নিজের প্রস্থে, আর ধাপ তিনটা।

                             উপরে ওঠার পর স্ট্রিপটা আর পুরো প্রস্থ পায় না —
                             সবুজ প্যানেল আর ছবির ঘর পাশে বসেছে, তাই এই
                             কলামটা ~৩০০px। পুরনো `@xl` (৩৬rem) ওখানে কোনোদিন
                             পৌঁছাত না, ফলে পাঁচটা ঘর দুই কলামে **তিন সারি**
                             হয়ে স্ট্রিপটা আগের চেয়ে উঁচু দেখাত — মালিক ঠিক
                             উল্টোটা চেয়েছিলেন।

                             ১৭rem-এ তিন কলাম (পাঁচটা ঘর দুই সারিতে), আর
                             জায়গা থাকলে ৩৪rem-এ পাঁচটাই এক সারিতে। --}}
                        {{-- একই কারণ, একই সারাই — উপরের ঘরগুলোও স্থির মাপে --}}
                        {{-- ── ঘরগুলো সারি জুড়ে সমানভাবে, ফাঁকা জায়গা ছাড়া ──────

                             ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────
                             *"ekhan kar faka jayga komaw"* — চারটা জায়গায় লাল
                             দাগ: তারিখের ভেতরে, মেয়াদের ভেতরে, DO-র ভেতরে, আর
                             DO-র পরে সারির শেষ পর্যন্ত।

                             ⚠️ সবচেয়ে বড়টা ছিল **শেষেরটা** — ঘরগুলোর মাপ বাঁধা
                             ছিল (`w-36`, `w-32`, `w-28`), তাই ওরা মিলে ~৫৩০px
                             নিত আর বাক্সের বাকি ~২৩০px খালি পড়ে থাকত।

                             ── আর মাপতে গিয়ে উল্টো একটা সমস্যা বেরোল ────────────
                             **বিল নম্বরটা কেটে যাচ্ছিল**: `w-32` (১২৮px) ঘরে
                             `INV-2026-2027-0001` ধরত না, পর্দায় দেখাত
                             `INV-2026-2027-0`। ⚠️ কাগজের **পরিচয়** অর্ধেক দেখা
                             যাচ্ছিল, আর ওটা স্ক্রিনশটে চোখ এড়িয়ে যায়।

                             এখন ছক: চারটা কলাম সারিটা ভাগ করে নেয়, প্রতিটা ঘর
                             নিজের কলামের পুরোটা। শেষে কিছু বাঁচে না, আর নম্বরও
                             পুরোটা দেখা যায়।

                             ⓘ সরু পর্দায় দুই কলাম — চারটা এক সারিতে চাপালে
                             প্রতিটা এত সরু হত যে তারিখটাই পড়া যেত না। --}}
                        <div class="grid grid-cols-2 gap-2 @md:grid-cols-[1.15fr_1.5fr_1.15fr_0.7fr]">
                            {{-- পুরো তারিখটা দেখা যেতে হবে — মালিকের কথা,
                                 ৩ সেপ্টেম্বর ২০২৬: "০৩-০৯-২০:" পর্যন্ত দেখিয়ে
                                 বছরটা কেটে যাচ্ছিল, আর একটা কাটা তারিখ পড়ে
                                 কেউ নিশ্চিত হতে পারেন না কোন বছরের কাগজ। --}}
                            <label class="min-w-0">
                                <span class="mb-0.5 block text-2xs font-semibold uppercase tracking-wide
                                             text-(--color-ink-muted)">{{ __('sales::field.challan_date') }}</span>
                                <x-ui.date name="trx_date"
                                           :value="old('trx_date', now()->toDateString())"
                                           class="w-full text-sm" />
                                           </label>

                            {{--
                                বিলের নম্বর — এখনই দেখা যায়, আর বদলানোও যায়।

                                ── কেন বদলাল (৩ সেপ্টেম্বর ২০২৬) ─────────────────
                                এখানে "নিশ্চিত করলে" লেখা একটা নিষ্ক্রিয় ঘর ছিল।
                                যুক্তিটা ছিল: আগে থেকে নম্বর দেখালে খসড়া বাতিল
                                হলে ওই নম্বরটা খরচ হয়ে সিরিজে ফাঁক থেকে যেত।

                                মালিক বললেন নম্বরটা এখানেই তৈরি হবে, আর দরকারে
                                বদলানো যাবে। **যুক্তিটা টিকে আছে, শুধু সমাধানটা
                                বদলেছে**: এটা `preview()`, `next()` নয় — অর্থাৎ
                                সিরিজের পরের নম্বরটা কেবল **দেখানো** হয়, খরচ হয়
                                না। খসড়া বাতিল হলে কিছুই হারায় না, আর দুইজন
                                একসাথে কাউন্টার খুললেও দুইজনেই একই নম্বর দেখেন —
                                আসল নম্বরটা বসে সংরক্ষণের মুহূর্তে, তালার ভেতরে।

                                ⚠️ তাই পর্দায় দেখা নম্বরটা **প্রতিশ্রুতি নয়,
                                পূর্বাভাস**। কেউ হাতে বদলালে সেটাই যায়, আর
                                না বদলালে সংরক্ষণের সময়কার আসল পরেরটা।
                            --}}
                            <label class="min-w-0">
                                <span class="mb-0.5 block text-2xs font-semibold uppercase tracking-wide
                                             text-(--color-ink-muted)">{{ __('sales::field.inv_number') }}</span>
                                <input type="text" name="invoice_no" value="{{ old('invoice_no', $invoicePreview) }}"
                                       :title="@js(__('sales::field.invoice_no_editable'))"
                                       placeholder="{{ __('sales::field.on_confirm') }}"
                                       class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-sm">
                            </label>

                            {{--
                                বাকির মেয়াদ — একটাই ঘর, ৩ সেপ্টেম্বর ২০২৬।

                                ── কেন দুইটা ঘর এক হয়ে গেল ───────────────────────
                                কিছুক্ষণ আগে এখানে দুইটা ছিল — "কত দিন" আর "কোন
                                তারিখে"। মালিক দেখেই বললেন একটাই হোক: *"Drop dawn
                                diye ektai box kora huk duto na"*।

                                তিনি ঠিক বলেছেন। দুইটা ঘর মানে প্রতিবার একটা
                                নীরব প্রশ্ন — কোনটায় লিখব — অথচ উত্তরটা প্রায়
                                সবসময় একই। আর দুইটা একসাথে ভরা থাকলে কোনটা
                                জেতে সেটা পর্দা দেখে বোঝার কোনো উপায় ছিল না।

                                ── তালিকাটা কোথা থেকে ─────────────────────────────
                                [[mdm_payment_terms]] — মাস্টার ডাটার সারি, কোডে
                                লেখা তালিকা নয়। কেউ "৪৫ দিন" যোগ করলে পরের দিনই
                                এখানে দেখা যাবে। এটাই মালিকের পুরনো নিয়ম: "কোন
                                কোন ধরনের জিনিস" এমন প্রতিটা তালিকা কোম্পানির
                                নিজের বাড়ানোর কথা।

                                ── ডিফল্ট কেন "আজ" ────────────────────────────────
                                *"day closing date defolt thakbe"* — কাউন্টারে
                                বেশিরভাগ বিক্রিই নগদ, তাই ওটাই স্বাভাবিক অবস্থা,
                                আর বাকি দেওয়াটা ব্যতিক্রম।

                                তবে ব্যতিক্রমটা সিস্টেম নিজেই জানে: ক্রেতা বাছার
                                সাথে সাথে তাঁর নিজের মেয়াদ থাকলে সেটাই বসে যায়
                                ([[chooseCustomer()]])। যাঁর সাথে ৩০ দিনের কথা
                                আছে, তাঁর বেলায় প্রতিবার হাতে বাছতে হয় না।

                                ── "নির্দিষ্ট তারিখ" ───────────────────────────────
                                শেষ বিকল্পটা বাছলেই তারিখের ঘরটা বেরোয় — ঈদের
                                আগের হিসাব বা মিলের সাথে ঠিক করা একটা দিনের জন্য।
                                বাকি সময় ওটা পর্দাতেই থাকে না।
                            --}}
                            <label class="min-w-0">
                                <span class="mb-0.5 block text-2xs font-semibold uppercase tracking-wide
                                             text-(--color-ink-muted)">{{ __('sales::field.credit_period') }}</span>
                                <select x-model="creditTerm" @change="termChanged()"
                                        class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-app) px-2 text-sm">
                                    @foreach ($paymentTerms as $term)
                                        <option value="{{ $term['days'] }}">{{ $term['label'] }}</option>
                                    @endforeach
                                    <option value="custom">{{ __('sales::field.due_on') }}</option>
                                </select>
                            </label>

                            {{-- কেবল "নির্দিষ্ট তারিখ" বাছলে --}}
                            <label class="min-w-0" x-show="creditTerm === 'custom'" x-cloak>
                                <span class="mb-0.5 block text-2xs font-semibold uppercase tracking-wide
                                             text-(--color-ink-muted)">{{ __('sales::field.due_on') }}</span>
                                <input type="date" name="due_on" x-model="dueOn"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-sm">
                            </label>

                            {{-- দিনের সংখ্যাটা সার্ভারে যায়, কিন্তু পর্দায় নয় —
                                 ড্রপডাউনটাই এখন ওটার মুখ --}}
                            <input type="hidden" name="credit_period_days"
                                   :value="creditTerm === 'custom' ? '' : creditTerm">

                            {{-- DO নম্বর — ঐচ্ছিক, আর নিজের সুইচের পেছনে।

                                 ⚠️ এই ঘরটা একবার দুর্ঘটনাক্রমে মুছে গিয়েছিল
                                 (৩ সেপ্টেম্বর ২০২৬): মেয়াদের দুইটা ঘর একটা
                                 ড্রপডাউনে বদলাতে গিয়ে প্রতিস্থাপনের সীমা
                                 বেশি টানা হয়েছিল, আর মাঝের এই ব্লকটাও তার
                                 ভেতরে পড়ে গিয়েছিল।

                                 [[DirectSaleTest::test_every_field_switch_really_hides_its_field]]
                                 পরের রানেই ধরে ফেলেছে — "খোলা থাকলেও ঘরটা
                                 নেই: sales.field_do_no"। স্ক্রিনশটে ধরা
                                 পড়েনি, কারণ ঘরটা এমনিতেই ঐচ্ছিক আর ফাঁকা
                                 দেখায়; একটা কম ঘর চোখে পড়ে না। --}}
                            @if ($show['do_no'])
                                <label class="min-w-0">
                                    <span class="mb-0.5 block text-2xs font-semibold uppercase tracking-wide
                                                 text-(--color-ink-muted)">{{ __('sales::field.do_no') }}</span>
                                    <input type="text" name="do_no" placeholder="{{ __('sales::field.optional') }}"
                                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-sm">
                                </label>
                            @endif

                            {{--
                                গুদাম — স্ট্রিপ থেকে বাদ, ৩ সেপ্টেম্বর ২০২৬।

                                ── মালিকের নির্দেশ, আর কেন এটা নিরাপদ ────────────
                                *"Warehouse bad daw"*। কাউন্টারে দাঁড়িয়ে মাল
                                কোন গুদাম থেকে বেরোচ্ছে সেটা বাছার প্রশ্ন ওঠে না
                                — কাউন্টারের নিজের গুদামটাই বেরোয়, আর ঘরটা
                                প্রতিবার একই মান দেখাত।

                                ⚠️ কিন্তু ঘরটা গেছে, **মানটা যায়নি**। মজুদ কোন
                                গুদাম থেকে কমল সেটা না লিখলে স্টক লেজার আর
                                গুদামের যোগফল আলাদা হয়ে যেত, আর সেটা এমন ভুল
                                যা মাস শেষে ধরা পড়ে। তাই মানটা এখনো যায় —
                                কেবল চোখের আড়ালে।

                                বদলানোর দরকার হলে জায়গাটা [[Control Panel]],
                                কাউন্টারের পর্দা নয়: চালানের মাঝপথে গুদাম
                                বদলানো মানে অর্ধেক লাইন এক গুদামের, বাকি অর্ধেক
                                অন্যটার।
                            --}}
                            <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">
                        </div>
                    </div>

                    {{--
                        ── ক্রেতা — কাগজের পরিচয়ের একই বাক্সে, নিচের সারিতে ──

                        ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────
                        *"Invoice Date, INV Number, Credit Period, DO No.
                        eigulor niche sundor kore ekoi box e bosaw"*।

                        ── কেন এটা ঠিক ────────────────────────────────────────
                        কাগজটা **কী** (তারিখ · নম্বর · মেয়াদ · DO) আর কাগজটা
                        **কার** — দুইটাই একই প্রশ্নের অংশ: *এই চালানটা কীসের?*
                        দুইটা আলাদা বাক্সে থাকলে চোখকে দুইবার থামতে হত।

                        ⚠️ আর ক্রেতার বাক্সটা সরে যাওয়ায় উপরের ডান কোণটা খালি
                        হলো — সেখানে **"এই লাইন" উঠে এসেছে**, মালিকের নির্দেশে।
                    --}}
                    {{--
                        ক্রেতা — চিহ্ন বাঁয়ে, পরিচয় ডানে।

                        ── কী বদলাল (২ সেপ্টেম্বর ২০২৬) ──────────────────────
                        এখানে একটা `<select>` ছিল — কোম্পানির প্রতিটা গ্রাহক
                        একটা তালিকায়, আর টাইপ করে খোঁজার উপায় নেই। তিন
                        হাজার দোকানের ডিপোতে ওটা কার্যত অব্যবহার্য: প্রথম
                        অক্ষরের পরে ব্রাউজার আর কিছু মেলায় না।

                        মালিক NEXUS-এর নমুনা পাঠিয়েছেন, আর সেখানে জিনিসটা
                        একটা **চিহ্ন** — চাপলে একটা প্যানেল খোলে যেটা টাইপের
                        সাথে ছাঁকে, আর বাছা হয়ে গেলেই বন্ধ হয়ে যায়।

                        ── কেন নামটা চিহ্নের পাশে, নিচে নয় ───────────────────
                        বাক্সটা চিহ্ন হয়েছিল প্রস্থ ফেরত পেতে; সেই প্রস্থ
                        খালি ফেলে রাখলে লাভটাই থাকত না। তাই এক সারিতেই:
                        চিহ্নটাই মার্ক, নামটা তার গায়ে লেগে শুরু।

                        ── এলাকা ও ফোন পাশাপাশি কেন ─────────────────────────
                        ফোন তোলার সময় মানুষ দুইটা একসাথে পড়েন — "তারাকান্দার
                        দোকানটা, এই নম্বর"। আলাদা সারিতে থাকলে চোখকে দুইবার
                        নামতে হত।
                    --}}
                    <div class="flex items-start gap-2">
                        <button type="button" @click="customerPickerOpen = ! customerPickerOpen"
                                :aria-expanded="customerPickerOpen ? 'true' : 'false'"
                                :class="customerPickerOpen
                                    ? 'border-(--color-brand-600) bg-(--color-brand-600) text-(--color-brand-ink)'
                                    : 'border-(--color-brand-300) bg-(--color-brand-50) text-(--color-brand-700) hover:bg-(--color-brand-100)'"
                                class="grid size-9 shrink-0 place-items-center rounded-(--radius-field)
                                       border-2 transition-colors">
                            <span class="sr-only">{{ __('sales::message.search_customer') }}</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current"
                                 x-show="! customerPickerOpen">
                                <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                            </svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current"
                                 x-show="customerPickerOpen" x-cloak>
                                <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                            </svg>
                        </button>

                        {{--
                            ── ক্রেতার পুরো পরিচয় — এক লম্বা সারিতে ──────────

                            ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────
                            *"Customer Name, search icon tar por lomba kete
                            Point, Mobile, address, note thakle ta, cr limit
                            — egulo"*।

                            আগে জিনিসগুলো তিন সারিতে ভাগ ছিল (নাম · এলাকা+ফোন ·
                            ঠিকানা), আর ক্রেডিট লিমিট একদম আলাদা সারিতে। **তিন
                            সারি মানে বাক্সটা তিন গুণ উঁচু**, অথচ কাগজের বাক্সে
                            জায়গা এখন পাশে, উপরে-নিচে নয়।

                            ⚠️ `flex-wrap` ইচ্ছাকৃত — সরু পর্দায় জিনিসগুলো নিজে
                            থেকে নিচে নামে, **চেপে গিয়ে অপাঠ্য হওয়ার বদলে**।

                            ⓘ ক্রেডিট লিমিট `ms-auto` দিয়ে ডান প্রান্তে, কারণ
                            ওটাই একমাত্র **টাকার** সংখ্যা — বাকিগুলো পরিচয়।
                        --}}
                        <div class="min-w-0 flex-1 leading-tight">
                            {{--
                                ── উপরের সারি: নাম · এলাকা · ফোন … বাকির সীমা ──

                                মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                                *"Customer nam ro boro hobe. Point, Mobile no,
                                cr Limit uporer line, Address nicher line."*

                                ⚠️ নামটা `text-base` — বাকিদের চেয়ে বড়, আর
                                সেটাই ঠিক: **ভুল পার্টি বাছার দাম পুরো একটা
                                চালান**, তাই নামটাই সবচেয়ে আগে চোখে পড়া দরকার।

                                ⓘ বাকির সীমা `ms-auto` দিয়ে ডান প্রান্তে —
                                একই সারিতে, কিন্তু আলাদা করে, কারণ ওটাই
                                একমাত্র **টাকার** সংখ্যা।
                            --}}
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="truncate text-base font-semibold text-(--color-ink)"
                                      x-text="customer.name || @js(__('sales::message.search_customer'))"></span>

                                <span x-show="customer.location" x-cloak
                                      class="inline-flex max-w-36 items-center truncate rounded-full
                                             border border-(--color-brand-200) bg-(--color-brand-50)
                                             px-1.5 py-px text-2xs font-medium text-(--color-brand-700)"
                                      x-text="customer.location"></span>

                                {{-- ⚠️ মোবাইল নম্বর মোটা করে — মালিকের নির্দেশ
                                     (৩ সেপ্টেম্বর ২০২৬): *"Mob no o emon kore
                                     bold kore dio, zate dekhte subidha hoy"*।

                                     ── কেন ─────────────────────────────────
                                     কাউন্টারে নম্বরটা **পড়ে শোনানো হয়** —
                                     "০১৮১১…, ঠিক আছে তো?" — আর হালকা ধূসর
                                     ছোট লেখায় এগারোটা অঙ্ক পড়তে গিয়ে চোখ
                                     আটকায়। ⚠️ **একটা অঙ্ক ভুল পড়লে ডেলিভারির
                                     ফোনটাই অন্য কারো কাছে যায়।**

                                     ⓘ রঙটা কালি-রঙে ফিরল, ধূসর নয় — মোটা করা
                                     আর হালকা রাখা একসাথে অর্থহীন। --}}
                                <span x-show="customer.phone" x-cloak
                                      class="num text-xs font-semibold text-(--color-ink)"
                                      x-text="customer.phone"></span>

                            {{-- ⚠️ নোট এখানে **নেই**, আর ইচ্ছাকৃতভাবে নেই।

                                 মালিক চেয়েছিলেন *"note thakle ta"* — কিন্তু
                                 মেপে দেখা গেছে **`customers` টেবিলে নোটের কোনো
                                 কলামই নেই** (৩ সেপ্টেম্বর ২০২৬, ৩০টা কলাম গুনে)।

                                 একটা `x-text="customer.note"` বসিয়ে রাখা যেত —
                                 চুপচাপ কিছুই দেখাত না, আর দেখতে মনে হত কাজটা
                                 হয়ে গেছে। **ওটাই সবচেয়ে খারাপ**: মালিক ভাবতেন
                                 নোট লেখার জায়গা আছে, আর খুঁজে পেতেন না।

                                 নোট সত্যিই দরকার হলে তিনটা জিনিস লাগবে:
                                 কলাম · গ্রাহকের ফর্মে ঘর · এখানে দেখানো।
                                 মালিকের সিদ্ধান্তের অপেক্ষায়। --}}

                            @if ($show['credit_limit'])
                                <span class="ms-auto whitespace-nowrap text-2xs text-(--color-ink-muted)">
                                    {{ __('sales::field.credit_limit') }}
                                    <span class="num ms-1 font-medium text-(--color-ink)"
                                          x-text="customer.limit > 0 ? money(customer.limit) : '—'"></span>
                                </span>
                            @endif
                            </div>

                            {{-- ── নিচের সারি: ঠিকানা ────────────────────────

                                 ঠিকানা একাই একটা সারি পায়, কারণ ওটাই সবচেয়ে
                                 লম্বা — উপরের সারিতে ঢোকালে নাম আর ফোন চেপে
                                 যেত, অথচ **ঠিকানা কাউন্টারে সবচেয়ে কম পড়া
                                 হয়** (মাল তো সামনেই নেওয়া হচ্ছে)।

                                 ⓘ `truncate` + `title` — লম্বা ঠিকানা এক
                                 লাইনেই থাকে, আর পুরোটা মাউস রাখলে দেখা যায়। --}}
                            <div x-show="customer.address" x-cloak :title="customer.address"
                                 class="truncate text-2xs text-(--color-ink-muted)"
                                 x-text="customer.address"></div>
                        </div>
                    </div>

                    {{-- ছাঁকনি — টাইপের সাথে সাথে, আর বাছা হলেই বন্ধ --}}
                    <div x-show="customerPickerOpen" x-cloak
                         @keydown.escape="customerPickerOpen = false"
                         class="mt-2 rounded-(--radius-field) border-2 border-(--color-brand-200)
                                bg-(--color-brand-50) p-1.5">
                        <input type="search" x-model="customerTerm"
                               x-effect="customerPickerOpen && $nextTick(() => $el.focus())"
                               @keydown.enter.prevent="pickFirstCustomer()"
                               placeholder="{{ __('sales::message.search_customer') }}"
                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">

                        <ul class="mt-1.5 max-h-56 overflow-y-auto">
                            <template x-for="row in customerMatches" :key="row.id">
                                <li>
                                    <button type="button" @click="chooseCustomer(row.id)"
                                            :class="String(row.id) === customerId
                                                ? 'bg-(--color-brand-100) font-semibold' : ''"
                                            class="w-full rounded px-2 py-1.5 text-start
                                                   hover:bg-(--color-brand-100)">
                                        <span class="block truncate text-xs" x-text="row.name"></span>
                                        <span class="num block text-2xs text-(--color-ink-muted)"
                                              x-text="row.phone"></span>
                                    </button>
                                </li>
                            </template>

                            <li x-show="customerMatches.length === 0" x-cloak
                                class="px-2 py-2 text-2xs text-(--color-ink-muted)">
                                {{ __('sales::message.no_customer_match') }}
                            </li>
                        </ul>
                    </div>

                    {{-- ফর্মের সাথে যা সত্যিই যায় — উপরেরটা কেবল বাছার পর্দা।

                         `<select>`-টা নিজেই `name="customer_id"` বহন করত; চিহ্ন
                         আর তালিকা কোনো ফর্ম-ঘর নয়, তাই মানটা এখানে বসাতে হয়।
                         না বসালে চালান সেভ হত ক্রেতা ছাড়াই। --}}
                    <input type="hidden" name="customer_id" x-model="customerId">
                </section>
                {{--
                    ── পণ্য খোঁজা ও লাইনের ঘর — স্ট্রিপের ঠিক নিচে ──────────

                    ── কেন এখানে (৩ সেপ্টেম্বর ২০২৬) ────────────────────────
                    মালিক লাল বাক্স এঁকে দেখালেন: স্ট্রিপের নিচে একটা বড়
                    ফাঁকা জায়গা পড়ে ছিল, আর নিচের ব্লকটা তার বাইরে।
                    *"upore faka lal box e nicher mark kora box guchiye
                    uporer mark kora box er vitore niye aso"*।

                    ফাঁকাটা ছিল কারণ পাশের সবুজ প্যানেল স্ট্রিপের চেয়ে
                    উঁচু। এখন ব্লকটা ওই জায়গাতেই বসে, আর সারিটার তিনটা
                    কলামই কাছাকাছি উচ্চতার হয়ে যায়।

                    ⚠️ ঘরগুলো এখনো `flex-wrap` + নিজের নিজের প্রস্থে, তাই
                    কলামটা সরু হলে ওরা নিচে নামে — চেপে যায় না।
                --}}

                {{--
                    ── এন্ট্রি এলাকা — পুরো প্রস্থে, কিন্তু ঘরগুলো স্থির ────────

                    ── কেন কলামটা চওড়া, অথচ ঘরগুলো নয় (৩ সেপ্টেম্বর ২০২৬) ──
                    একবার এটাকে উপরের সরু কলামে ঢোকানো হয়েছিল, যাতে ঘরগুলো
                    টেনে লম্বা না হয়। ফল উল্টো: কলামটা ~৪৩০px হওয়ায় দশটা ঘর
                    দুইয়ে দুইয়ে পাঁচ সারি হয়ে গেল, আর পণ্য খোঁজার বারটা এত
                    সরু হল যে লেখাটাই কেটে গেল।

                    আসল সমস্যাটা কলামের প্রস্থ ছিল না, **ঘরের**: ঘরগুলো
                    `w-full` ছিল, তাই যত জায়গা পেত তত টানত। এখন প্রতিটার
                    নিজের মাপ বাঁধা, আর অতিরিক্ত জায়গাটা ডান পাশে ফাঁকা
                    থাকে — যেটাই ঠিক, কারণ একটা সংখ্যার ঘর দুই ইঞ্চি চওড়া
                    হয়ে কিছুই বেশি বলে না।
                --}}
                <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-3">

                    {{-- বাঁ: খোঁজা ও ঘরগুলো।

                         @container — মাপটা এই কলামের নিজের প্রস্থে, পর্দার
                         নয়। ভিউপোর্ট ধরে মাপলে ডান পাশের প্যানেল জায়গা
                         নেওয়ায় পাঁচটা ঘর তিন-দুইয়ে ভেঙে চার সারি হয়ে যেত। --}}
                    <div class="@container min-w-0">
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
                                        : 'border-(--color-brand-300) bg-(--color-brand-50) text-(--color-brand-700) hover:bg-(--color-brand-100)'"
                                    class="grid size-11 shrink-0 place-items-center rounded-(--radius-card)
                                           border-2 transition-colors">
                                <span class="sr-only">{{ __('sales::message.type_or_pick') }}</span>
                                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-5 fill-current"
                                     x-show="! pickerOpen">
                                    <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                                </svg>
                                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-5 fill-current"
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
                                            class="min-w-0 flex-1 truncate rounded-(--radius-field) border
                                                   border-transparent px-3 py-2 text-start text-xl font-semibold
                                                   transition-colors hover:border-(--color-border)"
                                            :class="picked ? 'text-(--color-ink)' : 'text-(--color-ink-muted)'"
                                            x-text="picked?.name || @js(__('sales::message.type_or_pick'))">
                                    </button>

                                    <span x-show="picked" x-cloak
                                          class="num shrink-0 rounded-(--radius-field) border border-(--color-border)
                                                 px-2 py-0.5 text-2xs font-medium text-(--color-ink-muted)"
                                          x-text="picked?.code"></span>
                                </div>

                                <label class="block" x-show="pickerOpen" x-cloak>
                                    <span class="sr-only">{{ __('sales::message.type_or_pick') }}</span>
                                    <input type="search" x-model="term" x-ref="search"
                                           @keydown.enter.prevent="pickFirst()"
                                           @keydown.escape="pickerOpen = false"
                                           placeholder="{{ __('sales::message.type_or_pick') }}"
                                           class="h-11 w-full truncate rounded-(--radius-field) border
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

                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-2xs" x-show="picked" x-cloak>
                            @foreach ([
                                'main' => 'sales::field.main_stock',
                                'reserved' => 'sales::field.reserved_short',
                                'available' => 'sales::field.available_short',
                                'free' => 'sales::field.free_stock',
                                'free_available' => 'sales::field.free_available',
                            ] as $key => $label)
                                <span>
                                    <span class="text-(--color-ink-muted)">{{ __($label) }}</span>
                                    <span class="num font-semibold" x-text="qty(picked?.{{ $key }})"></span>
                                </span>
                            @endforeach
                        </div>

                        {{-- খোঁজার ফল --}}
                        <div class="mt-2 max-h-40 space-y-0.5 overflow-y-auto" x-show="pickerOpen" x-cloak>
                            <template x-for="p in visible" :key="p.id">
                                <button type="button" @click="pick(p)"
                                        class="flex w-full items-baseline justify-between gap-2 rounded-(--radius-field)
                                               px-2 py-1 text-start text-sm transition-colors
                                               hover:bg-(--color-surface-hover)">
                                    <span class="min-w-0 truncate" x-text="p.name"></span>
                                    <span class="num shrink-0 text-2xs text-(--color-ink-muted)"
                                          x-text="qty(p.available)"></span>
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
                    <div class="mt-3 grid max-w-4xl gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
                            <x-sales::entry-field label="sales::field.qty" width="w-full">
                                <input type="number" step="0.01" min="0" x-model="entry.qty"
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
                                    <input type="text" readonly :value="picked?.unit || ''"
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
                                    <input type="text" readonly :value="picked?.unit || ''"
                                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                                </x-sales::entry-field>
                            @endif

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
                                <p class="mb-2 text-2xs font-semibold text-(--color-badge-pending-ink)">
                                    🎁 {{ __('sales::field.gift_item') }} —
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
                                            :disabled="! giftDraft.productId || ! (Number(giftDraft.qty) > 0)"
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
                                        🎁 <span x-text="(catalogue.find(p => String(p.id) === String(g.productId)) || {}).name || ''"></span>
                                        <span class="num" x-text="qty(Number(g.qty || 0))"></span>
                                        <button type="button" @click="entry.gifts.splice(n, 1)"
                                                class="text-(--color-danger)">&times;</button>
                                    </span>
                                </template>
                            </div>
                        @endif

                    </div>
                </section>
            </div>

                    {{-- মাঝ: এই লাইন --}}
                    {{--
                        ⚠️ `lg:row-start-1` — ইচ্ছাকৃত, আর ছাড়া চলে না।

                        ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────
                        *"This line upore dike tulo"* — ক্রেতার বাক্স কাগজের
                        বাক্সে নেমে যাওয়ায় উপরের ডান কোণটা খালি হয়েছিল, আর
                        "এই লাইন" তবু নিচেই পড়ে থাকত।

                        ── কেন নিজে থেকে উপরে ওঠেনি ───────────────────────────
                        CSS-এর অটো-প্লেসমেন্ট **পিছনে ফেরে না**। বাঁ কলামটা
                        `col-start-1` দিয়ে দ্বিতীয় সারিতে বসার পর কার্সরটা
                        ওখানেই থেকে যায় — তাই পরের জিনিসটা **প্রথম সারির খালি
                        ঘরে না গিয়ে** দ্বিতীয় সারিতে বসে।

                        সারিটা হাতে বলে দেওয়াই একমাত্র নিশ্চিত পথ।
                    --}}
                    <div class="rounded-(--radius-card) bg-(--color-badge-success-bg) p-3
                                lg:col-start-2 lg:col-end-[-1] lg:row-start-1">
                        {{--
                            ── লেবেল বাঁয়ে, অঙ্ক ডানে — এক সারিতে ─────────────

                            ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────
                            *"Amount daner mark kora box e boro kore rako"* —
                            অঙ্কটা লেবেলের নিচে ছিল, আর ডান পাশটা খালি পড়ে
                            ছিল। এখন দুইটা এক সারিতে, আর **বাক্সটা এক সারি
                            কম উঁচু**।

                            ⚠️ অঙ্কটা **বড়ই থাকল** (`text-2xl`), ছোট করা হয়নি।
                            কাউন্টারে ওটাই একমাত্র সংখ্যা যেটা দূর থেকে পড়া
                            হয় — ক্রেতা পাশে দাঁড়িয়ে জিজ্ঞেস করেন *"কত হলো?"*,
                            আর বিক্রেতা তাকিয়েই বলেন।

                            ⓘ নিচের সারিগুলোও ডান-ঘেঁষা, তাই সব সংখ্যা এখন
                            **একই খাড়া রেখায়** — চোখকে বাঁয়ে-ডানে লাফাতে হয় না।
                        --}}
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-badge-success-ink)">
                                {{ __('sales::field.this_line') }}
                            </p>
                            <p class="num text-2xl font-bold text-(--color-badge-success-ink)"
                               x-text="'৳' + money(entryNet)"></p>
                        </div>

                        {{--
                            ── এই লাইনের অঙ্ক — টাকার পথের ক্রমেই ─────────────

                            ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────
                            *"Discount e Amount or % box daw · VAT dropdown
                            daw · Net Value — VAT-er niche daw"*।

                            ── ক্রমটা কেন এই ─────────────────────────────────
                            অঙ্কটা যেভাবে সত্যিই গড়ায়, ঠিক সেভাবেই উপর থেকে
                            নিচে:

                                ছাড়        কত কমল
                                ভ্যাট       কত কর বসল
                                নিট মূল্য   শেষ পর্যন্ত কত  ← তাই সবার নিচে
                                মোট পরিমাণ

                            ⭐ **আর নিট মূল্যটা এখন `entryNet`** — বাঁয়ের ঘরের
                            "নিট মূল্য"-র হুবহু একই সংখ্যা।

                            ⚠️ আগে এখানে `entryAfterDiscount` ছিল, অর্থাৎ
                            **এক পর্দায় "নিট মূল্য" নামে দুইটা আলাদা সংখ্যা** —
                            বাঁয়ে ভ্যাট-সহ, এখানে ভ্যাট ছাড়া। ভ্যাট বন্ধ থাকা
                            কোম্পানিতে দুইটা মিলত, তাই কেউ কোনোদিন ধরত না;
                            ভ্যাট চালু হলেই কাউন্টারের লোক দুইটা সংখ্যা দেখে
                            বুঝতেন না কোনটা সত্যি।
                        --}}
                        <dl class="mt-2 space-y-0.5 text-2xs">
                            {{--
                                ── মোট টাকা — সবার উপরে ────────────────────

                                মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬):
                                *"This line Discount er upore Total Amount
                                bosbe"*।

                                ⭐ এতে সারিগুলো **অঙ্কের গল্পটাই** বলে, উপর
                                থেকে নিচে:

                                    মোট টাকা    দর × পরিমাণ
                                    ছাড়         কত কমল
                                    ভ্যাট        কত কর বসল
                                    নিট মূল্য     শেষে কত

                                ⓘ আগে শুরুটাই ছিল না — ছাড় দেখা যেত, কিন্তু
                                **কীসের উপর ছাড়** তা নয়। "৭%" বলতে কত, সেটা
                                যাচাই করার কোনো উপায় পর্দায় ছিল না।
                            --}}
                            <div class="flex justify-between gap-2">
                                <dt class="text-(--color-ink-muted)">{{ __('sales::field.total_amount') }}</dt>
                                <dd class="num" x-text="money(entryBase)"></dd>
                            </div>

                            {{-- ছাড় — এখানেই লেখা যায়, টাকায় বা শতাংশে --}}
                            @if ($show['line_discount'])
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.discount_amount') }}</dt>
                                    <dd class="flex items-center gap-1">
                                        <input type="text" inputmode="decimal" x-model="entry.discountInput"
                                               placeholder="{{ __('sales::field.amount_or_pct') }}"
                                               class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        <span class="num w-16 text-end" x-text="money(entryDiscount)"></span>
                                    </dd>
                                </div>
                            @endif

                            {{-- ভ্যাট — পুরো কাগজের ধরন এখানেই বদলানো যায় --}}
                            @if ($vatEnabled)
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.vat') }}</dt>
                                    <dd class="flex items-center gap-1">
                                        <select x-model="vatMode"
                                                title="{{ __('sales::field.vat_per_product_hint') }}"
                                                class="h-(--spacing-inline) w-20 rounded-(--radius-field) border
                                                       border-(--color-border) bg-(--color-surface-card) px-1">
                                            <option value="">{{ __('sales::field.vat_per_product') }}</option>
                                            <option value="exclusive">{{ __('sales::field.vat_exclusive') }}</option>
                                            <option value="inclusive">{{ __('sales::field.vat_inclusive') }}</option>
                                            <option value="exempt">{{ __('sales::field.vat_exempt') }}</option>
                                        </select>
                                        <span class="num w-16 text-end" x-text="money(entryVat)"></span>
                                    </dd>
                                </div>
                            @endif

                            @foreach ([
                                'sales::field.net_value' => 'entryNet',
                                'sales::field.total_qty' => 'entryTotalQty',
                            ] as $label => $expr)
                                <div class="flex justify-between gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                                    <dd class="num" x-text="money({{ $expr }})"></dd>
                                </div>
                            @endforeach
                        </dl>
                        <div class="mt-2 border-t border-(--color-badge-success-ink)/20 pt-1 text-2xs">
                            <div class="flex justify-between">
                                <span class="text-(--color-ink-muted)">{{ __('sales::field.in_cart') }}</span>
                                <span class="num" x-text="lines.length + ' ' + @js(__('sales::field.items'))"></span>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <span>{{ __('sales::field.running_total') }}</span>
                                <span class="num" x-text="'৳' + money(subTotal)"></span>
                            </div>
                        </div>

                        {{-- ⚠️ বোতামগুলো "এই লাইন" বাক্সের **ভেতরে**, একদম নিচে।

                             ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────
                             *"Gift, Add to Cart, Costing, Clear Data — eigulo
                             baton this line box er ekdom niche bosbe"*।

                             ── কেন এটা ঠিক ────────────────────────────────────
                             বোতামগুলো বাক্সের বাইরে ভাসত, আর ওদের সাথে অঙ্কের
                             কোনো দৃশ্যমান সম্পর্ক ছিল না। **অথচ ওরা ঠিক ওই
                             অঙ্কটার উপরেই কাজ করে** — "কার্টে যোগ করুন" মানে
                             ওই ৳-টাকেই কার্টে ফেলা।

                             এখন এক বাক্সে: **উপরে কত, নিচে কী করব।**

                             ⚠️ ক্রমটা মালিকের লেখা, অনুমান করে বদলাবেন না।
                             আর "সব মুছুন" নিচের বারে আলাদা — দুইটা মুছে ফেলার
                             বোতাম পাশাপাশি থাকলে ভুল চাপ পড়া নিশ্চিত ছিল। --}}
                        <div class="mt-3 grid grid-cols-2 gap-1 border-t border-(--color-badge-success-ink)/20 pt-3 2xl:grid-cols-4">
                        @if ($show['gift'])
                            {{-- ⚠️ এখানে `:disabled`, একটা কোলন — আর নিচে
                                 "নিশ্চিত করুন" বোতামে `::disabled`, দুইটা।
                                 **দুইটাই ঠিক**: Blade কেবল কম্পোনেন্ট ট্যাগে
                                 `::`-কে `:`-এ নামায়। সাধারণ ট্যাগে দুইটা দিলে
                                 অ্যাট্রিবিউটটা হুবহু `::disabled` হয়ে ব্রাউজারে
                                 যায়, আর **Alpine নীরবে উপেক্ষা করে** — বোতামটা
                                 সক্রিয় দেখাত, চাপলে কিছু হত না।

                                 ⭐ পর্দায় কিছুই ভাঙা দেখাত না, JS ত্রুটিও ছিল না।
                                 ধরেছে `AlpineBindingsReachTheBrowserTest`। --}}
                            <button type="button" @click="openGift()" :disabled="! picked"
                                    class="w-full rounded-(--radius-field) leading-tight border border-(--color-badge-pending-ink)/30 disabled:opacity-40
                                           bg-(--color-badge-pending-bg) px-1 py-1.5 text-2xs font-medium
                                           text-(--color-badge-pending-ink)">
                                {{ __('sales::field.gift') }}
                            </button>
                        @endif

                        <button type="button" @click="addToCart()" :disabled="! picked"
                                class="w-full rounded-(--radius-field) leading-tight bg-(--color-success) px-1 py-1.5
                                       text-2xs font-semibold text-white disabled:opacity-50">
                            {{ __('sales::action.add_to_cart') }}
                        </button>

                        {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
                             জন্য নয়। তাই বোতামের পেছনে: চোখে পড়ে না,
                             কিন্তু দরকার হলে এক চাপ দূরে। --}}
                        <button type="button" @click="showCosting = ! showCosting"
                                class="w-full rounded-(--radius-field) leading-tight border border-(--color-border)
                                       px-1 py-1.5 text-2xs font-medium">
                            {{ __('sales::field.costing') }}
                        </button>

                        <span x-show="showCosting" x-cloak
                              class="num col-span-full text-end text-xs text-(--color-ink-muted)"
                              x-text="picked ? money(picked.cost) : ''"></span>

                        <button type="button" @click="clearEntry()"
                                class="w-full rounded-(--radius-field) leading-tight bg-(--color-danger)/10 px-2 py-1.5
                                       text-2xs font-medium text-(--color-danger) hover:bg-(--color-danger)/20">
                            {{ __('sales::action.clear_data') }}
                        </button>

                        </div>
                    </div>
                    {{--
                        ── ছবির ঘরটা তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬) ──────

                        মালিকের নির্দেশ: *"একটি পণ্য বেছে নিন — ei box ta
                        utiye daw"*, লাল দাগ দিয়ে ঘেরা।

                        ── কী ছিল, আর কেন গেল ───────────────────────────────
                        বাছা পণ্যের ছবি বসার জায়গা, আর ছবি না থাকায় সেখানে
                        কেবল একটা বাক্সের আইকন আর "একটি পণ্য বেছে নিন" লেখা
                        দেখাত। **অর্থাৎ ৯rem জায়গা নিত, আর বদলে কিছুই বলত
                        না** — পণ্যের নামটা তো বাঁয়ের খোঁজার ঘরেই বড় করে
                        লেখা থাকে।

                        ⚠️ কলামটাও ছকের সংজ্ঞা থেকে গেছে, শুধু ঘরটা নয় —
                        নাহলে ৯rem খালি পড়ে থাকত আর কেউ বুঝত না কেন।
                        এখন `2xl`-এ ডান কলাম একটাই, ২২.৭৫rem — উপরের
                        ক্রেতার বাক্সের ঠিক সমান।

                        ⓘ পণ্যের ছবি ব্যবস্থাটায় আসছে (A3 বসাচ্ছে)। এলে
                        জায়গাটা ফিরিয়ে আনার কথা ভাবা যাবে — কিন্তু তখন
                        ওখানে **সত্যিকারের ছবি** থাকবে, খালি আইকন নয়।
                    --}}

            </div>

            {{-- ── কার্ট ────────────────────────────────────────────── --}}
            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('sales::field.sl') }}</th>
                                <th class="text-start">{{ __('sales::field.item_name') }}</th>
                                <th class="text-end">{{ __('sales::field.unit_price') }}</th>
                                <th class="text-end">{{ __('sales::field.quantity') }}</th>
                                @if ($show['free_qty'])
                                    <th class="text-end">{{ __('sales::field.free_unit') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.total_qty') }}</th>
                                @if ($show['line_discount'])
                                    <th class="text-end">{{ __('sales::field.dis') }}</th>
                                @endif
                                @if ($vatEnabled)
                                    <th class="text-end">{{ __('sales::field.vat') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <template x-for="(line, i) in lines" :key="line.key">
                        <tbody>
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell" x-text="i + 1"></td>

                                    <td class="cell" data-label="{{ __('sales::field.item_name') }}">
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
                                        {{-- বাছা প্যাকের একক — সার্ভার এটা দেখেই
                                             "২ বাক্স"-কে পিসে নামায়, দর সহ --}}
                                        <input type="hidden" :name="`lines[${i}][unit_id]`" :value="line.unitId || ''">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.unit_price') }}">
                                        <input type="number" step="0.0001" min="0" x-model="line.rate"
                                               :name="`lines[${i}][rate]`"
                                               class="num h-(--spacing-field-dense) w-full sm:w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.quantity') }}">
                                        <input type="number" step="0.01" min="0.01" x-model="line.qty"
                                               :name="`lines[${i}][qty]`"
                                               class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    @if ($show['free_qty'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.free_unit') }}">
                                            <input type="number" step="0.01" min="0" x-model="line.freeQty"
                                                   :name="`lines[${i}][free_qty]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    <td class="num cell" data-label="{{ __('sales::field.total_qty') }}"
                                        x-text="qty(Number(line.qty || 0) + Number(line.freeQty || 0))"></td>

                                    @if ($show['line_discount'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.dis') }}">
                                            <input type="number" step="0.01" min="0" max="100"
                                                   x-model="line.discountPercent"
                                                   :name="`lines[${i}][discount_percent]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    @if ($vatEnabled)
                                        <td class="num cell" data-label="{{ __('sales::field.vat') }}"
                                            x-text="money(lineVat(line))"></td>
                                    @endif

                                    <td class="num cell font-medium" data-label="{{ __('sales::field.amount') }}"
                                        x-text="money(lineNet(line))"></td>

                                    <td class="cell-input text-end">
                                        <button type="button" @click="lines.splice(i, 1)"
                                                aria-label="{{ __('sales::action.remove_line') }}"
                                                class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                       hover:bg-(--color-surface-hover)">&times;</button>
                                    </td>
                                </tr>

                                {{--
                                    ── উপহার — লাইনের নিচে, ভেতরে ────────────

                                    ── কেন সন্তান-সারি, আলাদা টেবিল নয় ───────
                                    মালিকের নমুনা (৩ সেপ্টেম্বর ২০২৬): উপহারটা
                                    পণ্যের ঠিক নিচে, `↳` চিহ্ন দিয়ে।

                                    ⚠️ **এতে "কোন পণ্যের জন্য" প্রশ্নটাই মুছে
                                    যায়** — উত্তরটা জায়গাতেই লেখা। আগে ওটা
                                    একটা ড্রপডাউন ছিল, আর সেটা খালি থাকলে
                                    উপহার কোনো পণ্যের সাথে বাঁধা পড়ত না।

                                    ⓘ NEXUS-এ ঠিক এই ভুলটাই একবার হয়েছিল, আর
                                    সেখানেও সমাধানটা এটাই — ড্রপডাউন তুলে
                                    দিয়ে উপহারকে লাইনের ভেতরে নেওয়া।

                                    দাম-ছাড়-ভ্যাটের ঘরে ড্যাশ, কারণ **উপহারের
                                    দাম নেই** — থাকলে ওটা বিক্রি হয়ে যেত।
                                --}}
                                <template x-for="(gift, g) in line.gifts" :key="gift.key">
                                    <tr class="border-b border-(--color-border) bg-(--color-badge-pending-bg)/40 text-2xs">
                                        <td class="cell text-end text-(--color-badge-pending-ink)">↳</td>

                                        <td class="cell ps-4 text-(--color-badge-pending-ink)"
                                            data-label="{{ __('sales::field.item_name') }}">
                                            🎁 <span x-text="(catalogue.find(p => String(p.id) === String(gift.productId)) || {}).name || ''"></span>
                                        </td>

                                        <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        <td class="cell text-end text-(--color-ink-muted)">—</td>

                                        @if ($show['free_qty'])
                                            <td class="num cell text-(--color-badge-pending-ink)"
                                                data-label="{{ __('sales::field.free_unit') }}"
                                                x-text="qty(Number(gift.qty || 0)) + ' ' + (line.unit || '')"></td>
                                        @endif

                                        <td class="num cell text-(--color-badge-pending-ink)"
                                            data-label="{{ __('sales::field.total_qty') }}"
                                            x-text="qty(Number(gift.qty || 0))"></td>

                                        @if ($show['line_discount'])
                                            <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        @endif

                                        @if ($vatEnabled)
                                            <td class="cell text-end text-(--color-ink-muted)">—</td>
                                        @endif

                                        <td class="cell text-end italic text-(--color-badge-pending-ink)"
                                            data-label="{{ __('sales::field.amount') }}"
                                            x-text="gift.remarks"></td>

                                        <td class="cell-input text-end">
                                            <button type="button" @click="removeGift(i, g)"
                                                    aria-label="{{ __('sales::action.remove_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </template>
                    </table>
                </div>

                <p x-show="lines.length === 0" x-cloak
                   class="p-8 text-center text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.nothing_added') }}
                </p>
            </section>

            {{--
                ── কাজের প্যানেল — কার্টের ঠিক নিচে, পুরো প্রস্থে ──────────

                ── মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬) ────────────────────
                তিনি জিজ্ঞেস করেছিলেন পপআপ ভালো নাকি বাঁয়ে নিচে খোলা, আর
                যুক্তি শুনে **নিচেরটাই** বেছেছেন।

                ── কেন পপআপ নয় ───────────────────────────────────────────
                ⚠️ এই চারটার তিনটাই **টাকার অঙ্ক বদলায়** — খরচ লিখলে "নিট
                পরিশোধযোগ্য", জমা লিখলে "বিলের বকেয়া"। ওই দুইটা সংখ্যা ডান
                প্যানেলে। **পপআপ ঠিক সেগুলোকেই ঢেকে দিত** — টাইপ এক জায়গায়,
                ফল ঢাকা পড়া জায়গায়।

                ── আর জায়গার হিসাব ────────────────────────────────────────
                ডান প্যানেল ৩৪০px, এখানে ১২৪৫px। পরিবহনের তিনটা ঘর (গাড়ি ·
                চালক · ভাড়া) সরু কলামে একটার নিচে আরেকটা নামত।

                ⓘ উপহারের ঘরটাও আজ এভাবেই ইনলাইনে বসেছে — এক পর্দায় দুই
                রকম নিয়ম (কিছু ইনলাইন, কিছু পপআপ) শেখার বোঝা বাড়ায়।
            --}}
            <div x-ref="actionPanel">
                @include('sales::direct.partials.panels')
            </div>

            {{--
                ── উপহারগুলো সার্ভারে যায় এখান থেকে ─────────────────────

                কার্টের সারিগুলো নিজেরাই লুকানো ঘর বহন করে, কিন্তু উপহার
                পারে না: সার্ভার একটা **সমতল** তালিকা চায়
                (`gifts[0][…]`, `gifts[1][…]`), অথচ পর্দায় ওগুলো লাইনের
                ভেতরে বাসা বেঁধে আছে।

                `payloadGifts` ওই দুইটাকে মেলায় — আর ঠিক ওখানেই
                `against_product_id` বসে **লাইনের পণ্য থেকে**, কোনো
                ড্রপডাউন থেকে নয়।

                ⚠️ খালি productId বা শূন্য পরিমাণের উপহার এখানে ছেঁকে
                ফেলা হয়, নাহলে সার্ভারে অর্ধেক লেখা সারি যেত।
            --}}
            <template x-for="(g, n) in payloadGifts" :key="n">
                <span>
                    <input type="hidden" :name="`gifts[${n}][product_id]`" :value="g.productId">
                    <input type="hidden" :name="`gifts[${n}][against_product_id]`" :value="g.againstProductId">
                    <input type="hidden" :name="`gifts[${n}][qty]`" :value="g.qty">
                    <input type="hidden" :name="`gifts[${n}][remarks]`" :value="g.remarks">
                </span>
            </template>

        </div>

        {{-- ══ ডান পাশের প্যানেল ═══════════════════════════════════════ --}}
        <aside class="flex flex-col self-start rounded-(--radius-card) border
                      border-(--color-border) bg-(--color-surface-card)
                      xl:sticky xl:top-3 xl:max-h-[calc(100dvh-5.5rem)]">

            <div class="min-h-0 flex-1 overflow-y-auto">

            {{-- এই চালান --}}

            <div class="space-y-1 p-3 text-2xs">
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
                    <span class="flex items-center gap-2">
                        <input type="text" inputmode="decimal" x-model="discountInput"
                               placeholder="{{ __('sales::field.amount_or_pct') }}"
                               class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">

                        {{-- হিসাব হওয়া টাকাটা — "৬%" লিখে কত হলো তা না
                             দেখালে শতাংশ দেওয়াটা অন্ধ বাজি হয়ে যেত --}}
                        <span class="num w-16 text-end"
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
                        <span class="flex flex-wrap items-center justify-end gap-1">
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

                            <span class="num" x-text="'৳' + money(vatTotal)"></span>
                        </span>
                    </x-sales::panel-row>
                @endif

                @if ($show['expense'])
                    <x-sales::panel-row :label="__('sales::field.expense')">
                        <input type="number" step="0.01" min="0" name="expense_amount" x-model="expenseAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>

                    {{--
                        ── "খরচটা কীসের" — অঙ্কের ঠিক নিচে ──────────────────

                        ── কেন এখানে এলো (৩ সেপ্টেম্বর ২০২৬) ────────────────
                        মালিক NEXUS-এর নমুনা দেখিয়েছেন, সেখানে ঘরটা খরচের
                        অঙ্কের ঠিক নিচে। ABOS-এ ঘরটা **ছিল**, কিন্তু নিচের
                        বারের "খরচ" বোতামের পেছনের প্যানেলে — অর্থাৎ অঙ্কটা
                        এক পর্দায়, কারণটা আরেক পর্দায়।

                        ⚠️ ফলটা অনুমেয়: **খরচ বসত, কারণ বসত না।** আর "খরচ
                        ২০০" এক মাস পরে কারও কাজে আসে না — ওটা ভাড়া ছিল না
                        হাম্মালি, জানার একমাত্র সময় এখনই।

                        ⚠️ ঘরটা প্যানেল থেকে **সরানো হয়েছে, নকল করা হয়নি** —
                        একই `name` দুইবার থাকলে ব্রাউজার দুইটা মান পাঠাত আর
                        সার্ভারে শেষেরটা জিতত, নীরবে।
                    --}}
                    {{--
                        ⚠️ ঘরটা **টাকা বসলে তবেই খোলে**, আর তখন বাধ্যতামূলক।

                        ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ──────────────
                        *"Expense box e kichu amount bosale 'What the expense
                        is for' box open hobe & mendetory, ta bosate hobe"*।

                        ── কেন এটা ঠিক ──────────────────────────────────────
                        বেশিরভাগ চালানে কোনো খরচ থাকে না। ঘরটা সবসময় দেখালে
                        **প্রতিদিন একটা খালি ঘর** চোখের সামনে থাকত, আর যেদিন
                        সত্যিই দরকার সেদিনও সেটা আর চোখে পড়ত না।

                        ⚠️ আর টাকা বসার পরে ওটা **ঐচ্ছিক রাখা যায় না**: "খরচ
                        ২০০" এক মাস পরে কারও কাজে আসে না — ভাড়া ছিল না
                        হাম্মালি, জানার একমাত্র সময় এখনই, যখন যিনি টাকাটা
                        দিয়েছেন তিনি সামনেই দাঁড়ানো।
                    --}}
                    <template x-if="Number(expenseAmount) > 0">
                        <x-sales::panel-row :label="__('sales::field.expense_for')">
                            <input type="text" name="expense_narration" maxlength="191" required
                                   placeholder="{{ __('sales::field.expense_for_hint') }}"
                                   class="h-(--spacing-inline) w-40 rounded-(--radius-field) border
                                          border-(--color-warning) bg-(--color-surface-app) px-1">
                        </x-sales::panel-row>
                    </template>
                @endif

                @if ($show['rounding'])
                    <x-sales::panel-row :label="__('sales::field.rounding')">
                        <input type="number" step="0.01" min="0" name="rounding_amount" x-model="roundingAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif
                <x-sales::panel-row :label="__('sales::field.net_payable')" strong>
                    <span class="num text-sm" x-text="'৳' + money(netPayable)"></span>
                </x-sales::panel-row>

                @if ($show['deposit'])
                    <x-sales::panel-row :label="__('sales::field.received_deposit')">
                        <input type="number" step="0.01" min="0" name="deposit" x-model="deposit"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.invoice_due')" strong>
                    <span class="num" x-text="'৳' + money(invoiceDue)"></span>
                </x-sales::panel-row>
                <x-sales::panel-row :label="__('sales::field.previous_balance')">
                    <span class="num" x-text="customer.due > 0 ? money(customer.due) : '—'"></span>
                </x-sales::panel-row>

                <x-sales::panel-row :label="__('sales::field.outstanding')" strong>
                    <span class="num" x-text="outstanding > 0 ? money(outstanding) : '—'"></span>
                </x-sales::panel-row>
                @foreach ([
                    ['label' => 'sales::field.total_item', 'expr' => 'counts.totalItem', 'on' => 'total_item'],
                    ['label' => 'sales::field.total_sales_qty', 'expr' => 'counts.totalSalesQty', 'on' => 'sales_qty'],
                    ['label' => 'sales::field.total_free_qty', 'expr' => 'counts.totalFreeQty', 'on' => 'free_qty_total'],
                    ['label' => 'sales::field.total_free_plus_sales', 'expr' => 'counts.totalQty', 'on' => 'total_qty'],
                ] as $row)
                    @if ($show[$row['on']])
                        <x-sales::panel-row :label="__($row['label'])">
                            <span class="num" x-text="{{ $row['expr'] }} || '—'"></span>
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
                    ── ছয়টা কাজ, মালিকের দেওয়া ক্রমে ──────────────────────

                    *"Deposit, Note, Chart, Shipment, Transportation,
                    Clear All"* (৩ সেপ্টেম্বর ২০২৬)।

                    তিন কলামে বসায় পড়া হয় বাঁ থেকে ডানে, উপর থেকে নিচে —
                    হুবহু তাঁর ক্রম:

                        জমা যোগ  ·  নোট যোগ   ·  চার্ট
                        শিপমেন্ট  ·  পরিবহন    ·  সব মুছুন

                    ⚠️ **"সব মুছুন" সবার শেষে, আর একা লাল** — ধ্বংসাত্মক
                    বোতাম প্রথমে থাকলে চোখ ওটাতেই আগে পড়ত।

                    ⓘ প্যানেল-খোলা তিনটা বোতামের ক্লাস এক জায়গায় লেখা
                    ($panelBtn), কারণ ওরা পাশাপাশি নয় — foreach দিয়ে আঁকা
                    যেত না, আর তিনবার হাতে লিখলে একদিন একটা আলাদা দেখাত।
                --}}
                @php
                    $panelBtn = 'rounded-(--radius-field) border border-(--color-border) px-1 py-1.5
                                 text-2xs leading-tight transition-colors';
                @endphp

                <div class="grid grid-cols-3 gap-1">
                    {{-- ১ · জমা — একমাত্র বোতাম যেটা টাকা ভেতরে আনে, তাই সবুজ --}}
                    @if ($show['deposit'])
                        <button type="button" @click="openPanel('deposit')"
                                :class="panel === 'deposit'
                                    ? 'bg-(--color-surface-selected) border-(--color-brand-500) font-semibold'
                                    : 'hover:bg-(--color-surface-hover)'"
                                class="rounded-(--radius-field) border border-(--color-badge-success-ink)/30
                                       bg-(--color-badge-success-bg) px-1 py-1.5 text-2xs font-medium
                                       leading-tight text-(--color-badge-success-ink) transition-colors">
                            {{ __('sales::action.add_deposit') }}
                        </button>
                    @endif

                    {{-- ২ · নোট --}}
                    <button type="button" @click="openPanel('note')"
                            :class="panel === 'note'
                                ? 'bg-(--color-surface-selected) border-(--color-brand-500) font-semibold'
                                : 'hover:bg-(--color-surface-hover)'"
                            class="{{ $panelBtn }}">
                        {{ __('sales::action.add_note') }}
                    </button>

                    {{-- ৩ · শিপমেন্ট --}}
                    @if ($show['shipment'])
                        <button type="button" @click="openPanel('shipment')"
                                :class="panel === 'shipment'
                                    ? 'bg-(--color-surface-selected) border-(--color-brand-500) font-semibold'
                                    : 'hover:bg-(--color-surface-hover)'"
                                class="{{ $panelBtn }}">
                            {{ __('sales::action.shipment') }}
                        </button>
                    @endif

                    {{-- ৪ · চার্ট — নিজের বোতাম নিজেই আঁকে --}}
                    <x-sales::bulk-sheet :products="$sheetProducts" :stock="$sheetStock" :free-qty="$show['free_qty']" />

                    {{-- ৫ · পরিবহন --}}
                    @if ($show['transport'])
                        <button type="button" @click="openPanel('transport')"
                                :class="panel === 'transport'
                                    ? 'bg-(--color-surface-selected) border-(--color-brand-500) font-semibold'
                                    : 'hover:bg-(--color-surface-hover)'"
                                class="{{ $panelBtn }}">
                            {{ __('sales::action.transportation') }}
                        </button>
                    @endif

                    {{--
                        ৬ · সব মুছুন — ভরাট লাল, আর পর্দার একমাত্রটা।

                        ⚠️ পাশের "এই লাইন" বাক্সে আরেকটা বোতামে ইংরেজিতে
                        "Clear Data" লেখা, আর ওটা কেবল চলতি লাইনটা মোছে।
                        এটা পুরো চালান মোছে, **আর ফেরানো যায় না** — তাই
                        পার্থক্যটা লেখা নয়, রঙ বলে।
                    --}}
                    <button type="button" @click="clearAll()"
                            class="rounded-(--radius-field) bg-(--color-danger) px-1 py-1.5
                                   text-2xs font-semibold leading-tight text-white
                                   hover:bg-(--color-danger)/90">
                        {{ __('sales::action.clear_full') }}
                    </button>
                </div>

                {{-- সারি ৩ — শেষ করা।

                     পুরো প্রস্থে আর সবার নিচে: কাউন্টারে এটাই শেষ চাপ, আর
                     অঙ্কটা গায়ে লেখা বলে **কত টাকার কাগজ পাকা হচ্ছে সেটা
                     চাপ দেওয়ার আগেই চোখে পড়ে** (মালিকের সিদ্ধান্ত)। --}}
                <x-ui.button type="submit" tone="primary" class="mt-2 w-full py-2"
                             ::disabled="! canConfirm">
                    {{ __('sales::action.confirm') }}
                    <span class="num ms-2 font-semibold" x-text="'৳' + money(netPayable)"></span>
                </x-ui.button>
            </div>

        </aside>

    </form>

    @push('scripts')
        <script>
            function directSale(catalogue, customers, walkinId, vatEnabled, packs) {
                return {
                    catalogue,
                    customers,
                    vatEnabled,
                    term: '',
                    pickerOpen: false,
                    picked: null,
                    showCosting: false,
                    /*
                     * ⚠️ `gifts` এন্ট্রির **ভেতরে**, আলাদা তালিকা নয়।
                     *
                     * ── কেন (৩ সেপ্টেম্বর ২০২৬) ──────────────────────────
                     * আগে উপহারগুলো একটা আলাদা `gifts: []` তালিকায় থাকত,
                     * আর প্রতিটা উপহারে একটা "কোন পণ্যের জন্য" ড্রপডাউন
                     * ছিল — অর্থাৎ **উপহার আর পণ্যের সম্পর্কটা ছিল একটা
                     * বাছাই, যেটা মানুষ ভুল করতে পারত বা খালি রেখে দিত।**
                     *
                     * আর কোডে ওটা আগে থেকে বসানোর চেষ্টাও ছিল
                     * (`againstProductId: this.picked?.id`), কিন্তু সেটা
                     * **কোনোদিন কাজ করত না**: কার্টে যোগ করার আগে চাপলে
                     * পণ্যটা তালিকায় নেই, আর পরে চাপলে `picked` তখন null।
                     *
                     * এখন উপহারটা লাইনের ভেতরেই থাকে। **সম্পর্কটা আর
                     * বাছাই নয়, অবস্থান** — লাইন মুছলে উপহারও যায়, আর
                     * ভুল লাইনে বসার কোনো পথই নেই।
                     */
                    entry: { qty: '', freeQty: '', rate: '', discountInput: '', unitId: '', gifts: [] },

                    // পণ্যপ্রতি প্যাকের তালিকা — সার্ভার থেকে একবারেই
                    packs,
                    lines: [],

                    // একবারে একটাই উপহারের ঘর খোলা থাকে
                    giftDraft: null,
                    /*
                     * শুরুতে কে বাছা থাকে।
                     *
                     * ── কেন এই লাইনটা লাগল (২ সেপ্টেম্বর ২০২৬) ──────────
                     * আগে এটা ছিল একটা `<select>`, আর ব্রাউজার নিজে থেকেই
                     * প্রথম option-টা বেছে রাখত — অর্থাৎ ক্রেতা কখনো
                     * "কেউ না" হত না, যদিও কোডে কোথাও তা লেখা ছিল না।
                     *
                     * `<select>`-টা চিহ্ন হয়ে যাওয়ায় ওই নীরব ডিফল্টটাও
                     * চলে গেছে। যে কোম্পানিতে `sales.walkin_customer_id`
                     * বসানো নেই, সেখানে `customerId` খালি থেকে যেত আর
                     * চালান যেত ক্রেতা ছাড়াই। ব্রাউজারে ক্লিক করে দেখতে
                     * গিয়ে ধরা পড়েছে, কোড পড়ে নয়।
                     *
                     * তাই ক্রমটা স্পষ্ট করে লেখা: **নগদ ক্রেতা, নাহলে
                     * তালিকার প্রথমজন, নাহলে সত্যিই কেউ না** — শেষেরটা
                     * তখনই, যখন কোম্পানিতে একটাও ক্রেতা নেই।
                     */
                    /*
                     * ⚠️ কেউ আগে থেকে বাছা থাকে না — ইচ্ছাকৃতভাবে।
                     *
                     * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ──────────────
                     * *"Walk-in Customer default bose thakte parbe na …
                     * eta POS na, eta depot/wholesale counter. Obosoi
                     * dekhe nishchit hoye party select korte hobe."*
                     *
                     * আগে এখানে `walkinId` বসত, নাহলে **তালিকার প্রথম
                     * গ্রাহকটাই** — অর্থাৎ পর্দা খুললেই একজনের নাম বসানো
                     * থাকত, আর সেটা কার নাম তা কেউ বেছে দেয়নি।
                     *
                     * ⚠️ ফাঁকা রাখা মানে "নিশ্চিত করুন" বোতামও বন্ধ থাকে
                     * ([[canConfirm]]), আর সার্ভারেও ঘরটা এখন
                     * `required` — **তিন জায়গাতেই**, কারণ একটাতে ফাঁক
                     * থাকলে বাকি দুইটা অর্থহীন।
                     */
                    customerId: '',
                    customerPickerOpen: false,
                    customerTerm: '',
                    /*
                     * বাকির শর্ত — ড্রপডাউনের মান।
                     *
                     * `'0'` মানে আজই (দিন শেষে), আর সেটাই ডিফল্ট।
                     * `'custom'` মানে নিচে তারিখের ঘরটা বেরোবে।
                     */
                    creditTerm: '0',
                    dueOn: '',
                    discountInput: '',

                    // পুরো কাগজের ভ্যাট-বদল — খালি মানে পণ্য অনুযায়ী
                    vatMode: '',
                    vatRate: '',
                    expenseAmount: '',
                    roundingAmount: '',
                    deposit: '',
                    panel: '',
                    depositMethod: 'cash',

                    nextKey: 1,

                    /*
                     * ══ অসমাপ্ত চালান — রিফ্রেশ বা বিদ্যুৎ গেলেও থাকে ══════
                     *
                     * ── কোন প্রশ্ন থেকে এটা এলো (৩ সেপ্টেম্বর ২০২৬) ─────────
                     * মালিক: *"ধরো আমি একটা সেল করছি, এই মুহূর্তে হঠাৎ
                     * ভুলেই রিফ্রেশ চাপ পড়ে গেছে — অর্ধেক অর্ডার নেওয়ার পরে।
                     * তাহলে তো পুরা কাজই শেষ।"*
                     *
                     * ⚠️ **তিনি ঠিক**: এতদিন সবকিছু কেবল ব্রাউজারের স্মৃতিতে
                     * ছিল। ভুল করে F5, বিদ্যুৎ চলে যাওয়া, ট্যাব বন্ধ — বিশটা
                     * লাইন এক মুহূর্তে শেষ, আর ক্রেতা সামনে দাঁড়িয়ে।
                     *
                     * ── কেন সার্ভারে নয়, ব্রাউজারে ────────────────────────
                     * অসমাপ্ত চালান কোনো **নথি নয়** — ওটার নম্বর নেই, খাতায়
                     * কিছু বসে না, আর কেউ ওটা রিপোর্টে খুঁজবে না। সার্ভারে
                     * রাখলে একটা টেবিল, একটা নম্বর-সিরিজ আর "এই খসড়াগুলো
                     * কে মুছবে" — একটা আস্ত নতুন প্রশ্ন তৈরি হত।
                     *
                     * ⓘ আর ব্রাউজারে রাখাটা **তাৎক্ষণিক**: প্রতিটা কীস্ট্রোকে
                     * সার্ভারে গেলে কাউন্টারে দেরি হত, আর ইন্টারনেট গেলে
                     * সুরক্ষাটাই কাজ করত না — অথচ ঠিক তখনই ওটা সবচেয়ে দরকার।
                     *
                     * ⚠️ **চাবিতে কোম্পানি আর ব্যবহারকারী দুইটাই** — এক
                     * ব্রাউজারে দুইজন কর্মী পালা করে বসেন, আর একজনের অসমাপ্ত
                     * চালান অন্যজনের পর্দায় ফিরে এলে **ভুল পার্টির নামে বিল**
                     * হয়ে যেত।
                     */
                    draftKey: 'abos.direct-sale.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',

                    /** ফিরিয়ে আনার প্রস্তাব — খসড়া পাওয়া গেলে উপরে বার দেখায়। */
                    draftFound: false,
                    draftAt: '',

                    /*
                     * খসড়া লেখা — যা যা টাইপ করা হয়েছে, সব।
                     *
                     * ⚠️ **কার্ট খালি হলে খসড়া মুছে যায়**, রাখা হয় না। খালি
                     * খসড়া ফিরিয়ে আনার প্রস্তাব দেওয়া মানে প্রতিদিন সকালে
                     * একটা অর্থহীন প্রশ্ন করা।
                     */
                    saveDraft() {
                        /*
                         * ⚠️ প্রস্তাব পর্দায় থাকা অবস্থায় কিছু লেখা বা মোছা নয়।
                         *
                         * এই লাইনটা ছাড়া বাগটা নীরব ও মারাত্মক হত: পাতা খোলার
                         * সাথে সাথেই `x-effect` একবার চলে, আর তখন কার্ট খালি —
                         * অর্থাৎ **খসড়াটা মুছে যেত ঠিক সেই মুহূর্তে যখন
                         * ব্যবহারকারীকে ফেরানোর প্রস্তাব দেখানো হচ্ছে**।
                         * বোতামটা থাকত, চাপলে কিছুই ফিরত না।
                         */
                        if (this.draftFound) return;

                        try {
                            if (this.lines.length === 0) {
                                localStorage.removeItem(this.draftKey);

                                return;
                            }

                            localStorage.setItem(this.draftKey, JSON.stringify({
                                at: new Date().toISOString(),
                                customerId: this.customerId,
                                creditTerm: this.creditTerm,
                                dueOn: this.dueOn,
                                lines: this.lines,
                                discountInput: this.discountInput,
                                vatMode: this.vatMode,
                                vatRate: this.vatRate,
                                expenseAmount: this.expenseAmount,
                                roundingAmount: this.roundingAmount,
                                deposit: this.deposit,
                                depositMethod: this.depositMethod,
                                nextKey: this.nextKey,
                            }));
                        } catch (e) {
                            /*
                             * ⚠️ চুপ করে থাকা ইচ্ছাকৃত।
                             *
                             * localStorage বন্ধ থাকতে পারে (ব্যক্তিগত উইন্ডো,
                             * সাইট-ডেটা বন্ধ করা ব্রাউজার), আর তখন লেখা
                             * ব্যতিক্রম ছোঁড়ে। **কিন্তু ওটা বিক্রি থামানোর
                             * কারণ নয়** — সুরক্ষাটা না পেলেও কাউন্টার চলবে।
                             */
                        }
                    },

                    /** পাতা খোলার সময় — আছে কিনা দেখা, নিজে থেকে ফেরানো নয়। */
                    lookForDraft() {
                        try {
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

                    /*
                     * ⚠️ ফিরিয়ে আনা **কেবল চাপ দিলে** — নিজে থেকে নয়।
                     *
                     * নিজে থেকে ফিরিয়ে আনলে সবচেয়ে বিপজ্জনক জিনিসটা ঘটত:
                     * কেউ নতুন বিক্রি শুরু করতে এসে **আগের অসমাপ্ত চালানটা
                     * পেয়ে যেতেন, না বুঝে** — আর তার উপরেই নতুন লাইন যোগ
                     * করে নিশ্চিত করে ফেলতেন। **ভুল ক্রেতার নামে ভুল মাল।**
                     */
                    restoreDraft() {
                        try {
                            const d = JSON.parse(localStorage.getItem(this.draftKey) || '{}');

                            this.customerId = d.customerId ?? '';
                            this.creditTerm = d.creditTerm ?? '0';
                            this.dueOn = d.dueOn ?? '';
                            this.lines = d.lines ?? [];
                            this.discountInput = d.discountInput ?? '';
                            this.vatMode = d.vatMode ?? '';
                            this.vatRate = d.vatRate ?? '';
                            this.expenseAmount = d.expenseAmount ?? '';
                            this.roundingAmount = d.roundingAmount ?? '';
                            this.deposit = d.deposit ?? '';
                            this.depositMethod = d.depositMethod ?? 'cash';
                            this.nextKey = d.nextKey ?? (this.lines.length + 1);
                        } catch (e) {
                            // ভাঙা খসড়া — ফেরানোর চেয়ে বাদ দেওয়াই নিরাপদ
                        }

                        this.draftFound = false;
                    },

                    /** প্রস্তাব ফিরিয়ে দেওয়া — খসড়াটাও সাথে যায়। */
                    dropDraft() {
                        try {
                            localStorage.removeItem(this.draftKey);
                        } catch (e) {
                            // উপরের একই কারণ
                        }

                        this.draftFound = false;
                    },

                    /*
                     * খোলা মাত্রই তালিকা — ক্রেতার চিহ্নের মতোই।
                     *
                     * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ──────────────
                     * *"customer icon e click korle zemon search button
                     * open hoy, products search icon-eও zate emon hoy"*।
                     *
                     * ── পার্থক্যটা কোথায় ছিল ──────────────────────────────
                     * দুইটাই চিহ্নে চাপলে খুলত, কিন্তু:
                     *
                     *     ক্রেতা  খোলা মাত্রই তালিকা — না লিখেও বাছা যায়
                     *     পণ্য    টাইপ না করা পর্যন্ত **কিছুই নেই**
                     *
                     * ⚠️ ফলে পণ্যের চিহ্নটা চেপে মনে হত কিছুই হয়নি — ঘরটা
                     * এসেছে ঠিকই, কিন্তু **নিচে শূন্য**। যিনি পণ্যের নাম
                     * জানেন না, তাঁর পক্ষে শুরু করারই উপায় ছিল না।
                     *
                     * ⓘ ৩০-এর সীমাটা দুই জায়গাতেই এক। পুরো তালিকা আঁকলে দুই
                     * হাজার পণ্যের গুদামে প্রতিটা কীস্ট্রোকে পাতা কাঁপত।
                     */
                    get visible() {
                        if (! this.pickerOpen) return [];

                        const t = this.term.trim().toLowerCase();

                        if (t === '') return this.catalogue.slice(0, 30);

                        return this.catalogue.filter(p =>
                            p.name.toLowerCase().includes(t)
                            || p.code.toLowerCase().includes(t)
                            || (p.barcode || '').toLowerCase().includes(t)
                        ).slice(0, 30);
                    },

                    get customer() {
                        return this.customers[this.customerId]
                            || { limit: 0, due: 0, days: 0, name: '', phone: '', address: '', location: '' };
                    },

                    /*
                     * চালান নিশ্চিত করা যাবে কখন।
                     *
                     * ── কেন দুইটা শর্ত, একটা নয় ─────────────────────────
                     * আগে কেবল **কার্ট খালি কিনা** দেখা হত, কারণ ক্রেতা
                     * সবসময় আগে থেকেই বসানো থাকত। ডিফল্ট তুলে দেওয়ায়
                     * শর্তটা অসম্পূর্ণ হয়ে গেছে: **মাল আছে অথচ কার নামে
                     * তা কেউ বলেনি** — এমন চালান আর সম্ভব নয়।
                     *
                     * ⚠️ সার্ভারও `required` দেখে, আর সেটাই আসল পাহারা।
                     * এই লাইনটা কেবল **বোতামটা মিথ্যা না বলার জন্য** —
                     * চাপা যায় অথচ কিছু হয় না, ওটাই সবচেয়ে বিরক্তিকর।
                     */
                    /*
                     * বাছা পণ্যটার প্যাকগুলো।
                     *
                     * ⚠️ খালি অ্যারে মানে দুইটা আলাদা কথা, আর দুইটাতেই ঘরটা
                     * পড়ার-জন্য থাকে — তাই আলাদা করার দরকার নেই:
                     *     • কন্ট্রোল প্যানেলের সুইচ বন্ধ
                     *     • পণ্যটার একটাই একক
                     */
                    get entryUnits() {
                        if (! this.picked) return [];

                        return this.packs[this.picked.id] ?? [];
                    },

                    /*
                     * কাজের প্যানেল খোলা ও বন্ধ — একবারে একটাই।
                     *
                     * ── কেন খোলার সাথে পর্দা নামে (৩ সেপ্টেম্বর ২০২৬) ─────
                     * বোতামগুলো **ডান পাশে**, আর প্যানেলটা খোলে **কার্টের
                     * নিচে** — অর্থাৎ চাপ এক জায়গায়, ফল আরেক জায়গায়।
                     *
                     * ⚠️ বিশ লাইনের কার্টে ওটা পর্দার বাইরে পড়ত, আর চেপে
                     * মনে হত **কিছুই হয়নি** — তারপর আবার চাপলে প্যানেলটা
                     * বন্ধ হয়ে যেত। ঠিক এই ফাঁদেই আজ Action মেনুটা পড়েছিল।
                     *
                     * ⓘ `block: 'nearest'` — ইতিমধ্যেই দেখা যাচ্ছে এমন হলে
                     * পর্দা নড়ে না। অকারণ লাফানো নিজেই একটা বিরক্তি।
                     */
                    openPanel(name) {
                        this.panel = this.panel === name ? '' : name;

                        if (! this.panel) return;

                        this.$nextTick(() => this.$refs.actionPanel?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'nearest',
                        }));
                    },

                    get canConfirm() {
                        return this.lines.length > 0 && this.customerId !== '';
                    },

                    /*
                     * ক্রেতা খোঁজা — নাম, কোড আর ফোন, তিনটাতেই।
                     *
                     * ফোনটা ইচ্ছাকৃত: কাউন্টারে অনেক সময় দোকানের নাম
                     * মনে থাকে না, নম্বরটা থাকে — ফোনেই তো অর্ডারটা
                     * এসেছিল।
                     *
                     * ⚠️ খালি লেখায় **পুরো তালিকা** দেখানো হয়, প্রথম
                     * ত্রিশটা। চিহ্নে চাপ দিয়ে কেউ যদি কিছু না লেখেন,
                     * একটা ফাঁকা প্যানেল দেখে তিনি ভাববেন কোনো গ্রাহকই
                     * নেই — অথচ তিনি কেবল এখনো কিছু টাইপ করেননি।
                     */
                    get customerMatches() {
                        const t = this.customerTerm.trim().toLowerCase();

                        const rows = Object.entries(this.customers)
                            .map(([id, c]) => ({ id, ...c }));

                        return (t === '' ? rows : rows.filter(c =>
                            (c.name || '').toLowerCase().includes(t)
                            || (c.code || '').toLowerCase().includes(t)
                            || (c.phone || '').toLowerCase().includes(t)
                        )).slice(0, 30);
                    },

                    /*
                     * শর্ত বদলালে তারিখের ঘরটা মেলানো।
                     *
                     * "নির্দিষ্ট তারিখ" ছাড়া বাকি সব বিকল্পে তারিখটা দিন
                     * থেকে গোনা হয়, আর সার্ভারেও তাই — তাই ঘরটা খালি করে
                     * দেওয়া হয়, নাহলে আগের বাছাইয়ের একটা বাসি তারিখ
                     * ফর্মের সাথে চলে যেত।
                     *
                     * ⚠️ গোনা শুরু হয় **বিলের তারিখ থেকে**, আজ থেকে নয় —
                     * ব্যাক-ডেটেড বিলে দুইটা আলাদা, আর আজ থেকে গুনলে
                     * মেয়াদটা ভুল দিনে পড়ত।
                     */
                    termChanged() {
                        if (this.creditTerm !== 'custom') {
                            this.dueOn = '';

                            return;
                        }

                        // তারিখের ঘরটা খালি খুলবে না — একটা যুক্তিসঙ্গত শুরু
                        const el = this.$root.querySelector('input[name=trx_date]');
                        const d = el && el.value ? new Date(el.value + 'T00:00:00') : new Date();
                        d.setDate(d.getDate() + 30);
                        this.dueOn = d.toISOString().slice(0, 10);
                    },

                    chooseCustomer(id) {
                        this.customerId = String(id);

                        /*
                         * ক্রেতার নিজের মেয়াদ থাকলে সেটাই বসে।
                         *
                         * ডিফল্ট "আজ" কাউন্টারের স্বাভাবিক অবস্থা, কিন্তু
                         * যে দোকানের সাথে ৩০ দিনের কথা আছে তাঁর বেলায় ওটা
                         * ব্যতিক্রম — আর ব্যতিক্রমটা সিস্টেম নিজেই জানে।
                         * প্রতিবার হাতে বাছতে দেওয়া মানে একদিন কেউ ভুলে
                         * যাবেন, আর নগদ বলে বসে থাকা একটা বাকি বিল
                         * কাউকে তাগাদা দেওয়া হবে না।
                         *
                         * তালিকায় ওই দিনসংখ্যা না থাকলে বদলানো হয় না —
                         * নাহলে ড্রপডাউনটা এমন একটা মান দেখাত যা তার
                         * নিজের বিকল্পে নেই, আর ঘরটা ফাঁকা দেখাত।
                         */
                        const days = Number(this.customers[this.customerId]?.days || 0);
                        const has = [...this.$root.querySelectorAll('select option')]
                            .some(o => o.value === String(days));

                        if (days > 0 && has) {
                            this.creditTerm = String(days);
                            this.dueOn = '';
                        }
                        this.customerTerm = '';
                        this.customerPickerOpen = false;
                    },

                    pickFirstCustomer() {
                        const first = this.customerMatches[0];
                        if (first) this.chooseCustomer(first.id);
                    },

                    /*
                     * চিহ্নে চাপলে ঘরটা খোলে, আর সাথে সাথেই ফোকাস।
                     *
                     * `$nextTick` ছাড়া চলত না: `x-show` ঘরটাকে ওই মুহূর্তে
                     * এখনো `display:none`-এ রাখে, আর লুকানো ঘরে ফোকাস দিলে
                     * ব্রাউজার নীরবে কিছুই করে না — বোতামে চাপ দিয়ে মানুষ
                     * টাইপ শুরু করতেন আর কোথাও কিছু বসত না।
                     */
                    openPicker() {
                        this.pickerOpen = true;
                        this.term = '';
                        this.$nextTick(() => this.$refs.search?.focus());
                    },

                    pick(product) {
                        this.picked = product;
                        this.entry.rate = product.rate;
                        this.entry.qty = this.entry.qty || '1';
                        this.term = '';
                        // বাছা হয়ে গেছে — তালিকাটা আর কিছু বলার নেই
                        this.pickerOpen = false;
                    },

                    pickFirst() {
                        const first = this.visible[0];
                        if (first) this.pick(first);
                    },

                    // ── চলতি লাইনের অঙ্ক ────────────────────────────────
                    get entryBase() {
                        return (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
                    },

                    get entryAfterDiscount() {
                        return this.entryBase
                            - this.entryDiscount;
                    },

                    /*
                     * লাইনের ছাড় — টাকায় বা শতাংশে, একটাই ঘর।
                     *
                     * ── কেন একটাই (৩ সেপ্টেম্বর ২০২৬) ────────────────────
                     * আগে ছাড়ের ঘরটা বাঁয়ের ফর্মে ছিল ("ছাড় %"), আর অঙ্কটা
                     * দেখা যেত না। মালিক ঘরগুলো এক সারিতে নামিয়ে বাকিগুলো
                     * তুলে দিতে বললেন — *"this line e egulo ache, dubar
                     * dorkar nai"* — তাই ছাড়টা এখন কেবল "এই লাইন" প্যানেলে,
                     * আর **লেখা ও ফল পাশাপাশি**।
                     *
                     * শেষে `%` থাকলে শতাংশ, নাহলে সোজা টাকা।
                     *
                     * ⚠️ ছাড় লাইনের মোটের চেয়ে বড় হতে দেওয়া হয় না — দিলে
                     * লাইনটা ঋণাত্মক হত, আর তখন **বিক্রির কাগজে একটা সারি
                     * গ্রাহককে টাকা ফেরত দিত**।
                     */
                    get entryDiscount() {
                        const raw = String(this.entry.discountInput || '').trim();

                        if (raw === '') return 0;

                        const isPercent = raw.endsWith('%');
                        const n = Number(raw.replace('%', '').trim());

                        if (! isFinite(n) || n <= 0) return 0;

                        const value = isPercent ? this.entryBase * n / 100 : n;

                        return Math.min(value, this.entryBase);
                    },

                    /*
                     * সার্ভার লাইনের ছাড় **শতাংশে** নেয়, তাই টাকায় লেখা হলে
                     * এখানে ফিরিয়ে দেওয়া হয়।
                     *
                     * ⚠️ ভিত্তি শূন্য হলে শতাংশ বের করা যায় না (কোনো দর বা
                     * পরিমাণ বসানো হয়নি) — তখন ০, কারণ ছাড় বলে কিছু নেই।
                     */
                    get entryDiscountPercent() {
                        if (this.entryBase <= 0) return '';

                        return String(this.entryDiscount * 100 / this.entryBase);
                    },

                    get entryVat() {
                        if (! this.vatEnabled || ! this.picked) return 0;
                        return this.vatOn(this.entryAfterDiscount, this.picked);
                    },

                    get entryNet() {
                        return this.isInclusive(this.picked)
                            ? this.entryAfterDiscount
                            : this.entryAfterDiscount + this.entryVat;
                    },

                    /* বিক্রয় + ফ্রি — গুদাম থেকে মোট যতটা বেরোবে।
                       নমুনায় ঘরটা নিজে থেকেই ভরে, হাতে লেখা যায় না। */
                    get entryTotalQty() {
                        return (Number(this.entry.qty) || 0) + (Number(this.entry.freeQty) || 0);
                    },

                    /*
                     * শিট থেকে আসা সারিগুলো কার্টে।
                     *
                     * ── কেন ইভেন্ট, সরাসরি ডাকা নয় ────────────────────
                     * শিটটা জানে না কে শুনছে — তাই একই শিট চালানে,
                     * সরাসরি বিক্রয়ে আর ক্রয়েও বসে। তিনটা কপি থাকলে
                     * একদিন একটার অঙ্ক বদলাত, বাকি দুইটা থাকত।
                     *
                     * ── একই পণ্য দুইবার এলে সারি বাড়ে না ──────────────
                     * পরিমাণ বাড়ে। নাহলে এক পণ্যে দুই সারি, আর কাগজে
                     * একই নাম দুইবার।
                     */
                    absorbBulk(rows) {
                        (rows || []).forEach(row => {
                            const p = this.catalogue.find(c => String(c.id) === String(row.product_id));
                            if (! p) { return; }

                            const already = this.lines.find(l => l.id === p.id);

                            if (already) {
                                already.qty = String((parseFloat(already.qty) || 0)
                                    + (parseFloat(row.qty) || 0));
                                already.freeQty = String((parseFloat(already.freeQty) || 0)
                                    + (parseFloat(row.free_qty) || 0)) || '';

                                return;
                            }

                            this.lines.push({
                                key: this.nextKey++,
                                id: p.id,
                                name: p.name,
                                unit: p.unit,
                                vatRate: p.vatRate || 0,
                                vatInclusive: !! p.vatInclusive,
                                qty: String(row.qty || '0'),
                                freeQty: row.free_qty || '',
                                rate: String(row.rate || p.rate || 0),
                                discountPercent: '',
                                gifts: [],
                            });
                        });

                        this.panel = '';
                    },

                    addToCart() {
                        if (! this.picked) return;

                        this.lines.push({
                            key: this.nextKey++,
                            id: this.picked.id,
                            name: this.picked.name,
                            unit: this.picked.unit,
                            vatRate: this.picked.vatRate || 0,
                            vatInclusive: !! this.picked.vatInclusive,
                            qty: this.entry.qty || '1',
                            freeQty: this.entry.freeQty || '',
                            rate: this.entry.rate || '0',
                            discountPercent: this.entryDiscountPercent,
                            unitId: this.entry.unitId || '',
                            gifts: this.entry.gifts,
                        });

                        this.clearEntry();
                        this.$nextTick(() => this.$refs.search.focus());
                    },

                    clearEntry() {
                        this.picked = null;
                        this.entry = { qty: '', freeQty: '', rate: '', discountInput: '', unitId: '', gifts: [] };
                        this.giftDraft = null;
                        this.term = '';
                        this.showCosting = false;
                    },

                    clearAll() {
                        this.lines = [];
                        this.discountInput = '';
                        this.expenseAmount = '';
                        this.roundingAmount = '';
                        this.deposit = '';
                        this.clearEntry();

                        /*
                         * ⚠️ সংরক্ষিত খসড়াও যায় — মালিকের নির্দেশ:
                         * *"এরপর না লাগলে ক্লিয়ার ডাটা দিলে যেন মুছে যায়"*।
                         *
                         * না মুছলে "সব মুছুন" একটা মিথ্যা হয়ে যেত: পর্দা
                         * খালি দেখাত, অথচ পরের বার পাতা খুললেই **মুছে ফেলা
                         * চালানটা ফিরে আসার প্রস্তাব** আসত।
                         */
                        this.dropDraft();
                    },

                    /*
                     * ── উপহার — যে পণ্যটা এখন হাতে, তার সাথেই ─────────────
                     *
                     * বোতামটা পণ্য না বাছা পর্যন্ত নিষ্ক্রিয়। কারণ উপহার
                     * সবসময় **কোনো একটা পণ্যের সাথে** যায়, আর কোনটার সাথে
                     * সেটা এখানে জিজ্ঞেস করা হয় না — যেটা হাতে আছে, সেটাই।
                     */
                    openGift() {
                        if (! this.picked) return;

                        this.giftDraft = this.giftDraft
                            ? null
                            : { productId: '', qty: '1', remarks: @js(__('sales::message.not_for_sales')) };
                    },

                    commitGift() {
                        const g = this.giftDraft;

                        if (! g || ! g.productId || ! (Number(g.qty) > 0)) return;

                        this.entry.gifts.push({ key: this.nextKey++, ...g });
                        this.giftDraft = null;
                    },

                    removeGift(lineIndex, giftIndex) {
                        this.lines[lineIndex].gifts.splice(giftIndex, 1);
                    },

                    /*
                     * সব লাইনের উপহার একসাথে — গোনার জন্য।
                     *
                     * ⚠️ এন্ট্রির উপহারগুলো এখানে নেই, ইচ্ছে করে: ওগুলো
                     * এখনো কার্টে যায়নি, তাই যোগফলেও যাওয়ার কথা নয়।
                     */
                    get allGifts() {
                        return this.lines.flatMap((line) => line.gifts || []);
                    },

                    /*
                     * সার্ভারে যা যায়।
                     *
                     * ⭐ `against_product_id` কোনো ড্রপডাউন থেকে আসে না —
                     * **উপহারটা যে লাইনের ভেতরে বসে আছে, সেই লাইনের পণ্য**।
                     * তাই ওটা খালি থাকতে পারে না, আর ভুলও হতে পারে না।
                     */
                    get payloadGifts() {
                        return this.lines.flatMap((line) =>
                            (line.gifts || [])
                                .filter((g) => g.productId && Number(g.qty) > 0)
                                .map((g) => ({
                                    productId: g.productId,
                                    againstProductId: line.id,
                                    qty: g.qty,
                                    remarks: g.remarks,
                                })),
                        );
                    },

                    // ── কার্টের অঙ্ক ────────────────────────────────────
                    lineBase(line) {
                        return (Number(line.qty) || 0) * (Number(line.rate) || 0);
                    },

                    lineAfterDiscount(line) {
                        return this.lineBase(line)
                            - this.lineBase(line) * (Number(line.discountPercent) || 0) / 100;
                    },

                    /*
                     * ভ্যাট — সার্ভারের নিয়মেই, দুই রকম।
                     *
                     * বাইরের ভ্যাট দরের উপরে বসে; ভেতরের ভ্যাট দরের ভেতরেই
                     * আছে, তাই ওটা মোট বাড়ায় না — কেবল কতটুকু কর তা আলাদা
                     * করে দেখায়। আগে দুইটাই যোগ করা হত, আর ভেতরের বেলায়
                     * পর্দার সংখ্যা বিলের চেয়ে বেশি দেখাত।
                     *
                     * অঙ্কটা এখানে কেবল **দেখানোর** জন্য; খাতায় যেটা বসে
                     * সেটা সার্ভার নিজে গোনে (CalculatesSalesLines)।
                     */
                    vatOn(net, item) {
                        if (! this.vatEnabled || ! item) return 0;

                        /*
                         * পুরো কাগজের জন্য বদল — থাকলে পণ্যের নিজের হার ও
                         * ধরন দুইটাই এটাই ঢেকে দেয় (৩ সেপ্টেম্বর ২০২৬)।
                         *
                         * ⚠️ **ধরনটাও বদলায়, শুধু হার নয়।** কেউ "ভ্যাট সহ"
                         * বাছলে দরটা এখন ভ্যাট-সহ ধরা হবে — পণ্যটা নিজে
                         * "ভ্যাট বাদে" বলা থাকলেও। ওটাই তো বদলের মানে।
                         */
                        if (this.vatMode === 'exempt') return 0;

                        const override = this.vatMode === 'exclusive' || this.vatMode === 'inclusive';
                        const rate = (override ? Number(this.vatRate || 0) : Number(item.vatRate || 0)) / 100;

                        if (! rate) return 0;

                        const inclusive = override ? this.vatMode === 'inclusive' : !! item.vatInclusive;

                        return inclusive
                            ? net - (net / (1 + rate))
                            : net * rate;
                    },

                    /*
                     * দরটা ভ্যাট-সহ কিনা — লাইনের মোট গুনতে লাগে।
                     *
                     * ⚠️ আগে সরাসরি `line.vatInclusive` পড়া হত। পুরো কাগজের
                     * বদল এলে সেটা মিথ্যা বলত: ভ্যাট গোনা হত নতুন নিয়মে,
                     * আর মোট গোনা হত পুরনো নিয়মে — **দুইটা সংখ্যা মিলত না**।
                     */
                    isInclusive(item) {
                        if (this.vatMode === 'exempt') return false;

                        if (this.vatMode === 'exclusive') return false;
                        if (this.vatMode === 'inclusive') return true;

                        return !! (item && item.vatInclusive);
                    },

                    lineVat(line) {
                        return this.vatOn(this.lineAfterDiscount(line), line);
                    },

                    lineNet(line) {
                        return this.isInclusive(line)
                            ? this.lineAfterDiscount(line)
                            : this.lineAfterDiscount(line) + this.lineVat(line);
                    },

                    get subTotal() {
                        return this.lines.reduce((s, l) => s + this.lineAfterDiscount(l), 0);
                    },

                    get vatTotal() {
                        return this.lines.reduce((s, l) => s + this.lineVat(l), 0);
                    },

                    /*
                     * সারির নিট যোগ করা, subTotal + vatTotal নয়।
                     *
                     * ভেতরের ভ্যাটে দ্বিতীয় হিসাবটা দুইবার কর যোগ করত।
                     * সারি ধরে গুনলে দুই ধরনের ভ্যাট একসাথে থাকা বিলেও
                     * সংখ্যাটা ঠিক থাকে।
                     */
                    get grossTotal() {
                        return this.lines.reduce((s, l) => s + this.lineNet(l), 0);
                    },

                    /*
                     * ছাড় — টাকা, নাকি শতাংশ?
                     *
                     * ── নিয়ম ────────────────────────────────────────────
                     * শেষে `%` থাকলে শতাংশ, নাহলে সোজা টাকা। **এই একটাই
                     * getter সবখানে** — পর্দার সংখ্যা, দিতে হবে, আর
                     * সার্ভারে যাওয়া লুকানো ঘর, তিনটাই এখান থেকে।
                     *
                     * ⚠️ শতাংশটা **মোট বিলের উপরে** বসে, কোনো একটা লাইনের
                     * উপরে নয় — লাইনের নিজের ছাড় আলাদা ঘরে, কার্টে।
                     *
                     * ⚠️ ছাড় বিলের চেয়ে বড় হতে দেওয়া হয় না। দিলে "দিতে
                     * হবে" ঋণাত্মক হত, আর তখন চালানটা **গ্রাহককে টাকা
                     * ফেরত দেওয়ার কাগজ** হয়ে যেত — যেটা সম্পূর্ণ আলাদা
                     * জিনিস (বিক্রয় ফেরত), আর তার নিজের পর্দা আছে।
                     */
                    get discountValue() {
                        const raw = String(this.discountInput || '').trim();

                        if (raw === '') return 0;

                        const isPercent = raw.endsWith('%');
                        const n = Number(raw.replace('%', '').trim());

                        if (! isFinite(n) || n <= 0) return 0;

                        const value = isPercent ? this.grossTotal * n / 100 : n;

                        return Math.min(value, this.grossTotal);
                    },

                    get netPayable() {
                        return this.grossTotal
                            - this.discountValue
                            + (Number(this.expenseAmount) || 0)
                            + (Number(this.roundingAmount) || 0);
                    },

                    get invoiceDue() {
                        const due = this.netPayable - (Number(this.deposit) || 0);
                        return due > 0 ? due : 0;
                    },

                    get outstanding() {
                        return (Number(this.customer.due) || 0) + this.invoiceDue;
                    },

                    get counts() {
                        const sales = this.lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
                        const free = this.lines.reduce((s, l) => s + (Number(l.freeQty) || 0), 0)
                            + this.allGifts.reduce((s, g) => s + (Number(g.qty) || 0), 0);

                        return {
                            totalItem: this.lines.length,
                            totalSalesQty: this.qty(sales),
                            totalFreeQty: this.qty(free),
                            totalQty: this.qty(sales + free),
                        };
                    },

                    /* যে বোতামের কাজটা এই পাতাতেই আছে, সেটা ওই ঘরে নিয়ে
                       যায় — নতুন কোনো পপ-আপ নয়। খরচ বসাতে গিয়ে একটা জানালা
                       খুলে আবার বন্ধ করা কাউন্টারে দুইটা বাড়তি চাপ। */
                    /* $root, $el নয়: বোতাম থেকে ডাকা হলে $el হয় ওই
                       বোতামটাই, আর বোতামের ভেতরে ঘরটা থাকে না — তখন
                       কিছুই ফোকাস হত না, নীরবে। জাবেদা ও নগদ গণনার
                       পর্দায় এই একই ভুল দুইটা ফিচার মেরে রেখেছিল। */
                    focusField(name) {
                        const el = this.$root.querySelector(`[name="${name}"]`);
                        if (el) { el.focus(); el.select?.(); }
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
