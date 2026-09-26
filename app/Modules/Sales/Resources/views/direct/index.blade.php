{{--
    ⚠️ এই ফাইলের কোনো মন্তব্যে **কম্পোনেন্টের ট্যাগ কোণ-বন্ধনীসহ লিখবেন না**
    (কোণ-বন্ধনী ছাড়া, যেমন `x-ui.date`) — Blade মন্তব্য ফেলে দেওয়ার **আগে**
    কম্পোনেন্টের ট্যাগ খোঁজে, তাই মন্তব্যের ভিতরের ট্যাগটাও সে **আসল খোলা
    ট্যাগ** ধরে নেয়। বন্ধ না পেলে সে বাকি পুরো ফাইলটা গিলে ফেলে, আর ভুলটা
    দেখা যায় একদম শেষে: *"unexpected end of file, expecting endif"* — যে
    লাইনটার সাথে আসল ভুলের কোনো সম্পর্ক নেই।

    ⓘ একই কথা JavaScript-এর `/* */` মন্তব্যেও খাটে — ওগুলো Blade-এর কাছে
    সাধারণ লেখা, আর সেখানেও ট্যাগটা ধরা পড়ে। ৩ সেপ্টেম্বর ২০২৬-এ ঠিক
    এভাবেই পর্দাটা ৫০০ দিয়েছিল।

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
          x-data="directSale({
              catalogue: @js($products),
              lots: @js($lots),
              customers: @js($customerTerms),
              walkinId: {{ $walkinId }},
              vatEnabled: {{ $vatEnabled ? 'true' : 'false' }},
              packs: @js($packs),
              paymentTermDefault: @js($paymentTermDefault),
              carriers: @js($carriers),
              depositMethods: @js($depositMethods),
              moneyAccounts: @js($moneyAccounts),
              draftKey: 'abos.direct-sale.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',
              hasErrors: @js($errors->any()),
              texts: @js([
                  'notForSales' => __('sales::message.not_for_sales'),
                  'freeBeyondRatio' => __('sales::validation.free_over_allowance'),
                  'creditBeyondLimit' => __('sales::validation.credit_left_is_only'),
                  'creditWall' => __('sales::message.credit_wall_body'),
                  'lotIsRequired' => __('sales::validation.lot_must_be_chosen'),
                  'lotAlreadyInCart' => __('sales::validation.lot_already_in_cart'),
                  'freeNextAt' => __('sales::message.free_next_at'),
              ]),
              freeAllowedUrl: @js(route('sales.direct.free_allowed')),
              warehouseId: @js($warehouse?->id),
              creditRules: @js($creditRules),
          })"
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
          @submit="guardSubmit($event)"

          {{--
              ── কি-বোর্ডের শর্টকাট — POS-এর কী-গুলোই ─────────────────────

              মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"sortcut baton kaj
              koraw"*।

              ── কেন নতুন কী বানানো হয়নি ─────────────────────────────────
              POS-এ এই ব্যবস্থাটা **আগে থেকেই আছে** (F1–F10), আর কাউন্টারের
              লোক ওগুলো হাতে শিখে ফেলেছেন। ⚠️ এখানে আলাদা কী দিলে **একই
              মানুষকে দুই পর্দায় দুই অভ্যাস** রাখতে হত — আর কাউন্টারে
              অভ্যাসই গতি।

                  F1   এই তালিকা          POS-এ একই
                  F2   জমা যোগ            POS-এ "টাকার ঘর" — একই অর্থ
                  F6   চার্ট এন্ট্রি        POS-এ split, এখানে নেই
                  F7   ক্রেতা খোঁজা         POS-এ একই
                  F8   পণ্য খোঁজা          POS-এ একই
                  F9   কার্টে যোগ করুন
                  F10  নিশ্চিত করুন         POS-এ "বিক্রয় সম্পূর্ণ"
                  Esc  খোলা জিনিস বন্ধ

              ⚠️ **F4 ইচ্ছে করে খালি** — POS-এ ওটা "বিল ধরে রাখুন", আর সেই
              কাজটা এই পর্দাতেও আসছে। এখন অন্য কিছুতে দিলে পরে কেড়ে নিতে হত,
              আর কেড়ে নেওয়া অভ্যাস সবচেয়ে বিরক্তিকর।

              ⓘ `.prevent` লাগে কারণ ব্রাউজার F-কী গুলো নিজের কাজে নেয়
              (F7 caret browsing, F10 মেনু) — না দিলে শর্টকাট চলত, সাথে
              ব্রাউজারের কাজটাও।

              ⚠️ Esc দুই ধাপে, POS-এর মতোই: আগে খোলা প্যানেল, তারপর
              বাছাইয়ের ঘর। একবারে সব বন্ধ করলে ভুল চাপে টাইপ করা সব যেত।
          --}}
          @keydown.window.f1.prevent="helping = ! helping"
          @keydown.window.f2.prevent="openPanel('deposit')"
          @keydown.window.f6.prevent="openChartEntry()"
          @keydown.window.f7.prevent="customerPickerOpen = true"
          @keydown.window.f8.prevent="openPicker()"
          @keydown.window.f9.prevent="picked && addToCart()"

          {{--
              ── Enter দিয়েও কার্টে যোগ — মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬)

              *"শর্টকাট Enter key রাখো add করার জন্য"*।

              ⚠️ **শর্তগুলো ছাড়া এটা বিপজ্জনক হত:**

              `picked` না থাকলে কিছু করে না — নাহলে খালি পর্দায় Enter চেপে
              কিছু না ঘটা দেখে মানুষ ভাবতেন পর্দা আটকে গেছে।

              `panel` খোলা থাকলে করে না — জমা বা নোটের ঘরে Enter চাপা মানে
              ওই ঘরের কাজ, কার্টে ফেলা নয়।

              ⓘ পণ্য ও ক্রেতা খোঁজার ঘরে Enter আগে থেকেই **প্রথমটা বাছে**
              (`pickFirst`), আর ওগুলো নিজের ঘরে `.prevent` করে — তাই এই
              window-স্তরের হ্যান্ডলারে পৌঁছায় না। **দুইটা Enter দুই কাজ
              করে, আর কোনটা কখন তা ঘরটাই ঠিক করে।**
          --}}
          @keydown.window.enter="! panel && picked && addToCart()"
          @keydown.window.f10.prevent="canConfirm && $press($refs.confirm)"
          @keydown.window.escape="escape()"

          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{--
            ── মজুদ নেই — পর্দার মাঝখানে, লাল, শব্দসহ ──────────────────────

            মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"stock na thakle …
            warning sound dibe, color lal hobe, screen-er majkhane boro
            kore notice dibe"*।

            ── কেন মাঝখানে, কোণায় নয় ──────────────────────────────────
            ⚠️ কাউন্টারে চোখ থাকে পণ্যের ঘরে, আর কোণার ছোট বার্তা **কেউ
            পড়েন না** — বিশেষ করে যখন ক্রেতা সামনে দাঁড়িয়ে কথা বলছেন।
            এটা সরিয়ে না দেওয়া পর্যন্ত পর্দা এগোয় না, তাই না পড়ে
            এগোনোর উপায় নেই।

            ⓘ পণ্যের নাম ও কোড দুইটাই লেখা — একই নামের দুইটা পণ্য থাকলে
            কোনটা তা কোডই বলে (আজকের ডুপ্লিকেশন ইঞ্জিনের কারণ)।

            ⚠️ Esc বা বাইরে চাপলেই বন্ধ — একটা বাড়তি ক্লিক চাপিয়ে দেওয়ার
            মানে নেই, বার্তাটা পড়া হয়ে গেলে কাজ শেষ।
        --}}
        <div x-show="outOfStock" x-cloak @click.self="outOfStock = null"
             @keydown.window.escape="outOfStock = null"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-(--radius-card) border-2 border-(--color-danger)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p class="text-3xl font-bold text-(--color-danger)">
                    {{ __('sales::message.no_stock_title') }}
                </p>

                <p class="mt-3 text-lg font-semibold text-(--color-ink)"
                   x-text="(outOfStock && outOfStock.name)"></p>

                <p class="num mt-1 text-2xs text-(--color-ink-muted)"
                   x-text="(outOfStock && outOfStock.code)"></p>

                <p class="mt-4 text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.no_stock_hint') }}
                </p>

                <button type="button" @click="outOfStock = null"
                        class="mt-5 w-full rounded-(--radius-field) bg-(--color-danger) px-4 py-2
                               font-semibold text-white hover:bg-(--color-danger-hover)">
                    {{ __('core.action.close') }}
                </button>
            </div>
        </div>

        {{--
            ── ⛔ বাকির সীমা পার — বড় পপ-আপ, মালিকের নির্দেশ ─────────────────

            মালিক, ২৫ সেপ্টেম্বর ২০২৬: *"limit over confarm korte caile boro kore
            pop up notice & sound dite hobe ze eta kono vabei somvob na"*।

            ⚠️ বোতাম কেবল একটা — "বুঝেছি"। ⛔ কোনো "তবুও চালাও" নেই, আর সীমা
            বাড়ানোর কথাও নেই: *"kotin kore likhar karon zate malik k request na
            korte pare kew"*। ⓘ বাইরে চাপলে বা Esc-এ বন্ধ হয় না — পড়ে "বুঝেছি"
            চাপতে হয়।

            ⓘ লেখাটা [[direct-sale.js]]-এর `creditBlockText` থেকে; সংখ্যা দুইটা
            সেবার হিসাবের আয়না ([[CreditExposure::assertRoom()]])।
        --}}
        <div x-show="creditBlocked" x-cloak role="alertdialog" aria-modal="true"
             aria-labelledby="credit-wall-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-(--radius-card) border-2 border-(--color-danger)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p id="credit-wall-title" class="text-3xl font-bold text-(--color-danger)">
                    {{ __('sales::message.credit_wall_title') }}
                </p>

                <p class="mt-4 text-lg font-semibold leading-relaxed text-(--color-ink)"
                   x-text="creditBlockText"></p>

                <button type="button" @click="closeCreditBlock()" x-ref="creditWallOk"
                        class="mt-6 w-full rounded-(--radius-field) bg-(--color-danger) px-4 py-3
                               text-lg font-bold text-white hover:bg-(--color-danger-hover)">
                    {{ __('sales::message.credit_wall_ok') }}
                </button>
            </div>
        </div>

        {{--
            ── F1-এর সাহায্য — শর্টকাটগুলো কোথাও লেখা থাকতেই হবে ───────────

            ⚠️ **শর্টকাট আছে অথচ কোথাও লেখা নেই — এটাই সবচেয়ে অকেজো ধরনের
            সুবিধা।** যিনি জানেন কেবল তিনিই পান, আর নতুন কর্মী কোনোদিন
            জানেনই না যে জিনিসটা আছে।

            ⓘ POS-এও F1 ঠিক এই তালিকাটাই খোলে — একই কী, একই আচরণ।
        --}}
        <div x-show="helping" x-cloak @click.self="helping = false"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-sm rounded-(--radius-card) bg-(--color-surface-card) p-4 shadow-lg">
                <h2 class="mb-3 font-medium">{{ __('sales::message.direct_keys') }}</h2>

                <dl class="space-y-1 text-sm">
                    @foreach ([
                        'F1' => 'sales::message.key_help',
                        'F2' => 'sales::message.key_paid',
                        'F6' => 'sales::message.key_chart',
                        'F7' => 'sales::message.key_customer',
                        'F8' => 'sales::message.key_search',
                        'F9' => 'sales::message.key_add_line',
                        'F10' => 'sales::message.key_checkout',
                        'Esc' => 'sales::message.key_close',
                    ] as $key => $label)
                        <div class="flex justify-between gap-4">
                            <dt class="num font-medium">{{ $key }}</dt>
                            <dd class="text-(--color-ink-muted)">{{ __($label) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

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
            <div class="grid gap-3 lg:grid-cols-[1fr_14rem] lg:items-start">

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
                {{--
                    ⚠️ সবুজ উপরের রেখাটা তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬)।

                    মালিক বললেন সব বাক্স একসাথে ডিজাইন করতে। মেপে দেখা গেল
                    পাঁচটা বাক্সের **পাঁচ রকম চেহারা**: একটার সবুজ উপরের
                    রেখা, একটার সাধারণ বর্ডার, একটার বর্ডারই নেই, কারও
                    ছায়া নেই।

                    ⭐ **এখন পাঁচটাই এক নিয়মে** — একই বর্ডার, একই কোণ, একই
                    হালকা ছায়া। D365 বা Odoo-র মতো পর্দায় **কার্ডগুলো
                    নিজেরা চুপ থাকে, কথা বলে ভেতরের জিনিস** — একটা কার্ডের
                    গায়ে রঙিন রেখা মানে সে অন্যদের চেয়ে জরুরি, আর এখানে
                    সেটা সত্যি ছিল না।

                    ⓘ একমাত্র রঙিন কার্ড "এই লাইন", কারণ ওটাই **চলমান
                    অঙ্ক** — বাকিরা ঘর, ওটা ফল।
                --}}
                {{-- ⚠️ এই মোড়কটা আর **দেখা যায় না** — সীমানা, ছায়া আর
                     প্যাডিং তিনটাই ভিতরের দুইটা বাক্সে চলে গেছে।

                     ⛔ না সরালে বাক্সের ভিতরে বাক্স হত — তিনটা
                     সীমানা পাশাপাশি, আর মালিক ঠিক ঐ এলোমেলো
                     রেখাগুলোর কথাই বলছেন। --}}
                @include('sales::direct.partials.party')
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
                {{-- ⓘ পণ্যের বাক্স — নিজের রং আর বাঁয়ে দাগ, মালিকের
                     নির্দেশে (*"Producter Box eo dio"*)। --}}
                @include('sales::direct.partials.entry')
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
                    {{-- ⛔ জমিন `surface-card`, **ব্যাজের সবুজ নয়** — ৬ সেপ্টেম্বর ২০২৬।

                         ── কী ভাঙা ছিল ─────────────────────────────────────────
                         প্যানেলটা `--color-badge-success-bg` পরত। ⚠️ কিন্তু
                         **ব্যাজের টোকেন দিয়ে প্যানেল আঁকা যায় না**: ব্যাজ একটা
                         ছোট পিল — সে নিজের জমিন **আর** নিজের কালি একসাথে আনে,
                         আর ভিতরে আর কিছু বসে না। ⓘ এখানে ভিতরে বোতাম, লেবেল,
                         সংখ্যা, ড্রপডাউন — সবাই বসে, আর তাদের কেউ জানে না তারা
                         সবুজের উপরে আছে; তারা **পাতার কালি** পায়।

                         ⛔ মাপা ফল (গাঢ় থিম, সবুজ জমিনের উপরে):
                             উপহার বোতামের জমিন   ১.০৩:১   কার্যত অদৃশ্য
                             "ঘর খালি করুন" লেখা   ২.৫৫:১   পড়তে চাপ লাগে
                             লেবেলগুলোর কালি      ৪.৯০:১   অথচ পাশের সংখ্যা ১০.৩৫

                         ⭐ **ছাঁচটা মনে রাখার মতো:** টোকেনটা যাচাই হয়েছিল
                         **কার্ডের জমিনে** (১.৩৭:১, পাস), কিন্তু জিনিসটা বসে
                         **অন্য জমিনে**। ⚠️ "টোকেন মাপা আছে" যথেষ্ট নয় — সে
                         কোথায় বসছে সেটাও মাপার অংশ।

                         ⭐ তাই জমিনটা এখন `--color-surface-success-tint` —
                         **আলো থিমে সবুজ ছোপ, গাঢ় থিমে কার্ড**। ⓘ আপনার পছন্দের
                         সবুজ ঘরটা দিনের আলোয় অক্ষত; রাতে সবুজটা **পরিচয় হয়ে
                         বাঁচে** — বাঁয়ের দাগ, কিনারা আর "এই লাইন" শিরোনামে।

                         ⚠️ কেন গাঢ়তে ছোপ রাখা গেল না, তার মাপা তালিকাটা
                         `tokens.css`-এ ঐ টোকেনের পাশে। ⛔ সংক্ষেপে: জমিন যত
                         সবুজ, ভিতরের সবকিছু তত খারাপ — কারণ ভিতরের রংগুলো
                         নিরপেক্ষ গাঢ় কার্ডের সাথে মেপে বাছা। --}}
                    {{-- ⭐ ডান কলামের মোড়ক — "এই লাইন" আর তার নিচের তিনটা বোতাম
                         **একসাথে**, মালিকের নির্দেশ ২৪ সেপ্টেম্বর ২০২৬:
                         *"uporer faka ongshe mark kora lal box er vitor fit kore daw"*।

                         ── ⛔ কেন ফাঁকটা পড়েছিল ───────────────────────────────
                         মোড়ক গ্রিডে বাঁ কলামটা **একটাই ছক-ঘর** (কাগজের বাক্স +
                         এন্ট্রি কার্ড একসাথে), আর সেটা লম্বা। ⓘ বোতামগুলো আলাদা
                         ছক-ঘর হওয়ায় তারা পড়ত **দ্বিতীয় সারিতে** — আর দ্বিতীয়
                         সারি শুরু হয় বাঁ কলামটা শেষ হওয়ার পরে।
                         ⚠️ ফলে "এই লাইন"-এর নিচে একটা লম্বা ফাঁক, আর বোতামগুলো
                         অনেক নিচে — ছবিতে ঠিক সেটাই।

                         ⭐ এখন দুইটাই এক মোড়কে, আর মোড়কটাই ছক-ঘর। ⓘ `space-y-2`
                         বোতামগুলোকে প্যানেলের ঠিক নিচে এনে বসায়, ফাঁক ছাড়া। --}}
                    <div class="space-y-2 lg:col-start-2 lg:col-end-[-1] lg:row-start-1">
                    <div class="rounded-(--radius-card) border border-(--color-success)/40
                                bg-(--color-surface-success-tint) p-3 shadow-sm"
                         style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-state-on)">
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
                            {{-- ⓘ শিরোনাম ও অঙ্ক সবুজই — জমিন বদলেছে, পরিচয় নয়।
                                 ⚠️ কালিটা `--color-success` নয়, ব্যাজেরটাই: গাঢ়
                                 থিমে ব্যাজের সবুজ কালি হালকা (`#6ee7b7`), আর
                                 কার্ডের জমিনে সেটাই পড়া যায় — `--color-success`
                                 (#047857) গাঢ় কার্ডে ২:১-এরও নিচে নামত। --}}
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
                                <div class="flex items-center gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.line_discount') }}</dt>
                                    <dd class="flex flex-1 items-center gap-1">
                                        <input type="text" inputmode="decimal" x-model="entry.discountInput"
                                               placeholder="{{ __('sales::field.amount_or_pct') }}"
                                               class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        <span class="num ms-auto w-16 text-end" x-text="money(entryDiscount)"></span>
                                    </dd>
                                </div>
                            @endif

                            {{-- ⛔ ভ্যাটের সারিটা এই বাক্স থেকে **উঠে গেছে** —
                                 মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬:
                                 *"This Line box e VAT bad daw"*।

                                 ── ⓘ কিছুই হারায়নি, আর সেটা মেপে দেখা ──────────
                                 ভ্যাটের ধরন বদলানোর দ্বিতীয় জায়গা আগে থেকেই আছে —
                                 নিচের যোগফলের প্যানেলে ([[direct/partials/totals]]),
                                 আর ওখানেরটাই **আসল**: সেটা `name="vat_mode"` নিয়ে
                                 ফর্মের সাথে জমা যায়, এখানেরটা যেত না।

                                 ⚠️ অর্থাৎ এটা দুইটা নিয়ন্ত্রণের একটা সরানো, একটামাত্র
                                 নিয়ন্ত্রণ মুছে ফেলা নয় — ⛔ যাচাই করা হয়েছে
                                 (`grep vatMode`), নাহলে কাউন্টারে ভ্যাটের ধরন আর
                                 বদলানোই যেত না।

                                 ⓘ ভ্যাটের **অঙ্কটা** অক্ষত: `entryVat` নিট মূল্যের
                                 ভিতরে আগের মতোই বসে ([[direct-sale.js]])। এখানে কেবল
                                 সারিটা আর দেখানো হয় না। --}}

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

                            {{-- ⭐ আর কত বাকিতে দেওয়া যাবে — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।

                                 তাঁর কথা: *"avelable Cr Limit … এই লাইন box e চলতি মোট er niche"*।

                                 ── ⛔ হিসাবটা আগে থেকেই লেখা ছিল, দেখানো হত না ──────────
                                 `availableCredit` getter-টা কারণসহ লেখা — *"বাকির সীমা
                                 ৭৫,০০০ একটা চুক্তির সংখ্যা, কাউন্টারের নয়; যাঁর ৭০,০০০
                                 আগেই বাকি, তাঁর জন্য খোলা আছে মাত্র ৫,০০০"*। ⚠️ অথচ
                                 সংখ্যাটা কোনো পর্দায় উঠত না — কাজ হয়ে ছিল, জোড়াটা ছিল না।

                                 ⓘ সীমা ০ হলে সারিটা আসে না: শূন্য মানে *"বাকি বন্ধ"*,
                                 আর সেটা আলাদা কথা — ঐ যুক্তিটাও getter-এর মন্তব্যে লেখা।

                                 ⚠️ ঋণাত্মক হলে লাল: সীমা ইতিমধ্যেই পেরিয়ে গেছে, আর
                                 ⛔ কাউন্টারে ঐ মুহূর্তটা **চালান নিশ্চিত করার আগেই**
                                 চোখে পড়া দরকার, পরে নয়। --}}
                        </div>
                    </div>

                    {{-- ⭐ তিনটা বোতাম — ডান কলামে, "এই লাইন" বাক্সের **ঠিক নিচে**।
                         মালিকের ছবি, ২৪ সেপ্টেম্বর ২০২৬: লাল বাক্স দিয়ে ঘেরা
                         ফাঁকা জায়গাটা, আর তীর এঁকে দেখানো।

                         ── ⛔ একই দিনে তিনবার সরেছে, আর তিনবারই আমার পাঠের ভুল ──
                         ⓘ ১ম: *"ager jaygay daw"* → আমি ধরলাম "এই লাইন" বাক্সের
                         **ভিতরে** (৩ সেপ্টেম্বরের জায়গা)। ভুল।
                         ⓘ ২য়: *"Qty … Sales Rate … কার্টে যোগ করুন — ei gulor dane"*
                         → আমি ধরলাম এন্ট্রির সারির ভিতরে, অষ্টম কলাম। আবার ভুল —
                         ওটা এন্ট্রি কার্ডের ভিতরে পড়ে, আর তিনি কার্ডের **বাইরের**
                         ফাঁকা জায়গাটা দেখাচ্ছিলেন।
                         ⭐ ৩য়: ছবিতে লাল বাক্স — এই জায়গাটা।

                         ⚠️ দুইবার শব্দ পড়ে ধরে নিয়েছি, দুইবারই ভুল। ⛔ পর্দার
                         জায়গা শব্দে বোঝা যায় না; ছবিটাই একমাত্র সঠিক উৎস ছিল।

                         ── ⓘ কেন এখানে বসলে ঠিক জায়গায় পড়ে ──────────────────
                         মোড়ক গ্রিডটা `lg:grid-cols-[1fr_14rem]`, আর `items-start`
                         বলে ডান কলামটা টানটান হয় না — তাই "এই লাইন"-এর নিচে
                         জায়গাটা ফাঁকা পড়ে থাকত। ⭐ এই বাক্সটা ঐ কলামের দ্বিতীয়
                         সারি, তাই ঠিক ওখানেই বসে।

                         ⚠️ `lg:col-start-2` ছাড়া চলে না: `lg:` -এর নিচে কলাম
                         একটাই, আর তখন বাক্সটা নিজে থেকেই পুরো প্রস্থে নামে —
                         ⓘ ফোনে সেটাই ঠিক।

                         ⛔ ক্রমটা মালিকের লেখা, অনুমান করে বদলাবেন না। আর
                         "সব মুছুন" নিচের বারে আলাদাই থাকে — দুইটা মুছে ফেলার
                         বোতাম পাশাপাশি থাকলে ভুল চাপ পড়া নিশ্চিত। --}}
                    {{-- ⭐ তিনটা পাশাপাশি, একটার নিচে একটা নয় — মালিকের নির্দেশ,
                         ২৪ সেপ্টেম্বর ২০২৬: *"egulo pasa pasi bosbe"*।

                         ⓘ ৬ সেপ্টেম্বরেও তিনি একই কথা বলেছিলেন (*"Gift, Costing,
                         Clear Data এক লাইন রাখো"*) — অর্থাৎ এটা নতুন সিদ্ধান্ত নয়,
                         একই পছন্দ দ্বিতীয়বার। ⚠️ আমি জায়গা বদলাতে গিয়ে ছাঁচটাও
                         বদলে ফেলেছিলাম, অথচ তিনি কেবল জায়গার কথা বলেছিলেন।

                         ⛔ `grid-cols-3`, `flex` নয়: তিনটা ঘর **সমান ভাগ** পায়,
                         তাই "ঘর খালি করুন" লম্বা বলে সে বেশি জায়গা টেনে নেয় না।
                         ⓘ কলামটা ১৪rem, তাই প্রতিটা বোতাম ≈৯০px — লম্বা লেখাটা
                         দুই লাইনে ভাঁজ হয়, আর `leading-tight` তাতে উচ্চতা ধরে রাখে। --}}
                    <div class="grid grid-cols-3 items-start gap-1">
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

                        {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
                             জন্য নয়। তাই বোতামের পেছনে: চোখে পড়ে না,
                             কিন্তু দরকার হলে এক চাপ দূরে। --}}
                        <button type="button" @click="showCosting = ! showCosting"
                                class="w-full rounded-(--radius-field) leading-tight border border-(--color-border)
                                       px-1 py-1.5 text-2xs font-medium">
                            {{ __('sales::field.costing') }}
                        </button>

                        <button type="button" @click="clearEntry()"
                                class="w-full rounded-(--radius-field) leading-tight bg-(--color-danger)/10 px-2 py-1.5
                                       text-2xs font-medium text-(--color-danger) hover:bg-(--color-danger)/20">
                            {{ __('sales::action.clear_data') }}
                        </button>

                        {{-- ⓘ ক্রয়মূল্যের সংখ্যাটা তিন কলাম জুড়ে, বোতামের সারির নিচে।
                             ⚠️ একটা কলামে বসালে ≈৯০px-এ একটা দাম কাটা পড়ত। --}}
                        <span x-show="showCosting" x-cloak
                              class="num col-span-full text-end text-xs text-(--color-ink-muted)"
                              x-text="picked ? money(picked.cost) : ''"></span>
                    </div>
                    </div>{{-- ডান কলামের মোড়ক শেষ --}}
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
            @include('sales::direct.partials.cart')

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
                ── নেওয়া জমাগুলোর তালিকা — চার্টের নিচে, বাম পাশে ──────────

                মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"এই list Item Chart-এর
                নিচে বাম পাশে থাকবে, একাধিক payment add করতে পারবে"*।

                ── কেন এখানে, ডান প্যানেলে নয় ─────────────────────────────
                ডান প্যানেলে জমার **যোগফল** থাকে ("জমা নেওয়া হলো ৳১৫,০০০")।
                ⚠️ সারিগুলোও ওখানে বসালে সরু কলামে পাঁচটা ঘর একটার নিচে
                আরেকটা নামত, আর যোগফলটা নিচে ঠেলে যেত — যে সংখ্যাটা সবচেয়ে
                বেশি দেখা হয়।

                ⭐ কার্টের নিচে বসার আসল কারণটা আলাদা: **এটা কার্টেরই মতো
                একটা জিনিস** — যা যা নেওয়া হলো তার তালিকা। মালের তালিকার
                ঠিক নিচে টাকার তালিকা, একই চোখের পথে।

                ⓘ খালি থাকলে দেখাই যায় না — বেশিরভাগ বাকির চালানে কোনো জমা
                নেই, আর একটা স্থায়ী খালি বাক্স রোজ চোখের সামনে থাকত।
            --}}
            @if ($show['deposit'])
                <div x-show="deposits.length > 0" x-cloak
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                    <div class="mb-2 flex items-baseline justify-between gap-2">
                        <span class="text-2xs font-semibold text-(--color-ink)">
                            {{ __('sales::field.received_deposit') }}
                        </span>

                        <span class="num text-sm font-bold text-(--color-success)"
                              x-text="'৳' + money(deposit)"></span>
                    </div>

                    <div class="overflow-x-auto">
                        {{-- ⓘ `ui-list` — তিনটা নামের একটা।

                             নাম না থাকলে ছকের মাপ ও ধার **কোনো থিম বদলাতে
                             পারবে না**, আর থিম বদলালে এই ছকটা একা আগের
                             চেহারায় বসে থাকত। ⚠️ ঘরের প্যাডিংও তাই হাতে
                             লেখা হয়নি — ওটা টোকেন থেকে আসে। --}}
                        <table class="ui-list w-full text-2xs">
                            <thead class="text-(--color-ink-muted)">
                                <tr class="border-b border-(--color-border)">
                                    <th class="text-start font-medium">{{ __('sales::field.ref_date') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.deposit_method') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.account') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.deposit_ref') }}</th>
                                    {{-- ⚠️ "বিবরণ", "মন্তব্য" নয় — মালিকের সিদ্ধান্ত
                                         (৪ সেপ্টেম্বর ২০২৬)। লেখাটা
                                         `collections.narration`-এ বসে, আদায়ের
                                         কাগজে ছাপা হয়, আর খতিয়ানের সারিতে যায় —
                                         অর্থাৎ ওটা হিসাবের বক্তব্য, পাশের নোট নয়।
                                         ⓘ এক কলামের দুইটা নাম হলে রিপোর্টে ওরা
                                         মিলত না। --}}
                                    <th class="text-start font-medium">{{ __('sales::field.narration') }}</th>
                                    <th class="text-end font-medium">{{ __('sales::field.amount') }}</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(row, i) in deposits" :key="i">
                                    <tr class="border-b border-(--color-border)/50">
                                        <td class="num" x-text="row.refDate || '—'"></td>
                                        <td x-text="depositMethodName(row.methodId)"></td>
                                        <td x-text="depositAccountName(row.accountId)"></td>
                                        <td class="num" x-text="row.reference || '—'"></td>
                                        <td x-text="row.narration || '—'"></td>
                                        <td class="num text-end font-semibold"
                                            x-text="money(row.amount)"></td>

                                        <td class="text-end">
                                            <button type="button" @click="dropDeposit(i)"
                                                    class="rounded px-1.5 text-(--color-danger)"
                                                    aria-label="{{ __('sales::action.remove_line') }}">&times;</button>
                                        </td>

                                        {{-- ⚠️ সার্ভারে যা যায় — সারির ভেতরেই।

                                             উপহারের মতো আলাদা করে সমতল করার
                                             দরকার নেই: তালিকাটা এমনিতেই এক
                                             স্তরের, তাই সূচকটা এখানেই বসে।

                                             ⓘ উপায়ের **কোড আর খাত সার্ভার
                                             নিজে বের করে** — পর্দার পাঠানো
                                             নাম বিশ্বাস করা হয় না। --}}
                                        <td class="hidden">
                                            <x-counter.deposit-fields />
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

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
                    <input type="hidden" :name="'gifts[' + (n) + '][product_id]'" :value="g.productId">
                    <input type="hidden" :name="'gifts[' + (n) + '][against_product_id]'" :value="g.againstProductId">
                    <input type="hidden" :name="'gifts[' + (n) + '][qty]'" :value="g.qty">
                    <input type="hidden" :name="'gifts[' + (n) + '][remarks]'" :value="g.remarks">
                </span>
            </template>

        </div>

        {{-- ══ ডান পাশের প্যানেল ═══════════════════════════════════════ --}}
        {{-- ⓘ বিলের মোট — কাগজের **ফল**, তাই নিজের রং আর দাগ।
             ⚠️ `overflow-hidden` লাগে, নাহলে ভিতরের সারিগুলো গোল কোণের
             বাইরে বেরিয়ে যেত। --}}
        @include('sales::direct.partials.totals')

    </form>

</x-layouts.app>
