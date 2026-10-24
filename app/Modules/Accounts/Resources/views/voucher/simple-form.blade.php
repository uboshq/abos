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
    // ⓘ পোস্ট হওয়া ভাউচারের সংশোধন — একই ফর্ম, অন্য দরজা আর কারণের ঘর ([[VoucherController::revise()]])
    $revising ??= false;

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

    /*
     * ⭐ খরচের নমুনা — নিজের কাঠামো, ১৮ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ পর্দা আর নমুনা পাশাপাশি রেখে যা পাওয়া গেল ────────────────
     * মালিক দুইটা ছবি পাঠালেন — একটা নকশা, একটা আসল পর্দা। আসলটায়:
     *   ⛔ "কীসের খরচ" ঘরটা **দুইবার** (উপরে `expense_account_id`,
     *      নিচে `to_account_id` — দুইটার লেবেলই এক)
     *   ⛔ "কার মাধ্যমে · কখন" — মালিক নিজে বলেছিলেন *"দুইটাই বাদ দাও"*
     *   ⛔ "কী ধরনের পক্ষ · পক্ষ · এখন তার কাছে পাওনা · টাকার শ্রেণি" —
     *      নকশায় একটাও নেই
     *   ⛔ "কাগজের তারিখ" — নকশায় নেই
     *   ⛔ চারটা আলাদা কার্ড, নকশায় একটা টানা পর্দা
     *
     * ── ⭐ নকশার ক্রম ─────────────────────────────────────────────────
     *   সারি ১   তারিখ · খরচের খাত · খরচের কেন্দ্র
     *   সারি ২   কাকে দেওয়া হলো (পুরো চওড়া)
     *   সারি ৩   বিল / ভাউচার নম্বর
     *   ভাঁজ     কোন চালানের জন্য
     *   দুই কলাম উৎসে কর্তন · কীভাবে দেওয়া হলো
     *   শেষ সারি যে খাত থেকে · সংযুক্তি · বিবরণ · [বোতাম]
     *
     * ⓘ পক্ষের ঘর দুইটা মুছে ফেলা যায়নি: **বাকিতে খরচ** লিখলে যাচাই
     * পক্ষ চায় (`credit_needs_a_party`)। ⚠️ তাই ওগুলো এখন কেবল তখনই
     * দেখা যায় যখন "যে খাত থেকে" একটা প্রদেয় খাত — নগদে খরচে, অর্থাৎ
     * নকশার অবস্থায়, পর্দায় ওগুলো নেই।
     */
    $expenseLayout = $voucher->type === \App\Modules\Accounts\Models\Voucher::EXPENSE;

    /* নমুনা-কাঠামোর পর্দাগুলো — পুরনো ছড়ানো ঘরগুলো এখানে লুকানো ও নিষ্ক্রিয়। */
    /*
     * ⭐ কন্ট্রার নকশা — ১৮ সেপ্টেম্বর ২০২৬।
     *
     * মালিক ছবি পাঠিয়ে বললেন *"কন্ট্রা ভাউচার emon koro"*।
     * ⚠️ কন্ট্রায় পক্ষ বলে কিছু নেই — টাকাটা নিজেরই এক খাত
     * থেকে অন্য খাতে যায়। ⓘ তাই পক্ষ, টাকার শ্রেণি আর নোট
     * গোনা — তিনটাই এই পর্দায় অর্থহীন।
     */
    $contraLayout = $voucher->type === \App\Modules\Accounts\Models\Voucher::CONTRA;

    $sampled = $partyAbove || $expenseLayout || $contraLayout;

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

    /*
     * ⛔ মানুষ যে অঙ্ক লিখেছিলেন — চার্জসহ কাগজে ডেবিটের প্রথম সারি নয় (Accounts-Finance অডিট ম৪, ৪ অক্টোবর ২০২৬)।
     * ⚠️ টাকা ঢোকায় (রসিদ, কন্ট্রা) চার্জ ভেতর থেকে কাটে: Dr টাকার খাত (অঙ্ক − চার্জ) · Dr চার্জ / Cr অন্য পাশ (অঙ্ক)
     * ([[VoucherService::twoLineEntry()]])। ঘরে প্রথম ডেবিট বসালে সম্পাদনার প্রতিটা সংরক্ষণে অঙ্কটা চার্জের সমান কমত।
     * ⓘ পরিশোধ আর খরচে চার্জ বাইরে যোগ হয়, তাই সেখানে প্রথম ডেবিটই লেখা অঙ্ক; চার্জ না থাকলে দুই পাশ একই।
     */
    $charged = bccomp((string) ($voucher->charge_amount ?? '0'), '0', 4) > 0;
    $typedAmount = $charged && ! in_array($voucher->type, [\App\Modules\Accounts\Models\Voucher::PAYMENT, \App\Modules\Accounts\Models\Voucher::EXPENSE], true)
        ? $creditLine?->credit
        : $debitLine?->debit;
@endphp

