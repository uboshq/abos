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
    $tabs = [
        'active' => __('finance::state.active'),
        'closed' => __('finance::state.closed'),
    ];

    $depColumns = [
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

            <x-ui.toolbar :title="__('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer))"
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

        {{-- ট্যাবের সারি — চালু · শেষ, পাশে গোনা --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer)) }}">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('finance.deposit.index', $key === 'active' ? ['issuer' => $issuer] : ['issuer' => $issuer, 'tab' => $key]) }}"
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

        {{-- ── কত টাকা সরিয়ে রাখা আছে ───────────────────────────────────── --}}
        {{-- ⓘ এখন একই কার্ডের ভেতরে, তালিকার উপরে। ⚠️ ট্যাব বা খোঁজায় ছাঁকা হয়
             না: "কত সরিয়ে রাখা আছে" প্রশ্নটা চালু সব জমার। --}}
        <section class="grid gap-3 border-b border-(--color-border) p-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['finance::field.business_holds', \App\Core\Support\Money::format($standing['business']), true],
                ['finance::field.owner_holds', \App\Core\Support\Money::format($standing['owner']), true],
                ['finance::field.how_many', $standing['count'], false],
                ['finance::field.maturing_soon', $standing['maturing'], false],
            ] as [$label, $value, $money])
                <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-app) p-3">
                    <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                    <p @class(['mt-1 text-lg font-semibold tabular-nums', 'text-(--color-badge-danger-ink)' =>
                        $label === 'finance::field.maturing_soon' && $value > 0])>{{ $value }}</p>
                </div>
            @endforeach
        </section>

        {{-- ── যা যা আছে ─────────────────────────────────────────────────── --}}
        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_deposit_yet')"
            :rows="$deposits"
            :columns="$depColumns" />

        <x-ui.pager :rows="$deposits" />
    </div>
</x-layouts.app>
