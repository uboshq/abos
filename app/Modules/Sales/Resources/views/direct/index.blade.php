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
          x-data="directSale({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($customerTerms) }}, {{ $walkinId }}, {{ $vatEnabled ? 'true' : 'false' }})"
          @bulk-applied.window="absorbBulk($event.detail.rows)"
          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="min-w-0 space-y-3">

            {{--
                ── উপরের সারি: বাঁয়ে কাজ, মাঝে অঙ্ক, ডানে ছবি ────────────

                ── কেন সবকিছু একটাই সারিতে (৩ সেপ্টেম্বর ২০২৬) ───────────
                আগের ধাপে সবুজ "এই লাইন" প্যানেলটা উপরে তোলা হয়েছিল, আর
                এন্ট্রি এলাকাটা নিচে পুরো প্রস্থ পেয়ে গিয়েছিল। ফলে দশটা
                ঘর — Qty, UoM, Free Qty, দর, ছাড়, VAT — টেনে লম্বা হয়ে
                গিয়েছিল, আর মালিক ঠিক সেটাই ধরিয়ে দিলেন: *"ei box gulo
                ekho bare eta fix koro"*।

                ঘরগুলো নিজে থেকে বাড়েনি; **কলামটা** বেড়েছিল। তাই সারাইটা
                ঘরে নয়, কাঠামোয়: স্ট্রিপ, পণ্য খোঁজা আর লাইনের ঘরগুলো
                তিনটাই এখন **একই বাঁ কলামে** — অর্থাৎ ওই কলামের প্রস্থ
                আগের মতোই, আর ঘরগুলোও।

                ── পণ্য খোঁজার বারটা কেন ঠিক এখানে ───────────────────────
                মালিকের তীরচিহ্ন স্ট্রিপের নিচের ফাঁকা জায়গাটা দেখাচ্ছিল।
                জায়গাটা ফাঁকা ছিল কারণ পাশের সবুজ প্যানেল স্ট্রিপের চেয়ে
                উঁচু। খোঁজার বারটা ওখানে বসায় দুইটা জিনিস হয়: ফাঁকটা ভরে,
                আর কাগজের পরিচয় দেওয়ার ঠিক পরেই প্রথম কাজটা — পণ্য বাছা —
                চোখের সামনে আসে।
            --}}
            <div class="grid gap-3 lg:grid-cols-[1fr_11rem] 2xl:grid-cols-[1fr_13rem_9rem] lg:items-start">

            {{-- বাঁ কলাম: কাগজের পরিচয় → পণ্য খোঁজা → লাইনের ঘর --}}
            <div class="min-w-0 space-y-3">
                {{-- ── ডকুমেন্ট স্ট্রিপ ──────────────────────────────────── --}}
                <section data-boxed class="rounded-(--radius-card) border-t-2 border-(--color-success)
                                border-x border-b border-(--color-border)
                                bg-(--color-surface-card) p-2">
                    {{-- পাঁচটা ঘরই এক সারিতে, আর ঘরগুলো সরু।

                         আগে xl:grid-cols-5 দেওয়া ছিল, কিন্তু ডান পাশের প্যানেল
                         জায়গা নিয়ে নেওয়ায় বাঁ দিকটা আর "xl" হত না — ফলে পাঁচটা
                         ঘর দুই সারিতে ভেঙে যেত আর স্ট্রিপটা দ্বিগুণ উঁচু দেখাত।
                         এখন মাপটা ধরা হয়েছে কনটেইনারের নিজের প্রস্থে (@container),
                         পর্দার প্রস্থে নয় — তাই ঘরগুলো যেখানে বসছে সেখানকার
                         জায়গা দেখেই সিদ্ধান্ত হয়। --}}
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
                    <div class="flex flex-wrap gap-1.5">
                        {{-- পুরো তারিখটা দেখা যেতে হবে — মালিকের কথা,
                             ৩ সেপ্টেম্বর ২০২৬: "০৩-০৯-২০:" পর্যন্ত দেখিয়ে
                             বছরটা কেটে যাচ্ছিল, আর একটা কাটা তারিখ পড়ে
                             কেউ নিশ্চিত হতে পারেন না কোন বছরের কাগজ। --}}
                        <label class="w-40 shrink-0">
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
                        <label class="w-32 shrink-0">
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
                        <label class="w-40 shrink-0">
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
                        <label class="w-32 shrink-0" x-show="creditTerm === 'custom'" x-cloak>
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
                            <label class="w-28 shrink-0">
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
                                <div x-show="! pickerOpen" x-cloak>
                                    <button type="button" @click="openPicker()"
                                            class="block w-full truncate rounded-(--radius-field) border
                                                   border-transparent px-3 py-2 text-start text-xl font-semibold
                                                   transition-colors hover:border-(--color-border)"
                                            :class="picked ? 'text-(--color-ink)' : 'text-(--color-ink-muted)'"
                                            x-text="picked?.name || @js(__('sales::message.type_or_pick'))">
                                    </button>
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

                                <p class="mt-0.5 text-2xs text-(--color-ink-muted)"
                                   x-show="picked && ! pickerOpen" x-cloak x-text="picked?.code"></p>
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
                        <div class="mt-2 max-h-40 space-y-0.5 overflow-y-auto" x-show="pickerOpen && term.trim() !== ''" x-cloak>
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
                    <div class="mt-3 grid max-w-3xl gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                            <x-sales::entry-field label="sales::field.qty" width="w-full">
                                <input type="number" step="0.01" min="0" x-model="entry.qty"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            <x-sales::entry-field label="sales::field.uom" width="w-full">
                                <input type="text" readonly :value="picked?.unit || ''"
                                       class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
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
                        </div>

                        {{-- দ্বিতীয় সারি: দর · মোট টাকা · ছাড় · ভ্যাট · নিট --}}
                        {{-- দ্বিতীয় সারি, একই নিয়মে --}}
                    <div class="mt-2 grid max-w-3xl gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                            <x-sales::entry-field label="sales::field.sales_rate" width="w-full">
                                <input type="number" step="0.0001" min="0" x-model="entry.rate"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            <x-sales::entry-field label="sales::field.total_amount" width="w-full">
                                <input type="text" readonly :value="money(entryBase)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            @if ($show['line_discount'])
                                <x-sales::entry-field label="sales::field.discount_pct" width="w-full">
                                    <input type="number" step="0.01" min="0" max="100" x-model="entry.discountPercent"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>
                            @endif

                            @if ($vatEnabled)
                                <x-sales::entry-field label="sales::field.vat" width="w-full">
                                    <input type="text" readonly :value="money(entryVat)"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>
                            @endif

                            <x-sales::entry-field label="sales::field.net_value" width="w-full">
                                <input type="text" readonly :value="money(entryNet)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm font-semibold">
                            </x-sales::entry-field>
                        </div>
                    </div>
                </section>
            </div>

                    {{-- মাঝ: এই লাইন --}}
                    <div class="rounded-(--radius-card) bg-(--color-badge-success-bg) p-3">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-badge-success-ink)">
                            {{ __('sales::field.this_line') }}
                        </p>
                        <p class="num mt-1 text-2xl font-bold text-(--color-badge-success-ink)"
                           x-text="'৳' + money(entryNet)"></p>

                        <dl class="mt-2 space-y-0.5 text-2xs">
                            @foreach ([
                                'sales::field.net_value' => 'entryAfterDiscount',
                                'sales::field.vat' => 'entryVat',
                                'sales::field.total_qty' => 'entryTotalQty',
                            ] as $label => $expr)
                                <div class="flex justify-between gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                                    <dd class="num" x-text="money({{ $expr }})"></dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-2 grid grid-cols-2 gap-1">
                            @if ($show['gift'])
                                <button type="button" @click="addGift()"
                                        class="rounded-(--radius-field) border border-(--color-badge-pending-ink)/30
                                               bg-(--color-badge-pending-bg) px-2 py-1 text-2xs font-medium
                                               text-(--color-badge-pending-ink)">
                                    {{ __('sales::field.gift') }}
                                </button>
                            @endif

                            {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
                                 জন্য নয়। তাই আলাদা বোতামের পেছনে: চোখে পড়ে
                                 না, কিন্তু দরকার হলে এক চাপ দূরে। --}}
                            <button type="button" @click="showCosting = ! showCosting"
                                    class="rounded-(--radius-field) border border-(--color-border)
                                           px-2 py-1 text-2xs font-medium">
                                {{ __('sales::field.costing') }}
                            </button>
                        </div>

                        <p x-show="showCosting" x-cloak class="num mt-1 text-2xs text-(--color-ink-muted)"
                           x-text="picked ? money(picked.cost) : ''"></p>

                        <div class="mt-2 grid grid-cols-2 gap-1">
                            <button type="button" @click="addToCart()" :disabled="! picked"
                                    class="rounded-(--radius-field) bg-(--color-success) px-2 py-2 text-2xs
                                           font-semibold text-white disabled:opacity-50">
                                {{ __('sales::action.add_to_cart') }}
                            </button>
                            <button type="button" @click="clearEntry()"
                                    class="rounded-(--radius-field) bg-(--color-danger) px-2 py-2 text-2xs
                                           font-semibold text-white">
                                {{ __('sales::action.clear_data') }}
                            </button>
                        </div>

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
                    </div>


                    {{-- ডান: পণ্যের ছবির জায়গা --}}
                    {{-- ছবির ঘরটা কেবল খুব চওড়া পর্দায় — ৩ সেপ্টেম্বর ২০২৬।

                         ── কেন ─────────────────────────────────────────────
                         এটা পর্দার একমাত্র **সাজসজ্জা**: বাছা পণ্যের একটা
                         ছবি বসার জায়গা, আর আজ ওখানে কেবল একটা বাক্সের
                         আইকন। কিন্তু ওটা ৯rem জায়গা নেয়, আর সেই জায়গাটা
                         আসে বাঁ কলাম থেকে।

                         মেপে দেখা গেছে ১৫০০px পর্দায় বাঁ কলাম দাঁড়ায়
                         **৩৫৫px** — দশটা ঘর তখন দুইয়ে দুইয়ে পাঁচ সারি।
                         মালিক চেয়েছেন পাঁচটাই এক লাইনে, আর তার জন্য
                         ~৬০০px লাগে।

                         তাই সাজসজ্জাটা `2xl`-এ (১৫৩৬px+) সরল, যেখানে
                         জায়গা সত্যিই আছে। ⚠️ কাজের ঘর সাজসজ্জার কাছে
                         জায়গা হারাবে না — উল্টোটা। --}}
                    <div class="hidden items-center justify-center rounded-(--radius-card)
                                border border-(--color-border) p-3 2xl:flex">
                        <div class="text-center">
                            <svg viewBox="0 0 24 24" aria-hidden="true"
                                 class="mx-auto size-12 fill-(--color-ink-muted)/40">
                                <path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.2 6.6 3.3L12 10.8 5.4 7.5 12 4.2ZM5 9.3l6 3v7.4l-6-3V9.3Zm8 10.4v-7.4l6-3v7.4l-6 3Z"/>
                            </svg>
                            <p class="mt-1 text-2xs text-(--color-ink-muted)"
                               x-text="picked ? picked.name : @js(__('sales::message.pick_an_item'))"></p>
                        </div>
                    </div>
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

                        <tbody>
                            <template x-for="(line, i) in lines" :key="line.key">
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell" x-text="i + 1"></td>

                                    <td class="cell" data-label="{{ __('sales::field.item_name') }}">
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
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
                            </template>
                        </tbody>
                    </table>
                </div>

                <p x-show="lines.length === 0" x-cloak
                   class="p-8 text-center text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.nothing_added') }}
                </p>
            </section>

            {{-- ── উপহার ────────────────────────────────────────────── --}}
            @if ($show['gift'])
                <section x-show="gifts.length > 0" x-cloak
                         class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card)">
                    <h2 class="flex items-center justify-between border-b border-(--color-border) px-3 py-2">
                        <span class="text-2xs font-semibold uppercase tracking-wide">
                            {{ __('sales::field.gift_item') }}
                        </span>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('sales::message.not_for_sales') }}</span>
                    </h2>

                    <div class="table-responsive">
                        <table class="ui-lines table-cards w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ __('sales::field.sl') }}</th>
                                    <th class="text-start">{{ __('sales::field.gift_for') }}</th>
                                    <th class="text-start">{{ __('sales::field.item_name') }}</th>
                                    <th class="text-end">{{ __('sales::field.quantity') }}</th>
                                    <th class="text-start">{{ __('sales::field.remarks') }}</th>
                                    <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(gift, i) in gifts" :key="gift.key">
                                    <tr class="border-b border-(--color-border)">
                                        <td class="cell" x-text="i + 1"></td>

                                        <td class="cell-input" data-label="{{ __('sales::field.gift_for') }}">
                                            <select x-model="gift.againstProductId"
                                                    :name="`gifts[${i}][against_product_id]`"
                                                    class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                           border-(--color-border) bg-(--color-surface-app) px-2">
                                                <option value="">-</option>
                                                <template x-for="line in lines" :key="line.key">
                                                    <option :value="line.id" x-text="line.name"></option>
                                                </template>
                                            </select>
                                        </td>

                                        <td class="cell-input" data-label="{{ __('sales::field.item_name') }}">
                                            {{-- পণ্যতালিকা এখানে সার্ভার থেকে আবার আঁকা হয় না।

                                                 উপরে ওই একই তালিকা JSON হিসেবে চলে গেছে
                                                 (directSale-এর catalogue), তাই দ্বিতীয়বার
                                                 <option> হিসেবে পাঠানো মানে একই ডেটা দুইবার।
                                                 ছয়টা পণ্যে সেটা চোখে পড়ে না, কিন্তু দুই
                                                 হাজার পণ্যের গুদামে এটাই পাতাটাকে ভারী করে
                                                 তুলত — আর কাউন্টারের পাতা দিনে কয়েকশো বার
                                                 খোলা হয়। --}}
                                            <select x-model="gift.productId" :name="`gifts[${i}][product_id]`"
                                                    class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                           border-(--color-border) bg-(--color-surface-app) px-2">
                                                <option value="">-</option>
                                                <template x-for="p in catalogue" :key="p.id">
                                                    <option :value="p.id" x-text="p.name"></option>
                                                </template>
                                            </select>
                                        </td>

                                        <td class="cell-input text-end" data-label="{{ __('sales::field.quantity') }}">
                                            <input type="number" step="0.01" min="0" x-model="gift.qty"
                                                   :name="`gifts[${i}][qty]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>

                                        <td class="cell-input" data-label="{{ __('sales::field.remarks') }}">
                                            <input type="text" x-model="gift.remarks" :name="`gifts[${i}][remarks]`"
                                                   class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2">
                                        </td>

                                        <td class="cell-input text-end">
                                            <button type="button" @click="gifts.splice(i, 1)"
                                                    aria-label="{{ __('sales::action.remove_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        {{-- ══ ডান পাশের প্যানেল ═══════════════════════════════════════ --}}
        <aside class="flex flex-col self-start rounded-(--radius-card) border
                      border-(--color-border) bg-(--color-surface-card)
                      xl:sticky xl:top-3 xl:max-h-[calc(100dvh-5.5rem)]">

            <div class="min-h-0 flex-1 overflow-y-auto">

            {{-- ক্রেতা --}}
            <div class="border-b border-(--color-border) p-3">
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

                    <div class="min-w-0 flex-1 leading-tight">
                        <div class="truncate text-sm font-semibold text-(--color-ink)"
                             x-text="customer.name || @js(__('sales::message.search_customer'))"></div>

                        <div class="flex flex-wrap items-center gap-1">
                            <span x-show="customer.location" x-cloak
                                  class="inline-flex max-w-36 items-center truncate rounded-full
                                         border border-(--color-brand-200) bg-(--color-brand-50)
                                         px-1.5 py-px text-2xs font-medium text-(--color-brand-700)"
                                  x-text="customer.location"></span>

                            <span x-show="customer.phone" x-cloak
                                  class="num text-2xs text-(--color-ink-muted)"
                                  x-text="customer.phone"></span>
                        </div>

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

                @if ($show['credit_limit'])
                    <p class="mt-1 flex justify-between text-2xs">
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.credit_limit') }}</span>
                        <span class="num" x-text="customer.limit > 0 ? money(customer.limit) : '—'"></span>
                    </p>
                @endif
            </div>

            {{-- এই চালান --}}
            <x-sales::panel-heading>{{ __('sales::field.this_challan') }}</x-sales::panel-heading>

            <div class="space-y-1 p-3 text-2xs">
                <x-sales::panel-row :label="__('sales::field.invoice_total')" strong>
                    <span class="num" x-text="'৳' + money(grossTotal)"></span>
                </x-sales::panel-row>

                @if ($show['sub_total'])
                    <x-sales::panel-row :label="__('sales::field.sub_total_no_vat')">
                        <span class="num" x-text="'৳' + money(subTotal)"></span>
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.discount_amount')">
                    <input type="number" step="0.01" min="0" name="discount_amount" x-model="discountAmount"
                           placeholder="{{ __('sales::field.amount_or_pct') }}"
                           class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-1 text-end">
                </x-sales::panel-row>

                @if ($vatEnabled)
                    <x-sales::panel-row :label="__('sales::field.vat')">
                        <span class="num" x-text="'৳' + money(vatTotal)"></span>
                    </x-sales::panel-row>
                @endif

                @if ($show['expense'])
                    <x-sales::panel-row :label="__('sales::field.expense')">
                        <input type="number" step="0.01" min="0" name="expense_amount" x-model="expenseAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif

                @if ($show['rounding'])
                    <x-sales::panel-row :label="__('sales::field.rounding')">
                        <input type="number" step="0.01" min="0" name="rounding_amount" x-model="roundingAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif
            </div>

            {{-- দিতে হবে --}}
            <x-sales::panel-heading tone="success">
                {{ __('sales::field.to_pay_on_this') }}
            </x-sales::panel-heading>

            <div class="space-y-1 bg-(--color-badge-success-bg)/40 p-3 text-2xs">
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
            </div>

            {{-- পার্টির বকেয়া --}}
            <x-sales::panel-heading tone="pending">
                {{ __('sales::field.what_party_owes') }}
            </x-sales::panel-heading>

            <div class="space-y-1 bg-(--color-badge-pending-bg)/40 p-3 text-2xs">
                <x-sales::panel-row :label="__('sales::field.previous_balance')">
                    <span class="num" x-text="customer.due > 0 ? money(customer.due) : '—'"></span>
                </x-sales::panel-row>

                <x-sales::panel-row :label="__('sales::field.outstanding')" strong>
                    <span class="num" x-text="outstanding > 0 ? money(outstanding) : '—'"></span>
                </x-sales::panel-row>
            </div>

            {{-- গোনা --}}
            <x-sales::panel-heading>{{ __('sales::field.quantities') }}</x-sales::panel-heading>

            <div class="space-y-1 p-3 text-2xs">
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

            {{-- ছয়টা কাজ, আর ছয়টাই সত্যি।

                 ── ২৯ আগস্ট ২০২৬ পর্যন্ত চারটা কেবল "আসছে" বলত ────────
                 জায়গাটা ধরে রাখার যুক্তিতে বোতামগুলো বসানো ছিল, আর চাপলে
                 একটা হলুদ বার্তা আসত। যুক্তিটা খারাপ ছিল না — চুপচাপ
                 কিছু-না-করার চেয়ে "আসছে" বলা ভালো।

                 কিন্তু মালিক নাম ধরে ছয়টাই চেয়েছেন, আর নিয়ম হলো স্টাব
                 নয়। তাই প্রতিটার নিজের প্যানেল, আর প্রতিটার ঘর সার্ভারে
                 গিয়ে বসে।

                 ── কেন প্যানেল, আলাদা পাতা নয় ─────────────────────────
                 কাউন্টারে দাঁড়ানো লোক পাতা বদলাতে পারেন না — কার্টটা
                 সামনে থাকতে হয়। একটা পাতা ছেড়ে গেলে ফিরে এসে আবার সব
                 টাইপ করতে হত। --}}
            {{-- চার্ট / বাল্ক DO — এই কম্পোনেন্টটা আগে থেকেই ছিল।

                 ── আর আমি প্রথমে ওটার একটা নিকৃষ্ট নকল বানিয়েছিলাম ─────
                 ২৯ আগস্ট ২০২৬-এ ছয়টা বোতাম সত্যি করতে গিয়ে এই প্যানেলে
                 একটা ছোট শিট লিখেছিলাম: কেবল নাম, মজুদ আর পরিমাণ, সরু
                 ডান কলামের ভেতরে। মালিক DMS-এর শিটটা দেখতে বললেন, আর
                 তখনই দেখা গেল ABOS-এ ঠিক ওই জিনিসটাই আগে থেকেই আছে —
                 চালানের ফর্মে বসানো, পুরো পর্দা জুড়ে, যোগফল-ছাঁকনি-
                 সাজানো-ফ্রি পরিমাণসহ।

                 দুইটা শিট মানে দুই জায়গায় একই অঙ্ক, আর একদিন একটা
                 বদলাত অন্যটা থাকত। নকলটা মুছে আসলটাই বসানো হয়েছে।

                 নিজের বোতাম নিজেই আঁকে, তাই নিচের ছয়ের তালিকায় ওটা
                 আর নেই। --}}
            <div class="border-t border-(--color-border) p-3">
                <x-sales::bulk-sheet :products="$sheetProducts" :stock="$sheetStock" :free-qty="$show['free_qty']" />
            </div>

            <div class="grid grid-cols-2 gap-1 border-t border-(--color-border) p-3">
                @foreach (array_filter([
                    ['key' => 'expense', 'panel' => 'expense', 'show' => $show['expense']],
                    ['key' => 'transportation', 'panel' => 'transport', 'show' => $show['transport']],
                    ['key' => 'shipment', 'panel' => 'shipment', 'show' => $show['shipment']],
                    ['key' => 'add_deposit', 'panel' => 'deposit', 'show' => $show['deposit']],
                    ['key' => 'add_note', 'panel' => 'note', 'show' => true],
                ], fn (array $b) => $b['show']) as $button)
                    <button type="button"
                            @click="panel = (panel === '{{ $button['panel'] }}' ? '' : '{{ $button['panel'] }}')"
                            :class="panel === '{{ $button['panel'] }}'
                                ? 'bg-(--color-surface-selected) border-(--color-brand-500) font-semibold'
                                : 'hover:bg-(--color-surface-hover)'"
                            class="rounded-(--radius-field) border border-(--color-border) px-2 py-1.5
                                   text-2xs transition-colors">
                        {{ __('sales::action.'.$button['key']) }}
                    </button>
                @endforeach
            </div>

            {{-- একবারে একটাই প্যানেল খোলে: ডান কলামটা সরু, আর দুইটা খোলা
                 থাকলে কার্টের সংখ্যাগুলো পর্দা থেকে নেমে যেত। --}}
            @include('sales::direct.partials.panels')

            {{-- বোতাম --}}
            <div class="space-y-2 p-3">
                <x-ui.button type="submit" tone="primary" class="w-full"
                             ::disabled="lines.length === 0">
                    {{ __('sales::action.confirm') }}
                </x-ui.button>

                <button type="button" @click="clearAll()"
                        class="w-full rounded-(--radius-field) bg-(--color-danger)/10 px-3 py-2 text-2xs
                               font-medium text-(--color-danger)">
                    {{ __('sales::action.clear_full') }}
                </button>

                <a href="{{ route('sales.challan.index') }}"
                   class="block text-center text-2xs text-(--color-ink-muted) hover:underline">
                    ← {{ __('core.action.cancel') }}
                </a>
            </div>
        </aside>
    </form>

    @push('scripts')
        <script>
            function directSale(catalogue, customers, walkinId, vatEnabled) {
                return {
                    catalogue,
                    customers,
                    vatEnabled,
                    term: '',
                    pickerOpen: false,
                    picked: null,
                    showCosting: false,
                    entry: { qty: '', freeQty: '', rate: '', discountPercent: '' },
                    lines: [],
                    gifts: [],
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
                    customerId: String(walkinId || Object.keys(customers)[0] || ''),
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
                    discountAmount: '',
                    expenseAmount: '',
                    roundingAmount: '',
                    deposit: '',
                    panel: '',
                    depositMethod: 'cash',

                    nextKey: 1,

                    get visible() {
                        const t = this.term.trim().toLowerCase();
                        if (t === '' || ! this.pickerOpen) return [];

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
                            - this.entryBase * (Number(this.entry.discountPercent) || 0) / 100;
                    },

                    get entryVat() {
                        if (! this.vatEnabled || ! this.picked) return 0;
                        return this.vatOn(this.entryAfterDiscount, this.picked);
                    },

                    get entryNet() {
                        return this.picked && this.picked.vatInclusive
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
                            discountPercent: this.entry.discountPercent || '',
                        });

                        this.clearEntry();
                        this.$nextTick(() => this.$refs.search.focus());
                    },

                    clearEntry() {
                        this.picked = null;
                        this.entry = { qty: '', freeQty: '', rate: '', discountPercent: '' };
                        this.term = '';
                        this.showCosting = false;
                    },

                    clearAll() {
                        this.lines = [];
                        this.gifts = [];
                        this.discountAmount = '';
                        this.expenseAmount = '';
                        this.roundingAmount = '';
                        this.deposit = '';
                        this.clearEntry();
                    },

                    addGift() {
                        this.gifts.push({
                            key: this.nextKey++,
                            productId: '',
                            againstProductId: this.picked ? String(this.picked.id) : '',
                            qty: '',
                            remarks: @js(__('sales::message.not_for_sales')),
                        });
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

                        const rate = Number(item.vatRate || 0) / 100;

                        if (! rate) return 0;

                        return item.vatInclusive
                            ? net - (net / (1 + rate))
                            : net * rate;
                    },

                    lineVat(line) {
                        return this.vatOn(this.lineAfterDiscount(line), line);
                    },

                    lineNet(line) {
                        return line.vatInclusive
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

                    get netPayable() {
                        return this.grossTotal
                            - (Number(this.discountAmount) || 0)
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
                            + this.gifts.reduce((s, g) => s + (Number(g.qty) || 0), 0);

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
