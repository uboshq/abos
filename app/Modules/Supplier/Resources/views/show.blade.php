{{--
    একজন সরবরাহকারী।

    প্রদেয়ের অঙ্কটা নিছক একটা সংখ্যা নয় — নিয়ম ১ বলে প্রতিটা অঙ্ক থেকে
    তার উৎসে যাওয়া যাবে। তাই নিচে সেই লেনদেনগুলোই দেখানো হয় যেগুলো যোগ
    হয়ে অঙ্কটা হয়েছে। খোলা ব্যালেন্সও আলাদা সারি, কারণ সেটা কোনো ডকুমেন্ট
    থেকে আসেনি — সেটা না বললে যোগফল মেলে না।

    এখানে ডেবিট/ক্রেডিট কলাম দুইটা গ্রাহকের পাতার মতোই, কিন্তু ব্যালেন্স
    ক্রেডিট-ধনাত্মক: সরবরাহকারীর ঘরে "৫,০০০" মানে আমরা তাকে দেব।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $supplier->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$supplier->name()" :subtitle="$supplier->code">
            <x-slot:actions>
                {{-- ⭐ 👁 — বাকি সব (বকেয়া, পরিচয়, আচরণ, পোর্টাল) এক পপ-আপে; পাতায় খোলা থাকে কেবল লেনদেন (মালিক, ৩ অক্টোবর ২০২৬) --}}
                <x-ui.button tone="secondary" type="button" x-data @click="$dispatch('party-eye')" data-party-eye>
                    <x-ui.icon name="eye" :size="16" /> {{ __('supplier::action.details') }}
                </x-ui.button>
                {{-- ⭐ পাওনা-দেনা নিশ্চিতকরণের চিঠি (ISA 505, ২০ অক্টোবর ২০২৬) — পাতার দেনার খাতার একই জের, A4 --}}
                <x-ui.button tone="secondary" icon="print" :href="route('supplier.balance_confirmation', $supplier)" data-balance-confirmation>
                    {{ __('confirmation.button') }}
                </x-ui.button>
                @can('update', $supplier)
                    <x-ui.button tone="secondary" :href="route('supplier.edit', $supplier)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('delete', $supplier)
                    @if ($supplier->is_active)
                        <form method="POST" action="{{ route('supplier.destroy', $supplier) }}"
                              data-confirm="{{ __('supplier::message.deactivate_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('supplier::action.deactivate') }}
                            </x-ui.button>
                        </form>
                    @else
                        {{-- ফেরার পথ থাকতেই হবে: না থাকলে ভুল করে বন্ধ
                             করা সরবরাহকারীর জন্য কেউ দ্বিতীয় রেকর্ড খুলত,
                             আর তখন একই প্রতিষ্ঠানের দুইটা আলাদা বকেয়া। --}}
                        <form method="POST" action="{{ route('supplier.activate', $supplier) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('supplier::action.activate') }}
                            </x-ui.button>
                        </form>
                    @endif
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    {{-- ⭐ পার্টির পাতায় কেবল লেনদেনের ছক খোলা — বাকিটা 👁 চাপলে এই পপ-আপে (মালিক, ৩ অক্টোবর ২০২৬:
         "লেনদেন টেবিল খোলা, বাকি 👁-এর পেছনে")। ⓘ ঘরগুলো পাতাতেই থাকে, কেবল লুকানো। --}}
    <div x-data="{ open: false }" @party-eye.window="open = true" @keydown.escape.window="open = false">
    <div x-show="open" x-cloak @click.self="open = false" data-party-details
         class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
    <div class="w-full max-w-5xl rounded-(--radius-card) bg-(--color-surface-app) p-4 shadow-lg">
        <div class="mb-3 flex justify-end">
            <button type="button" @click="open = false" class="px-2 text-lg leading-none text-(--color-ink-muted)"
                    aria-label="{{ __('core.action.close') }}">&times;</button>
        </div>
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- প্রদেয় --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">
                {{ __('supplier::field.payable') }}
            </h2>

            {{-- num ক্লাসটা ট্যাবুলার অঙ্ক দেয় — একই প্রস্থে প্রতিটা সংখ্যা,
                 তাই দুই অঙ্ক পাশাপাশি রাখলে দশমিক বিন্দু এক লাইনে থাকে। --}}
            {{-- অঙ্কটাই লিংক — নিচের টেবিলে ঠিক সেই লেনদেনগুলো আছে
                 যেগুলো যোগ হয়ে এই সংখ্যাটা হয়েছে (নিয়ম ১) --}}
            <p class="mt-1 text-2xl font-semibold">
                {{-- ⭐ (Cr) = আমরা দেব, (Dr) = আগাম দেওয়া — মালিক, ৩ অক্টোবর ২০২৬; লেজারের ছকের একই দিক --}}
                <a href="{{ $part === 'payable' ? '' : request()->fullUrlWithQuery(['part' => 'payable', 'page' => null]) }}#transactions" @click="open = false" class="num" data-balance-drcr>{{ \App\Core\Support\Money::drCr(bcmul((string) $payable, '-1', 4)) }}</a>
            </p>

            {{-- ⭐ বিল-না-আসা মাল — দেনার পাশে আলাদা ভাগ (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬; মালিক: "দুই ভাগে দেখাও")।
                 ⓘ মাল এসেছে, সরবরাহকারীর বিল আসেনি — বিল না আসা পর্যন্ত শোধযোগ্য নয়; অঙ্কটা খাতার দ্বিতীয় ভাগ খোলে।
                 ⓘ যে ভাগ খোলা, তার অঙ্ক কেবল `#transactions` — পাতা আবার না এনে ছকে নামে (নিয়ম ১, [[FigureLinksTest]])। --}}
            <p class="mt-1 text-2xs text-(--color-ink-muted)" data-goods-not-billed>
                {{ __('supplier::field.goods_not_billed') }}:
                <a href="{{ $part === 'goods_not_billed' ? '' : request()->fullUrlWithQuery(['part' => 'goods_not_billed', 'page' => null]) }}#transactions" @click="open = false" class="num">{{ \App\Core\Support\Money::drCr(bcmul((string) $goodsNotBilled, '-1', 4)) }}</a>
            </p>

            {{-- ⭐ এক শাখা বাছা থাকলে ওপরের অঙ্কটা কেবল সেই শাখার (৩০ সেপ্টেম্বর ২০২৬);
                 সীমার সতর্কতা সব শাখা মিলিয়ে, তাই সেটাও এখানে। --}}
            @if ($payableAll !== null)
                <p class="mt-1 text-2xs text-(--color-ink-muted)" data-payable-all-branches>
                    {{ __('supplier::field.payable_all_branches') }}:
                    {{-- ⛔ (Cr) = আমরা দেব, (Dr) = আগাম — ওপরের অঙ্কের একই ভাষা, খালি বিয়োগ নয় ([[AnAdvanceReadAsABareMinusTest]]) --}}
                    <span class="num">{{ \App\Core\Support\Money::drCr(bcmul((string) $payableAll, '-1', 4)) }}</span>
                </p>
            @endif

            @if (bccomp((string) $supplier->credit_limit, '0', 4) > 0)
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('supplier::field.credit_limit') }}:
                    <span class="num">{{ \App\Core\Support\Money::format($supplier->credit_limit) }}</span>
                </p>

                @if ($supplier->isOverTheirLimit())
                    {{-- সতর্কতা, বাধা নয়: সীমাটা তাদের সিদ্ধান্ত। কিন্তু
                         পরের চালান আটকে গেলে ক্রয়কারীর আগে থেকে জানা
                         দরকার, বিলের দিনে নয়। --}}
                    <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-2 py-1
                              text-2xs text-(--color-badge-pending-ink)">
                        {{ __('supplier::message.over_limit') }}
                    </p>
                @endif
            @endif

            @if ($supplier->paymentTerm || $supplier->credit_days > 0)
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('supplier::field.payment_term') }}:
                    {{ $supplier->paymentTerm?->name()
                        ?? $supplier->credit_days.' '.__('supplier::field.credit_days') }}
                </p>
            @endif
        </section>

        {{-- পরিচয় --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('supplier::section.identity') }}</h2>
                @include('supplier::partials.state-badge', ['supplier' => $supplier])
            </div>

            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                @foreach ([
                    'supplier::field.party_type' => $supplier->partyType?->name(),
                    'supplier::field.phone' => $supplier->phone,
                    'supplier::field.email' => $supplier->email,
                    'supplier::field.contact_person' => $supplier->contact_person,
                    'supplier::field.contact_phone' => $supplier->contact_phone,
                    'supplier::field.bin' => $supplier->bin,
                    'supplier::field.tin' => $supplier->tin,
                    'supplier::field.branch' => $supplier->branch?->name(),
                    'supplier::field.address' => $supplier->address(),
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        {{-- ⭐ ব্যাংক তথ্য — দুই হাতে (নকশা দফা #৬, ২১ অক্টোবর ২০২৬; [[SupplierBank]])।
             ⓘ চালু = সই-পাওয়া সারি; বদল চাইলে "সইয়ের অপেক্ষায়", আর অন্য একজন সই না দেওয়া পর্যন্ত পুরনোটাই চালু।
             ⓘ হিসাব নম্বর পুরো কেবল `supplier.bank.view`-এ, নাহলে ••••শেষ চার — কন্ট্রোলার ঠিক করে ([[SupplierBank::accountShown()]])। --}}
        <section data-boxed data-supplier-bank class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-3">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('supplier::bank.title') }}</h2>
                @if ($bankPending)
                    <x-ui.badge tone="pending" data-bank-pending>{{ __('supplier::bank.pending_badge') }}</x-ui.badge>
                @endif
            </div>

            @if ($bankSigned)
                <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-3" data-bank-active>
                    @foreach ([
                        'supplier::bank.bank_name' => $bankSigned->bank_name,
                        'supplier::bank.branch_name' => $bankSigned->branch_name,
                        'supplier::bank.account_name' => $bankSigned->account_name,
                        'supplier::bank.account_no' => $bankSignedAccount,
                        'supplier::bank.routing_no' => $bankSigned->routing_no,
                    ] as $label => $value)
                        @if (filled($value))
                            <div>
                                <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                                <dd class="text-sm">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    <x-ui.badge tone="success">{{ __('supplier::bank.verified') }}</x-ui.badge>
                    {{ __('supplier::bank.signed_by_line', ['maker' => $bankSigned->requester?->name ?? '—', 'signer' => $bankSigned->signer?->name ?? '—']) }}
                </p>
            @else
                <p class="text-sm text-(--color-ink-muted)" data-bank-none>{{ __('supplier::bank.none') }}</p>
            @endif

            @if ($bankPending)
                <div class="mt-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) p-3 text-sm text-(--color-badge-pending-ink)" data-bank-request>
                    <p class="font-medium">{{ __('supplier::bank.pending_line', ['who' => $bankPending->requester?->name ?? '—']) }}</p>
                    <p>
                        {{ collect([$bankPending->bank_name, $bankPending->branch_name, $bankPending->account_name, $bankPendingAccount, $bankPending->routing_no])->filter()->implode(' · ') }}
                    </p>
                    @if (filled($bankPending->reason))
                        <p class="text-2xs">{{ __('supplier::bank.reason') }}: {{ $bankPending->reason }}</p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-end gap-2">
                        @if ($bankCanSign)
                            <form method="POST" action="{{ route('supplier.bank.sign', [$supplier, $bankPending]) }}">
                                @csrf
                                <x-ui.button type="submit" tone="success" data-bank-sign>{{ __('supplier::bank.sign') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('supplier.bank.reject', [$supplier, $bankPending]) }}" class="flex items-end gap-2">
                                @csrf
                                <x-ui.field name="note" :label="__('supplier::bank.note')" required />
                                <x-ui.button type="submit" tone="secondary" data-bank-reject>{{ __('supplier::bank.reject') }}</x-ui.button>
                            </form>
                        @endif
                        @if ($bankIsMaker)
                            <form method="POST" action="{{ route('supplier.bank.withdraw', [$supplier, $bankPending]) }}">
                                @csrf
                                <x-ui.button type="submit" tone="secondary" data-bank-withdraw>{{ __('supplier::bank.withdraw') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @can('update', $supplier)
                <form method="POST" action="{{ route('supplier.bank.store', $supplier) }}" class="mt-4" data-bank-request-form>
                    @csrf
                    <h3 class="mb-1 text-sm font-semibold">{{ __('supplier::bank.request_title') }}</h3>
                    <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('supplier::bank.request_hint') }}</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-ui.field name="bank_name" :value="old('bank_name')" :label="__('supplier::bank.bank_name')" required />
                        <x-ui.field name="branch_name" :value="old('branch_name')" :label="__('supplier::bank.branch_name')" />
                        <x-ui.field name="account_name" :value="old('account_name')" :label="__('supplier::bank.account_name')" required />
                        <x-ui.field name="account_no" :label="__('supplier::bank.account_no')" required />
                        <x-ui.field name="routing_no" :value="old('routing_no')" :label="__('supplier::bank.routing_no')" />
                        <x-ui.field name="reason" :value="old('reason')" :label="__('supplier::bank.reason')" />
                    </div>
                    <div class="mt-3">
                        <x-ui.button type="submit" tone="secondary">{{ __('supplier::bank.request') }}</x-ui.button>
                    </div>
                </form>
            @endcan
        </section>
    </div>

    {{-- লেনদেন — অঙ্কটা কোথা থেকে এল (নিয়ম ১) --}}
    @php
        /*
         * খতিয়ানের কলামগুলো একবার লেখা — টুলবার আর ছক দুইজনেই পড়ে।
         *
         * ⛔ আগে এগুলো `x-ui.table`-এর ভিতরে ইনলাইন ছিল, আর সেজন্যই
         * টুলবারের "কলাম" মেনুটা এখানে বসানোই যেত না: সে তালিকাটা
         * হাতে পায় না বলে **কিছুই দেখাত না**, আর নামমাত্র একটা বোতাম
         * হয়ে থাকত।
         */
        $ledgerColumns = [
            ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
             'render' => fn ($e) => \App\Core\Support\DateFormat::format($e->trx_date)],
            ['key' => 'document', 'label' => __('core.table.document'),
             'render' => fn ($e) => view('supplier::partials.entry-source', ['entry' => $e])],
            ['key' => 'narration', 'label' => __('core.table.narration')],
            ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true, 'width' => '8rem',
             'render' => fn ($e) => \App\Core\Support\Money::isZero($e->debit) ? '' : \App\Core\Support\Money::format($e->debit)],
            ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true, 'width' => '8rem',
             'render' => fn ($e) => \App\Core\Support\Money::isZero($e->credit) ? '' : \App\Core\Support\Money::format($e->credit)],
            ['key' => 'balance', 'label' => __('core.table.balance'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($e) => \App\Core\Support\Money::drCr($e->net_balance)],
        ];
    @endphp

    </div>
    </div>
    </div>

    <section id="transactions" data-boxed class="scroll-mt-24 mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        {{--
            ⭐ টুলবার — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬:
            *"lal mak kora joygay eta bosabe, same vabe customer e o"*।

            ── ⭐ খোঁজা আর ছাঁকনি — ৩ অক্টোবর ২০২৬ থেকে সত্যি ─────────────
            মালিক: *"ফিল্টার অপশন দিতে হবে সার্চ অপশন দিতে হবে"*। কন্ট্রোলার এখন
            চাবিগুলো পড়ে ([[PartyLedger::filter()]]): নম্বর/বিবরণ/অঙ্ক, তারিখ,
            কাগজের ধরন, কেবল ডেবিট বা ক্রেডিট। ⓘ আগে বোতামগুলো ইচ্ছে করে লুকানো
            ছিল, কারণ তখন কন্ট্রোলার পড়ত না — মৃত বোতামের চেয়ে না থাকা ভালো।
            ⚠️ জের ছাঁকনিতেও খাতার সব লেনদেন থেকে, কখনো শূন্য থেকে নয়।

            ── ⭐ ছাপাটা এখানে লিংক, বোতাম নয় ──────────────────────────
            পর্দায় আজকেরটা উপরে, কাগজে ব্যাংকের খাতার মতো পুরনো আগে।
            ⓘ তাই ছাপার আগে `?ledger=asc` ঠিকানায় যাওয়া হয়, আর সেখানে
            পৌঁছেই ছাপা শুরু হয়।
        --}}
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('supplier::section.transactions')"
                          :search-placeholder="__('party_ledger.search')"
                          :columns="$ledgerColumns"
                          {{-- ℹ এই দুইটা ছাঁকনি নয়, দৃশ্যের অবস্থা — না বললে টুলবার
                               "asc" আর "1" লেখা দুইটা কাঁচা চিপ তুলত, আর সরাতে গেলে
                               কাগজের ক্রমটাই হারাত। --}}
                          :quiet="['ledger', 'print', 'part']"
                          :print-href="request()->fullUrlWithQuery(['ledger' => 'asc', 'print' => 1])">
                {{-- ⭐ খোঁজা আর ছাঁকনি — মালিক, ৩ অক্টোবর ২০২৬; কন্ট্রোলার পড়ে [[PartyLedger::filter()]], জের খাতার সব লেনদেন থেকে --}}
                <x-ui.party-ledger-filters party="supplier" />
            </x-ui.toolbar>
        </form>

        {{-- ⭐ খাতার দুই ভাগ — দেনা (২১১০) আর বিল-না-আসা মাল (GRNI ২১৬০); মালিক, ১০ অক্টোবর ২০২৬: "দুই ভাগে দেখাও"।
             ⓘ প্রতিটা ভাগের জের নিজের সারি থেকে; দুই ভাগের যোগ = সরবরাহকারীর নামের সব সারি। --}}
        <x-ui.list-tabs class="px-4 pt-2" :label="__('supplier::section.ledger_parts')" :tabs="[
            ['key' => 'payable', 'label' => __('supplier::field.payable'), 'active' => $part === 'payable',
             'url' => request()->fullUrlWithQuery(['part' => 'payable', 'page' => null]),
             'count' => \App\Core\Support\Money::drCr(bcmul((string) $payable, '-1', 4))],
            ['key' => 'goods_not_billed', 'label' => __('supplier::field.goods_not_billed'), 'active' => $part === 'goods_not_billed',
             'url' => request()->fullUrlWithQuery(['part' => 'goods_not_billed', 'page' => null]),
             'count' => \App\Core\Support\Money::drCr(bcmul((string) $goodsNotBilled, '-1', 4))],
        ]" />

        <x-ui.table
            :empty="\App\Core\Support\PartyLedger::filtered(request()) ? __('core.empty.no_results') : __('supplier::message.no_transactions')"
            :rows="$entries"
            :compact="request()->boolean('compact')"
            :columns="$ledgerColumns" />

        <x-ui.pager :rows="$entries" />
        <x-ui.list-totals :rows="$entries" />
    </section>

    {{-- ⭐ জোড়া ডকুমেন্ট (ডকুমেন্ট পরিকল্পনা §১৫, ৯ অক্টোবর ২০২৬) — কোরের উপাদান; কিছু না থাকলে কিছুই বসে না --}}
    <x-ui.linked-documents :record="$supplier" />
</x-layouts.app>
