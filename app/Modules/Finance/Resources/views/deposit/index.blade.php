{{--
    সঞ্চয় ও বিনিয়োগ — ব্যাংক আমানত · সঞ্চয়পত্র · বন্ড।

    ── কেন উপরে দুইটা যোগফল, একটা নয় ──────────────────────────────────
    ব্যবসার নামের জমা স্থিতিপত্রে সম্পদ; মালিকের নামের সঞ্চয়পত্র নয় —
    ওটা উত্তোলন হয়ে বেরিয়ে গেছে, আর কাগজটা এখানে কেবল জানার জন্য।
    এক সংখ্যায় দেখালে ওটা কোনো রিপোর্টের সাথেই মিলত না।

    ── কেন "ত্রিশ দিনে মেয়াদ শেষ" আলাদা করে গোনা ──────────────────────
    মেয়াদোত্তীর্ণ FD ব্যাংকে পড়ে থাকে আর সাধারণ সঞ্চয়ী হারে সুদ পায় —
    অর্থাৎ প্রতিদিন টাকা হারায়। কেউ তারিখ মনে রাখে না; পর্দা রাখে।
--}}
@php
    /*
     * ধরনের আকৃতি JS-এ — কোন ঘরগুলো দেখাতে হবে সেটা ওটাই ঠিক করে।
     *
     * সব ঘর একসাথে দেখালে FDR খুলতে গিয়ে "কিস্তির দিন" আর "মুনাফা
     * কোথায় আসে" — দুইটা অপ্রাসঙ্গিক প্রশ্নের উত্তর দিতে হত, আর
     * তৃতীয়বারে কেউ যেকোনো একটা বসিয়ে দিত।
     */
    $shapes = $kinds->mapWithKeys(fn ($k) => [$k->id => [
        'shape' => $k->shape,
        'personal' => $k->personal_only,
    ]]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer)) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer))"
            :subtitle="__('finance::message.deposit_note_'.$issuer)" />
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

    {{-- ── কত টাকা সরিয়ে রাখা আছে ───────────────────────────────────── --}}
    <section data-boxed class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['finance::field.business_holds', \App\Core\Support\Money::format($standing['business']), true],
            ['finance::field.owner_holds', \App\Core\Support\Money::format($standing['owner']), true],
            ['finance::field.how_many', $standing['count'], false],
            ['finance::field.maturing_soon', $standing['maturing'], false],
        ] as [$label, $value, $money])
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                <p @class(['mt-1 text-lg font-semibold tabular-nums', 'text-(--color-badge-danger-ink)' =>
                    $label === 'finance::field.maturing_soon' && $value > 0])>{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ── নতুন জমা ──────────────────────────────────────────────────
         ঘরগুলো ধরনের আকৃতি অনুযায়ী দেখা যায়: কিস্তির জমায় কিস্তির ঘর,
         নিয়মিত মুনাফার জমায় মুনাফার খাত। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4"
             x-data="{
                 kinds: {{ Js::from($shapes) }},
                 kindId: '{{ old('kind_id') }}',
                 heldBy: '{{ old('held_by', $issuer === 'bank' ? 'business' : 'owner') }}',

                 get shape() { return this.kinds[this.kindId]?.shape ?? null },
                 get personalOnly() { return this.kinds[this.kindId]?.personal ?? false },
             }"
             x-effect="if (personalOnly) heldBy = 'owner'">
        <h2 class="mb-3 font-semibold">{{ __('finance::field.open_a_deposit') }}</h2>

        <form method="POST" action="{{ route('finance.deposit.store', ['issuer' => $issuer]) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.select name="kind_id" :label="__('finance::field.deposit_kind')" required
                         x-model="kindId"
                         :options="$kinds->mapWithKeys(fn ($k) => [$k->id => $k->name()])"
                         :placeholder="__('finance::field.choose')"
                         :selected="old('kind_id')" />

            <x-ui.field name="institution" :label="__('finance::field.institution')" required
                        :value="old('institution')" />

            <x-ui.field name="branch_name" :label="__('finance::field.branch_name')"
                        :value="old('branch_name')" />

            <x-ui.field name="reference_no" :label="__('finance::field.reference_no')"
                        :value="old('reference_no')" />

            {{-- কার নামে — আর এটাই ঠিক করে টাকাটা সম্পদ হবে না উত্তোলন।
                 সঞ্চয়পত্র ব্যবসার নামে কেনা যায় না, তাই তখন ঘরটা
                 আটকে থাকে; সেবাও একই কথা বলে, দুই জায়গাতেই। --}}
            <div>
                {{-- ── কেন আটকানো ঘরের পাশে একটা লুকানো ঘর ──────────────
                     `disabled` ঘর ফর্মের সাথে **কিছুই পাঠায় না** — HTML-এর
                     নিয়ম। সঞ্চয়পত্র বাছলে ঘরটা আটকে যেত, আর তখন
                     `held_by` ফাঁকা যেত: ব্যবহারকারী দেখতেন "মালিক" লেখা,
                     অথচ ফিরত "কার নামে বলুন" ভুল।

                     লুকানো ঘরটা select-এর **আগে**, ইচ্ছাকৃতভাবে: একই
                     নামে দুইটা ঘর গেলে পরেরটা জেতে। তাই ঘরটা খোলা
                     থাকলে ব্যবহারকারীর বাছাই জেতে, আর আটকানো থাকলে
                     কেবল লুকানোটাই যায়। --}}
                <input type="hidden" name="held_by" :value="heldBy">

                <x-ui.select name="held_by" :label="__('finance::field.held_by')" required
                             x-model="heldBy" ::disabled="personalOnly"
                             :options="[
                                 \App\Modules\Finance\Models\Deposit::BUSINESS => __('finance::who.business'),
                                 \App\Modules\Finance\Models\Deposit::OWNER => __('finance::who.owner'),
                             ]"
                             :selected="old('held_by', $issuer === 'bank' ? 'business' : 'owner')" />

                <p x-cloak x-show="heldBy === 'owner'"
                   class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('finance::message.owner_held_is_drawing') }}
                </p>
            </div>

            <div x-cloak x-show="heldBy === 'owner'">
                <x-ui.field name="holder_name" :label="__('finance::field.holder_name')"
                            :value="old('holder_name')" />
            </div>

            <x-ui.field name="principal" type="number" step="0.01" numeric required
                        :label="__('finance::field.principal')" :value="old('principal')" />


            <x-ui.select name="funded_from_account_id" :label="__('finance::field.funded_from')" required
                         :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()])"
                         :placeholder="__('finance::field.choose')"
                         :selected="old('funded_from_account_id')" />

            <x-ui.field name="profit_rate" type="number" step="0.01" numeric
                        :label="__('finance::field.profit_rate')" :value="old('profit_rate')" />

            {{-- সুদ না মুনাফা — ইসলামি ব্যাংকে কাগজে ওটাই ছাপা, আর
                 খাতের কথা কাগজের সাথে না মিললে হিসাবরক্ষক থেমে ভাবেন। --}}
            <x-ui.select name="return_word" :label="__('finance::field.return_word')" required
                         :options="['interest' => __('finance::who.interest'),
                                    'profit' => __('finance::who.profit')]"
                         :selected="old('return_word', 'interest')" />

            <x-ui.field name="opened_on" type="date" :label="__('finance::field.opened_on')" required
                        :value="old('opened_on', now()->toDateString())" />

            <x-ui.field name="matures_on" type="date" :label="__('finance::field.matures_on')"
                        :value="old('matures_on')" />

            <div x-cloak x-show="shape === 'instalment'">
                <x-ui.field name="instalment_amount" type="number" step="0.01" numeric
                            :label="__('finance::field.instalment_amount')"
                            :value="old('instalment_amount')" />
            </div>

            <div x-cloak x-show="shape === 'instalment'">
                <x-ui.field name="instalment_day" type="number" step="1" numeric
                            :label="__('finance::field.instalment_day')"
                            :value="old('instalment_day')" />
            </div>

            <div x-cloak x-show="shape === 'periodic_payout'" class="xl:col-span-2">
                <x-ui.select name="payout_account_id" :label="__('finance::field.payout_account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()])"
                             :placeholder="__('finance::field.choose')"
                             :selected="old('payout_account_id')" />
            </div>

            {{-- কোন ধারের বিপরীতে বন্ধক।

                 ---- কেন ঘরটা এখানে এল, ৩০ আগস্ট ২০২৬ ----
                 ঘরটা ছিল ঋণের ফর্মে, কারণ FD তখন ঋণেরই একটা ধরন ছিল।
                 FD জমার পর্দায় চলে আসায় ঘরটাও সাথে এল -- নাহলে দরজা
                 বন্ধ করতে গিয়ে "আমার FD-টা ঋণের বিপরীতে বাঁধা" কথাটা
                 বলার জায়গাই থাকত না, আর ওটা পরিষ্কার করা হত না,
                 ক্ষমতা হারানো হত।

                 খালি রাখলে জমাটা হাতের টাকা। বাঁধা থাকলে তালিকায়
                 "আছে" দেখাবে ঠিকই, কিন্তু ভাঙানো যাবে না।

                 ---- কেন কেবল ব্যবসার জমায় ----
                 মালিক নিজের নামে যেটা রেখেছেন সেটা ব্যবসার সম্পদ নয়,
                 তাই ব্যবসার ধারের জামানত হিসেবে ওটা দেখানো মানে এমন
                 কিছু গুনে ফেলা যা ব্যবসার নয়। --}}
            @if ($pledgeableLoans->isNotEmpty())
                <div x-cloak x-show="heldBy === 'business'" class="sm:col-span-2">
                    <x-ui.select name="pledged_to_loan_id"
                                 :label="__('finance::field.pledged_to_loan')"
                                 :options="$pledgeableLoans->mapWithKeys(fn ($l) => [$l->id => $l->lender.' — '.$l->document_no])"
                                 :placeholder="__('finance::field.not_pledged')"
                                 :selected="old('pledged_to_loan_id')" />
                </div>
            @endif

            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />
            </div>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    {{-- ── যা যা আছে ─────────────────────────────────────────────────── --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.deposits_standing') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_deposit_yet')"
            :rows="$deposits"
            :columns="[
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem'],
                ['key' => 'kind', 'label' => __('finance::field.deposit_kind'),
                 'render' => fn ($d) => $d->kind->name()],
                ['key' => 'institution', 'label' => __('finance::field.institution'),
                 'render' => fn ($d) => view('finance::deposit.partials.where', ['deposit' => $d])],
                ['key' => 'held_by', 'label' => __('finance::field.held_by'), 'width' => '9rem',
                 'render' => fn ($d) => view('finance::deposit.partials.holder', ['deposit' => $d])],
                ['key' => 'principal', 'label' => __('finance::field.principal'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($d) => view('ui.amount-link', [
                     'value' => $d->principal,
                     'href' => route('accounts.coa.show', $d->account_id).'#transactions',
                 ])],
                ['key' => 'matures_on', 'label' => __('finance::field.matures_on'), 'width' => '11rem',
                 'render' => fn ($d) => view('finance::deposit.partials.maturity', ['deposit' => $d])],
                ['key' => 'do', 'label' => '', 'width' => '7rem',
                 'render' => fn ($d) => view('finance::deposit.partials.open-it',
                     ['deposit' => $d, 'issuer' => $issuer])],
            ]" />

        <x-ui.pager :rows="$deposits" />
    </section>
</x-layouts.app>
