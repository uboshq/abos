{{--
    মূলধন লেখা ও শোধরানো — একটাই ফর্ম, দুই কাজে (One Form Standard, ১৫.২৪)।

    ── ⛔ কেন ফর্মটা এখানে, তালিকার মাঝখানে নয় ──────────────────────────
    আগে ঘরগুলো তালিকার পাতার মাঝখানে গোঁজা ছিল, আর উপরে কোনো বোতাম ছিল
    না। মালিক ১৩ সেপ্টেম্বর ২০২৬-এ ধরিয়ে দিলেন যে বাকি পর্দায় — বিক্রয়
    বিল, ভাউচার, চেক — উপরে বাঁ কোণে "+ নতুন" থাকে।

    ⓘ এক রকম না হলে দাম দিতে হয় প্রতিদিন: মানুষ প্রতিটা পর্দায় নতুন করে
    খোঁজেন কোথায় কী, আর একটা পর্দা শিখে অন্যটায় কাজে লাগে না।

    ── সম্পাদনা কেবল খসড়ায় ────────────────────────────────────────────
    ⚠️ পোস্ট হওয়া সারি এখানে আসেই না ([[CapitalController::edit()]] আটকে
    দেয়)। পোস্ট মানে একটা ভাউচার আর দুইটা দাখিলা খাতায় বসে গেছে; সারিটা
    পরে বদলালে **খাতা আর তালিকা দুই কথা বলত**।
--}}
@php
    $isNew = ! ($entry ?? null);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>
        {{ $isNew ? __('finance::action.new_contribution') : __('finance::action.edit_contribution') }}
    </x-slot:title>

    <x-slot:header>
        {{-- ⭐ নমুনার হেডার — খাতার নাম, পাতার নাম নয় (১৫ সেপ্টেম্বর ২০২৬)।

             ⛔ আগে এখানে ছিল "নতুন মূলধন", আর নিচে আমি নমুনার কার্ডটা
             বসিয়েছিলাম — ফলে **দুইটা শিরোনাম পাশাপাশি**। মালিক লোকালে
             দেখে ধরিয়ে দিয়েছেন।

             ⓘ নামটা খাতার: "মালিকের পুঁজি" — কারণ ঢোকা আর বেরোনো একই
             খাতার দুই দিক, আর মানুষটা ঐ নামেই ওটাকে চেনেন। --}}
        {{-- ⭐ নমুনার হেডার — এক লাইনে নাম · ব্যাখ্যা · ইংরেজি নাম · নম্বর।

             ⓘ ইংরেজি নামটা (`Capital & Investment + Withdrawal`) নমুনায়
             আছে আর ওটার একটা কাজ আছে: মেনুতে ঐ দুইটা নামেই জিনিসটা
             ছিল, তাই পুরনো ব্যবহারকারী এখানে এসে চিনতে পারেন।

             ⚠️ `x-ui.page-header` ব্যবহার করা হয়নি, কারণ ওটা নাম আর
             ব্যাখ্যা দুই লাইনে বসায়; নমুনায় সব এক লাইনে। --}}
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">{{ __('finance::field.capital_book_name') }}</h1>

            <span class="text-sm text-(--color-ink-muted)">
                {{ __('finance::field.capital_book_tag') }}
            </span>

            <span class="text-2xs text-(--color-ink-muted)">
                ← {{ __('finance::field.capital_book_english') }}
            </span>

            @unless ($isNew)
                <span class="ms-auto rounded-(--radius-field) border border-(--color-border)
                             px-2 py-0.5 font-mono text-2xs">
                    {{ $entry->document_no }}
                </span>
            @endunless
        </div>
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 max-w-4xl rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed
             class="max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST" enctype="multipart/form-data" x-data
              action="{{ $isNew ? route('finance.capital.store') : route('finance.capital.update', $entry) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            {{-- ⭐ কোন দিকে — নমুনার প্রথম বাছাই।

                 ⓘ "পুঁজি ঢুকল" এই পাতায়, "মালিক তুললেন" উত্তোলনের
                 পাতায় — দুইটা একই খাতার দুই দিক, তাই চিপ দুইটা পাশাপাশি
                 আর একটা চাপলে অন্য পাতায় নিয়ে যায়।

                 ⛔ একই ফর্মে দুইটা দিক রাখা হয়নি ইচ্ছাকৃতভাবে: উত্তোলনের
                 নিজস্ব সীমা, অনুমোদন আর ধরন আছে (drawing/salary/profit),
                 আর সেগুলো এখানে বসালে দুইটা আলাদা নিয়ম এক ফর্মে মিশত। --}}
            <div class="sm:col-span-2 xl:col-span-3">
                <span class="mb-1 block text-sm font-medium">{{ __('finance::field.which_way') }}</span>

                <div class="flex flex-wrap gap-2" role="group">
                    <span aria-current="page"
                          class="flex items-center gap-1.5 rounded-(--radius-field)
                                 border border-(--color-brand-500) bg-(--color-brand-50)
                                 px-3 py-1.5 text-sm font-medium">
                        <span aria-hidden="true" class="block size-1.5 rounded-full bg-(--color-brand-500)"></span>
                        {{ __('finance::field.way_in') }}
                    </span>

                    <a href="{{ route('finance.withdrawal.create') }}"
                       class="flex items-center gap-1.5 rounded-(--radius-field)
                              border border-(--color-border) px-3 py-1.5 text-sm
                              transition-colors hover:bg-(--color-surface-hover)">
                        <span aria-hidden="true" class="block size-1.5 rounded-full bg-(--color-ink-muted)"></span>
                        {{ __('finance::field.way_out') }}
                    </a>
                </div>
            </div>

            {{-- ⓘ ঘরটা দুই কলাম নেয়, কারণ ভেতরে বাছাই আর নিচে নতুন নাম
                 যোগ করার পথ — দুইটা একসাথে। --}}
            <x-ui.field name="trx_date" type="date" :label="__('finance::field.date')" required
                        :value="old('trx_date', $entry?->trx_date?->toDateString() ?? now()->toDateString())" />

            {{-- ⓘ এক কলাম — নমুনায় তারিখ · কে · কোম্পানি এক সারিতে।
                 ⛔ আগে দুই কলাম নিত, তাই কোম্পানি পরের সারিতে নেমে
                 যেত আর সারিটা তিনটার বদলে দুইটা ঘর দেখাত। --}}
            <div>
                @include('finance::components.person-picker', [
                    'people' => $people,
                    'label' => __('finance::field.who'),
                    'required' => true,
                    'selected' => old('person_id', $entry->person_id ?? null),
                ])
            </div>

            {{-- ⭐ কার খাতায় লেখা হচ্ছে — নকশার "কোম্পানি ও শাখা"।

                 ⓘ ড্রপডাউন নয়, লেখা। কোম্পানি ও শাখা আগেই বাছা হয়ে
                 আছে শেলের উপরে; এখানে দ্বিতীয় বাছাই বসালে দুই জায়গায়
                 দুই উত্তর থাকত ([[CapitalController::writingFor()]])। --}}
            <div class="sm:col-span-2 xl:col-span-3">
                {{-- ⓘ `<label>`, `<span>` নয় — ঘরটা পড়ার জিনিস হলেও
                     পর্দা-পাঠকের কাছে এটা একটা মাঠের নাম, আর নামহীন
                     মাঠ সে "গ্রুপ" বলে পড়ে। --}}
                <label for="writing-for" class="mb-1 block text-sm font-medium">
                    {{ __('finance::field.writing_for') }}
                </label>
                <p id="writing-for" class="rounded-(--radius-field) bg-(--color-surface-app) px-3 py-2 text-sm">
                    {{ $writingFor }}
                </p>
            </div>

            <x-ui.field name="amount" type="number" step="0.01" numeric required
                        :label="__('finance::field.capital_amount')"
                        :value="old('amount', $entry->amount ?? null)" />


            {{-- ⭐ কী দিয়ে — নমুনার তিনটা পথ।

                 ⛔ টাকা ছাড়া অন্য কিছু দিলে দাখিলার ডেবিট দিকটাই বদলায়:
                 যন্ত্রপাতি গেলে স্থায়ী সম্পদে, পণ্য গেলে মজুদে।
                 ⚠️ ঘরটা না থাকায় সবই টাকা ধরা হত — একটা ট্রাক দিয়ে
                 দেওয়া মূলধনও নগদ হিসেবে বসত। --}}
            <x-ui.select name="in_kind" :label="__('finance::field.in_kind')"
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::IN_KINDS)
                             ->mapWithKeys(fn ($k) => [$k => __('finance::field.in_kind_'.$k)])"
                         :selected="old('in_kind', $entry->in_kind ?? \App\Modules\Finance\Models\CapitalEntry::CASH)" />

            {{-- ⭐ যে খাতে জমা — মালিকের নির্দেশে, ১৫ সেপ্টেম্বর ২০২৬।

                 ⓘ ঘরটা **ঐচ্ছিক**: খালি রাখলে রসিদের পর্দাই খাতটা ঠিক
                 করে, আর ভরলে সেটা ওখানে আগে থেকে বসানো থাকে।

                 ⚠️ এটাই আমার আপত্তির উত্তর — ঘরটা প্রস্তাব, দ্বিতীয়
                 দরজা নয়। টাকা নড়ে এখনো একটাই জায়গা থেকে। --}}
            {{-- ⓘ `:reference="false"` — লেনদেন নম্বরের ঘরটা এখানে নয়।

                 ⛔ নমুনায় ওটা ভাউচারের বাক্সে, মাধ্যম অনুযায়ী আলাদা
                 (ট্রানজেকশন/রেফারেন্স · চেক নম্বর · ট্রানজেকশন আইডি)।
                 ⚠️ দুই জায়গায় থাকলে ব্যবহারকারী একটায় লিখতেন, অন্যটা
                 খালি থাকত, আর কোনটা আসল তা বলা যেত না। --}}
            <x-ui.money-account name="received_into_account_id" :reference="false"
                                :label="__('finance::field.received_into')"
                                :accounts="$moneyAccounts" codes
                                :blank="__('finance::field.choose')"
                                :selected="old('received_into_account_id', $entry->received_into_account_id ?? null)" />

            {{-- ⛔ তিনটা ঘর নমুনায় নেই, তাই ভাঁজের ভিতরে — ১৫ সেপ্টেম্বর ২০২৬।

                 ⓘ নমুনায় ভূমিকাটা "কে"-এর লেখাতেই ভাঁজ করা ("আল-আমিন
                 শুভ — মালিক"), আর অংশ % কোথাও নেই।

                 ⛔ ঘরগুলো মুছে ফেলা যেত না: `contributor_type` আর
                 `entry_type` সেবার দিকে **বাধ্যতামূলক**, আর অংশ %-ই
                 মুনাফা ভাগের হিসাব। ⚠️ মুছলে পুরনো সারিগুলোর তথ্য
                 নতুন করে লেখার কোনো পথ থাকত না।

                 ⭐ তাই দুইটাই সত্যি রাখা হলো: পর্দা নমুনার মতো দেখায়,
                 আর তথ্যটাও হারায় না। দুইটারই ডিফল্ট আছে, তাই ভাঁজ না
                 খুললেও ফর্ম জমা পড়ে। --}}
            <details class="sm:col-span-2 xl:col-span-3 text-sm">
                <summary class="cursor-pointer text-(--color-brand-500) underline-offset-2 hover:underline">
                    {{ __('finance::field.capital_more') }}
                </summary>

                <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.select name="contributor_type" :label="__('finance::field.as')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::WHO)
                             ->mapWithKeys(fn ($w) => [$w => __('finance::who.'.$w)])"
                         :selected="old('contributor_type', $entry->contributor_type ?? 'owner')" />

            <x-ui.select name="entry_type" :label="__('finance::field.kind')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::KINDS)
                             ->mapWithKeys(fn ($k) => [$k => __('finance::kind.'.$k)])"
                         :selected="old('entry_type', $entry->entry_type ?? 'contribution')" />

            <x-ui.field name="share_percent" type="number" step="0.01" numeric
                        :label="__('finance::field.share')"
                        :value="old('share_percent', $entry->share_percent ?? null)" />
                </div>
            </details>

            {{-- ⓘ নমুনার শব্দ "বিবরণ" — আগে লেখা ছিল "কীসের জন্য"।
                 ⚠️ দুইটার মানে কাছাকাছি, কিন্তু মালিকের নিয়ম ১০০%,
                 তাই নমুনার শব্দটাই বসল। --}}
            <div>
                <x-ui.field name="narration" :label="__('finance::field.narration')"
                            :value="old('narration', $entry->narration ?? null)" />
            </div>

            {{-- ⭐ ফিতাটা এখানে, ঘরগুলোর **পরে** — নমুনার ক্রম।

                 ⓘ আগে আমি ওটা ফর্মের উপরে বসিয়েছিলাম। ⚠️ নমুনায় ওটা
                 ছয়টা ঘরের নিচে, আর জায়গাটার একটা মানে আছে: খাতার ঘর
                 ভরা শেষ, এবার টাকার পালা — আর ওটা অন্য পর্দার কাজ। --}}
            <div class="sm:col-span-2 xl:col-span-3">
                @include('finance::partials.handoff', [
                    'voucher' => 'receipt',
                    'to' => route('accounts.voucher.create', ['type' => 'receipt']),
                    'action' => __('finance::action.take_money_receipt'),
                ])
            </div>

            {{-- ⭐ ভাউচারের ঘর — নমুনার সবচেয়ে বড় অংশ।
                 ⓘ ঘরগুলো খাতার সারিতে বসে না; ওগুলো রসিদ ভাউচারে যায়,
                 আর ফিতাটা ঠিক সেই কথাই বলে। --}}
            <div class="sm:col-span-2 xl:col-span-3">
                @include('finance::partials.voucher-box', [
                    'direction' => 'in',
                    'carriers' => $carriers,
                ])
            </div>

            {{-- ⭐ সংযুক্তি — নমুনায় ঘরটা তৈরির ফর্মেই, পরে নয়।

                 ⚠️ কাগজ বসতে হলে সারিটার আইডি লাগে, আর সারিটা তখনো
                 তৈরি হয়নি। ⓘ তাই ফাইলটা ফর্মের সাথে যায় আর
                 [[CapitalService::record()]] সারি বসানোর **সাথে সাথেই**
                 কাগজটা রাখে — একই লেনদেনের ভিতরে, যাতে অর্ধেক অবস্থা
                 না থাকে।

                 ⓘ `enctype` এই ফর্মে আজ যোগ হলো; ওটা ছাড়া ফাইল
                 পাঠানোই যেত না, আর কোনো ভুলও দেখাত না। --}}
            <div>
                <label for="paper" class="mb-1 block text-sm font-medium">
                    {{ __('finance::field.attachment') }}
                </label>
                <input id="paper" type="file" name="paper"
                       x-on:change="$store.scanner.begin($el, 'paper')"
                       class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                              file:border file:border-(--color-border) file:bg-(--color-surface-app)
                              file:px-3 file:py-1.5 file:text-sm">
            </div>

            {{-- ⓘ নমুনায় বিবরণ · সংযুক্তি · বোতাম — তিনটাই এক সারিতে,
                 আর বোতামটা ডান কোণে। --}}
            <div class="flex flex-wrap items-end gap-2">
                {{-- ⓘ নমুনার লেখা — "সংরক্ষণ" নয়, "মূলধনের সারি সংরক্ষণ"।
                     ⚠️ বোতামটা কী সংরক্ষণ করছে সেটা বলা থাকলে মানুষ
                     থামেন না; সাধারণ "সংরক্ষণ" পড়ে অনেকে ভাবেন টাকাটাও
                     বসে গেল। --}}
                <x-ui.button type="submit" tone="primary">
                    {{ __('finance::action.save_capital_row') }}
                </x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.capital.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    {{-- ⭐ খতিয়ানে যা বসবে — নমুনার নিচের ভাঁজ।

         ── ⓘ কেন এটা দরকারি, সাজসজ্জা নয় ─────────────────────────────
         ⚠️ দোকানদার ডেবিট-ক্রেডিট পড়েন না, কিন্তু *"আমার টাকাটা শেষে
         কোথায় গেল"* প্রশ্নটা তাঁর। ⓘ ভাঁজটা ঐ উত্তরটাই দেয়, আর
         **পোস্ট করার আগে** দেয় — যাতে ভুল খাত বাছলে তখনই ধরা পড়ে।

         ⛔ খোলা রাখা হয়নি: রোজকার কাজে ওটা জায়গা খায়, আর যিনি জানেন
         তিনি আর দেখতে চান না। --}}
    <details class="mt-3 max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3">
        <summary class="cursor-pointer text-sm font-medium text-(--color-brand-600)">
            {{ __('finance::field.what_lands_in_the_ledger') }} · {{ __('finance::field.lifeline') }}
        </summary>

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            {{-- ⓘ দুইটা লাইন, আর ক্রমটা খাতার নিয়মেই: ডেবিট আগে। --}}
            <dt class="font-medium">{{ __('finance::message.ledger_debit') }}</dt>
            <dd class="text-(--color-ink-muted)">{{ __('finance::message.ledger_debit_capital') }}</dd>

            <dt class="font-medium">{{ __('finance::message.ledger_credit') }}</dt>
            <dd class="text-(--color-ink-muted)">{{ __('finance::message.ledger_credit_capital') }}</dd>
        </dl>

        <p class="mt-3 text-2xs text-(--color-ink-muted)">
            {{ __('finance::message.ledger_only_after_posting') }}
        </p>
    </details>

</x-layouts.app>
