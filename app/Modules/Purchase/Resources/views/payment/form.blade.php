{{--
    ⭐ টাকা দেওয়া — সরবরাহকারীকে পরিশোধ, আন্তর্জাতিক গড়নে (মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে; নকশা:
    মালিকের-ফাইল/টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf, পাতা ৪; SAP F-53, Oracle Payables, D365 Vendor payments)।

      মাথা      — শিরোনাম, অবস্থার চিপ, নম্বর · বানালেন · মাস খোলা; ডানে তিন অঙ্ক: পাওনা, এই পরিশোধ, বাকি থাকবে (#১)
      বাঁয়ে     — সাধারণ তথ্য (পক্ষের ধরন, সরবরাহকারী, তারিখ, মোট টাকা; যে খাত থেকে | পদ্ধতি; প্রাপকের ব্যাংক; পদ্ধতির ঘর)
                  খোলা বিল (শেষ দিনের ক্রমে, টিক আর অঙ্ক, নিচে মোট — #৩), সংযুক্তি ও বিবরণ (#১৫)
      ডানে     — হিসাবের সারাংশ (#৪, #৫, #৯), খাতায় যা যাবে (#১০, সার্ভারের হিসাবে), নিয়ন্ত্রণ (#১১, #৬)
      নিচে     — স্থির বোতাম-সারি: বাতিল · খসড়া রাখুন · পোস্ট / সইয়ে পাঠান

    ⛔ যা নেই, ইচ্ছাকৃত: "কাকে দেওয়া হল — পক্ষ থেকে (সরবরাহকারী হলে তার দেনা)" — পক্ষের ধরন আর সরবরাহকারী উপরেই বাছা।
    লাইনগুলো পণ্যের নয়, বিলের: এক পরিশোধে কয়েকটা বিলের টাকা যায়, আর ভাগটাই বলে কোন বিল এখনো বাকি।
--}}
@php
    $isNew = ! $payment->exists;

    // ⓘ বিল ধরে খোলা হলে ঐ বিলের বকেয়া; সম্পাদনায় রাখা লাইন; ভুল নিয়ে ফিরলে ফেরত পাঠানো লাইন — একই ক্রমে
    $picks = collect(old('lines', $isNew && $bill
        ? [['purchase_bill_id' => $bill->id, 'amount' => $bill->dueAmount()]]
        : $payment->lines->map(fn ($l) => ['purchase_bill_id' => $l->purchase_bill_id, 'amount' => (string) $l->amount])->all()))
        ->filter(fn ($l) => filled($l['purchase_bill_id'] ?? null))
        ->mapWithKeys(fn ($l) => [(string) $l['purchase_bill_id'] => (string) ($l['amount'] ?? '0')])
        ->all();

    $supplierId = (string) old('supplier_id', $bill?->supplier_id ?? $payment->supplier_id ?? '');
    $amount = (string) old('amount', $isNew ? ($bill?->dueAmount() ?? '') : (string) $payment->amount);
    // ⭐ কাঁচা অঙ্ক — লাখ-কমায় দেখায় ঘর নিজেই ("1,20,000.00", নকশার মিল-তালিকা #৯; x-ui.field type="money", ২৪ অক্টোবর ২০২৬);
    //    ফেরত-আসা অচল লেখা যেমন ছিল তেমন — ভুলটা চোখে থাকে
    $plainAmount = str_replace(',', '', trim($amount));
    $amount = $plainAmount !== '' && is_numeric($plainAmount) ? $plainAmount : $amount;
    $accountId = (string) old('account_id', $payment->account_id ?? '');

    $billRows = $openBills->map(fn ($b) => [
        'id' => $b->id,
        'supplier' => (string) $b->supplier_id,
        'no' => $b->document_no,
        'url' => route('purchase.bill.show', $b),
        'ref' => (string) ($b->supplier_bill_no ?? ''),
        'due' => $b->due_on?->format('d-m') ?? '—',
        'late' => $b->due_on !== null && $b->due_on->isPast(),
        'total' => (string) $b->total,
        'remaining' => $b->dueAmount(),
    ])->values()->all();

    $year = \App\Models\FinancialYear::forDate(old('trx_date', $payment->trx_date ?? today()));
    $maker = $isNew ? auth()->user()?->name : $payment->creator?->name;
    $taxIds = $suppliers->mapWithKeys(fn ($s) => [(string) $s->id => trim(implode(' · ', array_filter([
        $s->bin ? 'BIN '.$s->bin : null, $s->tin ? 'TIN '.$s->tin : null,
    ])))])->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('money.screen.title_pay') }}</x-slot:title>

    {{-- ⭐ `x-money-keys` — Enter কিছু পাঠায় না, Ctrl+Enter মূল বোতাম ([[money-keys.js]]) --}}
    <form method="POST" data-pay-screen x-money-keys
          action="{{ $isNew ? route('purchase.payment.store') : route('purchase.payment.update', $payment) }}"
          enctype="multipart/form-data"
          x-data="payBills({ bills: @js($billRows), supplier: @js($supplierId), amount: @js($amount), account: @js($accountId), picks: @js($picks), balances: @js($balances), taxIds: @js($taxIds), bankIds: @js($bankSuppliers->map(fn ($s) => (string) $s->id)->values()), simulateUrl: @js(route('purchase.payment.simulate')), slipUrl: @js(route('purchase.payment.slip_check')), paymentId: @js($isNew ? null : $payment->id) })"
          @money-method="heard($event.detail)"
          class="pb-20">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        {{-- ── মাথা: শিরোনাম · চিপ · তথ্যের সারি, ডানে তিন অঙ্ক (#১) ── --}}
        <header class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold">{{ __('money.screen.title_pay') }}</h1>
                    <x-ui.paper-state state="draft" />
                </div>
                <p data-pay-meta class="mt-1 text-xs text-(--color-ink-muted)">
                    {{ $isNew ? __('money.screen.meta.number_on_save') : __('money.screen.meta.number', ['no' => $payment->document_no]) }}
                    · {{ __('money.screen.meta.maker', ['name' => $maker ?? '—']) }}
                    @if ($firstSigner !== null)
                        · <span data-pay-signer>{{ __('money.screen.meta.signer', ['who' => $firstSigner['who'] !== '' ? $firstSigner['who'] : '—', 'levels' => $firstSigner['levels']]) }}</span>
                    @endif
                    · <span @class(['text-(--color-danger)' => $year !== null && ! $year->isOpen()])>{{ $year !== null && ! $year->isOpen() ? __('money.screen.meta.month_closed') : __('money.screen.meta.month_open') }}</span>
                </p>
            </div>

            <dl class="flex gap-8 text-end" data-pay-head>
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.head.owed') }}</dt>
                    <dd class="num text-xl font-semibold text-(--color-danger)" data-head-owed>৳<span x-text="owedText"></span></dd>
                </div>
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.head.this') }}</dt>
                    <dd class="num text-xl font-semibold" data-head-this>৳<span x-text="payingText"></span></dd>
                </div>
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.head.left') }}</dt>
                    <dd class="num text-xl font-semibold" data-head-left>৳<span x-text="leftText"></span></dd>
                </div>
            </dl>
        </header>

        @if ($errors->any())
            <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex min-w-0 flex-col gap-4">

                {{-- ── সাধারণ তথ্য ── --}}
                <section data-boxed data-pay-general class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('money.screen.general') }}</h2>

                    {{-- ⭐ পক্ষের ধরন — প্রথম ঘর (fe, ২১ অক্টোবর ২০২৬)। এই পথে সরবরাহকারী স্থির; বাকি ধরন হিসাবের পরিশোধ ভাউচারে যায় --}}
                    <fieldset class="mb-3" data-party-type>
                        <legend class="mb-1 block text-sm font-medium">{{ __('money.screen.party_type') }} <span class="text-(--color-danger)">*</span></legend>
                        <div class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                            @foreach (['customer', 'supplier', 'staff', 'other'] as $type)
                                @if ($type === 'supplier')
                                    <span data-party-type-option="supplier" aria-current="true"
                                          class="block border-e border-(--color-border) bg-(--color-brand-600) px-3 py-2 text-sm font-medium text-white">{{ __('money.screen.party.'.$type) }}</span>
                                @else
                                    <a href="{{ route('accounts.voucher.create', ['type' => 'payment']) }}" data-party-type-option="{{ $type }}"
                                       title="{{ __('money.screen.party_type_elsewhere') }}"
                                       class="block border-e border-(--color-border) px-3 py-2 text-sm text-(--color-ink-body) last:border-e-0 hover:bg-(--color-surface-hover)">{{ __('money.screen.party.'.$type) }}</a>
                                @endif
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- ⓘ তারিখের ঘরের নিচের সীমা — ১৩৬৬-এ মডিউল-মেনু খোলা থাকলে সমান চার ভাগে "22-10-202" কাটা পড়ত (২২ অক্টোবর ২০২৬) --}}
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_minmax(9.5rem,1fr)_minmax(9.5rem,1fr)]" data-pay-general-row>
                        <div class="sm:col-span-2 lg:col-span-1">
                            <x-ui.select name="supplier_id" :label="__('money.screen.supplier')" x-model="supplier"
                                         :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->pickerName()])"
                                         :selected="$supplierId" placeholder="—" required />
                            <p class="mt-1 text-2xs text-(--color-ink-muted)" data-supplier-tax x-text="taxLine"></p>
                        </div>
                        <x-ui.field name="trx_date" type="date" :label="__('money.screen.date')" required
                                    :value="old('trx_date', $payment->trx_date?->toDateString() ?? now()->toDateString())" />
                        {{-- ⓘ দেখানোর ঘরে লাখ-কমা, পাঠানো মান কাঁচা অঙ্ক (type="money"); সার্ভার তবু কমা ছেঁটে পড়ে ([[PaymentRequest::prepareForValidation()]]) --}}
                        <x-ui.field name="amount" type="money" model="amount" required data-pay-amount
                                    :label="__('money.screen.gross')" :value="$amount" />
                    </div>

                    {{-- পদ্ধতি | যে খাত থেকে; প্রাপকের ব্যাংক; পদ্ধতির ঘর — এক গ্রিডে ([[components.money.method]]-এর `inline`) --}}
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">

                        {{-- ⭐ প্রাপকের ব্যাংক — সরবরাহকারীর রেকর্ড থেকে, কেবল পড়ার জন্য (নকশা #৬; fe-র [[supplier::components.payee-bank]])।
                             ⓘ কেবল যাঁদের ব্যাংক তথ্য আছে তাঁদের বাক্স আঁকা হয় (একটা প্রশ্নে খোঁজা), বাছা সরবরাহকারীরটা দেখায়; বাকিদের "নেই" --}}
                        <div class="order-2 col-span-full" data-payee-bank-row>
                            <span class="mb-1 block text-xs text-(--color-ink-muted)">{{ __('money.screen.payee_bank') }}</span>
                            @foreach ($bankSuppliers as $withBank)
                                <template x-if="supplier === '{{ $withBank->id }}'">
                                    <x-supplier::payee-bank :supplier="$withBank" />
                                </template>
                            @endforeach
                            <template x-if="noBankRecord">
                                <x-supplier::payee-bank :supplier="null" />
                            </template>
                        </div>

                        {{--
                            ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত — খাতের ঘর বাক্সের ভিতরে, পদ্ধতির ঠিক পরে (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe)।
                            ⓘ ঘর `x-model="account"`-এ বাঁধা — স্থিতি, বিলে বসানো আর খাতায় যা যাবে আগের মতোই এটা দেখে।
                        --}}
                        <x-money.method direction="out" inline slip-check :record="$isNew ? null : $payment" :modes="$modes"
                                        :methods="['cash', 'mfs', 'transfer', 'cheque']"
                                        :accounts="$accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' · '.$a->name(), 'kind' => (string) $a->money_kind])->values()->all()"
                                        account-name="account_id" account-model="account" :account-label="__('money.screen.from_account')"
                                        :account-selected="$accountId">
                            <x-slot:account-note>
                                {{-- ⭐ খাতে টাকা আছে কি না (#২৪) --}}
                                <p class="mt-1 text-2xs" data-account-balance x-show="hasBalance" x-cloak :class="balanceClass">
                                    {{ __('money.screen.in_account') }}<span x-text="balanceText"></span>
                                    <span x-show="short">— {{ __('money.screen.short') }}</span><span x-show="! short" data-balance-ok>✓</span>
                                </p>
                            </x-slot:account-note>
                        </x-money.method>
                    </div>
                </section>

                {{-- ── খোলা বিল ও ডেবিট নোট (#৩) ── --}}
                <section data-boxed data-open-bills class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="font-semibold">{{ __('money.screen.open_bills') }}</h2>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.due_order') }}</span>
                    </div>

                    <p class="text-sm text-(--color-ink-muted)" x-show="supplier === ''">{{ __('money.screen.pick_supplier_first') }}</p>
                    <p class="text-sm text-(--color-ink-muted)" x-show="supplier !== '' && visible.length === 0" x-cloak>{{ __('money.screen.no_bills') }}</p>

                    {{-- ⓘ সাত কলাম এক কার্ডে — ঘরের পাশের ফাঁক কম (`--grid-pad-x`)।
                         ⭐ "এখন প্রয়োগ" ডানে আটকানো (sticky) — বাঁয়ের মডিউল-মেনু খোলা ১৩৬৬-এ টেবিল পাশে সরলেও লেখার ঘরটা সবসময়
                         চোখে থাকে (মিল-তালিকার ফাঁক ১; fe, ২২ অক্টোবর ২০২৬: SAP/Xero-র চল)। --}}
                    <div class="table-responsive" x-show="visible.length > 0" x-cloak>
                        <table class="ui-grid w-full text-sm [--grid-pad-x:6px]">
                            <thead>
                                <tr>
                                    <th class="w-8"><span class="sr-only">{{ __('money.screen.col.apply') }}</span></th>
                                    <th class="text-start">{{ __('money.screen.col.bill') }}</th>
                                    <th class="text-start">{{ __('money.screen.col.ref') }}</th>
                                    <th class="text-start">{{ __('money.screen.col.due') }}</th>
                                    <th class="num">{{ __('money.screen.col.total') }}</th>
                                    <th class="num">{{ __('money.screen.col.remaining') }}</th>
                                    <th class="num sticky end-0 z-10 bg-(--color-surface-card) border-s border-(--color-border)" data-apply-col>{{ __('money.screen.col.apply') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="bill in visible" :key="bill.id">
                                    <tr data-open-bill>
                                        <td><input type="checkbox" :checked="isOn(bill.id)" @change="toggle(bill.id)" :aria-label="bill.no"></td>
                                        <td class="whitespace-nowrap"><a :href="bill.url" class="text-(--color-link) hover:underline" x-text="bill.no"></a></td>
                                        <td class="whitespace-nowrap" x-text="bill.ref"></td>
                                        <td class="whitespace-nowrap" :class="bill.late ? 'text-(--color-danger) font-medium' : ''" x-text="bill.due"></td>
                                        <td class="num whitespace-nowrap" x-text="money(bill.total)"></td>
                                        <td class="num whitespace-nowrap" x-text="money(bill.remaining)"></td>
                                        <td class="num sticky end-0 z-10 bg-(--color-surface-card) border-s border-(--color-border)" data-apply-col>
                                            <input type="text" inputmode="decimal" :value="shown(bill.id)" @change="typed(bill.id, $event.target.value)"
                                                   aria-label="{{ __('money.screen.col.apply') }}"
                                                   class="num h-(--spacing-field-compact) w-26 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot>
                                <tr class="font-semibold">
                                    <td></td>
                                    <td colspan="3">{{ __('money.screen.col.applied_total') }}</td>
                                    <td class="num" x-text="billTotalText"></td>
                                    <td class="num" x-text="remainingText"></td>
                                    <td class="num sticky end-0 z-10 bg-(--color-surface-card) border-s border-(--color-border)" data-applied-total data-apply-col x-text="appliedText"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- ⓘ সার্ভারে যায় কেবল টিক-দেওয়া বিল --}}
                    <template x-for="(line, i) in picked" :key="line.id">
                        <span>
                            <input type="hidden" :name="'lines[' + i + '][purchase_bill_id]'" :value="line.id">
                            <input type="hidden" :name="'lines[' + i + '][amount]'" :value="line.amount">
                        </span>
                    </template>
                </section>

                {{-- ── সংযুক্তি ও বিবরণ (#১৫) ── --}}
                <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('money.screen.attach_and_note') }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        {{-- ⓘ বাংলা ফাইল-বাছাই ([[ui.file-input]]) — ব্রাউজারের "Choose File" নয় (AFileIsChosenInBengaliTest) --}}
                        <div data-pay-attachment>
                            <span class="mb-1 block text-sm font-medium">{{ __('money.screen.attach_hint') }}</span>
                            <x-ui.file-input name="attachment" accept="image/*,application/pdf" />
                        </div>
                        <x-ui.field name="narration" :label="__('money.screen.narration')" :value="old('narration', $payment->narration)" />
                    </div>
                </section>
            </div>

            {{-- ═══ ডানের কলাম ═══ --}}
            <aside class="flex min-w-0 flex-col gap-4">
                {{-- ── হিসাবের সারাংশ (#৪, #৫, #৯) — তিন পর্দার একই কার্ড ([[x-money.summary]], ২৪ অক্টোবর ২০২৬) ── --}}
                <x-money.summary data-pay-summary>
                        <dt>{{ __('money.screen.sum.applied') }}</dt><dd class="num" x-text="appliedText"></dd>
                        {{-- ⭐ কাটা অঙ্ক বিয়োগ চিহ্নে, পাশে হারের ছোট ট্যাগ (নকশা; মিল-তালিকার ফাঁক ৬) --}}
                        <dt>{{ __('money.screen.sum.ait') }} <span class="ms-1 rounded bg-(--color-badge-info-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)">{{ __('money.screen.tag.ait') }}</span></dt>
                        <dd class="num" data-sum-ait x-text="aitMinus"></dd>
                        <dt>{{ __('money.screen.sum.vds') }} <span class="ms-1 rounded bg-(--color-badge-info-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)">{{ __('money.screen.tag.vds') }}</span></dt>
                        <dd class="num" data-sum-vds x-text="vdsMinus"></dd>
                        {{-- ⭐ নিটের নাম পদ্ধতি ধরে — ব্যাংক / MFS / নগদ থেকে যাবে (মিল-তালিকার ফাঁক ৭) --}}
                        <dt class="border-t border-(--color-border) pt-2 font-semibold" data-net-label x-text="netLabel"
                            data-net-bank="{{ __('money.screen.sum.net_bank') }}" data-net-mfs="{{ __('money.screen.sum.net_mfs') }}"
                            data-net-cash="{{ __('money.screen.sum.net_cash') }}">{{ __('money.screen.sum.net') }}</dt>
                        <dd class="num border-t border-(--color-border) pt-2 font-semibold" x-text="netText"></dd>
                        <dt>{{ __('money.screen.sum.charge') }}</dt><dd class="num" data-sum-charge x-text="chargeText"></dd>
                        <dt x-show="hasUnapplied" x-cloak>{{ __('money.screen.sum.unapplied') }}</dt>
                        <dd class="num" x-show="hasUnapplied" x-cloak data-sum-unapplied x-text="unappliedText"></dd>
                    <x-slot:after>
                    <p class="mt-3 border-s-2 border-(--color-badge-success-ink) bg-(--color-badge-success-bg) px-3 py-2 text-xs text-(--color-badge-success-ink)"
                       x-show="matches || hasUnapplied" x-cloak data-sum-matches>{{ __('money.screen.sum.matches') }}</p>
                    <p class="mt-3 border-s-2 border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2 text-xs text-(--color-badge-danger-ink)"
                       x-show="overApplied" x-cloak data-sum-over>{{ __('money.screen.sum.over') }}</p>
                    {{-- ⭐ কাটা কর/ভ্যাটের দায় মনে করানো — কেবল সত্যি কাটা হলে (টাকা দেওয়ার মিল-তালিকা #৩, ২২ অক্টোবর ২০২৬)। ⓘ "করণীয় তালিকায়
                         উঠবে" অংশটা তালিকা বানানোর পরে — আগে লিখলে পর্দা এমন কিছু কথা দিত যা ব্যবস্থায় নেই --}}
                    <p class="mt-3 border-s-2 border-(--color-warning) bg-(--color-badge-warning-bg) px-3 py-2 text-xs text-(--color-badge-warning-ink)"
                       x-show="withheld" x-cloak data-sum-tax-note>{{ __('money.screen.sum.tax_note') }}</p>
                    </x-slot:after>
                </x-money.summary>

                {{-- ── খাতায় যা যাবে (#১০) — সার্ভারের হিসাবে ── --}}
                <section data-boxed data-pay-ledger class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="font-semibold">{{ __('money.screen.ledger') }}</h2>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.ledger_hint') }}</span>
                    </div>
                    <p class="text-sm text-(--color-ink-muted)" x-show="! hasPreview">{{ __('money.screen.ledger_empty') }}</p>
                    <table class="ui-grid w-full text-sm" x-show="hasPreview" x-cloak>
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('money.screen.ledger_col.account') }}</th>
                                <th class="num">{{ __('money.screen.ledger_col.debit') }}</th>
                                <th class="num">{{ __('money.screen.ledger_col.credit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in preview.rows" :key="i">
                                <tr data-ledger-row>
                                    <td class="text-xs"><span x-text="row.account"></span><span class="block text-2xs text-(--color-ink-muted)" x-text="row.branch"></span></td>
                                    <td class="num" x-text="row.debit"></td>
                                    <td class="num" x-text="row.credit"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td>{{ __('money.screen.ledger_col.total') }}</td>
                                <td class="num" x-text="preview.debit"></td>
                                <td class="num" x-text="preview.credit"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <p class="mt-2 text-xs text-(--color-danger)" x-show="unbalanced" x-cloak>{{ __('money.screen.ledger_unbalanced') }}</p>
                </section>

                {{-- ── নিয়ন্ত্রণ (#১১, #২৩, #৬) ── --}}
                {{-- ⓘ তিন পর্দার একই কার্ড ([[x-money.controls]]); প্রাপকের ব্যাংকের সতর্কতা কেবল এই পর্দার, তাই slot-এ --}}
                <x-money.controls data-pay-controls :signatures="$signatures" :three-hands="$threeHands" direction="out">
                    <p class="border-s-2 border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2 text-(--color-badge-danger-ink)" data-control="bank">
                        {{ __('money.screen.control.bank_unsigned') }}
                    </p>
                </x-money.controls>
            </aside>
        </div>

        {{-- ── নিচের বোতাম-সারি (নকশা) ──
             ⛔ `fixed bottom-0` নয় (fe, ২২ অক্টোবর ২০২৬, cb/hf-footer-pad): তাতে সারিটা নিচের ঘোষণার বারের উপরে বসে সেটা ঢাকত, বোতাম
             বারের ফালিতে নামত (১৩৬৬×৭৬৮-এ নিচ ৭৫৩, বার ৭৪০ থেকে), আর পুরো চওড়ায় বাঁয়ের মেনুও ঢাকত। ⭐ এখন ফর্মের মান-পট্টির মতো
             ([[ui.form-actions]]): `sticky`, বারের ঠিক উপরে, মূল কলামের ভেতরে — আর নিজের জায়গা নেয়, তাই পাতার শেষ কিছু ঢাকে না। --}}
        <div data-pay-footer class="print-hide sticky bottom-(--spacing-bottom-nav) md:bottom-(--spacing-status-bar) z-10 mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3 shadow-lg">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-(--color-ink-muted)">{{ __('money.screen.after_post') }}</p>
                <div class="flex items-center gap-2">
                    <x-ui.button tone="ghost" :href="route('purchase.payment.index')">{{ __('money.screen.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" tone="secondary" name="intent" value="draft">{{ __('money.screen.draft') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary" name="intent" value="post" data-pay-primary>
                        {{ $signatures ? __('money.screen.send') : __('money.screen.post') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </form>
</x-layouts.app>