<x-layouts.app :menu="$menu">
    {{-- ⓘ রসিদ-পরিশোধে ট্যাবের নাম মাথার নামেই ("টাকা নেওয়া" / "টাকা দেওয়া"; টাকা দেওয়ার পর্দার মতো) --}}
    <x-slot:title>{{ match ($voucher->type) {
        \App\Modules\Accounts\Models\Voucher::RECEIPT => __('money.screen.title_receive'),
        \App\Modules\Accounts\Models\Voucher::PAYMENT => __('money.screen.title_pay'),
        default => __('accounts::voucher.' . $type),
    } }}</x-slot:title>

    {{-- ⓘ রসিদ আর পরিশোধের মাথা ফর্মের ভিতরে (নিচে) — ডানের তিন অঙ্ক ফর্মের চলতি অঙ্ক থেকে আসে, টাকা দেওয়ার পর্দার একই গড়ন --}}
    @unless ($partyAbove)
    <x-slot:header>
        {{--
            ⓘ নকশায় নম্বরটা ডান দিকে একটা ব্যাজে বসে, শিরোনামের নিচে নয় —
            আর উপশিরোনামে থাকে টাকার দিকটা ("টাকা যাচ্ছে · খরচের খাতে")।
            ⚠️ নতুন ভাউচারে নম্বর নেই, তাই ব্যাজে তখন কারণটাই লেখা থাকে।
        --}}
        <x-ui.page-header
            :title="__('accounts::voucher.' . $type)"
            :subtitle="$expenseLayout
                ? __('accounts::message.expense_subtitle')
                : ($contraLayout
                    ? __('accounts::message.contra_subtitle')
                    : ($isNew ? __('accounts::message.number_on_save') : $voucher->document_no))">
            @if ($expenseLayout || $contraLayout)
                <x-slot:actions>
                    <span class="num rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-sunken) px-3 py-1.5 text-sm text-(--color-ink-muted)">
                        {{ $isNew ? __('accounts::message.number_on_save') : $voucher->document_no }}
                    </span>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    {{--
        ⛔ `enctype` ছাড়া ফাইলের ঘরটা নীরবে অকেজো — ১৫ সেপ্টেম্বর ২০২৬।

        ⓘ ব্রাউজার ডিফল্টে ফর্মটা `application/x-www-form-urlencoded`
        হিসেবে পাঠায়, আর তাতে ফাইলের কেবল **নামটা** যায়, বাইটগুলো নয়।

        ⚠️ সার্ভারে কোনো ভুল দেখা যেত না: `$request->file('attachment')`
        হত `null`, আর কোড ভাবত ব্যবহারকারী কিছু দেননি। ⓘ তিনি দেখতেন
        ভাউচারটা সেভ হয়েছে, কেবল ছবিটা নেই — আর কেন, তার কোনো চিহ্নও
        থাকত না।
    --}}
    @if ($partyAbove)
        @php
            /*
             * ⭐ মাথার তিন অঙ্কের নাম পক্ষের ধরনে — "গ্রাহকের বকেয়া", "সরবরাহকারীর পাওনা" (নকশা পাতা ২ আর ৪)। ⓘ ধরন আসে পক্ষের বাক্স
             * থেকে ঘটনায় ([[party-voucher.js]] `tellDue`); অজানা ধরনে সাধারণ নাম ("পক্ষের বকেয়া")।
             */
            $headSide = $voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT ? 'out' : 'in';
            $headOwedLabels = collect($parties)->pluck('type')
                ->mapWithKeys(fn ($t) => [(string) $t => \Illuminate\Support\Facades\Lang::has('money.screen.head.owed_by_'.$headSide.'.'.$t)
                    ? __('money.screen.head.owed_by_'.$headSide.'.'.$t) : __('money.screen.head.owed_'.$headSide)])
                ->put('', __('money.screen.head.owed_'.$headSide))->all();
        @endphp
    @endif
    {{-- ⭐ `x-money-keys` — Enter কিছু পাঠায় না, Ctrl+Enter মূল বোতাম ([[money-keys.js]]; রসিদ, পরিশোধ, খরচ — একই ফর্ম) --}}
    <form method="POST" enctype="multipart/form-data" x-money-keys
          action="{{ $revising ? route('accounts.voucher.revise.save', $voucher) : ($isNew ? route('accounts.voucher.store', $type) : route('accounts.voucher.update', $voucher)) }}"
          @if ($partyAbove)
              x-data="voucherMoney({
                  // ⓘ পরিশোধে ঘরটা মোট (`gross_amount`) — ফর্মের `amount` তখন মোট ধরে, নিট গোনা হয় নিচে ([[voucherMoney]])
                  amount: @js((string) ($voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT
                      ? old('gross_amount', $typedAmount ?? $voucher->amount ?? '')
                      : old('amount', $typedAmount ?? $voucher->amount ?? ''))),
                  account: @js((string) old($voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT ? 'from_account_id' : 'to_account_id',
                      $voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT ? $creditLine?->account_id : $debitLine?->account_id)),
                  balances: @js(collect($moneyAccounts)->mapWithKeys(fn ($a) => [$a->id => bcadd($a->balanceInView(), '0', 2)])->all()),
                  simulateUrl: @js(route('accounts.voucher.simulate')),
                  referenceUrl: @js(route('accounts.voucher.reference_check')),
                  voucherId: @js($voucher->exists ? $voucher->id : null),
                  out: @js($voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT),
                  ait: @js((string) old('ait_amount', bccomp((string) ($voucher->ait_amount ?? '0'), '0', 4) > 0 ? $voucher->ait_amount : '')),
                  vds: @js((string) old('vds_amount', bccomp((string) ($voucher->vds_amount ?? '0'), '0', 4) > 0 ? $voucher->vds_amount : '')),
                  owedLabels: @js($headOwedLabels),
                  branchLabels: @js(__('money.screen.meta.party_branch_by')),
              })"
              @money-method="heard($event.detail)"
              @party-due="heardParty($event.detail)"
              @fill-amount="heardFill($event.detail)"
          @else
              x-data="{ busy: false }"
          @endif
          @submit="busy ? $event.preventDefault() : (busy = true)"
          {{-- ⛔ রসিদ ও পরিশোধও চওড়া — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"bame faka jayga
               komale box e sari vange na"*। ⓘ `max-w-3xl`-এ পাতাটা মাঝখানে সরু বসত,
               দুই পাশ ফাঁকা, আর নিচের সারির "কার মাধ্যমে" লেখা কেটে যেত। --}}
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="type" value="{{ $type }}">

        @include('accounts::voucher.partials.revision-reason')

        {{--
            ⭐ মাথা — নকশা পাতা ২ আর ৪, টাকা দেওয়ার পর্দার একই সারি ([[purchase::payment.form]]): শিরোনাম "টাকা নেওয়া"/"টাকা দেওয়া",
            অবস্থার চিপ, তার নিচে "নম্বর: সংরক্ষণে বসবে (RCV-…) · বানালেন · সই দেবেন: … (ধাপ 1/N) · মাস খোলা ✓"; ডানে তিন অঙ্ক —
            পক্ষের বকেয়া/পাওনা · এই কাগজ · বাকি থাকবে (fe, ২৫ অক্টোবর ২০২৬)। ⓘ আগে তিন অঙ্ক ডানের কলামের মাথায় একটা কার্ডে বসত; নকশায়
            ওরা পাতার মাথায়, ডান কোণে। ⓘ উপসর্গ আর প্রথম সই নিয়ন্ত্রকের ([[VoucherController::formOptions()]])।
        --}}
        @if ($partyAbove)
            @php
                $headPaying = $voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT;
                $headYear = \App\Models\FinancialYear::forDate(old('trx_date', $voucher->trx_date ?? today()));
                $headMaker = $isNew ? auth()->user()?->name : $voucher->creator?->name;
                $headPrefix = rtrim((string) ($moneyPrefix ?? ''), '-');
                // ⓘ শুরুর ধরন — পক্ষের বাক্সের একই নিয়ম ([[party-fields]]: নতুন রসিদে গ্রাহক, পরিশোধে সরবরাহকারী)
                $headStartType = (string) (old('party_type', $voucher->party_type ?? '') ?: ($headPaying ? 'supplier' : 'customer'));
            @endphp
            <header class="flex flex-wrap items-end justify-between gap-4" data-voucher-header>
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        {{-- ⓘ ট্যাগ [[PageHeading]] থেকে (পাতার প্রথম শিরোনাম h1) — এই ব্লেডে অন্য ভাউচারের page-header আছে, হাতে লেখা h1 নয় --}}
                        @php $headTag = \App\Core\Support\PageHeading::tag(); @endphp
                        <{{ $headTag }} class="text-2xl font-semibold">{{ $headPaying ? __('money.screen.title_pay') : __('money.screen.title_receive') }}</{{ $headTag }}>
                        {{-- ⭐ সইয়ের ছক থাকলে খসড়াতেই "সইয়ের অপেক্ষায় হবে" (Core-এর will_wait; অঙ্ক এখনো লেখা হয়নি, তাই null — ছক নিজে বলে) --}}
                        <x-ui.paper-state state="draft" :approval="[\App\Modules\Accounts\Services\VoucherApproval::MODULE, $voucher->type, null, 'Voucher']" />
                    </div>
                    <p data-voucher-meta class="mt-1 text-xs text-(--color-ink-muted)">
                        {{ $isNew ? __('money.screen.meta.number_on_save_as', ['prefix' => $headPrefix !== '' ? $headPrefix : '—']) : __('money.screen.meta.number', ['no' => $voucher->document_no]) }}
                        · {{ __('money.screen.meta.maker', ['name' => $headMaker ?? '—']) }}
                        {{-- ⭐ নকশার "শাখা: গ্রাহকের শাখা (…)" কেবল তথ্য; পাশে "পোস্ট হবে: …" — টাকা বাক্সের শাখায় (মালিকের ১৯ অক্টোবরের নিয়ম; cb-ma-4) --}}
                        <span data-voucher-branch x-show="partyBranchText !== ''" x-cloak>· {{ __('money.screen.meta.branch') }} <span data-voucher-party-branch x-text="partyBranchText"></span><span x-show="postBranchText !== ''"> · {{ __('money.screen.meta.posts_in') }} <b class="font-medium" data-voucher-post-branch x-text="postBranchText"></b></span></span>
                        @if (($moneyFirstSigner ?? null) !== null)
                            · <span data-voucher-signer>{{ __('money.screen.meta.signer', ['who' => $moneyFirstSigner['who'] !== '' ? $moneyFirstSigner['who'] : '—', 'levels' => $moneyFirstSigner['levels']]) }}</span>
                        @endif
                        · <span data-voucher-month @class(['text-(--color-danger)' => $headYear !== null && ! $headYear->isOpen()])>{{ $headYear !== null && ! $headYear->isOpen() ? __('money.screen.meta.month_closed') : __('money.screen.meta.month_open') }}</span>
                    </p>
                </div>

                {{-- ⭐ তিন অঙ্ক — বকেয়া লালে, এই আদায় সবুজে (রসিদ), বাকি সাধারণ; শূন্যে "৳0.00", চিহ্ন বা শব্দ ছাড়া ([[voucherMoney]]) --}}
                <div class="text-end" data-voucher-head-figures>
                    <dl class="flex gap-8" x-show="hasHeadFigures" x-cloak>
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)" x-text="headOwedLabel">{{ $headOwedLabels[$headStartType] ?? $headOwedLabels[''] }}</dt>
                            <dd class="num text-xl font-semibold" data-head-owed x-bind:class="headOwedClass">
                                <span x-text="headOwedText"></span>
                                <span class="block text-2xs font-normal text-(--color-badge-success-ink)" x-show="headOwedIsAdvance" x-cloak>{{ __('money.screen.head.advance') }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ $headPaying ? __('money.screen.head.this_out') : __('money.screen.head.this_in') }}</dt>
                            <dd @class(['num text-xl font-semibold', 'text-(--color-badge-success-ink)' => ! $headPaying]) data-head-this x-text="headThisText"></dd>
                        </div>
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.head.left') }}</dt>
                            <dd class="num text-xl font-semibold" data-head-left>
                                <span x-text="headLeftText"></span>
                                <span class="block text-2xs font-normal text-(--color-badge-success-ink)" x-show="headLeftIsAdvance" x-cloak>{{ __('money.screen.head.advance') }}</span>
                            </dd>
                        </div>
                    </dl>
                    <p class="max-w-sm text-xs text-(--color-ink-muted)" data-head-none x-show="! hasHeadFigures">{{ __('money.screen.head.none') }}</p>
                </div>
            </header>
        @endif

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
        {{-- ⓘ রসিদ আর পরিশোধে ঘর দুটো party-fields পাঠায় — বিল বাছলে বদলায়, তাই এখানে দ্বিতীয় কপি নয় (অডিট ম১) --}}
        @if (filled(old('against_type', $voucher->against_type))
            && ! in_array($voucher->type, [\App\Modules\Accounts\Models\Voucher::RECEIPT, \App\Modules\Accounts\Models\Voucher::PAYMENT], true))
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
        {{--
            ⓘ নকশার মাথায় "কেন এভাবে" — পর্দাটা নিজেই বলে কেন
            ঘরগুলো এই ক্রমে। ⚠️ লেখাটা ভাঁজে, কারণ রোজ যিনি লেখেন
            তাঁর এটা লাগে না; নতুন কেউ প্রথম দিনে পড়েন।
        --}}
        {{--
            ⛔ কন্ট্রায় "কেন এভাবে" তুলে দেওয়া হলো — ১৮ সেপ্টেম্বর ২০২৬।

            মালিকের প্রশ্ন: *"eta ki asolei dorkar ache?"* — আর না, ছিল না।

            ⓘ কন্ট্রার উপশিরোনামেই পুরো কথাটা আছে — "নিজের এক খাত
            থেকে অন্য খাতে"। ⚠️ তার পরে তিন ঘরের একটা পর্দায়
            আরো চার লাইন ব্যাখ্যা পড়ার মতো কিছু নয়, কেবল জায়গা।

            ⭐ খরচে রয়ে গেছে, আর সেটা ইচ্ছাকৃত: ওখানে প্রত্যক্ষ-পরোক্ষের
            সিদ্ধান্তটা সত্যিই ব্যাখ্যা চায়, আর ভাঁজ করা আছে।
        --}}
        @if ($expenseLayout)
            <details class="text-sm">
                <summary class="cursor-pointer text-(--color-ink-muted)">
                    ⓘ {{ __('accounts::field.why_this_way') }}
                </summary>
                <p class="mt-2 text-xs text-(--color-ink-muted)">
                    {{ __('accounts::message.why_expense_this_way') }}
                </p>
            </details>
        @endif

        {{--
            ⭐ রসিদ আর পরিশোধ দুই কলামে — টাকা দেওয়ার পর্দার একই গড়ন (নকশা পাতা ২ আর ৪; fe, ২৪ অক্টোবর ২০২৬, মালিকের প্রশ্ন: "হিসাবের
            সারাংশ ভাউচারে এখন নেই কেন?")। বাঁয়ে পক্ষ, টাকা, পদ্ধতি → খাত, সংযুক্তি ও বিবরণ; ডানে হিসাবের সারাংশ আর খাতায় যা যাবে।
            ⓘ `lg`-এর নিচে (মোবাইল, সরু জানালা) ডানের কলাম নিচে নামে; নিচের বোতাম-সারি দুই কলামের বাইরে, পুরো চওড়ায়।
        --}}
        @if ($partyAbove)
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]" data-voucher-columns>
            {{-- ⓘ বাঁয়ের খণ্ড (a6-এর আসন্ন ফর্মের `left`): পক্ষ আর খোলা বিল, টাকা আর পদ্ধতি, সংযুক্তি ও বিবরণ --}}
            <div class="min-w-0 space-y-4" data-voucher-main data-part="left">
        @endif

        @if (in_array($voucher->type, [
            \App\Modules\Accounts\Models\Voucher::RECEIPT,
            \App\Modules\Accounts\Models\Voucher::PAYMENT,
        ], true))
            @include('accounts::voucher.partials.party-fields')
        @elseif (! $expenseLayout && ! $contraLayout)
            {{-- ⓘ খরচে এই সারিটা নেই: তারিখ নকশার সারি ১-এ চলে গেছে,
                 "কাগজের তারিখ" নকশায় নেই, আর "টাকার অঙ্ক" শেষ সারিতে। --}}
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                                :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                                required />

                    <x-ui.field name="ref_date" type="date" :label="__('accounts::field.ref_date')"
                                :value="old('ref_date', $voucher->ref_date?->format('Y-m-d'))" />

                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                                :label="__('accounts::field.amount')"
                                {{-- ⭐ `?? $voucher->amount` — ১৮ সেপ্টেম্বর ২০২৬, অর্থের খাতার কারণে।

                                     ⓘ [[VoucherController::prefill()]] ঠিকানা থেকে `amount`
                                     নিয়ে `new Voucher([...])`-এ বসায়, আর ঘরটা এতদিন কেবল
                                     **দাখিলার লাইন** পড়ত। ⛔ নতুন ফর্মে লাইনই নেই, তাই
                                     সংখ্যাটা মডেলে বসেও পর্দায় পৌঁছাত না।

                                     ⚠️ ফল: অর্থের খাতা থেকে "টাকা নিন — রসিদ ভাউচার" চাপলে
                                     নাম ও বিবরণ বসত, **টাকাটা বসত না**, আর অঙ্কটা দুইবার
                                     টাইপ করতে হত। ⓘ মালিকের প্রশ্নটাই ছিল *"আবার Accounts-এ
                                     গেলে খাতা আলাদা রেখে লাভ কী"*।

                                     ⭐ যোগ করা, কেড়ে নেওয়া নয়: লাইন থাকলে আগের মতোই সেটাই
                                     জেতে, তাই সম্পাদনার আচরণ অপরিবর্তিত।

                                     ⚠️ তিন জায়গায় একই লাইন (২১৫ · ৬৩৬ · ৭৬১) — পক্ষ উপরে
                                     না নিচে, আর ধরন অনুযায়ী তিনটা শাখা। ⛔ একটা বদলে বাকি
                                     দুইটা ভুলে গেলে একই বোতাম এক ভাউচারে কাজ করত, অন্যটায়
                                     নীরবে করত না। --}}
                                :value="old('amount', $typedAmount ?? $voucher->amount)" required numeric />
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

        @if ($contraLayout)
            @include('accounts::voucher.partials.contra-fields')
        @endif

        {{--
            ⛔ কন্ট্রায় এই বাক্সটা খালি দেখা যেত — ১৮ সেপ্টেম্বর ২০২৬।

            ⓘ ভিতরের সব ঘর লুকানো (খাত দুইটা উপরে, পক্ষ ও টাকার
            শ্রেণি কন্ট্রায় অর্থহীন), আর খরচের মতো শেষ সারিও এখানে
            নেই — তাই পর্দায় পড়ে থাকত একটা সাদা, খালি কার্ড।
            ⚠️ মালিক ছবিতে সেটাই দেখা যাচ্ছিল, দুই অংশের মাঝখানে।

            ⭐ ঘরগুলো রয়ে গেছে (লুকানো ও নিষ্ক্রিয়), কেবল বাক্সটা নেই।
        --}}
        {{-- ⛔ রসিদ ও পরিশোধেও বাক্স নেই — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"majkane
             ei khali box ta keno dewa?"*। ⓘ ওখানে ভিতরের সব ঘর নমুনা মেনে লুকানো
             (নিচের সারিতে "যে খাতে জমা", উপরে ডিপোজিটর), তাই কার্ডটা খালি পড়ে থাকত। --}}
        <section @unless ($contraLayout || $partyAbove) data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" @endunless
                 {{-- ⓘ যুক্তিটা `resources/js/components/forms.js`-এ (simpleVoucherBox) --}}
                 x-data="simpleVoucherBox({
                     from: @js((string) old('from_account_id', $creditLine?->account_id)),
                     credit: @js($creditIds),
                     partyType: @js((string) old('party_type', $voucher->party_type ?? '')),
                     partyId: @js((string) old('party_id', $voucher->party_id ?? '')),
                     parties: @js(collect($parties)->flatMap(fn (array $g) => collect($g['options'])
                         ->map(fn (array $o) => ['type' => $g['type'], 'id' => (int) $o['id'], 'label' => $o['label']]))
                         ->values()),
                     cats: @js($moneyCategories),
                     catId: @js((string) old('money_category_id', $voucher->money_category_id ?? '')),
                     subId: @js((string) old('money_subcategory_id', $voucher->money_subcategory_id ?? '')),
                     dueUrl: @js(route('accounts.voucher.due')),
                     weOweThem: @js(__('accounts::message.we_owe_them')),
                     theyOweUs: @js(__('accounts::message.they_owe_us')),
                 })">
            {{--
                ⛔ রসিদ ও পরিশোধে এই দুইটা ঘর নিচের সারিতে বসে (নমুনা)।
                ⓘ "কার কাছ থেকে" ঘরটা নমুনায় নেই — উপরে ডিপোজিটরই
                বলে দেয় টাকা কার কাছ থেকে। ⚠️ আলাদা ঘরটা দ্বিত্ব।
            --}}
            <div class="grid gap-3 sm:grid-cols-2" @if ($sampled) hidden @endif>
                {{-- from — টাকা যেখান থেকে এল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['from']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="from_account_id" required @disabled($sampled) x-model="from"
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
                    <select name="to_account_id" required @disabled($sampled)
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
                {{--
                    ⓘ খরচে ঘরটা মুছে ফেলা যায়নি: **বাকিতে খরচ** লিখলে যাচাই পক্ষ চায়
                    ⚠️ তাই নকশার অবস্থায় (নগদে খরচ) পর্দায় এটা নেই, আর
                    প্রদেয় খাত বাছলেই ঘর দুইটা ফুটে ওঠে।
                --}}
                <label class="mt-3 block" @if ($partyAbove) hidden @endif
                       @if ($expenseLayout) x-cloak x-show="onCredit" @endif>
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

                <label class="mt-3 block" @if ($partyAbove) hidden @endif
                       @if ($expenseLayout) x-cloak x-show="onCredit" @endif>
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
                                    :selected="$str(row.id) === $str(partyId)"></option>
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
                <div class="mt-3" @if ($sampled) hidden @endif>
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
                <div class="mt-3 grid gap-3 sm:grid-cols-2" @if ($sampled) hidden @endif>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">
                            {{ __('accounts::field.money_category') }}
                        </span>
                        <select name="money_category_id" x-model="catId" @change="pickCategory()"
                                @disabled($sampled)
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">—</option>
                            <template x-for="row in parentCats" :key="row.id">
                                <option :value="row.id" x-text="row.label"
                                        :selected="$str(row.id) === $str(catId)"></option>
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
                        {{--
                            ⛔ লুকানো হলেই ঘরটা জমা পড়া থামে না — ১৮ সেপ্টেম্বর ২০২৬।
                            ⓘ পাশের শ্রেণির ঘরটায় `@disabled` ছিল, এইটায় ছিল না —
                            একটা পরীক্ষা গনা শুরু করার আগ পর্যন্ত কেউ টের পায়নি।
                        --}}
                        <select name="money_subcategory_id" x-model="subId" @change="pickSub()"
                                @disabled($sampled)
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">—</option>
                            <template x-for="row in subCats" :key="row.id">
                                <option :value="row.id" x-text="row.label"
                                        :selected="$str(row.id) === $str(subId)"></option>
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

            {{--
                ── নকশার শেষ সারি, ১৮ সেপ্টেম্বর ২০২৬ ────────────
                    যে খাত থেকে · টাকার অঙ্ক · সংযুক্তি · বিবরণ · [বোতাম]

                ⚠️ সারিটা এই সেকশনের **ভিতরে**, বাইরে নয় — কারণ
                "যে খাত থেকে" ঘরটা Alpine-এর `from` অবস্থা ধরে রাখে, আর
                সেই অবস্থা থেকেই "বাকিতে কি না" (`onCredit`) ঠিক হয়।
                ⛔ বাইরে বসালে স্কোপ শেষ হয়ে যেত, আর পক্ষের ঘর কখনো ফুটত না।

                ⓘ নকশায় "টাকার অঙ্ক" ঘরটা আঁকা নেই — ওখানে উৎসে
                কর্তনের ভাঁজে "হাতে ৳৪৮,৫০০" দেখানো। ⚠️ কিন্তু কর্তন
                ছাড়া খরচে (যা বেশিরভাগ) অঙ্ক লেখার কোনো ঘরই থাকত না —
                ভাঁজ খুলে "বিলের মোট" লিখতে হত। তাই ঘরটা এখানে রাখা হলো।
            --}}
            @if ($expenseLayout)
                <div class="mt-4 grid items-end gap-3 sm:grid-cols-[2fr_1fr_2fr_auto]">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">
                            {{ __('accounts::field.from_head') }}
                            <span class="text-(--color-danger)" aria-hidden="true">*</span>
                        </span>
                        <select name="from_account_id" required x-model="from"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">&mdash;</option>
                            @foreach ($optionsFor($sides['from']['source']) as $account)
                                {{-- ⓘ খাতের ধরন — টাকার বাক্স ([[x-money.method]]) দেখে পদ্ধতি নিজে সরায়, রসিদ আর পরিশোধের মতোই (২২ অক্টোবর ২০২৬) --}}
                                <option value="{{ $account->id }}"
                                        @if (filled($account->money_kind ?? null)) data-kind="{{ $account->money_kind }}" @endif
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

                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                                :label="__('accounts::field.amount')"
                                {{-- ⭐ `?? $voucher->amount` — ১৮ সেপ্টেম্বর ২০২৬, অর্থের খাতার কারণে।

                                     ⓘ [[VoucherController::prefill()]] ঠিকানা থেকে `amount`
                                     নিয়ে `new Voucher([...])`-এ বসায়, আর ঘরটা এতদিন কেবল
                                     **দাখিলার লাইন** পড়ত। ⛔ নতুন ফর্মে লাইনই নেই, তাই
                                     সংখ্যাটা মডেলে বসেও পর্দায় পৌঁছাত না।

                                     ⚠️ ফল: অর্থের খাতা থেকে "টাকা নিন — রসিদ ভাউচার" চাপলে
                                     নাম ও বিবরণ বসত, **টাকাটা বসত না**, আর অঙ্কটা দুইবার
                                     টাইপ করতে হত। ⓘ মালিকের প্রশ্নটাই ছিল *"আবার Accounts-এ
                                     গেলে খাতা আলাদা রেখে লাভ কী"*।

                                     ⭐ যোগ করা, কেড়ে নেওয়া নয়: লাইন থাকলে আগের মতোই সেটাই
                                     জেতে, তাই সম্পাদনার আচরণ অপরিবর্তিত।

                                     ⚠️ তিন জায়গায় একই লাইন (২১৫ · ৬৩৬ · ৭৬১) — পক্ষ উপরে
                                     না নিচে, আর ধরন অনুযায়ী তিনটা শাখা। ⛔ একটা বদলে বাকি
                                     দুইটা ভুলে গেলে একই বোতাম এক ভাউচারে কাজ করত, অন্যটায়
                                     নীরবে করত না। --}}
                                :value="old('amount', $typedAmount ?? $voucher->amount)" required numeric 
                                x-on:input="$dispatch('abos-expense-amount', $event.target.value)" />

                    {{-- ⓘ সংযুক্তি মালিকের ছবির মার্ক করা খালি জায়গায় — উৎসে কর্তনের ঠিক নিচে। --}}

                    <x-ui.field name="narration"
                                :label="__('core.table.narration')"
                                :value="old('narration', $voucher->narration)" />

                    {{--
                        ⭐ নকশার বোতাম — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।

                        ⓘ আমি প্রশ্ন তুলেছিলাম: অনুমোদন লাগবে কি না সেটা
                        প্রতিটা কোম্পানি নিজে ছকে বসায়, আর ছক না বসালে ভাউচার
                        সোজা খাতায় যায়। ⭐ মালিক তারপরও বললেন *"নাম বদলা"* —
                        নকশাই চূড়ান্ত, আর খরচে অনুমোদন তাঁদের নিয়ম।

                        ⚠️ কেবল খরচে, পাঁচটাতে নয়: আদায় ও পরিশোধের নকশায়
                        বোতামটা "সংরক্ষণ ও পোস্ট"ই।
                    --}}
                    <div class="pb-1">
                        @include('accounts::voucher.partials.save-buttons', [
                            'voucher' => $voucher,
                            'type' => $type,
                            'primaryLabel' => 'accounts::action.send_for_approval',
                        ])
                    </div>
                </div>
            @endif
        </section>

        {{--
            ── ⭐ ২৩ অক্টোবর ২০২৬: রসিদ ও পরিশোধ চূড়ান্ত নকশার ক্রমে ─────────────────────────────────────────────
            fe, মালিকের কথায়: *"নকশা অনুযায়ী, লাইভের আগে মিলিয়ে নাও"*
            (টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা, পাতা ২ আর ৪)। ক্রম এখন:
                পক্ষের ধরন · পক্ষ · তারিখ · টাকা  (উপরের বাক্সে, [[party-fields]])
                যে খাতে জমা / যে খাত থেকে (খাতে আছে ৳…) · পদ্ধতি → পদ্ধতির ঘর
                সংযুক্তি | কাকে দেওয়া হল · কার মাধ্যমে · বিবরণ
                খাতায় যা যাবে (পোস্টের আগে, সার্ভারের হিসাবে)
                বোতাম, আর পোস্টের পরে কী পাওয়া যায়
            ⓘ আগে পদ্ধতি আগে আসত, আর খাত, টাকা, কার মাধ্যমে আর বিবরণ নিচের এক সারিতে পাঁচটা ঘর — ১৩৬৬-এ লম্বা খাতের নাম কাটা
            পড়ত ("1102-DBBL — ডাচ-বা…")। ⛔ ঘরের নাম একটাও বদলায়নি: জমা, ফোন, পুরনো কাগজ আর ছাপা অক্ষত।
            ⓘ টাকার অংশের যুক্তি ফর্মের [[voucherMoney]]-এ (money.js) — অঙ্ক লাখ-কমায়, খাতের স্থিতি, রেফারেন্সের চলতি উত্তর আর প্রিভিউ।
        --}}
        @if ($partyAbove)
            @php $isPayment = $voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT; @endphp

            <section data-boxed data-voucher-details
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('money.screen.attach_and_note') }}</h2>
                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- ⭐ সংযুক্তি — ব্যাংক স্লিপ, স্ক্রিনশট বা অ্যাডভাইস (নকশার "সংযুক্তি ও বিবরণ"); লেখক আগে থেকেই নেয় ([[VoucherWriter::store()]]) --}}
                    <div data-voucher-attachment>
                        @include('accounts::voucher.partials.attachment-field', ['attachmentLabel' => $isPayment ? __('money.screen.attach_hint') : __('money.screen.attach_hint_in')])
                    </div>

                    <div class="flex flex-col gap-3">
                        @if ($isPayment)
                            {{-- ⓘ ফাঁকা রাখা যায়: সরবরাহকারী হলে দেনা নিজে ২১১১-এ কমে ([[VoucherRequest]]) --}}
                            {{-- ⭐ নামে খাত, পক্ষ নয় — "যে খাতে বসবে", নিচে "না বাছলে পক্ষের দেনা" (fe, ২৪ অক্টোবর ২০২৬: "কাকে দেওয়া হল" শুনে পক্ষ মনে হত) --}}
                            <x-ui.select name="to_account_id"
                                         :label="__('accounts::field.lands_in')"
                                         :hint="__('accounts::field.lands_in_hint')"
                                         :options="collect($optionsFor($sides['to']['source']))->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()"
                                         :selected="old('to_account_id', $debitLine?->account_id)"
                                         placeholder="—" />
                        @endif

                        {{-- ⭐ কার মাধ্যমে — মালিকের আলাদা আদেশ (১৮ সেপ্টেম্বর ২০২৬: *"বিবরণ er age niye aso"*); বিবরণের ঠিক আগে --}}
                        <x-ui.select name="carried_by" :label="__('accounts::field.carried_by')"
                                     :options="$carriers ?? []"
                                     :selected="old('carried_by', $voucher->carried_by)"
                                     :placeholder="__('accounts::field.carried_by_none')" />

                        <x-ui.field name="narration"
                                    :label="__('core.table.narration')"
                                    :value="old('narration', $voucher->narration)" />
                    </div>
                </div>
            </section>

            </div>{{-- data-voucher-main --}}

            {{--
                ═══ ডানের কলাম — একটাই খণ্ড: মাথার তিন অঙ্ক → হিসাবের সারাংশ → খাতায় যা যাবে → নিয়ন্ত্রণ (নকশা পাতা ২ আর ৪; a6-এর আসন্ন
                Core দুই-কলামের ফর্মের `right` খণ্ডের হুবহু ক্রম — তখন পুরো খণ্ডটা সরে বসবে, নতুন করে লেখা নয়) ═══
            --}}
            <aside class="flex min-w-0 flex-col gap-4" data-voucher-aside data-part="right">
            {{--
                ⭐ হিসাবের সারাংশ — তিন পর্দার একই কার্ড ([[x-money.summary]])। রসিদ: পাওয়া টাকা → চার্জ → খাতে উঠবে (নিট) → কথায় → মিলেছে;
                পরিশোধ আয়নায়: দেওয়া টাকা → চার্জ → খাত থেকে যাবে (নিট) → কথায় → মিলেছে। ⓘ অঙ্ক আর কথা সার্ভারের, খাতার একই সারি থেকে
                ([[VoucherController::summaryOf()]])। ⓘ উৎসে কাটার ঘর রসিদ-পরিশোধ ভাউচারে নেই (আছে আদায় আর সরবরাহকারী-পরিশোধে), তাই সারিও নেই।
            --}}
            <x-money.summary data-voucher-summary words="sumWords">
                @if ($isPayment)
                    {{-- ⭐ নকশা পাতা ৪-এর ক্রম: মোট (পক্ষের খাতে কমে) − AIT − VDS; তারপর দেওয়া, চার্জ, নিট --}}
                    <dt>{{ __('money.screen.sum.gross_party') }}</dt>
                    <dd class="num" data-sum-gross x-text="sumGross"></dd>
                    <dt>{{ __('money.screen.sum.ait') }}</dt>
                    <dd class="num" data-sum-ait x-text="sumAitMinus"></dd>
                    <dt>{{ __('money.screen.sum.vds') }}</dt>
                    <dd class="num" data-sum-vds x-text="sumVdsMinus"></dd>
                @endif
                <dt>{{ $isPayment ? __('money.screen.sum.paid') : __('money.screen.sum.received') }}</dt>
                <dd class="num" data-sum-amount x-text="sumAmount"></dd>
                @unless ($isPayment)
                    {{-- ⭐ নকশা পাতা ২-এর ক্রম: পাওয়া টাকা → পক্ষ কেটে রেখেছেন VDS → AIT → পক্ষের খাতে (মোট); তারপর চার্জ, নিট --}}
                    <dt>{{ __('money.screen.sum.vds_in') }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" data-sum-vds-tag>{{ __('money.screen.tag.if_any') }}</span></dt>
                    <dd class="num" data-sum-vds x-text="sumVdsText"></dd>
                    <dt>{{ __('money.screen.sum.ait_in') }}</dt>
                    <dd class="num" data-sum-ait x-text="sumAitText"></dd>
                    <dt class="font-semibold">{{ __('money.screen.sum.gross_party') }}</dt>
                    <dd class="num font-semibold" data-sum-gross x-text="sumGross"></dd>
                @endunless
                <dt>{{ __('money.screen.sum.charge_any') }}</dt>
                <dd class="num" data-sum-charge x-text="sumCharge"></dd>
                <dt class="border-t border-(--color-border) pt-2 font-semibold">{{ $isPayment ? __('money.screen.sum.net') : __('money.screen.sum.into') }}</dt>
                <dd class="num border-t border-(--color-border) pt-2 font-semibold" data-sum-net x-text="sumNet"></dd>
                <x-slot:after>
                    <p class="mt-3 border-s-2 border-(--color-badge-success-ink) bg-(--color-badge-success-bg) px-3 py-2 text-xs text-(--color-badge-success-ink)"
                       x-show="sumMatches" x-cloak data-sum-matches>{{ $isPayment ? __('money.screen.sum.matches_out') : __('money.screen.sum.matches_in') }}</p>
                    {{-- ⭐ নকশার কমলা লাইন — পরিশোধে AIT/VDS কাটলে পোস্টে সত্যিই কোষাগারে জমা আর মূসক-৬.৬-এর করণীয় ওঠে
                         ([[VoucherService::raiseTaxDuties()]], cb-ma-4); তাই লাইনটা এখন সত্য, টাকা দেওয়ার পর্দার একই কথা --}}
                    @if ($isPayment)
                        <p class="mt-3 border-s-2 border-(--color-warning) bg-(--color-badge-warning-bg) px-3 py-2 text-xs text-(--color-badge-warning-ink)"
                           x-show="hasWithheld" x-cloak data-sum-tax-note>{{ __('money.screen.sum.tax_note') }}</p>
                    @endif
                </x-slot:after>
            </x-money.summary>

            {{-- ── ⭐ খাতায় যা যাবে — পোস্টের আগে, সার্ভারের হিসাবে ([[VoucherController::simulate()]]); কিছুই লেখা হয় না ── --}}
            <section data-boxed data-voucher-ledger
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="font-semibold">{{ __('money.screen.ledger') }}</h2>
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.ledger_hint') }}</span>
                </div>
                <p class="text-sm text-(--color-ink-muted)" x-show="! hasPreview && ! previewBlocked">{{ __('accounts::message.ledger_empty') }}</p>
                {{-- ⛔ পোস্ট যে কারণে থামত (যেমন অন্য শাখার পক্ষ), প্রিভিউ সেই বার্তাই দেখায় --}}
                <p class="text-sm text-(--color-danger)" data-ledger-blocked x-show="previewBlocked" x-cloak x-text="preview.message"></p>
                <table class="ui-grid w-full text-sm" x-show="hasPreview" x-cloak>
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('money.screen.ledger_col.account') }}</th>
                            @unless ($isPayment)
                                <th class="text-start" data-ledger-branch-col>{{ __('money.screen.ledger_col.branch') }}</th>
                            @endunless
                            <th class="num">{{ __('money.screen.ledger_col.debit') }}</th>
                            <th class="num">{{ __('money.screen.ledger_col.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in preview.rows" :key="i">
                            <tr data-ledger-row>
                                <td class="text-xs">
                                    <span x-text="row.account"></span>
                                    <span class="block text-2xs text-(--color-ink-muted)"><span x-text="row.party"></span>@if ($isPayment) <span x-text="row.branch"></span>@endif</span>
                                </td>
                                @unless ($isPayment)
                                    <td class="text-xs" x-text="row.branch"></td>
                                @endunless
                                <td class="num" x-text="row.debit"></td>
                                <td class="num" x-text="row.credit"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td @unless ($isPayment) colspan="2" @endunless>{{ __('money.screen.ledger_col.total') }}</td>
                            <td class="num" x-text="preview.debit"></td>
                            <td class="num" x-text="preview.credit"></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-xs text-(--color-badge-success-ink)" data-ledger-matches x-show="previewMatches" x-cloak>{{ __('accounts::message.ledger_matches') }}</p>
                <p class="mt-2 text-xs text-(--color-danger)" x-show="unbalanced" x-cloak>{{ __('money.screen.ledger_unbalanced') }}</p>
            </section>

            {{-- ⭐ নিয়ন্ত্রণ — টাকা দেওয়ার পর্দার একই কার্ড ([[x-money.controls]]): সই চালু/বন্ধ, লেখক ≠ পাকাকারী --}}
            <x-money.controls data-voucher-controls :signatures="$moneySignatures ?? false" :three-hands="$moneyThreeHands ?? false"
                              :direction="$isPayment ? 'out' : 'in'">
                {{--
                    ⭐ নকশার "বাউন্স চিহ্ন দিলে নিজে উল্টাবে" — সত্যি করে: গৃহীত চেক এই পর্দায় ঢোকে না (মালিকের ২৬ সেপ্টেম্বরের নিয়ম), চেকের খাতায়;
                    সেখানে বাউন্স চিহ্ন দিলে খাতা নিজে উল্টায়, গ্রাহকের বকেয়া ফেরে ([[ChequeService::bounce()]])। তাই লাইনটা সেই খাতায় নিয়ে যায় (cb-ma-4)।
                --}}
                @unless ($isPayment)
                    <p class="px-1 text-xs text-(--color-ink-muted)" data-control="bounce">
                        {{ __('money.screen.bounce_note') }}
                        <a href="{{ route('accounts.cheque.index') }}" class="text-(--color-brand-700) underline" data-bounce-register>{{ __('money.screen.cheque_register') }}</a>
                    </p>
                @endunless
            </x-money.controls>
            </aside>
            </div>{{-- data-voucher-columns --}}

            {{-- ⓘ নিচের বোতাম-সারি — দুই কলামের বাইরে, পুরো চওড়ায় (a6-এর আসন্ন ফর্মের `bar` খণ্ড) --}}
            {{-- ⭐ স্থির, বারের ঠিক উপরে — টাকা দেওয়ার পর্দার একই সারি (`data-pay-footer`; `fixed` নয়, তাই কিছু ঢাকে না) --}}
            <section data-boxed data-part="bar"
                     class="print-hide sticky bottom-(--spacing-bottom-nav) md:bottom-(--spacing-status-bar) z-10 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3 shadow-lg">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    {{-- ⭐ পোস্টের পরে কী পাওয়া যায় — নকশার নিচের লাইন; দেখার পাতার ছাপার মেনুতে ছাপা, PDF আর WhatsApp ([[voucher.show]]) --}}
                    <p class="text-xs text-(--color-ink-muted)" data-after-post>
                        {{ $isPayment ? __('accounts::message.after_post_payment') : __('accounts::message.after_post_receipt') }}
                        {{-- ⭐ নকশার "VDS সার্টিফিকেট তালিকা" — এখন সত্য: পরিশোধ ভাউচারের কাটা VDS-এ মূসক-৬.৬-এর করণীয় ওঠে ([[VoucherService::raiseTaxDuties()]]) --}}
                        @if ($isPayment)
                            · <a href="{{ route('accounts.obligation.index') }}" class="underline" data-after-post-vds>{{ __('money.screen.vds_list') }}</a>
                        @endif
                    </p>
                    @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type,
                        'primaryLabel' => ($moneySignatures ?? false) ? 'money.screen.send' : 'money.screen.post'])
                </div>
            </section>
        @endif

        {{--
            ⓘ রসিদ ও পরিশোধে বোতামগুলো নিচের সারির ভিতরে বসে
            (নমুনায় "সংরক্ষণ ও পোস্ট" তিন ঘরের পাশে)।
            ⛔ এখানেও আঁকলে বোতাম দুইবার দেখা যায় — গুনে ধরা পড়েছে।
        --}}
        {{--
            ── কন্ট্রার শেষ সারি, ১৮ সেপ্টেম্বর ২০২৬ ───────────────
                টাকার পরিমাণ · বিবরণ · [সংরক্ষণ ও পোস্ট]

            ⓘ নকশায় বোতামটা এই সারির পাশেই — আদায় বা খরচের মতো
            নিচে নয়। ⚠️ কন্ট্রায় ঘর দুইটা, তাই জায়গার টানাটানি নেই।
        --}}
        @if ($contraLayout)
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid items-end gap-3 sm:grid-cols-[1fr_2fr_auto]">
                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                                :label="__('accounts::field.amount_moved')"
                                :value="old('amount', $typedAmount ?? $voucher->amount)" required numeric />

                    <x-ui.field name="narration"
                                :label="__('core.table.narration')"
                                :value="old('narration', $voucher->narration)" />

                    <div class="pb-1">
                        @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type])
                    </div>
                </div>
            </section>
        @endif

        @unless ($sampled)
            @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type])
        @endunless
    </form>
</x-layouts.app>
