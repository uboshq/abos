{{--
    সহজ ভাউচার ফর্ম — আদায়, পরিশোধ, খরচ, কন্ট্রা।

    দুইটা ঘর আর একটা অঙ্ক। "ডেবিট" ও "ক্রেডিট" শব্দ দুটো এই পর্দায়
    কোথাও নেই, ইচ্ছাকৃতভাবে: যিনি কাউন্টারে বসে আদায় লিখছেন তিনি
    "কার কাছ থেকে" ও "কোথায় রাখলাম" বোঝেন। দিকটা VoucherService
    একবারের জন্য ঠিক করে — এই পর্দা সেটা জানেও না।

    DMS-এ প্রতিটা ভাউচারের পর্দা নিজে দিক ঠিক করত, আর একটায় উল্টো
    লেখা ছিল। সেই ভুলটা এখানে অসম্ভব, কারণ ভুল করার জায়গাটাই নেই।
--}}
@php
    $isNew = ! $voucher->exists;

    /*
     * রসিদ ও পরিশোধে পক্ষের ঘর দুইটা উপরে বসে (নমুনার সারি ২)।
     *
     * ⚠️ নিচের পুরনো ঘরগুলো তখন লুকানো **আর নিষ্ক্রিয়** — দুইটাই লাগে।
     * ⛔ কেবল লুকালে ব্রাউজার শেষেরটার মান পাঠাত, আর উপরের বাছাইটা
     * নীরবে হারাত; কেবল নিষ্ক্রিয় করলে খালি ঘরটা পর্দায় থেকে যেত।
     */
    $partyAbove = in_array($voucher->type, [
        \App\Modules\Accounts\Models\Voucher::RECEIPT,
        \App\Modules\Accounts\Models\Voucher::PAYMENT,
    ], true);

    $optionsFor = fn (string $source) => match ($source) {
        'money' => $moneyAccounts,
        'expense' => $expenseAccounts,
        'party_or_income' => $allAccounts,

        /*
         * খরচ: নগদে **বা** বাকিতে।
         *
         * ⚠️ দুইটা দল আলাদা রাখা হয়, এক তালিকায় মিশিয়ে নয় — কারণ
         * দুইটার ফল **সম্পূর্ণ আলাদা**: একটায় টাকা এখনই যায়, অন্যটায়
         * দেনা তৈরি হয়। মিশিয়ে দিলে ব্যবহারকারী তফাতটা দেখতেন না।
         */
        'money_or_credit' => $moneyAccounts->concat($creditAccounts ?? collect()),
        default => $allAccounts,
    };

    /*
     * বাকিতে খরচের খাতগুলোর id — ফর্মে দুইটা কাজে লাগে:
     * দল আলাদা দেখানো, আর "বাকিতে বাছলে পক্ষ বাধ্যতামূলক" নিয়মটা।
     */
    $creditIds = collect($creditAccounts ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();

    // সম্পাদনার সময় দুইটা সারি থেকে দিক ফিরে পাওয়া — ডেবিটেরটা "to",
    // ক্রেডিটেরটা "from", ঠিক যেভাবে সেভ হয়েছিল
    $debitLine = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
    $creditLine = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.' . $type) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::voucher.' . $type)"
            :subtitle="$isNew ? __('accounts::message.number_on_save') : $voucher->document_no" />
    </x-slot:header>

    {{--
        ⛔ `enctype` ছাড়া ফাইলের ঘরটা নীরবে অকেজো — ১৫ সেপ্টেম্বর ২০২৬।

        ⓘ ব্রাউজার ডিফল্টে ফর্মটা `application/x-www-form-urlencoded`
        হিসেবে পাঠায়, আর তাতে ফাইলের কেবল **নামটা** যায়, বাইটগুলো নয়।

        ⚠️ সার্ভারে কোনো ভুল দেখা যেত না: `$request->file('attachment')`
        হত `null`, আর কোড ভাবত ব্যবহারকারী কিছু দেননি। ⓘ তিনি দেখতেন
        ভাউচারটা সেভ হয়েছে, কেবল ছবিটা নেই — আর কেন, তার কোনো চিহ্নও
        থাকত না।
    --}}
    <form method="POST" enctype="multipart/form-data"
          action="{{ $isNew ? route('accounts.voucher.store', $type) : route('accounts.voucher.update', $voucher) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="type" value="{{ $type }}">

        {{--
            ⭐ এই রসিদটা কোন নথি নিষ্পন্ন করছে — "কীসের বিপরীতে"।

            ── কেন লুকানো ঘর, ড্রপডাউন নয় ───────────────────────────────
            মালিকের সিদ্ধান্ত (১৪ সেপ্টেম্বর ২০২৬): অর্থ মডিউল কেবল লেখে
            *"কে কত দেবেন"*, আর টাকা গ্রহণ করে **একাই এই পর্দা**। অর্থের
            তালিকায় "টাকা এসেছে" চাপলে এই পর্দাটা আগে থেকে ভরা অবস্থায়
            খোলে, আর ঘর দুইটা তখন লিংক থেকেই আসে।

            ⛔ ড্রপডাউন দিলে ব্যবহারকারীকে খসড়া নথির তালিকা থেকে খুঁজে
            বের করতে বলা হত — অথচ তিনি এইমাত্র ঐ সারিটার পাশের বোতামেই
            চাপ দিয়েছেন। আর ভুল সারি বাছলে টাকাটা অন্য কারো নথি নিষ্পন্ন
            করে ফেলত।

            ⓘ হাতে খোলা রসিদে ঘর দুইটা খালি থাকে, আর সেটাই স্বাভাবিক —
            বেশিরভাগ আদায় কোনো নথির বিপরীতে নয়।
        --}}
        @if (filled(old('against_type', $voucher->against_type)))
            <input type="hidden" name="against_type"
                   value="{{ old('against_type', $voucher->against_type) }}">
            <input type="hidden" name="against_id"
                   value="{{ old('against_id', $voucher->against_id) }}">
        @endif

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{--
            ── রসিদ ও পরিশোধ — নমুনার নিজস্ব কাঠামো, ১৫ সেপ্টেম্বর ২০২৬ ─
            ⛔ এখানে আগে ছিল একটা সাধারণ তিন-ঘরের সারি (তারিখ · কাগজের
            তারিখ · টাকার অঙ্ক), যেটা পাঁচটা ভাউচারের সবগুলোতেই একই
            দেখাত।

            ⚠️ নমুনায় রসিদের প্রথম সারি আলাদা — **লেনদেনের তারিখ · কার
            মাধ্যমে · কখন** — আর দ্বিতীয় সারিতে ডিপোজিটর, তার পাশে পাওনা।
            ⓘ তারপর সবচেয়ে বড় অংশটা: "কোন বিলের বিপরীতে"।

            ⭐ মালিকের সিদ্ধান্ত: **নমুনাই চূড়ান্ত।** তাই "কাগজের তারিখ",
            "টাকার শ্রেণি" ও "উপশ্রেণি" — নমুনায় নেই বলে তুলে দেওয়া হলো।
        --}}
        @if (in_array($voucher->type, [
            \App\Modules\Accounts\Models\Voucher::RECEIPT,
            \App\Modules\Accounts\Models\Voucher::PAYMENT,
        ], true))
            @include('accounts::voucher.partials.party-fields')
        @else
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                                :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                                required />

                    <x-ui.field name="ref_date" type="date" :label="__('accounts::field.ref_date')"
                                :value="old('ref_date', $voucher->ref_date?->format('Y-m-d'))" />

                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                                :label="__('accounts::field.amount')"
                                :value="old('amount', $debitLine?->debit)" required numeric />
                </div>
            </section>
        @endif

        {{--
            ── খরচ ভাউচারের নিজস্ব অংশ, ১৫ সেপ্টেম্বর ২০২৬ ──────────────
            ⛔ এই পর্দাটা পাঁচটা ভাউচারের চারটাকেই এক চেহারায় দেখাত, আর
            টাইপভেদে আলাদা কিছুই ছিল না। ⓘ ফলে খরচ ভাউচার দেখতে হুবহু
            রসিদের মতো — অথচ খরচের নিজের প্রশ্নগুলো (কোন খাতে, কার খরচ,
            কোন চালানের জন্য) কোথাও জিজ্ঞেসই করা হত না।

            ⚠️ মালিক নমুনা আর পর্দা পাশাপাশি রেখে দেখান, আর গুনে পাওয়া
            যায় নমুনার চৌদ্দটা ঘরের মাত্র তিনটা।
        --}}
        @if ($voucher->type === \App\Modules\Accounts\Models\Voucher::EXPENSE)
            @include('accounts::voucher.partials.expense-fields')
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                 x-data="{
                     from: '{{ old('from_account_id', $creditLine?->account_id) }}',
                     credit: @js($creditIds),
                     get onCredit() { return this.credit.includes(Number(this.from)); },

                     partyType: @js((string) old('party_type', $voucher->party_type ?? '')),
                     partyId: @js((string) old('party_id', $voucher->party_id ?? '')),
                     parties: @js(collect($parties)->flatMap(fn (array $g) => collect($g['options'])
                         ->map(fn (array $o) => ['type' => $g['type'], 'id' => (int) $o['id'], 'label' => $o['label']]))
                         ->values()),
                     get partyOptions() {
                         return this.parties.filter(p => p.type === this.partyType);
                     },

                     cats: @js($moneyCategories),
                     catId: @js((string) old('money_category_id', $voucher->money_category_id ?? '')),
                     subId: @js((string) old('money_subcategory_id', $voucher->money_subcategory_id ?? '')),
                     get parentCats() { return this.cats.filter(c => c.parent_id === null); },
                     get subCats() {
                         return this.cats.filter(c => String(c.parent_id) === String(this.catId));
                     },

                     dueUrl: @js(route('accounts.voucher.due')),
                     due: null,
                     dueBusy: false,

                     /*
                      * ⭐ চিহ্নটাই বাক্যটা বদলে দেয়।
                      *
                      * ডেবিট − ক্রেডিট ধনাত্মক মানে তিনি আমাদের দেবেন,
                      * ঋণাত্মক মানে উল্টো। ⚠️ কেবল একটা সংখ্যা দেখালে
                      * সরবরাহকারীর ঘরে “−৫,০০০” বসত আর কেউ ভাবতেন
                      * হিসাবে গোলমাল — অথচ ওটাই ঠিক উত্তর, শুধু অন্য
                      * দিকের। তাই সংখ্যার পাশে কথাটাও থাকে।
                      */
                     get dueText() {
                         const n = Number(this.due);
                         const shown = Math.abs(n).toLocaleString(undefined, {
                             minimumFractionDigits: 2, maximumFractionDigits: 2,
                         });

                         return shown + ' — ' + (n < 0
                             ? @js(__('accounts::message.we_owe_them'))
                             : @js(__('accounts::message.they_owe_us')));
                     },

                     /*
                      * ধরন বদলালে বাছা মানুষটাও বদলাতে হবে — নাহলে
                      * 'customer' ধরনের সাথে একজন সরবরাহকারীর আইডি
                      * জোড়া লেগে থাকত, আর সেটা চুপচাপ ভুল পক্ষের
                      * খতিয়ানে টাকা বসাত।
                      */
                     resetParty() { this.partyId = ''; this.due = null; },

                     loadDue() {
                         this.due = null;

                         if (! this.partyType || ! this.partyId) { return; }

                         this.dueBusy = true;

                         fetch(this.dueUrl + '?party_type=' + encodeURIComponent(this.partyType)
                                   + '&party_id=' + encodeURIComponent(this.partyId),
                               { headers: { 'Accept': 'application/json' } })
                             .then(r => r.ok ? r.json() : null)
                             .then(d => { this.due = (d && d.known) ? d.amount : null; })
                             .catch(() => { this.due = null; })
                             .finally(() => { this.dueBusy = false; });
                     },

                     /*
                      * শ্রেণি বাছলে খাতটা নিজে থেকে বসে।
                      *
                      * ⓘ এটা কেবল **সুবিধা** — আসল নিয়মটা সার্ভারে
                      * ([[VoucherRequest::fillAccountFromCategory()]]),
                      * তাই JS বন্ধ থাকলেও শ্রেণি থেকে খাত বসে।
                      */
                     pickCategory() { this.subId = ''; this.applyAccount(this.catId); },
                     pickSub() { this.applyAccount(this.subId || this.catId); },
                     applyAccount(id) {
                         const row = this.cats.find(c => String(c.id) === String(id));

                         if (row && row.account_id) { this.from = String(row.account_id); }
                     },

                     init() { this.loadDue(); },
                 }">
            {{--
                ⛔ রসিদ ও পরিশোধে এই দুইটা ঘর নিচের সারিতে বসে (নমুনা)।
                ⓘ "কার কাছ থেকে" ঘরটা নমুনায় নেই — উপরে ডিপোজিটরই
                বলে দেয় টাকা কার কাছ থেকে। ⚠️ আলাদা ঘরটা দ্বিত্ব।
            --}}
            <div class="grid gap-3 sm:grid-cols-2" @if ($partyAbove) hidden @endif>
                {{-- from — টাকা যেখান থেকে এল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['from']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="from_account_id" required @disabled($partyAbove) x-model="from"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($optionsFor($sides['from']['source']) as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('from_account_id', $creditLine?->account_id) == $account->id)>
                                {{ in_array((int) $account->id, $creditIds, true)
                                    ? __('accounts::field.on_credit_option', ['account' => $account->label()])
                                    : $account->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('from_account_id')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{-- to — টাকা যেখানে গেল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['to']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="to_account_id" required @disabled($partyAbove)
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($optionsFor($sides['to']['source']) as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('to_account_id', $debitLine?->account_id) == $account->id)>
                                {{ $account->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_account_id')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{--
                    কাকে — ঐচ্ছিক, কিন্তু বাকিতে হলে বাধ্যতামূলক।
                
                    ── কেন ঘরটা লাগল ─────────────────────────────────────────
                    পক্ষ বসালে টাকাটা **তাঁর খতিয়ানে** যায়, আর তখন পরিবহনকারী বা
                    হাম্মালি ঠিকাদারের হিসাব নিজে থেকেই ভরে ওঠে। না বসালে খরচটা
                    সরাসরি খাতে বসে — চা-নাস্তা বা রিকশাভাড়ায় "কাকে দিলাম" লেখার
                    দরকার নেই।
                
                    ⛔ **কিন্তু বাকিতে হলে পক্ষ ছাড়া চলে না** — কারো কাছে দেনা হতে
                    হলে "কার কাছে" জানতেই হবে। ⚠️ নাহলে প্রদেয়ের ঘরে একটা টাকা বসে
                    থাকত **যার কোনো মালিক নেই**, আর "কাকে কত দিতে হবে" তালিকার
                    যোগফল স্থিতিপত্রের সাথে মিলত না।
                
                    ⚠️ এখানে আগে লেখা ছিল *"ইঞ্জিনে কিছু যোগ করতে হয়নি — সারি
                    হেডার থেকে উত্তরাধিকার পায়"*। **কথাটা ভুল ছিল, আর আমি
                    যাচাই না করেই লিখেছিলাম।**

                    মেপে দেখা গেছে [[VoucherService::replaceLines]] সারির পক্ষ
                    নেয় কেবল `$line['party_type'] ?? null` থেকে — **হেডার থেকে
                    কিছুই নামে না।** ⛔ ফলে বাকিতে খরচ লিখলে হেডারে পক্ষ বসত,
                    কিন্তু প্রদেয়ের **সারিটা মালিকহীনই থাকত** — ঠিক যেটা উপরের
                    বার্তাটা ঠেকানোর প্রতিশ্রুতি দেয়। ⚠️ আর বকেয়ার বয়স-রিপোর্ট
                    ও "কাকে কত দিতে হবে" পড়ে **সারি থেকে**, হেডার থেকে নয়।

                    ⓘ ইতিহাসটা রেখে দেওয়া হলো ইচ্ছে করেই: পরের জন যেন মন্তব্য
                    বিশ্বাস করার আগে মেপে নেন।
                --}}
                {{--
                    ⭐ "কার কাছ থেকে" — দুইটা ঘর, একটা নয়।

                    ── কেন ভাঙা হলো, ১৪ সেপ্টেম্বর ২০২৬ ──────────────────────
                    মালিক ঘরের তালিকায় **Received From Type** আলাদা করে
                    চেয়েছেন, আর কারণটা পর্দায় দাঁড়ালেই বোঝা যায়: আগে একটাই
                    ড্রপডাউনে গ্রাহক-সরবরাহকারী-কর্মী-ব্যক্তি সবাই মিলে
                    কয়েকশো সারি হত। ⛔ কয়েকশো সারির ড্রপডাউনে মানুষ খোঁজেন
                    না — তিনি উপরের দিকের যেকোনো একটা চেনা নাম বেছে ফেলেন।

                    ধরন আগে বাছলে তালিকাটা ছোট হয়ে আসে, আর ছোট তালিকায়
                    ভুল বাছাই কমে।

                    ⓘ `party_type` ও `party_id` সরাসরি যাচ্ছে — আগের মতো
                    `type:id` জোড়া লাগিয়ে `party` ঘরে নয়। এক ঘরে দুইটা
                    তথ্য ঠেসে দিলে ভাঙা-জোড়ার একটা ধাপ বাড়ে, আর ঐ ধাপটা
                    JS-এর উপর দাঁড়িয়ে থাকত।
                --}}
                {{--
                    ⓘ রসিদ ও পরিশোধে পক্ষের ঘর দুইটা **উপরে** বসে
                    (নমুনার সারি ২), তাই এখানে কেবল খরচ ও কন্ট্রার জন্য।
                    ⚠️ দুই জায়গায় একই নামের ঘর থাকলে ব্রাউজার শেষেরটার
                    মান পাঠাত, আর উপরের বাছাইটা নীরবে হারাত।
                --}}
                <label class="mt-3 block" @if ($partyAbove) hidden @endif>
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.party_type') }}
                        <span class="text-(--color-danger)" x-cloak x-show="onCredit" aria-hidden="true">*</span>
                    </span>
                    <select name="party_type" x-model="partyType" @change="resetParty()"
                            @disabled($partyAbove)
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($parties as $group)
                            <option value="{{ $group['type'] }}">{{ $group['label'] }}</option>
                        @endforeach
                    </select>
                    @error('party_type')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                <label class="mt-3 block" @if ($partyAbove) hidden @endif>
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.party') }}
                        <span class="text-(--color-danger)" x-cloak x-show="onCredit" aria-hidden="true">*</span>
                    </span>
                    <select name="party_id" x-model="partyId" @change="loadDue()"
                            :required="onCredit"
                            @disabled($partyAbove)
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>

                        <template x-for="row in partyOptions" :key="row.type + ':' + row.id">
                            <option :value="row.id" x-text="row.label"
                                    :selected="String(row.id) === String(partyId)"></option>
                        </template>
                    </select>
                    @error('party_id')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                    @error('party')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{--
                    ⭐ এখন তাঁর কাছে কত পাওনা — **পড়ার ঘর, লেখার নয়**।

                    মালিকের সিদ্ধান্ত (১৪ সেপ্টেম্বর ২০২৬): সংখ্যাটা নিজে
                    থেকে আসবে, টাইপ করা যাবে না। হাতে লিখতে দিলে সেটা
                    খতিয়ানের একটা **দ্বিতীয় উৎস** হত, আর এই রিপো একবার
                    শিখেছে দুই উৎস মানে একদিন দুই উত্তর।

                    ⚠️ সংখ্যাটা **এখনকার**, ভাউচারের তারিখের নয় — লেবেলটাই
                    সেটা বলে, যাতে কেউ ওটাকে ঐতিহাসিক জের ভেবে না বসেন।

                    ⓘ কোনো `name` নেই ইচ্ছাকৃতভাবে: ঘরটা জমা পড়ে না, তাই
                    বাইরে থেকে একটা মনগড়া অঙ্ক পাঠানোর পথও নেই।
                --}}
                {{--
                    ⛔ রসিদ ও পরিশোধে পাওনাটা **নামের পাশে** দেখায়
                    (নমুনার সারি ২), আলাদা ঘরে নয় — তাই সেখানে এই
                    ব্লকটা আঁকা হয় না।

                    ⓘ মালিকের সিদ্ধান্ত: নমুনাই চূড়ান্ত।
                --}}
                <div class="mt-3" @if ($partyAbove) hidden @endif>
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.collectable') }}
                    </span>
                    <p class="rounded-(--radius-field) border border-dashed border-(--color-border)
                              px-3 py-2 text-sm">
                        <span x-show="dueBusy" x-cloak>…</span>
                        <span x-show="! dueBusy && due === null">—</span>
                        <span x-show="! dueBusy && due !== null" x-cloak>
                            <span x-text="dueText"></span>
                        </span>
                    </p>
                </div>

                {{--
                    ⭐ টাকার শ্রেণি — আর **শ্রেণিই খাত ঠিক করে**।

                    ── কেন ঘর দুইটা লাগল ────────────────────────────────────
                    মালিকের সিদ্ধান্ত (১৪ সেপ্টেম্বর ২০২৬)। আগে উপরের
                    "কোথা থেকে এল" ঘরে পুরো হিসাবের ছক খোলা থাকত, আর
                    কাউন্টারের লোককে নিজে ঠিক করতে হত টাকাটা কোন খাতে
                    যাবে। ⛔ ভুল খাত বাছলে কিছুই ভাঙত না — ভাউচার পোস্ট
                    হত, খতিয়ান মিলত, শুধু টাকাটা ভুল জায়গায় বসত। মাস
                    শেষে বকেয়া মিলত না আর কেউ বলতে পারত না কোন সারিতে।

                    ⓘ শ্রেণি বাছলে উপরের খাতের ঘরটা নিজে থেকে ভরে যায়,
                    কিন্তু **লুকানো হয় না** — কেউ চাইলে বদলাতে পারেন, আর
                    কী বসল সেটা চোখের সামনেই থাকে।
                --}}
                {{--
                    ⛔ টাকার শ্রেণি ও উপশ্রেণি — নমুনায় নেই।

                    ⓘ মালিকের সিদ্ধান্ত, ১৫ সেপ্টেম্বর ২০২৬: **"নমুনাই
                    চূড়ান্ত — বাদ দাও।"** তাই রসিদ ও পরিশোধে ঘর দুইটা
                    দেখানো হয় না। ⚠️ খরচ ও কন্ট্রায় থেকে যায়, কারণ
                    নমুনায় ঐ দুইটার নিজস্ব কাঠামো আলাদা।
                --}}
                <div class="mt-3 grid gap-3 sm:grid-cols-2" @if ($partyAbove) hidden @endif>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">
                            {{ __('accounts::field.money_category') }}
                        </span>
                        <select name="money_category_id" x-model="catId" @change="pickCategory()"
                                @disabled($partyAbove)
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">—</option>
                            <template x-for="row in parentCats" :key="row.id">
                                <option :value="row.id" x-text="row.label"
                                        :selected="String(row.id) === String(catId)"></option>
                            </template>
                        </select>
                        @error('money_category_id')
                            <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                        @enderror
                    </label>

                    {{-- ⓘ উপ-শ্রেণি না থাকলে ঘরটা দেখানোই হয় না — একটা
                         চিরকাল খালি ড্রপডাউন জায়গা নেয় আর কিছুই বলে না। --}}
                    <label class="block" x-show="subCats.length > 0" x-cloak>
                        <span class="mb-1 block text-sm font-medium">
                            {{ __('accounts::field.money_subcategory') }}
                        </span>
                        <select name="money_subcategory_id" x-model="subId" @change="pickSub()"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">—</option>
                            <template x-for="row in subCats" :key="row.id">
                                <option :value="row.id" x-text="row.label"
                                        :selected="String(row.id) === String(subId)"></option>
                            </template>
                        </select>
                        @error('money_subcategory_id')
                            <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                {{--
                    ⭐ বাছার পর পর্দা বলে **কী ঘটতে যাচ্ছে**।
                
                    ⚠️ "কীভাবে" লেখা একটা তালিকা যথেষ্ট নয় — নগদে আর বাকিতে দুইটার
                    ফল সম্পূর্ণ আলাদা, আর ব্যবহারকারী যেন বুঝে বাছেন।
                --}}
                <p x-cloak x-show="onCredit"
                   class="mt-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                          text-2xs text-(--color-badge-pending-ink)">
                    {{ __('accounts::message.expense_on_credit') }}
                </p>
            </div>
        </section>

        {{-- ⓘ রসিদ ও পরিশোধে এই ব্লকটার নিজস্ব বাক্স নেই — ভিতরের
             সবকিছু লুকানো থাকায় পর্দায় একটা খালি বাক্স দেখা যেত। --}}
        <section @if (! $partyAbove) data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                 @endif>
            {{--
                ⛔ "বিবরণ" শিরোনামটা নমুনায় নেই — ১৫ সেপ্টেম্বর ২০২৬।

                ⓘ নমুনায় "বিবরণ" কেবল সবচেয়ে নিচের **ঘরটা** (p-nar),
                কোনো সেকশনের নাম নয়। ⚠️ এখানে শিরোনাম থাকায় "পেমেন্ট
                মেথড" চিপগুলো একটা "বিবরণ" ঘরের ভিতরে বসে আছে বলে মনে
                হত — মালিক সেটাই ধরিয়ে দেন।

                ⓘ খরচ ও কন্ট্রায় থাকে, কারণ ঐ দুইটার নমুনায় ব্লকটা
                সত্যিই আলাদা একটা দল।
            --}}
            @unless ($partyAbove)
                <h2 class="mb-3 font-semibold">{{ __('accounts::section.details') }}</h2>
            @endunless

            {{--
                ⭐ টাকাটা কীভাবে হাতবদল হলো — একটাই ব্লক, ১৪ সেপ্টেম্বর ২০২৬।

                ── ⛔ আগে এখানে কী ছিল ─────────────────────────────────
                পাঁচটা ছড়ানো ঘর: মাধ্যম, লেনদেন নম্বর, তারিখ, প্রেরকের
                ব্যাংক ও হিসাব নম্বর, আর চার্জ। ⓘ কাজ করত, কিন্তু মালিকের
                তালিকার **আঠারোটার মধ্যে ছয়টা** — মিল ৩৩.৩%।

                যা চাওয়া হত না: নোট গুনে মেলানো · MFS-এর ওয়ালেট ও মাধ্যম ·
                প্রেরকের মোবাইল নম্বর · চার্জটা কে দিয়েছে · BEFTN/RTGS/NPSB ·
                ব্রাঞ্চ ও হিসাবধারীর নাম · জমা স্লিপ · কবে পৌঁছাবে ·
                কে বহন করল · কখন।

                ── ⚠️ আর কেন ব্লকটা এই ফাইলে লেখা নেই ──────────────────
                অর্থের খাতাগুলোরও হুবহু এই প্রশ্নগুলো লাগে। দুই জায়গায়
                লিখলে একদিন একটায় চেকের ব্রাঞ্চ চাওয়া হত, অন্যটায় না —
                আর তখন "কোন ব্যাংক থেকে কত এল" প্রশ্নের উত্তর অর্ধেক
                লেনদেনে থাকত না। ⓘ তাই [[resources/views/components/ui/money-movement]]
                একটাই, আর দুইজনেই সেটা ডাকে।
            --}}
            <x-ui.money-movement
                :direction="$voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT ? 'out' : 'in'"
                :carriers="$carriers ?? []"
                :modes="$transferModes ?? []"
                :carrier-here="! $partyAbove"
                :record="$voucher" />

            {{--
                ⛔ রসিদ ও পরিশোধে "বিবরণ" নিচের সারিতে বসে — নমুনার মতো।
                ⓘ খরচ ও কন্ট্রায় এখানেই থাকে, কারণ ঐ দুইটার নমুনায়
                কাঠামোটা আলাদা।
            --}}
            <label class="mt-3 block" @if ($partyAbove) hidden @endif>
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <textarea name="narration" rows="2" @disabled($partyAbove)
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-card) px-3 py-2">{{ old('narration', $voucher->narration) }}</textarea>
            </label>
        </section>

        {{--
            ── নমুনার শেষ সারি, ১৬ সেপ্টেম্বর ২০২৬ ──────────────────────
            নমুনায় রসিদের একদম নিচে তিনটা ঘর এক সারিতে, আর পাশে বোতাম:

                যে খাতে জমা · গৃহীত টাকা · বিবরণ · [সংরক্ষণ ও পোস্ট]

            ⛔ আগে এগুলো ছড়িয়ে ছিল: "কার কাছ থেকে" ও "যে খাতে জমা"
            মাঝখানে একটা আলাদা বাক্সে, "গৃহীত টাকা" একদম উপরে, আর
            "বিবরণ" টাকা-চলাচলের ব্লকের ভিতরে।

            ⚠️ আর "কার কাছ থেকে" ঘরটা **দ্বিত্ব**: উপরে ডিপোজিটর বাছা
            হয়েই গেছে। ⓘ দুইটা আলাদা উত্তর দিলে খাতায় কোনটা বসত তা
            বলা যেত না, তাই সেটা লুকানো ও নিষ্ক্রিয়।
        --}}
        @if ($partyAbove)
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid items-end gap-3 sm:grid-cols-[2fr_1fr_2fr_auto]">
                    <x-ui.select name="to_account_id"
                                 :label="__($sides['to']['label'])"
                                 :options="collect($optionsFor($sides['to']['source']))->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()"
                                 :selected="old('to_account_id', $debitLine?->account_id)"
                                 required />

                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                                :label="$voucher->type === \App\Modules\Accounts\Models\Voucher::RECEIPT
                                    ? __('accounts::field.amount_received')
                                    : __('accounts::field.amount_paid')"
                                :value="old('amount', $debitLine?->debit)" required numeric />

                    <x-ui.field name="narration"
                                :label="__('core.table.narration')"
                                :value="old('narration', $voucher->narration)" />

                    <div class="pb-1">
                        @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type])
                    </div>
                </div>
            </section>
        @endif

        {{--
            ⓘ রসিদ ও পরিশোধে বোতামগুলো নিচের সারির ভিতরে বসে
            (নমুনায় "সংরক্ষণ ও পোস্ট" তিন ঘরের পাশে)।
            ⛔ এখানেও আঁকলে বোতাম দুইবার দেখা যায় — গুনে ধরা পড়েছে।
        --}}
        @unless ($partyAbove)
            @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type])
        @endunless
    </form>
</x-layouts.app>
