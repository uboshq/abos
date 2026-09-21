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
                <x-ui.amount :value="$payable" href="#transactions" />
            </p>

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
             'render' => fn ($e) => \App\Core\Support\Money::format($e->running_balance)],
        ];
    @endphp

    <section id="transactions" data-boxed class="scroll-mt-24 mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        {{--
            ⭐ টুলবার — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬:
            *"lal mak kora joygay eta bosabe, same vabe customer e o"*।

            ── ⛔ যে বোতামগুলো এখানে **নেই**, আর কেন ─────────────────────
            খোঁজা · ছাঁকনি · সাজানো — তিনটাই ঠিকানায় চাবি বসায়, আর এই
            পাতার কন্ট্রোলার ঐ চাবিগুলো **পড়েই না**। ⚠️ বসালে তিনটা
            জীবন্ত দেখতে বোতাম হত যেগুলো চাপলে কিছুই বদলাত না — ঠিক
            সেই মৃত বোতাম, যার তেইশটা আজ রাতে সারানো হয়েছে।

            ⓘ মালিক নিজেই বলেছেন *"ok za lage ta daw"* — তাই যা সত্যিই
            কাজ করে কেবল সেটুকুই: কলাম, ঘনত্ব, রপ্তানি, ভাগ, ছাপা, নতুন করে।

            ── ⭐ ছাপাটা এখানে লিংক, বোতাম নয় ──────────────────────────
            পর্দায় আজকেরটা উপরে, কাগজে ব্যাংকের খাতার মতো পুরনো আগে।
            ⓘ তাই ছাপার আগে `?ledger=asc` ঠিকানায় যাওয়া হয়, আর সেখানে
            পৌঁছেই ছাপা শুরু হয়।
        --}}
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('supplier::section.transactions')"
                          :search="false"
                          :filter="false"
                          :columns="$ledgerColumns"
                          {{-- ℹ এই দুইটা ছাঁকনি নয়, দৃশ্যের অবস্থা — না বললে টুলবার
                               "asc" আর "1" লেখা দুইটা কাঁচা চিপ তুলত, আর সরাতে গেলে
                               কাগজের ক্রমটাই হারাত। --}}
                          :quiet="['ledger', 'print']"
                          :print-href="request()->fullUrlWithQuery(['ledger' => 'asc', 'print' => 1])" />
        </form>

        <x-ui.table
            :empty="__('supplier::message.no_transactions')"
            :rows="$entries"
            :compact="request()->boolean('compact')"
            :columns="$ledgerColumns" />

        <x-ui.pager :rows="$entries" />
    </section>
</x-layouts.app>
