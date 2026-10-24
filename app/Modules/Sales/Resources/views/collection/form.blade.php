{{--
    ⭐ টাকা নেওয়া — মালিকের নকশা ১০০, পাতা ২ (মালিকের-ফাইল/টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf; ২১ অক্টোবর ২০২৬)।

    ⓘ বাঁয়ে তিন কার্ড — সাধারণ তথ্য, খোলা বিল ও ক্রেডিট, সংযুক্তি ও বিবরণ; ডানে তিন — হিসাবের সারাংশ, খাতায় যা যাবে,
    নিয়ন্ত্রণ; মাথায় তিন অঙ্ক; নিচে স্থির পট্টি "বাতিল · খসড়া রাখুন · পোস্ট"। "পোস্ট" নিশ্চিতের আগের সারাংশ দেখায় খাতার
    লাইনসহ ([[CollectionController::overviewNew()]])।
    ⓘ হিসাব সব সার্ভারে ([[CollectionService]]) — পর্দা কেবল দেখায় আর পাঠায়; "খাতায় যা যাবে" সার্ভারের প্রিভিউ, পোস্টের একই লাইন।
    ⛔ CSP-Alpine: এক্সপ্রেশনে কেবল ঘরের নাম আর মেথড ([[collection.js]])।
--}}
@php
    $isNew = ! $collection->exists;
    // ⛔ টাকা কখনো float নয় — bcmath-এর গোল ([[MoneyIsNeverAFloatTest]]); কমা ছাড়া, ঘরে বসানোর লেখা
    $money = fn ($v) => $v === null || $v === '' ? '' : \App\Core\Support\Money::round((string) $v, 2);

    // ⓘ টাকার খাতের ধরন — `data-kind`, cb-র পদ্ধতির ব্লক খাত দেখে পদ্ধতি সরায় ([[moneyMethod]])
    // ⓘ খাতের সারি cb-র পদ্ধতির ব্লকের জন্য ([[x-money.method]] `accounts`): পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত
    $accountRows = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' · '.$a->name(), 'kind' => (string) $a->money_kind])->values()->all();

    /*
     * ⓘ আগে বাছা সারি — public_id ধরে (পর্দার খোলা কাগজের `id`): ভুল নিয়ে ফেরা ফর্মে old(), খসড়া খুললে কাগজের নিজের সারি
     * (বিলের পুরো অঙ্ক = টাকার অংশ + ক্রেডিটের অংশ), বিল ধরে খুললে সেই বিলের বকেয়া।
     */
    $chosen = [];
    if (is_array(old('lines'))) {
        foreach (old('lines') as $line) {
            if (is_array($line) && filled($line['id'] ?? null)) {
                $chosen[] = ['id' => (string) $line['id'], 'amount' => (string) ($line['amount'] ?? '0')];
            }
        }
    } elseif (! $isNew) {
        $bills = [];
        foreach ($collection->lines as $line) {
            $key = (string) $line->invoice?->public_id;
            $bills[$key] = bcadd($bills[$key] ?? '0', (string) $line->amount, 4);
        }
        foreach ($collection->applications as $app) {
            $source = $app->kind === 'credit'
                ? \App\Modules\Sales\Models\Collection::acrossBranches()->whereKey($app->source_id)->value('public_id')
                : \App\Modules\Accounts\Models\Note::acrossBranches()->whereKey($app->source_id)->value('public_id');
            $chosen[] = ['id' => (string) $source, 'amount' => (string) $app->amount];
            if ($app->target_invoice_id !== null) {
                $key = (string) \App\Modules\Sales\Models\SalesInvoice::acrossBranches()->whereKey($app->target_invoice_id)->value('public_id');
                $bills[$key] = bcadd($bills[$key] ?? '0', (string) $app->amount, 4);
            }
        }
        foreach ($bills as $id => $amount) {
            $chosen[] = ['id' => (string) $id, 'amount' => $amount];
        }
    } elseif ($invoice !== null) {
        $chosen[] = ['id' => (string) $invoice->public_id, 'amount' => $invoice->dueAmount()];
    }

    $amount = old('amount', $isNew ? ($invoice !== null ? $money($invoice->dueAmount()) : '') : $money($collection->amount));
    $differenceKind = old('difference.kind', $collection->difference_kind);
    $differenceAmount = old('difference.amount', $collection->difference_amount !== null && bccomp((string) $collection->difference_amount, '0', 4) > 0 ? $money($collection->difference_amount) : '');

    $roundingLimit = \App\Core\Support\Money::format((string) app(\App\Core\Services\SettingsService::class)
        ->get('sales.collection.rounding_limit', \App\Modules\Sales\Services\CollectionService::ROUNDING_LIMIT), 0);

    $monthOpen = $open['month_open'] ?? app(\App\Core\Services\OpenPeriod::class)->isOpen(today());
    $branchName = $open['customer']['branch'] ?? null;
    $maker = $isNew ? auth()->user()?->name : $collection->creator?->name;
    $state = ! $isNew && in_array((string) $collection->status, \App\Core\Support\DocumentStatus::POSTED, true) ? 'posted' : 'draft';

    // ⓘ অন্য পক্ষের টাকা নেওয়া এখনো হিসাবের রসিদ ভাউচারে — বোতামগুলো সেখানেই নেয় (দফা ২-এর পক্ষের ধরন)
    $receiptUrl = Route::has('accounts.voucher.create') && auth()->user()?->can('accounts.voucher.create')
        ? route('accounts.voucher.create', ['type' => \App\Modules\Accounts\Models\Voucher::RECEIPT]) : null;

    $config = [
        'customerId' => (string) old('customer_id', $invoice?->customer_id ?? $collection->customer_id ?? ''),
        'open' => $open,
        'lines' => $chosen,
        'amount' => (string) $amount,
        'vds' => (string) old('vds', $isNew ? '' : $money(bccomp((string) $collection->vds, '0', 4) > 0 ? $collection->vds : null)),
        'ait' => (string) old('ait', $isNew ? '' : $money(bccomp((string) $collection->ait, '0', 4) > 0 ? $collection->ait : null)),
        'discount' => $differenceKind === 'discount' ? (string) $differenceAmount : '',
        'rounding' => $differenceKind === 'rounding' ? (string) $differenceAmount : '',
        'method' => (string) old('instrument', $collection->instrument ?? 'cash'),
        'account' => (string) old('account_id', $collection->account_id ?? ''),
        'monthOpen' => (bool) $monthOpen,
        'branch' => $branchName,
        'urls' => [
            'openItems' => route('sales.collection.open_items'),
            'preview' => route('sales.collection.preview'),
        ],
        'words' => [
            'limit' => __('sales::collection_form.credit_limit'),
            'noLimit' => __('sales::collection_form.no_limit'),
            'oldest' => __('sales::collection_form.oldest'),
            'days' => __('sales::collection_form.days'),
            'chips' => [
                'credit_note' => __('sales::collection_form.chip_credit_note'),
                'debit_note' => __('sales::collection_form.chip_debit_note'),
                'credit' => __('sales::collection_form.chip_credit'),
            ],
        ],
    ];
