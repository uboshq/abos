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
<x-layouts.app :menu="$menu" :fit1920="true">
    <x-slot:title>{{ __('purchase::menu.direct') }}</x-slot:title>

    {{-- ⓘ `data-confirm-overview` — নিশ্চিতের আগে সারাংশের পপ-আপ ([[confirm-overview.js]], ৪ অক্টোবর ২০২৬) --}}
    {{-- ⭐ সরাসরি ক্রয় v2 — মালিকের নমুনা (২৭ নভেম্বর ২০২৬), কেবল ABOS-এর তিন রূপে (ABOS · Navy · Blue); SAP/Dynamics/Odoo আগের মতো --}}
    @php
        $abosLook = in_array(
            \App\Core\Support\LookRegistry::lookFor(\App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)),
            \App\Core\Support\Ui::FOLD_MENU_LOOKS, true);
    @endphp
    <form method="POST" action="{{ route('purchase.direct.store') }}" data-confirm-overview="{{ route('purchase.direct.overview') }}" @if ($abosLook) data-abos-purchase data-final-confirm @keydown.window.f2.prevent="depositOpen = ! depositOpen" @keydown.window.f4.prevent="openUnloading()" @keydown.window.f7.prevent="transportOpen = ! transportOpen" @endif
          x-data="directPurchase({
              catalogue: @js($products),
              vatEnabled: {{ $show['vat'] ? 'true' : 'false' }},
              lastRatesUrl: @js(route('purchase.direct.last_rates', ['supplier' => 0])),
              depositMethods: @js($depositMethods),
              moneyAccounts: @js($moneyAccounts->map(fn ($a) => [
                  'id' => (string) $a->id,
                  'label' => $a->label(),
                  'parent' => (string) ($a->parent?->code ?? ''),
              ])->values()),
              carriers: @js($carriers),
              packs: @js($packs),
              {{-- ⭐ কোন প্যাকটা আগে থেকে বসবে — পণ্যের ফর্মের “কাউন্টারে” রেডিও (ধাপ ৫) --}}
              packDefaults: @js($packDefaults),
              {{-- ⓘ দুইটা নামই যায় — বাংলা লোকেলে ইংরেজি নাম না গেলে
                   `Bengal` লিখে `বেঙ্গল ফুডস` মিলত না, আর পর্দা বলত
                   “ওই নামে কোনো সরবরাহকারী নেই”। ⛔ মিথ্যা বার্তাটাই
                   সবচেয়ে খারাপ: মানুষ তখন একই সরবরাহকারী দ্বিতীয়বার
                   বসান, আর বকেয়া দুই সারিতে ভাগ হয়ে যায়। --}}
              suppliers: @js($suppliers->map(fn ($s) => [
                  'id' => (string) $s->id,
                  'name' => $s->name(),
                  'name_en' => (string) $s->name_en,
                  'name_bn' => (string) $s->name_bn,
                  'phone' => $s->phone,
                  'address' => $s->address(),
                  'proprietor' => $s->contact_person,
              ])->values()),
              paymentTermDefault: @js($paymentTermDefault),
              draftKey: 'abos.direct-purchase.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',
              hasErrors: @js($errors->any()),
              accountCodes: @js([
                  'cash' => App\Modules\Accounts\Services\StandardChart::CASH_IN_HAND,
                  'bank' => App\Modules\Accounts\Services\StandardChart::BANK,
                  'mfs' => App\Modules\Accounts\Services\StandardChart::MOBILE_MONEY,
                  'cheque' => App\Modules\Accounts\Services\StandardChart::BANK,
              ]),
              texts: @js([
                  'paidMoreConfirm' => __('purchase::message.paid_more_confirm'),

                  {{-- ⭐ পাঠানো থামার তিনটা কারণ — পর্দায় লেখার জন্য --}}
                  'needALine' => __('purchase::message.need_a_line'),
                  'yesMark' => __('purchase::field.yes_mark'),
                  'noWord' => __('purchase::field.no_word'),
                  'landedNote' => __('purchase::field.landed_note'),
                  'ready' => [
                      'ok' => __('purchase::message.ready_ok'),
                      'needSupplier' => __('purchase::message.ready_need_supplier'),
                      'needLines' => __('purchase::message.ready_need_lines'),
                      'needCarrier' => __('purchase::message.ready_need_carrier'),
                  ],
                  'needACarrier' => __('purchase::message.need_a_carrier'),

                  {{-- ⛔ দর ০ বা বিক্রয়দর ছাড়া — কার্টে নয়, পাঠানোও নয় (মালিক, ৩ অক্টোবর ২০২৬) --}}
                  'needRate' => __('purchase::message.need_rate'),
                  'needSalesPrice' => __('purchase::message.need_sales_price'),
                  'needPricedLines' => __('purchase::message.need_priced_lines'),

                  {{-- ⭐ আনলোডিং বিলের পপআপ — ডানের এক লাইন আর পাঠানোর আগের পাহারা ([[LabourCost::read()]]-এর একই বার্তা) --}}
                  'unloadModes' => collect(\App\Modules\Supplier\Services\LabourCost::UNLOADING)
                      ->mapWithKeys(fn ($m) => [$m => __('supplier::labour.mode_'.$m)])->all(),
                  'unloadNotChosen' => __('supplier::labour.not_chosen'),
                  'unload' => [
                      'pick' => __('supplier::labour.pick_one'),
                      'till' => __('supplier::labour.needs_till'),
                      'contractor' => __('supplier::labour.needs_contractor'),
                      'payee' => __('supplier::labour.needs_payee'),
                      'reason' => __('supplier::labour.needs_reason'),
                      'amount' => __('supplier::labour.needs_amount'),
                  ],
              ]),
              {{-- ⭐ সার্ভার কোন সারির লট ফিরিয়েছে — সারির ক্রম ধরে (`lines.{i}.batch_no`),
                   যাতে বার্তাটা ঐ সারির লট-ঘরের নিচেই বসে, কেবল উপরের তালিকায় নয় --}}
              lotErrors: @js(collect($errors->getMessages())
                  ->filter(fn ($messages, $key) => preg_match('/^lines\.\d+\.batch_no$/', (string) $key) === 1)
                  ->mapWithKeys(fn ($messages, $key) => [(string) explode('.', (string) $key)[1] => $messages[0]])
                  ->all()),
              {{-- ⭐ খালি লট-ঘরের প্রস্তাব — পরের লট নম্বর, খরচ হয় না ([[PurchaseLots]], মালিক, ৫ অক্টোবর ২০২৬) --}}
              lotHint: @js($lotHint),
              {{-- ⭐ আনলোডিং বিল (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v9) — নিয়ন্ত্রণ প্যানেলের দর, আর ফিরে আসা পাতার আগের বাছাই --}}
              unloadRate: @js((string) $unloadRate),
              unloadRequired: @js((bool) config('abos.unloading_required', true)),
              unloadStart: @js([
                  'mode' => (string) old('unload_mode', ''),
                  'split' => collect((array) old('lines', []))->contains(fn ($l) => is_array($l) && filled($l['unload_rate'] ?? null)) ? 'product' : 'whole',
                  'amount' => (string) old('unload_amount', ''),
                  'account' => (string) old('unload_account_id', ''),
                  'contractor' => (string) old('unload_contractor_id', ''),
                  'reason' => (string) old('unload_reason', ''),
                  'payee' => (string) old('unload_payee', ''),
                  'failed' => $errors->hasAny([...\App\Modules\Supplier\Services\LabourCost::fields('unload_'), 'unload_payee']),
              ]),
          })"
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
        @if (\App\Core\Support\CounterMode::wanted())
            <input type="hidden" name="{{ \App\Core\Support\CounterMode::PARAM }}" value="1">
        @endif

        {{-- ══ বাঁ অংশ — মাথা, পণ্য, "এই লাইনে", প্যানেল আর কার্ট ══════

             ⚠️ মোটের কার্ডটা এই মোড়কের **বাইরে**, আর তাই সে **একদম উপর
             থেকে** শুরু হয় — মালিকের নির্দেশ, ৫ সেপ্টেম্বর ২০২৬।

             ⛔ এক দফায় কাগজের মাথাটা পুরো চওড়ায় নেওয়া হয়েছিল
             (`lg:col-span-2`), আর তাতে মোটের কার্ডটা এক সারি নিচে নেমে
             গিয়েছিল। ⓘ ওটা ভুল ছিল: *"Bill total box ek dom upor thekei
             bosbe ager moto"* — টাকার ছকটা চোখের সোজাসুজি থাকা দরকার,
             কাগজের মাথার নিচে নয়। --}}
        <div class="min-w-0 space-y-3">
        {{-- ⓘ খবরের ফিতা কেবল বাঁ পাশে — ডানের মোট বিলের বাক্স একদম উপর থেকে "মাল বুঝে নিন" পর্যন্ত টানা (মালিক, ২৬ নভেম্বর ২০২৬) --}}
        {{-- ⭐ কাউন্টার মোডে জমার পরে — সবুজ খবর, ছাপুন · দেখুন; নিচে নতুন খালি বিল (মালিক, ২৬ নভেম্বর ২০২৬) --}}
        @if ($done = session('counter_done'))
            <div data-counter-done role="status"
                 class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border border-(--color-success)/40
                        bg-(--color-badge-success-bg) px-4 py-2 text-sm text-(--color-badge-success-ink)">
                <span>✔ <b>{{ __('core.counter.saved', ['no' => $done['no']]) }}</b> · {{ $done['party'] }} · ৳{{ $done['total'] }} · {{ __('core.counter.new_bill') }}</span>
                <span class="ms-auto flex gap-2">
                    <a href="{{ $done['print'] }}" target="_blank" rel="noopener"
                       class="rounded-(--radius-field) bg-(--color-success) px-3 py-1 font-semibold text-(--color-ink-inverse)">🖨 {{ __('core.counter.print') }}</a>
                    <a href="{{ $done['view'] }}" target="_blank" rel="noopener"
                       class="rounded-(--radius-field) border border-(--color-success)/40 bg-(--color-surface-card) px-3 py-1 font-semibold">{{ __('core.counter.view') }}</a>
                </span>
            </div>
        @endif

        {{-- ── খসড়া পাওয়া গেছে ───────────────────────────────────────

             ⚠️ প্রস্তাব, ফেরানো নয়। ⓘ নিজে থেকে ফিরিয়ে দিলে কেউ নতুন
             ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে।

             ⓘ বারটা ফর্মের ভিতরে, তাই `x-data`-র স্কোপেই আছে — আর
             দুইটা বোতামেই `type="button"`, নাহলে ওগুলো ফর্মটাই সাবমিট
             করে দিত। --}}
        {{--
            ⭐ পাঠানো কেন থামল — এক বাক্যে, একদম উপরে।

            ⛔ আগে তিনটা কারণেই পাঠানো চুপ করে থেমে যেত, আর কাউন্টারে
            দাঁড়ানো মানুষটার কাছে তার মানে একটাই: বোতাম চাপলে কিছুই হয় না।

            ⓘ রংটা সার্ভারের ভুলের বাক্সের মতোই — এটা একটা অভিযোগ, খবর নয়।
            ⚠️ `x-cloak` — নাহলে Alpine বসার আগে এক পলকের জন্য খালি বাক্সটা
            দেখা যেত।
        --}}
        <div x-show="draftFound" x-cloak
             class="flex flex-wrap items-center gap-2 rounded-(--radius-card)
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
                data-purchase-head class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2.1fr)]">
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
        {{-- ⚠️ `relative` — সরবরাহকারী খোঁজার প্যানেলটা এর সাপেক্ষে ভাসে
             (নিচে দেখুন)। --}}
        @include('purchase::direct.partials.supplier')
            </div>
            @include('purchase::direct.partials.bill')
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
            {{-- ⓘ "এই লাইনে" বাক্সটা নেই — মালিক, ২৬ নভেম্বর ২০২৬ (নমুনা v9): টাকা আর তিনটা বোতাম পরিমাণের সারিতেই ([[entry]]) --}}
            <div class="min-w-0 space-y-3 lg:col-span-2">

            {{-- ── পণ্যের বাক্স — মালিকের দাগানো *"Products box"* ──────── --}}
            @include('purchase::direct.partials.entry')
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
            @include('purchase::direct.partials.cart')

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
            @include('purchase::direct.partials.deposit')

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
                <div x-show="transportOpen" x-cloak data-purchase-transport @keydown.escape.window="transportOpen = false"
                     class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    {{-- v2 (নমুনা): ABOS-এর রূপে পর্দার মাঝের পপআপ — সোনালি মাথা, বন্ধের বোতাম, Esc --}}
                    @if ($abosLook)
                        <div class="pv2-pophead pv2-pophead-amber">
                            <h2>{{ __('purchase::action.transportation') }} <kbd>F7</kbd></h2>
                            <button type="button" @click="transportOpen = false" aria-label="{{ __('purchase::action.close_panel') }}">&times;</button>
                        </div>
                    @endif
                    <div class="text-2xs font-medium text-(--color-ink-muted)">
                        {{ __('purchase::field.carrier') }}
                    </div>

                    {{-- ⚠️ তালিকা খালি থাকলে কারণটা বলা হয়, আর হাতে নাম
                         লেখার ঘরটা তখনো আছে — তাই পর্দাটা অচল হয় না।

                         ⓘ এই অবস্থাটা কল্পনা নয়: আজ কোনো সরবরাহকারী
                         TRANSPORT ধরনে নেই, তাই **প্রথম দিন থেকেই** তালিকা
                         খালি থাকবে। --}}
                    <select name="carrier_id" x-model="carrierId" x-ref="carrier"
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
                           x-ref="carrierName"
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

                    {{-- ⭐ অন্যান্য খরচ (ঘাট, টোল …) — ঐচ্ছিক; ভাড়ার মতোই মালের দামে বসে (নমুনা v6 গ, ২১ নভেম্বর ২০২৬)।
                         ⓘ সাধারণ ঘর, Alpine-এর মডেল নয় — ফর্মের সাথে সরাসরি যায়। --}}
                    <input type="number" step="0.01" min="0" inputmode="decimal"
                           name="other_cost" value="{{ old('other_cost') }}" data-other-cost
                           placeholder="{{ __('purchase::field.other_cost') }}"
                           aria-label="{{ __('purchase::field.other_cost') }}"
                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end text-2xs">

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
                    <p x-show="$num(transportCost) > 0" x-cloak
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
        @include('purchase::direct.partials.totals')
        {{-- ✓ শেষবার পাকা করার পপআপ (মালিক, ২৮ নভেম্বর ২০২৬) — কেবল ABOS-এর রূপে --}}
        @if ($abosLook)
            @include('purchase::direct.partials.final-confirm')
        @endif

        {{-- ══ আনলোডিং বিল — পপআপ, পর্দার ঠিক মাঝে ═══════════════════════
             মালিক, ২৬ নভেম্বর ২০২৬ (নমুনা v9 "ঠিক আছে"): "পপ আপটা স্ক্রিনের মাঝামাঝিতে আসবে, এটার কাজটা কমপ্লিট করে অন্য কাজে
             হাত দিবে"। ⓘ দুই রকম বিল: পুরো চালানে একটা অঙ্ক (কার্টন ধরে সমান ভাগ), নয়তো পণ্য ধরে প্রতি কার্টনের হার
             (`lines.*.unload_rate`) — দুটোই [[UnloadingSplit]]-এর পুরনো পথ, সার্ভার বদলায়নি।
             ⚠️ ফর্মের ভিতরে — ঘরগুলো বিলের সাথেই যায়; বাধ্যতামূলক থাকছে ([[LabourCost]], মালিক, ১৮ নভেম্বর ২০২৬)। --}}
        @php
            $unloadInput = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
        @endphp
        <div x-show="unloadOpen" x-cloak data-direct-unloading
             x-on:keydown.escape.window="unloadOpen = false"
             x-on:click.self="unloadOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="unloading-title"
                     class="flex max-h-[92dvh] w-full max-w-3xl flex-col overflow-hidden rounded-(--radius-card)
                            bg-(--color-surface-card) text-(--color-ink) shadow-xl">
                <header class="flex items-center justify-between gap-2 px-4 py-3 text-(--color-ink-inverse)" data-unload-head
                        style="background: var(--color-info)">
                    <h2 id="unloading-title" class="text-base font-semibold">
                        {{ __('purchase::field.unloading_bill') }}<span x-show="unloadSplit === 'product'" x-cloak> — {{ __('supplier::labour.split_product') }}</span>
                    </h2>
                    <button type="button" @click="unloadOpen = false" aria-label="{{ __('purchase::action.close_panel') }}"
                            class="rounded-(--radius-field) px-2 text-xl leading-none hover:opacity-80">&times;</button>
                </header>

                <div class="min-h-0 space-y-3 overflow-y-auto p-4 text-sm">
                    <div>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.split_how') }}</span>
                        <div class="ds-seg" role="radiogroup" aria-label="{{ __('supplier::labour.split_how') }}">
                            @foreach (['whole', 'product'] as $split)
                                <button type="button" role="radio" @click="setUnloadSplit('{{ $split }}')"
                                        :class="unloadSplit === '{{ $split }}' ? 'is-on' : ''"
                                        :aria-checked="unloadSplit === '{{ $split }}' ? 'true' : 'false'">{{ __('supplier::labour.split_'.$split) }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.who_pays') }}</span>
                        <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-label="{{ __('supplier::labour.who_pays') }}">
                            @foreach (\App\Modules\Supplier\Services\LabourCost::UNLOADING as $choice)
                                <button type="button" role="radio" @click="unloadMode = '{{ $choice }}'"
                                        :aria-checked="unloadMode === '{{ $choice }}' ? 'true' : 'false'"
                                        :class="unloadMode === '{{ $choice }}'
                                            ? 'border-(--color-success) bg-(--color-success) text-(--color-ink-inverse)'
                                            : 'border-(--color-border) bg-(--color-surface-card) hover:bg-(--color-surface-hover)'"
                                        class="rounded-(--radius-field) border px-2 py-2 text-sm font-semibold">{{ __('supplier::labour.mode_'.$choice) }}</button>
                            @endforeach
                        </div>
                    </div>
                    <input type="hidden" name="unload_mode" :value="unloadMode">
                    <input type="hidden" name="unload_amount" :value="unloadAmountSent">

                    {{-- ⭐ কাকে দিলেন / কার কাছে বাকি — উপরে (মালিক, ২৬ নভেম্বর ২০২৬)। নগদে: তালিকা থেকে, অথবা হাতে নাম;
                         বাকিতে: তালিকা থেকেই (দেনা কারো নামে বসতে হয়), না থাকলে "＋ নতুন ঠিকাদার"। --}}
                    <div x-show="unloadCostsUs" x-cloak>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)"
                              x-text="unloadMode === 'cash' ? @js(__('supplier::labour.paid_to_label')) : @js(__('supplier::labour.contractor'))"></span>
                        <div class="grid gap-2" :class="unloadMode === 'cash' ? 'grid-cols-2' : 'grid-cols-1'">
                            @if ($labourContractors->isEmpty())
                                <p class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1 text-2xs text-(--color-badge-warning-ink)">
                                    {{ __('supplier::labour.no_contractor_party') }}
                                </p>
                            @else
                                <select name="unload_contractor_id" x-model="unloadContractorId" class="{{ $unloadInput }}">
                                    <option value="">—</option>
                                    @foreach ($labourContractors as $contractor)
                                        <option value="{{ $contractor['id'] }}">{{ $contractor['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <input type="text" maxlength="120" x-show="unloadMode === 'cash'" x-cloak x-model="unloadPayee"
                                   placeholder="{{ __('supplier::labour.payee_name') }}" aria-label="{{ __('supplier::labour.payee_name') }}"
                                   class="{{ $unloadInput }}">
                        </div>
                        <input type="hidden" name="unload_payee" :value="unloadMode === 'cash' ? unloadPayee : ''">
                        {{-- ⓘ ঠিকাদার না থাকলে এখান থেকেই যোগ — নতুন ট্যাবে, তাই এই বিলটা হারায় না; ফিরে এসে পাতা নতুন করে খুললে তালিকায় আসে --}}
                        <a href="{{ $newContractorUrl }}" target="_blank" rel="noopener" data-new-contractor
                           class="mt-1 inline-block text-2xs font-semibold text-(--color-link)">＋ {{ __('supplier::labour.new_contractor') }}</a>
                    </div>

                    {{-- ⭐ এক লাইনে: কোন টিল থেকে (নগদে) · কত টাকা --}}
                    <div x-show="unloadCostsUs" x-cloak class="grid gap-2" :class="unloadMode === 'cash' ? 'grid-cols-2' : 'grid-cols-1'">
                        <label class="block" x-show="unloadMode === 'cash'" x-cloak>
                            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.till') }}</span>
                            <select name="unload_account_id" x-model="unloadAccountId" class="{{ $unloadInput }}">
                                <option value="">—</option>
                                @foreach ($labourTills as $till)
                                    <option value="{{ $till['id'] }}">{{ $till['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block" x-show="unloadSplit === 'whole'">
                            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.whole_amount') }}</span>
                            <input type="number" step="0.01" min="0" inputmode="decimal" x-model="unloadAmount"
                                   class="num {{ $unloadInput }} text-end font-bold">
                        </label>
                        <div x-show="unloadSplit === 'product'" x-cloak>
                            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('purchase::field.amount') }}</span>
                            <div class="num flex h-(--spacing-field) items-center justify-end rounded-(--radius-field) bg-(--color-surface-sunken) px-2 font-bold"
                                 x-text="money(unloadProductTotal)"></div>
                        </div>
                    </div>

                    <label class="block" x-show="unloadMode === 'none'" x-cloak>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.reason') }}</span>
                        <input type="text" name="unload_reason" maxlength="255" x-model="unloadReason" class="{{ $unloadInput }}">
                    </label>

                    {{-- ── পুরো চালানে একটা অঙ্ক — যা সত্যিই দেওয়া হলো, নিজে লিখতে হয়; কার্টনপ্রতি দরের প্রস্তাব দেখায় না
                         (মালিক, ২৬ নভেম্বর ২০২৬: "৪০ পয়সার সাজেশন দিব না") --}}
                    <div x-show="unloadCostsUs && unloadSplit === 'whole'" x-cloak>
                        <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.split_hint') }}</p>
                    </div>

                    {{-- ── পণ্য ধরে — প্রতি কার্টনের হার, টাকা নিজে গোনা (কার্টন × হার) --}}
                    <div x-show="unloadCostsUs && unloadSplit === 'product'" x-cloak class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-2xs">
                            <span>{{ __('supplier::labour.same_rate') }}:</span>
                            <input type="number" step="0.01" min="0" inputmode="decimal" x-model="unloadSame"
                                   aria-label="{{ __('supplier::labour.same_rate') }}"
                                   class="num h-(--spacing-field-dense) w-24 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                            <button type="button" @click="applySameRate()"
                                    class="rounded-(--radius-field) px-3 py-1 font-semibold text-(--color-ink-inverse)"
                                    style="background: var(--color-info)">{{ __('supplier::labour.apply_all') }}</button>
                            <span class="text-(--color-ink-muted)">— {{ __('supplier::labour.same_rate_hint') }}</span>
                        </div>

                        <table class="ui-grid is-compact w-full text-sm">
                            <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                                <tr>
                                    <th class="text-start">{{ __('core.table.serial') }}</th>
                                    <th class="text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="text-end">{{ __('supplier::labour.cartons') }}</th>
                                    <th class="text-end">{{ __('supplier::labour.rate_short') }}</th>
                                    <th class="text-end">{{ __('purchase::field.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, index) in lines" :key="'u' + line.key">
                                    <tr class="border-t border-(--color-border)">
                                        <td class="num" x-text="index + 1"></td>
                                        <td x-text="line.name"></td>
                                        <td class="num" x-text="qty(lineCartons(line))"></td>
                                        <td class="text-end">
                                            <input type="number" step="0.01" min="0" inputmode="decimal" x-model="line.unload_rate"
                                                   aria-label="{{ __('supplier::labour.rate_short') }}"
                                                   class="num h-(--spacing-field-dense) w-24 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                        </td>
                                        <td class="num font-medium" x-text="money(unloadLineAmount(line))"></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="bg-(--color-surface-sunken) font-semibold">
                                <tr>
                                    <td></td>
                                    <td x-text="@js(__('purchase::field.items_count', ['count' => ':n'])).replace(':n', lines.length)"></td>
                                    <td class="num" x-text="qty(unloadCartons)"></td>
                                    <td></td>
                                    <td class="num" x-text="money(unloadProductTotal)"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p x-show="unloadProblem !== ''" x-cloak role="alert" x-text="unloadProblem"
                       class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-2xs text-(--color-badge-danger-ink)"></p>
                    @foreach ([...\App\Modules\Supplier\Services\LabourCost::fields('unload_'), 'unload_mode', 'unload_payee'] as $field)
                        @error($field)
                            <p class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-2xs text-(--color-badge-danger-ink)" role="alert" data-unload-error>{{ $message }}</p>
                        @enderror
                    @endforeach
                </div>

                <footer class="border-t border-(--color-border) p-4">
                    <button type="button" @click="saveUnloading()"
                            class="w-full rounded-(--radius-field) bg-(--color-success) px-4 py-2.5 text-base font-semibold
                                   text-(--color-ink-inverse) hover:bg-(--color-success-hover)">
                        {{ __('supplier::labour.put_on_bill') }}<span x-show="unloadCostsUs" x-cloak class="num"> · ৳<span x-text="money(unloadTotal)"></span></span>
                    </button>
                </footer>
            </section>
        </div>
    </form>

    {{-- ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ — এই পাতায় খসড়ার বোতাম নেই, তাই পপ-আপেও নেই --}}
    <x-ui.confirm-overview :draft="false" />

</x-layouts.app>
