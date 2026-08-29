{{--
    একটা জমার নিজের পাতা — তার তথ্য, যা করা যায়, আর যা হয়েছে।

    ── কেন তিনটা অংশ এই ক্রমে ──────────────────────────────────────────
    উপরে তথ্য, কারণ ফোনে ব্যাংক প্রথমে হিসাব নম্বরটাই চায়। মাঝে কাজ,
    কারণ পাতাটা খোলাই হয় কিছু করতে — কিস্তি দিতে, মুনাফা তুলতে। নিচে
    ইতিহাস, কারণ ওটা পড়া হয় কেবল কিছু না মিললে।
--}}
@php
    $d = $deposit;
    $isOpen = $d->status === \App\Modules\Finance\Models\Deposit::ACTIVE;
    $money = $accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()]);

    /*
     * এই জমায় কি নিয়মিত কিছু করার আছে — কিস্তি বা মুনাফা।
     *
     * মেয়াদান্তে মুনাফার FD-তে নেই, আর তখন কেবল "ভাঙুন" প্যানেলটাই
     * থাকে। দুই কলামের ছক ধরে রাখলে ডান পাশটা ফাঁকা পড়ে থাকত — মালিক
     * ঠিক ওই জিনিসটাই একবার দেখিয়ে বলেছিলেন, *"dane koto jayga faka"*।
     */
    $hasRegularMove = $d->kind->takesInstalments() || $d->kind->paysOut();

    /*
     * ঘরে বসানো অঙ্ক — চারটা দশমিক পড়তে কেউ চায় না।
     *
     * ── কেন `(float)` নয় ────────────────────────────────────────────
     * প্রথমে `number_format((float) $v, 2)` লেখা হয়েছিল, আর
     * [[MoneyIsNeverAFloatTest]] সঙ্গে সঙ্গে লাল হলো। ঠিকই হয়েছে:
     * ৫,০০,০০০.০০ float-এ গিয়ে ফিরে আসতে ৪,৯৯,৯৯৯.৯৯৯৯৯ হতে পারে,
     * আর ওই সংখ্যাটাই ঘরে বসে যেত — তারপর ব্যবহারকারী Save চাপলে
     * খাতায় ওটাই বসত।
     *
     * `bcadd(.., '0', 2)` একই কাজ করে দশমিক না হারিয়ে।
     */
    $plain = fn ($v) => $v === null ? null : rtrim(rtrim(bcadd((string) $v, '0', 2), '0'), '.');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $d->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$d->document_no"
                          :subtitle="$d->kind->name().' · '.$d->institution">
            <x-slot:actions>
                <x-ui.button tone="secondary"
                             :href="route('finance.deposit.index', ['issuer' => $issuer])">
                    {{ __('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer)) }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── তথ্য ──────────────────────────────────────────────────────
         মূলধনের সংখ্যাটা তার খাতের এন্ট্রিগুলোতে নামায় (নিয়ম ১) — আর
         মালিকের কাগজে ওটা উত্তোলনের খাত, কারণ টাকাটা সত্যিই ওখানেই
         গেছে। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                [__('finance::field.held_by'), view('finance::deposit.partials.holder', ['deposit' => $d])],
                [__('finance::field.principal'), view('ui.amount-link', [
                    'value' => $d->principal,
                    'href' => route('accounts.coa.show', $d->account_id).'#transactions',
                ])],
                [__('finance::field.profit_rate'), $d->profit_rate === null
                    ? '—'
                    : rtrim(rtrim((string) $d->profit_rate, '0'), '.').'% · '.__('finance::who.'.$d->return_word)],
                [__('finance::field.matures_on'), view('finance::deposit.partials.maturity', ['deposit' => $d])],
                [__('finance::field.institution'), view('finance::deposit.partials.where', ['deposit' => $d])],
                [__('finance::field.opened_on'), \App\Core\Support\DateFormat::format($d->opened_on)],
                [__('finance::field.instalment_amount'), $d->instalment_amount === null
                    ? '—'
                    : \App\Core\Support\Money::format($d->instalment_amount)
                        .($d->instalment_day ? ' · '.__('finance::field.instalment_day').' '.$d->instalment_day : '')],
                [__('finance::field.payout_account'), $d->payoutAccount?->name() ?? '—'],
            ] as [$label, $value])
                <div class="min-w-0">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ $label }}</dt>
                    <dd class="mt-0.5 truncate">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($d->note)
            <p class="mt-3 border-t border-(--color-border) pt-3 text-sm text-(--color-ink-muted)">
                {{ $d->note }}
            </p>
        @endif
    </section>

    @if ($isOpen)
        <div @class(['mb-4 grid gap-4', 'lg:grid-cols-2' => $hasRegularMove])>
            {{-- ── কিস্তি বা মুনাফা ───────────────────────────────────
                 একটাই ফর্ম, কারণ জমার আকৃতিই বলে দেয় কোনটা: কিস্তির
                 জমায় মুনাফা তোলার প্রশ্ন ওঠে না, আর মেয়াদান্তে
                 মুনাফার জমায় কিস্তির প্রশ্ন ওঠে না। ব্যবহারকারীকে
                 এমন একটা পার্থক্য বেছে নিতে বলা হয় না যা সিস্টেম
                 নিজেই জানে। --}}
            @php
                $moveKind = $d->kind->takesInstalments()
                    ? \App\Modules\Finance\Models\DepositMovement::INSTALMENT
                    : \App\Modules\Finance\Models\DepositMovement::PAYOUT;
            @endphp

            @if ($hasRegularMove)
                <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">
                        {{ $d->kind->takesInstalments()
                            ? __('finance::action.add_instalment')
                            : __('finance::action.take_payout') }}
                    </h2>

                    <form method="POST"
                          action="{{ route('finance.deposit.movement',
                              ['issuer' => $issuer, 'deposit' => $d->id]) }}"
                          class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $moveKind }}">

                        <x-ui.field name="amount" type="number" step="0.01" numeric required
                                    :label="__('finance::field.amount')"
                                    :value="old('amount', $plain($d->kind->takesInstalments()
                                        ? $d->instalment_amount : null))" />

                        <x-ui.field name="moved_on" type="date" required
                                    :label="__('finance::field.date')"
                                    :value="old('moved_on', now()->toDateString())" />

                        <x-ui.select name="money_account_id" required
                                     :label="__('finance::field.money_account')"
                                     :options="$money"
                                     :placeholder="__('finance::field.choose')"
                                     :selected="old('money_account_id',
                                         $d->payout_account_id ?? $d->funded_from_account_id)" />

                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="primary" class="w-full">
                                {{ __('core.action.save') }}
                            </x-ui.button>
                        </div>
                    </form>
                </section>
            @endif

            {{-- ── ভাঙা বা মেয়াদ শেষ ─────────────────────────────────
                 প্রাপ্ত টাকাটা জিজ্ঞেস করা হয়, হিসাব করা হয় না: ব্যাংক
                 যা দেয় তা প্রায়ই আলাদা — উৎসে কর, আবগারি শুল্ক, আগে
                 ভাঙলে জরিমানা। বাড়তিটা মুনাফা, ঘাটতিটা খরচ। --}}
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('finance::action.close_deposit') }}</h2>

                <form method="POST"
                      action="{{ route('finance.deposit.close',
                          ['issuer' => $issuer, 'deposit' => $d->id]) }}"
                      class="grid gap-3 sm:grid-cols-2">
                    @csrf

                    <x-ui.field name="amount" type="number" step="0.01" numeric required
                                :label="__('finance::field.amount')"
                                :value="old('amount', $plain($d->principal))" />

                    <x-ui.field name="moved_on" type="date" required
                                :label="__('finance::field.date')"
                                :value="old('moved_on', now()->toDateString())" />

                    <x-ui.select name="money_account_id" required
                                 :label="__('finance::field.money_account')"
                                 :options="$money"
                                 :placeholder="__('finance::field.choose')"
                                 :selected="old('money_account_id', $d->funded_from_account_id)" />

                    <div class="flex items-end">
                        <x-ui.button type="submit" tone="danger" class="w-full">
                            {{ __('finance::action.close_deposit') }}
                        </x-ui.button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    {{-- ── যা যা হয়েছে ───────────────────────────────────────────────
         প্রতিটা সারি তার ভাউচারে নামায় — নিয়ম ১। --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.movement') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_deposit_yet')"
            :rows="$movements"
            :columns="[
                ['key' => 'moved_on', 'label' => __('finance::field.date'), 'width' => '9rem',
                 'render' => fn ($m) => \App\Core\Support\DateFormat::format($m->moved_on)],
                ['key' => 'kind', 'label' => __('finance::field.kind'), 'width' => '11rem',
                 'render' => fn ($m) => __('finance::kind.'.$m->kind)],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($m) => \App\Core\Support\Money::format($m->amount)],
                ['key' => 'money', 'label' => __('finance::field.money_account'),
                 'render' => fn ($m) => $m->moneyAccount?->name() ?? '—'],
                ['key' => 'voucher', 'label' => __('core.print.document_no'), 'width' => '10rem',
                 'render' => fn ($m) => view('finance::deposit.partials.voucher', ['movement' => $m])],
            ]" />
    </section>
</x-layouts.app>