@endphp

<x-layouts.app :menu="$menu" :two-column-form="true">
    <x-slot:title>{{ $isNew ? __('sales::collection_form.title') : $collection->document_no }}</x-slot:title>

    {{-- ⓘ cb-র পদ্ধতির ব্লক `inline` — তার ঘরগুলো সাধারণ তথ্যের চার কলামের গ্রিডেই বসে: পদ্ধতি আর "যে খাতে জমা" এক সারিতে, পদ্ধতির
         চার ঘর (মোড · রেফারেন্স · মূল্য-তারিখ · চার্জ) নিচের এক সারিতে, নকশার মতো। ⛔ আগে মোড়কটা `display: contents` করা ছিল — তাতে
         ব্লকের নিজের @container হারাত, আর চার ঘর এক-একটা পুরো সারিতে নামত (১৩৬৬-এ মাপা, ২৫ অক্টোবর ২০২৬)।
         ⓘ <fieldset>-এর ব্রাউজারের নিজের min-inline-size = ভিতরের মাপ: পাঁচ চিপ ৩৬৩px, অর্ধেক সারি ৩৩৫px — তাই fieldset পাশের খাতের ঘরে
         ৩২px ঢুকত ("চেক" ঢাকা পড়ত)। এখানে min-width 0, আর চিপের পাশের ফাঁক একটু কম — পাঁচটাই অর্ধেক সারিতে ধরে, কিছু কাটে না। --}}
    <style @nonce>
        [data-collection-general] [data-money-method] > fieldset { min-width: 0; }
        [data-collection-general] [data-money-method] > fieldset [role="radiogroup"] > label > span { padding-inline: 0.4rem; }
    </style>

    <form method="POST" enctype="multipart/form-data" data-collection-form
          action="{{ $isNew ? route('sales.collection.store') : route('sales.collection.update', $collection) }}"
          data-confirm-overview="{{ route('sales.collection.overview_new') }}"
          x-data="collectionForm(@js($config))"
          @money-method.window="moneyChanged($event.detail)"
          class="flex flex-col gap-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="lines_given" value="1">

        {{-- ── মাথা: নাম আর অবস্থা, এক লাইনের তথ্য, ডানে তিন অঙ্ক (দফা ১, ১৩) ── --}}
        <header data-collection-head class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @php($tag = \App\Core\Support\PageHeading::tag())
                    <{{ $tag }} class="text-xl font-semibold">{{ __('sales::collection_form.title') }}</{{ $tag }}>
                    <x-ui.paper-state :state="$state" />
                </div>
                <p class="mt-1 text-sm text-(--color-ink-muted)" data-collection-facts>
                    {{ __('sales::collection_form.number') }}: {{ $isNew ? __('sales::collection_form.number_on_save') : $collection->document_no }}
                    · {{ __('sales::collection_form.made_by') }}: {{ $maker ?? '—' }}
                    · {{ __('sales::collection_form.branch') }}: {{ __('sales::collection_form.customers_branch') }}<span data-customer-branch x-text="branchText">@if ($branchName) ({{ $branchName }})@endif</span>
                    · <span data-month-open x-show="monthOpen">{{ __('sales::collection_form.month_open') }}</span><span data-month-closed x-show="!monthOpen" x-cloak class="text-(--color-danger)">{{ __('sales::collection_form.month_closed') }}</span>
                </p>
            </div>

            <dl class="flex flex-wrap gap-6" data-collection-figures>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.fig_due') }}</dt>
                    <dd class="num text-xl font-semibold text-(--color-danger)">৳<span data-fig-due x-text="dueText">0.00</span></dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.fig_this') }}</dt>
                    <dd class="num text-xl font-semibold text-(--color-success)">৳<span data-fig-this x-text="thisText">0.00</span></dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.fig_left') }}</dt>
                    <dd class="num text-xl font-semibold">৳<span data-fig-left x-text="leftText">0.00</span></dd>
                </div>
            </dl>
        </header>

        <x-ui.errors />

        {{-- ⭐ নকশার দুই কলাম মালিকের ১৩৬৬-এর পর্দা থেকেই (মালিক আর fe, ২৪ অক্টোবর ২০২৬, ছবি ১৩): ১৫৩৬-এর নিচে মেনু রেলে বা
             ভাসমান (`two-column-form`, [[sidebar-state.js]]; ছাঁচ [[ui.money-form]]), আর রেলের পাশে বিলের টেবিল ঠিক ধরে (৬৮৫/৬৮৫px, আসল ব্রাউজারে মাপা) --}}
        <x-ui.money-form>
            {{-- ⭐ সারাংশ-পট্টি — ১৩৬৬-এর নিচে এক কলামে পুরো সারাংশ নিচে নামে, তাই উপরে ছোট পট্টি, আটকানো; কোনো পর্দায় সারাংশ
                 চোখের বাইরে যায় না (fe, ২৪ অক্টোবর ২০২৬)। দুই কলামে লুকানো — তখন ডান কলামের পুরো সারাংশ চোখের সামনেই। --}}
            <x-slot:strip>
                <span>{{ __('sales::collection_form.strip.received') }} <span class="num font-semibold" x-text="receivedText"></span></span>
                <span aria-hidden="true">·</span>
                <span>{{ __('sales::collection_form.strip.applied') }} <span class="num font-semibold" x-text="appliedText"></span></span>
                <span aria-hidden="true">·</span>
                <span>{{ __('sales::collection_form.strip.unapplied') }} <span class="num font-semibold" x-text="unappliedText"></span></span>
                <span aria-hidden="true">·</span>
                <span class="font-semibold text-(--color-success)" x-show="balanced">{{ __('sales::collection_form.strip.balanced') }}</span>
                <span class="font-semibold text-(--color-danger)" x-show="unbalanced" x-cloak>{{ __('sales::collection_form.strip.unbalanced') }}</span>
            </x-slot:strip>

            {{-- ════ বাঁ কলাম ════ (ছাঁচের মূল slot) --}}

                {{-- ── সাধারণ তথ্য (দফা ১, ২) ── --}}
                <section data-boxed data-collection-general aria-labelledby="cf-general"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 id="cf-general" class="mb-3 font-semibold">{{ __('sales::collection_form.general') }}</h2>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {{-- পক্ষের ধরন — এই পর্দা গ্রাহকের; বাকি তিনটা হিসাবের রসিদ ভাউচারে নেয় --}}
                        <fieldset class="sm:col-span-2 lg:col-span-4" data-party-kind>
                            <legend class="mb-1 block text-sm font-medium">
                                {{ __('sales::collection_form.party_kind') }}
                                <span class="text-(--color-danger)" aria-hidden="true">*</span>
                            </legend>
                            <div class="inline-flex flex-wrap overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                                <span aria-current="page" data-party-kind-chip="customer"
                                      class="border-e border-(--color-border) bg-(--color-brand-600) px-3 py-2 text-sm font-medium text-white">{{ __('sales::collection_form.kind_customer') }}</span>
                                @foreach (['supplier' => 'kind_supplier', 'employee' => 'kind_employee', 'other' => 'kind_other'] as $kind => $word)
                                    @if ($receiptUrl)
                                        <a href="{{ $receiptUrl }}" data-party-kind-chip="{{ $kind }}"
                                           class="border-e border-(--color-border) px-3 py-2 text-sm text-(--color-ink-body) last:border-e-0 hover:bg-(--color-surface-hover)">{{ __('sales::collection_form.'.$word) }}</a>
                                    @else
                                        <span data-party-kind-chip="{{ $kind }}" aria-disabled="true"
                                              class="border-e border-(--color-border) px-3 py-2 text-sm text-(--color-ink-muted) last:border-e-0">{{ __('sales::collection_form.'.$word) }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="sm:col-span-2">
                            {{-- ⭐ খোঁজা যায় এমন পিকার, ভাউচারের পক্ষ-খোঁজার একই (fe, ২৪ অক্টোবর ২০২৬): নাম উপরে, তার নিচে ছোট লাইনে
                                 পয়েন্ট · শাখা — মালিকের ১৯ অক্টোবরের নিয়ম থাকে, আর দুই কলামে তীরের নিচে কিছু কাটে না। Tab, তীর, Enter, Esc
                                 চলে; পর্দা-পাঠক নাম, পয়েন্ট আর শাখা পড়ে। পাঠানো মান আগের মতো `customer_id`; বাছলে hidden ঘর `change` ছাড়ে,
                                 তাই `pickCustomer` আগের পথেই খবর পায়। --}}
                            <x-ui.party-search name="customer_id" id="cf-customer" :label="__('sales::collection_form.customer')"
                                               :options="$customerRows" :selected="$config['customerId']" required stacked
                                               @change="pickCustomer($event.target.value)" />
                            <p class="mt-1 text-2xs text-(--color-ink-muted)" data-customer-facts x-text="facts"></p>
                        </div>

                        <x-ui.field name="trx_date" id="cf-date" type="date" :label="__('sales::collection_form.date')"
                                    :value="old('trx_date', $collection->trx_date?->toDateString() ?? now()->toDateString())"
                                    required />

                        <div>
                            {{-- ⭐ টাকা লাখ-কমায় আর দুই দশমিকে ("45,000.00", নকশার মতো) — দেখানোর ঘর আর কাঁচা অঙ্কের লুকানো ঘর আলাদা
                                 (cb-র type="money", fe, ২৪ অক্টোবর ২০২৬; আদায়, ভাউচার আর পরিশোধ একসাথে) --}}
                            <x-ui.field name="amount" id="cf-amount" type="money" model="amount" :label="__('sales::collection_form.amount')"
                                        required :value="$amount" @input="changedSoon()" />
                            <p class="mt-1 text-2xs text-(--color-ink-muted)">
                                <button type="button" class="text-(--color-link) hover:underline" @click="fillDue()" data-fill-due>{{ __('sales::collection_form.full_due') }}</button>
                                ·
                                <button type="button" class="text-(--color-link) hover:underline" @click="fillChosen()" data-fill-chosen>{{ __('sales::collection_form.chosen_bills') }}</button>
                            </p>
                        </div>

                        {{-- ⭐ cb-র পদ্ধতির ব্লক (দফা ২, ৯) — নগদ · MFS · ব্যাংক ট্রান্সফার · নগদ জমা · চেক; প্রতিটার কেবল নিজের ঘর।
                             ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe; রসিদ-পরিশোধের একই নিয়ম): "যে খাতে
                             জমা" এখন এই ব্লকের ভিতরে, পদ্ধতির ঠিক পরে; ⛔ আগে খাত আগে ছিল, আর বিকাশের খাত বেছে "নগদ" চাপা যেত। সার্ভারও
                             একই কথা বলে ([[MoneyMethod::assertFits()]], [[CollectionService]])। ঘর `x-model="account"` — [[collectionForm]] শোনে --}}
                        <x-money.method inline direction="in" :record="$isNew ? null : $collection" :modes="$transferModes"
                                        :methods="['cash', 'mfs', 'transfer', 'deposit', 'cheque']"
                                        :accounts="$accountRows" account-name="account_id" account-model="account"
                                        :account-label="__('sales::collection_form.into')"
                                        :account-selected="old('account_id', $collection->account_id)"
                                        charge-field="bank_charge" />

                        {{-- ⓘ order-3 — ব্লকের ঘরগুলোর (order-3) ঠিক পরে, রেফারেন্সের নিচে; চার্জের লাইন (order-4) তার পরে --}}
                        <p class="order-3 whitespace-nowrap text-2xs text-(--color-ink-muted) lg:col-start-2" data-reference-free
                           x-show="showsReferenceFree" x-cloak>{{ __('sales::collection_form.reference_free') }}</p>
                    </div>

                    <p role="status" data-over-due x-show="over" x-cloak
                       class="mt-3 rounded-(--radius-field) border-s-2 border-(--color-warning) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
                        {{ __('sales::collection_form.over_due') }}
                    </p>
                </section>

                {{-- ── খোলা বিল ও ক্রেডিট (দফা ৩) ── --}}
                <section data-boxed data-open-items aria-labelledby="cf-open"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="cf-open" class="font-semibold">{{ __('sales::collection_form.open_title') }}</h2>
                        <p class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.open_hint') }}</p>
                    </div>

                    <div class="table-responsive">
                        <table class="ui-grid" data-open-items-table>
                            <thead>
                                <tr>
                                    <th scope="col"><span class="sr-only">{{ __('sales::collection_form.col_tick') }}</span></th>
                                    <th scope="col">{{ __('sales::collection_form.col_paper') }}</th>
                                    <th scope="col">{{ __('sales::collection_form.col_date') }}</th>
                                    <th scope="col">{{ __('sales::collection_form.col_due_date') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_late') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_amount') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_open') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_apply') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, i) in items" :key="row.id">
                                    <tr data-open-item>
                                        <td>
                                            <input type="checkbox" x-model="row.on" @change="touched(row)" :aria-label="row.no"
                                                   class="size-4 accent-(--color-brand-600)">
                                        </td>
                                        <td>
                                            <a :href="row.url" x-text="row.no" :id="'cf-' + i + '-paper'" class="text-(--color-link) hover:underline"></a>
                                            <span x-show="row.chip !== ''" x-text="row.chip"
                                                  class="ms-1 rounded bg-(--color-badge-info-bg) px-1.5 text-2xs text-(--color-badge-info-ink)"></span>
                                            <input type="hidden" :name="'lines[' + i + '][kind]'" :value="row.kind" :disabled="!row.on">
                                            <input type="hidden" :name="'lines[' + i + '][id]'" :value="row.id" :disabled="!row.on">
                                            <input type="hidden" :name="'lines[' + i + '][amount]'" :value="sent(row)" :disabled="!row.on">
                                        </td>
                                        <td x-text="row.date"></td>
                                        <td x-text="row.dueDate"></td>
                                        <td class="num whitespace-nowrap" :class="row.lateOn ? 'font-semibold text-(--color-danger)' : ''" x-text="row.late"></td>
                                        <td class="num" x-text="row.amountText"></td>
                                        <td class="num" x-text="row.openText"></td>
                                        <td class="num">
                                            <input type="text" inputmode="decimal" x-model="row.apply" @input="typed()" @blur="tidyApply(row)" :disabled="!row.on"
                                                   aria-label="{{ __('sales::collection_form.col_apply') }}" :id="'cf-' + i + '-apply'"
                                                   :aria-labelledby="'cf-' + i + '-apply' + ' ' + 'cf-' + i + '-paper'"
                                                   class="num h-(--spacing-field-compact) w-32 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="items.length === 0">
                                    <td colspan="8" class="text-sm text-(--color-ink-muted)">
                                        <span x-show="customerId === ''">{{ __('sales::collection_form.pick_customer') }}</span>
                                        <span x-show="customerId !== ''">{{ __('sales::collection_form.no_items') }}</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td></td>
                                    <td colspan="5" class="font-semibold">{{ __('sales::collection_form.total_apply') }}</td>
                                    <td class="num font-semibold" x-text="openTotalText"></td>
                                    <td class="num font-semibold" data-total-applied x-text="appliedText"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                {{-- ── সংযুক্তি ও বিবরণ (দফা ১৫) ── --}}
                <section data-boxed aria-labelledby="cf-attach"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 id="cf-attach" class="mb-3 font-semibold">{{ __('sales::collection_form.attach_title') }}</h2>

                    <div class="grid gap-3 sm:grid-cols-2">
                        {{-- ⓘ বাংলা ফাইল-বাছাই ([[ui.file-input]]): ব্রাউজারের "Choose File" নয়, বাছা ফাইলের নাম পাশে (AFileIsChosenInBengaliTest) --}}
                        <div data-slip-drop>
                            <span class="mb-1 block text-sm text-(--color-ink-muted)">{{ __('sales::collection_form.drop') }}</span>
                            <x-ui.file-input name="slip" id="cf-slip" accept="image/jpeg,image/png,image/webp,application/pdf" />
                        </div>

                        <x-ui.field name="narration" id="cf-narration" :label="__('sales::collection_form.narration')"
                                    :value="old('narration', $collection->narration)" />
                    </div>
                </section>

            {{-- ════ ডান কলাম ════ — দুই কলামে আটকানো: বিল টিক দেওয়ার সময় সারাংশ চোখের সামনে (fe, ২৪ অক্টোবর ২০২৬)।
                 ⓘ পর্দার চেয়ে লম্বা হলে নিজের ভিতরে সরে — নইলে আটকানো কলামের নিচের নিয়ন্ত্রণ পাতার শেষ না আসা পর্যন্ত দেখা যেত না --}}
            <x-slot:right>

                {{-- ── হিসাবের সারাংশ (দফা ৪, ৫) ── --}}
                <section data-boxed data-collection-summary aria-labelledby="cf-summary"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 id="cf-summary" class="mb-3 font-semibold">{{ __('sales::collection_form.summary_title') }}</h2>

                    <dl class="flex flex-col gap-2 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt>{{ __('sales::collection_form.summary.received') }}</dt>
                            <dd class="num" data-received x-text="receivedText"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt>
                                <label for="cf-vds">{{ __('sales::collection_form.summary.vds') }}</label>
                                <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 text-2xs text-(--color-badge-warning-ink)">{{ __('sales::collection_form.summary.vds_chip') }}</span>
                            </dt>
                            <dd><input type="hidden" name="vds" :value="vds"><input id="cf-vds" type="text" inputmode="decimal" x-money-display="vds" data-money-empty="0.00" @input="changedSoon()" placeholder="0.00"
                                       class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt><label for="cf-ait">{{ __('sales::collection_form.summary.ait') }}</label></dt>
                            <dd><input type="hidden" name="ait" :value="ait"><input id="cf-ait" type="text" inputmode="decimal" x-money-display="ait" data-money-empty="0.00" @input="changedSoon()" placeholder="0.00"
                                       class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt>
                                <label for="cf-discount">{{ __('sales::collection_form.summary.discount') }}</label>
                                <span class="ms-1 rounded bg-(--color-badge-info-bg) px-1.5 text-2xs text-(--color-badge-info-ink)" data-owner-signs>{{ __('sales::collection_form.summary.owner_chip') }}</span>
                            </dt>
                            <dd><input id="cf-discount" type="text" inputmode="decimal" x-money-display="discount" data-money-empty="0.00" @input="changedSoon()" placeholder="0.00"
                                       class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt>
                                <label for="cf-rounding">{{ __('sales::collection_form.summary.rounding', ['limit' => $roundingLimit]) }}</label>
                                {{-- ⓘ ৳0.50-এর বেশি রাউন্ডিংয়ে মালিকের সই (মালিক, ১ অক্টোবর ২০২৬) — তবেই চিপ, নকশার লেবেল অক্ষত --}}
                                <span x-show="roundingSigned" x-cloak data-rounding-signs
                                      class="ms-1 rounded bg-(--color-badge-info-bg) px-1.5 text-2xs text-(--color-badge-info-ink)">{{ __('sales::collection_form.summary.rounding_chip') }}</span>
                            </dt>
                            <dd><input id="cf-rounding" type="text" inputmode="decimal" x-money-display="rounding" data-money-empty="0.00" @input="changedSoon()" placeholder="0.00"
                                       class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end"></dd>
                        </div>
                        {{-- ⓘ সার্ভার একটাই পার্থক্য নেয় — ছাড় বা রাউন্ডিং ([[CollectionInput]]) --}}
                        <input type="hidden" name="difference[kind]" :value="diffKind">
                        <input type="hidden" name="difference[amount]" :value="diffAmount">

                        <div class="flex items-center justify-between gap-3 border-t-2 border-(--color-ink-body) pt-2 font-semibold">
                            <dt>{{ __('sales::collection_form.summary.applied') }}</dt>
                            <dd class="num" x-text="appliedText"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt>{{ __('sales::collection_form.summary.unapplied') }}</dt>
                            <dd class="num" data-unapplied x-text="unappliedText"></dd>
                        </div>
                    </dl>

                    <p role="status" data-balanced x-show="balanced"
                       class="mt-3 rounded-(--radius-field) border-s-4 border-(--color-success) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
                        {{ __('sales::collection_form.summary.balanced') }}
                    </p>
                    <p role="alert" data-unbalanced x-show="short" x-cloak
                       class="mt-3 rounded-(--radius-field) border-s-4 border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
                        {{ __('sales::collection_form.summary.unbalanced') }}
                    </p>
                    <p role="alert" data-both x-show="both" x-cloak
                       class="mt-3 rounded-(--radius-field) border-s-4 border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
                        {{ __('sales::collection_form.summary.both') }}
                    </p>
                </section>

                {{-- ── খাতায় যা যাবে (দফা ১০) — সার্ভারের প্রিভিউ, পোস্টের একই লাইন ── --}}
                <section data-boxed data-ledger-preview aria-labelledby="cf-ledger"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <div class="mb-3 flex items-baseline justify-between gap-2">
                        <h2 id="cf-ledger" class="font-semibold">{{ __('sales::collection_form.ledger_title') }}</h2>
                        <p class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.ledger_hint') }}</p>
                    </div>

                    <div class="table-responsive">
                        <table class="ui-grid" data-ledger-table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sales::collection_form.col_account') }}</th>
                                    <th scope="col">{{ __('sales::collection_form.col_branch') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_dr') }}</th>
                                    <th scope="col" class="num">{{ __('sales::collection_form.col_cr') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, n) in ledger" :key="n">
                                    <tr>
                                        <td x-text="line.account"></td>
                                        <td x-text="line.branch"></td>
                                        <td class="num" x-text="line.dr"></td>
                                        <td class="num" x-text="line.cr"></td>
                                    </tr>
                                </template>
                                <tr x-show="ledger.length === 0">
                                    <td colspan="4" class="text-sm text-(--color-ink-muted)">{{ __('sales::collection_form.ledger_empty') }}</td>
                                </tr>
                            </tbody>
                            <tfoot x-show="ledger.length > 0">
                                <tr>
                                    <td colspan="2" class="font-semibold">{{ __('sales::collection_form.total') }}</td>
                                    <td class="num font-semibold" x-text="ledgerDr"></td>
                                    <td class="num font-semibold" x-text="ledgerCr"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <ul role="alert" data-preview-stops x-show="stops.length > 0" x-cloak class="mt-3 flex flex-col gap-1 text-sm text-(--color-danger)">
                        <template x-for="(stop, s) in stops" :key="s">
                            <li x-text="stop"></li>
                        </template>
                    </ul>
                    <p role="alert" x-show="previewFailed" x-cloak class="mt-3 text-sm text-(--color-danger)">{{ __('sales::collection_form.preview_failed') }}</p>
                </section>

                {{-- ── নিয়ন্ত্রণ (দফা ১১, ১২, ৮) ── --}}
                <section data-boxed data-collection-controls aria-labelledby="cf-controls"
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 id="cf-controls" class="mb-3 font-semibold">{{ __('sales::collection_form.controls_title') }}</h2>
                    <p class="rounded-(--radius-field) border-s-4 border-(--color-brand-500) bg-(--color-badge-info-bg) px-3 py-2 text-sm text-(--color-badge-info-ink)"
                       x-show="postsNow" data-controls-company>
                        {{ $collectionSigned ? __('sales::collection_form.controls_on') : __('sales::collection_form.controls_off') }}
                    </p>
                    {{-- ⓘ ছাড় বা ৳0.50-এর বেশি রাউন্ডিং — তখন "সই বন্ধ, সাথে সাথে খাতায়" কথাটা ভুল; কাগজ মালিকের সইয়ে যায় (fe, ২৪ অক্টোবর ২০২৬) --}}
                    <p class="rounded-(--radius-field) border-s-4 border-(--color-warning) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)"
                       x-show="ownerSigns" x-cloak data-controls-owner-signs>
                        {{ __('sales::collection_form.controls_owner_signs') }}
                    </p>
                    <p class="mt-2 flex items-center gap-1.5 text-sm" data-controls-bounce>
                        <x-ui.icon name="undo" :size="16" />
                        <span>{{ __('sales::collection_form.controls_bounce') }}</span>
                    </p>
                </section>
            </x-slot:right>

            {{-- ── নিচের স্থির পট্টি: বাতিল · খসড়া রাখুন · পোস্ট; পোস্টের পরে রসিদ (দফা ১৭) ── --}}
            <x-slot:bar>
            <x-ui.form-actions :cancel="route('sales.collection.index')">
                <p class="text-xs text-(--color-ink-muted)">{{ __('sales::collection_form.after_post') }}</p>

                <x-slot:submit>
                    <x-ui.button type="submit" tone="secondary" name="save_as_draft" value="1" data-collection-draft>
                        {{ __('sales::collection_form.draft') }}
                    </x-ui.button>
                    <x-ui.button type="submit" tone="primary" name="post" value="1" data-overview-trigger data-collection-post>
                        <span x-show="postsNow">{{ __('sales::collection_form.post') }}</span>
                        {{-- ⓘ একই দরজা, একই "post" — সার্ভার খসড়া রেখে সই চায় ([[CollectionService::assertDiscountSigned()]]); কেবল কথাটা সত্যি --}}
                        <span x-show="ownerSigns" x-cloak data-collection-post-for-signature>{{ __('sales::collection_form.post_for_signature') }}</span>
                    </x-ui.button>
                </x-slot:submit>
            </x-ui.form-actions>
            </x-slot:bar>
        </x-ui.money-form>
    </form>

    <x-ui.confirm-overview :draft="true" />
</x-layouts.app>
