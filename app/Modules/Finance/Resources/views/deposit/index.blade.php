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
     * ⭐ তালিকার গড়ন — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"সব পাতাতেই সমস্যা"*।
     *
     * ⓘ টুলবার (শিরোনাম · বর্ণনা · + নতুন জমা) → চালু · শেষ ট্যাব → চারটা
     * যোগফল → তালিকা। ফর্মটা নিজের পাতায় ([[deposit/create]]), ইস্যুয়ার সহ —
     * আগে লম্বা ফর্মের নিচে তালিকাটা কেউ খুঁজে পেত না।
     * ⓘ কলামগুলো এক জায়গায়, যাতে টুলবারের Columns মেনু আর টেবিল একই জিনিস বলে।
     */
    /* ⭐ দুইটা নতুন ট্যাব — মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬:
       মেয়াদ আসছে (৩০/৬০/৯০ দিন) আর বন্ধক দেওয়া। */
    $tabs = [
        'active' => __('finance::state.active'),
        'maturing' => __('finance::field.dep_tab_maturing'),
        'pledged' => __('finance::field.dep_tab_pledged'),
        'closed' => __('finance::state.closed'),

        /* ⭐ কোন প্রতিষ্ঠানে — মালিকের সংশোধন, ২০ সেপ্টেম্বর ২০২৬।
           ⓘ আমানত মানুষের সাথে নয়, প্রতিষ্ঠানে রাখা হয়। */
        'institution' => __('finance::field.dep_tab_institution'),
    ];

    /*
     * ⭐ ইস্যুকারীর ট্যাব — মালিকের সিদ্ধান্ত, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ মেনুতে তিনটা সারি ছিল (ব্যাংক আমানত · সঞ্চয়পত্র · বন্ড); এখন
     * একটাই — "আমানত" — আর তিনটা এই পর্দার উপরের ট্যাব। ⚠️ ইস্যুকারীটা
     * পথেই থাকে (`/deposits/{issuer}`), তাই বুকমার্ক করা লিংক আগের মতোই
     * নিজের ট্যাবে নামে, আর বাঁ পাশের সারিটাও জ্বলে থাকে।
     */
    $issuerTabs = [
        'bank' => __('finance::menu.deposit_bank'),
        'national_savings' => __('finance::menu.deposit_savings'),
        'bond' => __('finance::menu.deposit_bond'),
    ];

    $depColumns = [
        // ⭐ নম্বরটা নিজের পাতায় খোলে — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem',
         'render' => fn ($d) => view('finance::deposit.partials.number', ['deposit' => $d])],
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
        /*
         * ⭐ কোন ঋণের জামানতে — মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ কলামটা (`pledged_to_loan_id`) অনেক দিন ধরেই ছিল, পর্দা ছিল না।
         * ⚠️ বন্ধক দেওয়া জমা ভাঙা যায় না — ব্যাংক ছাড়বে না — তাই "কতটা
         * খালি" প্রশ্নের উত্তর এই কলামেই শুরু।
         */
        ['key' => 'pledged', 'label' => __('finance::field.dep_pledged_to'), 'width' => '12rem',
         'render' => fn ($d) => view('finance::deposit.partials.pledge', ['deposit' => $d])],

        ['key' => 'do', 'label' => __('core.table.actions'), 'width' => '7rem',
         'render' => fn ($d) => view('finance::deposit.partials.open-it',
             ['deposit' => $d, 'issuer' => $issuer])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer)) }}</x-slot:title>

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

    <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ ঘনত্ব বা কলাম বদলালে ট্যাবটা হারায় না --}}
            @if ($tab === 'closed')
                <input type="hidden" name="tab" value="closed">
            @endif

            <x-ui.toolbar :title="__('finance::menu.deposits')"
                          :subtitle="__('finance::message.deposit_note_'.$issuer)"
                          :columns="$depColumns"
                          :search-placeholder="__('finance::message.deposit_search')"
                          :quiet="['tab']">
                <x-slot:actions>
                    @can('finance.deposit.create')
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('finance.deposit.create', ['issuer' => $issuer])">
                            {{ __('finance::field.open_a_deposit') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        {{-- ── কার কাগজ: ব্যাংক · সঞ্চয়পত্র · বন্ড ───────────────────────
             ⓘ অবস্থার ট্যাবের উপরে, কারণ এটা বড় ভাগ: আগে "কার কাগজ",
             তারপর "কোন অবস্থায়"। ⚠️ ট্যাব আর মেয়াদের জানালা সাথে যায়,
             নাহলে ইস্যুকারী বদলালেই মানুষ চালু তালিকায় ফিরে যেতেন। --}}
        <nav class="flex flex-wrap gap-2 border-b border-(--color-border) px-3 py-2 text-sm"
             aria-label="{{ __('finance::menu.deposits') }}">
            @foreach ($issuerTabs as $key => $label)
                <a href="{{ route('finance.deposit.index', array_filter([
                        'issuer' => $key,
                        'tab' => $tab === 'active' ? null : $tab,
                        'within' => $tab === 'maturing' && $within !== 30 ? $within : null,
                    ])) }}"
                   @if ($issuer === $key) aria-current="page" @endif
                   @class([
                       'flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-pill) border px-3',
                       'border-(--color-brand-500) font-semibold text-(--color-ink)' => $issuer === $key,
                       'border-(--color-border) text-(--color-ink-muted) hover:text-(--color-ink)' => $issuer !== $key,
                   ])>
                    {{ $label }}
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ $issuerCounts[$key] }}
                    </span>
                </a>
            @endforeach
        </nav>

        {{-- ট্যাবের সারি — চালু · শেষ, পাশে গোনা --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer)) }}">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('finance.deposit.index', array_filter([
                        'issuer' => $issuer,
                        'tab' => $key === 'active' ? null : $key,
                        // ⓘ মেয়াদের জানালাটা ট্যাব বদলালেও সাথে যায়
                        'within' => $key === 'maturing' && $within !== 30 ? $within : null,
                    ])) }}"
                   @if ($tab === $key) aria-current="page" @endif
                   class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                          {{ $tab === $key
                              ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                              : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ $label }}
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ $counts[$key] }}
                    </span>
                </a>
            @endforeach
        </nav>

        {{-- ⭐ কত দিনের ভিতরে — মেয়াদের ট্যাবে কেবল (মানচিত্র §১৪ক)।
             ⓘ তিনটা জানালা: এক মাস, দুই মাস, তিন মাস। ⚠️ FDR নবায়নের সিদ্ধান্ত
             সাধারণত মাসখানেক আগে নিতে হয়, তাই ডিফল্ট ৩০। --}}
        @if ($tab === 'maturing')
            <div class="flex flex-wrap items-center gap-2 border-b border-(--color-border) px-3 py-2 text-sm">
                <span class="text-(--color-ink-muted)">{{ __('finance::field.dep_within') }}</span>

                @foreach ([30, 60, 90] as $days)
                    <a href="{{ route('finance.deposit.index', ['issuer' => $issuer, 'tab' => 'maturing', 'within' => $days]) }}"
                       @class([
                           'rounded-(--radius-pill) border px-3 py-0.5',
                           'border-(--color-brand-500) font-semibold text-(--color-ink)' => $within === $days,
                           'border-(--color-border) text-(--color-ink-muted)' => $within !== $days,
                       ])>
                        {{ __('finance::field.dep_days', ['days' => $days]) }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ── কত টাকা সরিয়ে রাখা আছে ───────────────────────────────────── --}}
        {{-- ⓘ এখন একই কার্ডের ভেতরে, তালিকার উপরে। ⚠️ ট্যাব বা খোঁজায় ছাঁকা হয়
             না: "কত সরিয়ে রাখা আছে" প্রশ্নটা চালু সব জমার। --}}
        <section class="grid gap-3 border-b border-(--color-border) p-3 sm:grid-cols-2 xl:grid-cols-4">
            {{-- ⭐ গোনার টালি দুইটা এখন ক্লিকযোগ্য — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
                 ⓘ সংখ্যাটা যে তালিকা থেকে এসেছে, ক্লিকে সেটাই খোলে। ⚠️ টাকার
                 দুইটা টালি লিংক নয়: ওগুলো অনেকগুলো জমার যোগফল, আর নামার মতো
                 একটাও পাতা নেই — যেটা নেই সেখানে লিংক দিলে ফাঁকা পাতা খুলত। --}}
            @foreach ([
                ['finance::field.business_holds', \App\Core\Support\Money::format($standing['business']), null],
                ['finance::field.owner_holds', \App\Core\Support\Money::format($standing['owner']), null],
                ['finance::field.how_many', $standing['count'],
                    route('finance.deposit.index', ['issuer' => $issuer])],
                ['finance::field.maturing_soon', $standing['maturing'],
                    route('finance.deposit.index', ['issuer' => $issuer, 'tab' => 'maturing'])],
            ] as [$label, $value, $href])
                <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-app) p-3">
                    <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>

                    @php
                        $figureClass = 'mt-1 text-lg font-semibold tabular-nums'
                            .($label === 'finance::field.maturing_soon' && $value > 0
                                ? ' text-(--color-badge-danger-ink)' : '');
                    @endphp

                    @if ($href === null)
                        <p class="{{ $figureClass }}">{{ $value }}</p>
                    @else
                        <a href="{{ $href }}"
                           class="{{ $figureClass }} block underline-offset-2 hover:underline">{{ $value }}</a>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- ── যা যা আছে ─────────────────────────────────────────────────── --}}
        @if ($tab === 'institution')
            @include('finance::deposit.partials.institutions', ['institutions' => $institutions])
        @else
        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_deposit_yet')"
            :rows="$deposits"
            :columns="$depColumns" />

        <x-ui.pager :rows="$deposits" />
        @endif
    </div>
</x-layouts.app>
