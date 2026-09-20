{{--
    উত্তোলন — কে কত নিলেন, আর মাসে কতটা নিতে পারবেন।

    ── কেন উপরে "কে কোথায় দাঁড়িয়ে", তালিকা নিচে ────────────────────────
    সারির তালিকাটা ইতিহাস; কেউ রোজ পড়ে না। রোজ জানার দরকার একটাই জিনিস:
    **এই মাসে কার সীমায় কতটা বাকি** — কারণ ওটা না জানলে উত্তোলন লিখতে
    গিয়ে আটকাতে হয়, আর তখন কেউ ভাবে জিনিসটা নষ্ট।
--}}
{{--
    ⭐ নতুন গড়ন — মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব পাতাতেই সমস্যা"*।

    ⓘ আগে পাতাটা ছিল মাসের ফর্ম → কে কোথায় → লেখার ফর্ম আর সীমার ফর্ম
    পাশাপাশি → তারপর তালিকা; তালিকাটা এত নিচে যে কেউ খুঁজে পেত না, আর
    Save বোতাম একটা সরু ঘরে চাপা পড়ত। ⭐ এখন মূলধনের পাতার মতো: টুলবার
    (শিরোনাম · বর্ণনা · + উত্তোলন লিখুন) → দুইটা ট্যাব, পাশে গোনা:
      · তোলা টাকার তালিকা — খোঁজা সহ, এটাই প্রথম ট্যাব;
      · কে কোথায় দাঁড়িয়ে — মাসের ছাঁকনি টুলবারে, আর মাসিক সীমার ফর্ম
        ঐ তালিকার ঠিক নিচে, কারণ সীমাটা ওখানেই পড়া হয়।
    ⓘ লেখার ফর্মটা আর এখানে নেই: [[withdrawal/form]]-এ (`create`) প্রতিটা
    ঘর আগে থেকেই আছে — কে, কত, তারিখ, কেন — সাথে ধরন, খাত আর কাগজ।
--}}
{{-- ⭐ ধরনের কলাম দুইটা — ১৮ সেপ্টেম্বর ২০২৬, মালিকের প্রশ্নে।

     ── ⛔ কলাম ছিল, সারিতে বসত, কোথাও দেখা যেত না ──────────────
     তিনটা চিপ দিয়ে ধরন বাছা যেত, ডাটাবেজে বসতও — অথচ তালিকায়
     কলামই ছিল না। ⚠️ মালিক জিজ্ঞেস করেছেন *"এগুলোর লিস্ট
     কোথায়"*, আর সৎ উত্তর ছিল: কোথাও নেই।

     ⓘ আর দেখাটা জরুরি, কারণ তিনটার হিসাব তিন রকম — বেতন একটা
     **খরচ**, বাকি দুইটা মূলধন কমায়। ⛔ কোনটা কী তা না দেখে
     মাসের শেষে মেলানো যায় না।

     ── ⚠️ "কী দিয়ে" কেবল টাকা না হলে দেখায় ────────────────────
     ⓘ সব সারিতে "টাকা" লিখলে কলামটা কোলাহল হত, আর যে দুই-একটা
     সারি সত্যিই আলাদা সেগুলো ভিড়ে হারাত।

     ⛔ ⚠️ এই মন্তব্যটা এখানে, `:columns`-এর **ভিতরে নয়** —
     অ্যাট্রিবিউটের ভিতরে PHP মন্তব্য লিখলে Blade ওটা পার্স করে
     না, আর পুরো সংজ্ঞাটা পাতায় **লেখা হিসেবে ছাপা হয়**। --}}
@php
    $wdColumns = [
        ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '9rem',
         'render' => fn ($w) => \App\Core\Support\DateFormat::format($w->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '10rem',
         'render' => fn ($w) => view('finance::withdrawal.partials.number', ['row' => $w])],
        ['key' => 'person', 'label' => __('finance::field.who'),
         'render' => fn ($w) => view('finance::partials.person-link', [
             'id' => $w->person_id, 'label' => $w->person?->name(),
         ])],
        ['key' => 'kind', 'label' => __('finance::field.withdrawal_kind_box'), 'width' => '10rem',
         'render' => fn ($w) => __('finance::field.kind_'.($w->kind ?: 'drawing'))],
        ['key' => 'in_kind', 'label' => __('finance::field.in_kind'), 'width' => '9rem',
         'render' => fn ($w) => ($w->in_kind ?? 'cash') === 'cash'
             ? '—'
             : __('finance::field.in_kind_'.$w->in_kind)],
        ['key' => 'reason', 'label' => __('finance::field.why'),
         'render' => fn ($w) => $w->reason ?: '—'],
        ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true,
         'width' => '11rem',
         'render' => fn ($w) => \App\Core\Support\Money::format($w->amount)],
        /* ⓘ অবস্থা আর কাজ একই ঘরে: খসড়া হলে "টাকা গেছে" বোতাম, বসে গেলে
           কোথা থেকে গেল ([[withdrawal/partials/state]]) */
        ['key' => 'state', 'label' => __('finance::field.state'), 'width' => '16rem',
         'render' => fn ($w) => view('finance::withdrawal.partials.state',
             ['row' => $w, 'accounts' => $accounts])],
    ];

    $standColumns = [
        ['key' => 'name', 'label' => __('finance::field.who'),
         'render' => fn ($r) => view('finance::partials.person-link', [
             'id' => $r['person_id'], 'label' => $r['name'],
         ])],
        ['key' => 'cap', 'label' => __('finance::field.monthly_cap'), 'numeric' => true,
         'width' => '10rem',
         'render' => fn ($r) => $r['cap'] === null
             ? __('finance::field.no_cap')
             : \App\Core\Support\Money::format($r['cap'])],
        ['key' => 'this_month', 'label' => __('finance::field.taken_this_month'),
         'numeric' => true, 'width' => '11rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['this_month'])],
        ['key' => 'left', 'label' => __('finance::field.cap_left'), 'numeric' => true,
         'width' => '11rem',
         'render' => fn ($r) => view('finance::withdrawal.partials.left', ['row' => $r])],
        ['key' => 'taken_all', 'label' => __('finance::field.taken_all'), 'numeric' => true,
         'width' => '11rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['taken_all'])],
    ];

    $wdTabs = [
        'rows' => __('finance::field.withdrawals_list'),
        'standing' => __('finance::field.where_each_stands'),
    ];

    // ⓘ কলাম-মেনু আর ঘনত্ব যে ট্যাব খোলা, তার টেবিলেই খাটে
    $columns = $tab === 'standing' ? $standColumns : $wdColumns;
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.withdrawal') }}</x-slot:title>

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

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ ঘনত্ব, কলাম বা মাস বদলালে খোলা ট্যাবটা হারায় না --}}
            @if ($tab === 'standing')
                <input type="hidden" name="tab" value="standing">
            @endif

            {{-- ⓘ খোঁজা কেবল তালিকার ট্যাবে — "কে কোথায়" খাতা থেকে গোনা হিসাব,
                 ওখানে খোঁজার ঘর কিছুই ছাঁকত না (মৃত বোতাম) --}}
            <x-ui.toolbar :title="__('finance::menu.withdrawal')"
                :subtitle="__('finance::message.withdrawal_note')"
                :columns="$columns"
                :search="$tab === 'rows'"
                :search-placeholder="__('finance::message.withdrawal_search')"
                :filter-labels="['month' => __('finance::field.month')]"
                {{-- ⓘ ট্যাব ছাঁকনির চিপ নয়; `highlight` আসে খতিয়ান থেকে নামার লিংকে --}}
                :quiet="['tab', 'highlight']">
                @if ($tab === 'standing')
                    {{-- ── কোন মাস ───────────────────────────────────────────────────
                         সীমা মাসের হিসাব, তাই পর্দাটাও মাসের। --}}
                    <x-ui.field name="month" type="month" :label="__('finance::field.month')" :value="$month" />
                @endif

                <x-slot:actions>
                    @can('finance.withdrawal.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.withdrawal.create')">
                            {{ __('finance::field.record_a_withdrawal') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        {{-- ট্যাবের সারি — প্রতিটার পাশে গোনা --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.withdrawal') }}">
            @foreach ($wdTabs as $key => $label)
                <a href="{{ route('finance.withdrawal.index', $key === 'rows' ? [] : ['tab' => $key]) }}"
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

        @if ($tab === 'standing')
            {{-- ── কে কোথায় দাঁড়িয়ে ─────────────────────────────────────────── --}}
            <section>
                <h2 class="flex flex-wrap items-center gap-2 border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                    {{ __('finance::field.where_each_stands') }}
                    <span class="text-xs font-normal text-(--color-ink-muted)">
                        {{ __('finance::field.month') }}: {{ $month }}
                    </span>
                </h2>

                <x-ui.table
                    :compact="request()->boolean('compact')"
                    :empty="__('finance::message.no_withdrawal_yet')"
                    :rows="$standing"
                    :columns="$standColumns" />
            </section>

            {{-- ── মাসিক সীমা ────────────────────────────────────────────
                 একই পর্দায়, ইচ্ছাকৃতভাবে: সীমা পেরোলে সেবাটা আটকায়, আর
                 বদলানোর ঘরটা অন্য পাতায় থাকলে ব্যবহারকারী খুঁজতে যেতেন না
                 — তাঁরা ধরে নিতেন জিনিসটা নষ্ট। --}}
            @if (auth()->user()?->can('finance.withdrawal.cap'))
                <section class="border-t border-(--color-border) p-4">
                    <h2 class="mb-1 font-semibold">{{ __('finance::field.set_a_cap') }}</h2>

                    <p class="mb-3 text-2xs text-(--color-ink-muted)">
                        {{ __('finance::message.cap_can_be_changed_here') }}
                    </p>

                    <form method="POST" action="{{ route('finance.withdrawal.cap') }}"
                          class="grid max-w-4xl gap-3 sm:grid-cols-2">
                        @csrf

                        {{-- ⛔ সীমাটা এখন ব্যক্তির সারির উপর বসে, নামের উপর নয়।

                             আগে এখানে নাম টাইপ করা হত, আর উত্তোলনেও নাম টাইপ
                             করা হত — দুইটা বানান আলাদা হলেই সীমাটা খুঁজে
                             পাওয়া যেত না আর চুপচাপ কিছুই আটকাত না। --}}
                        <div class="sm:col-span-2">
                            @include('finance::components.person-picker', [
                                'people' => $people,
                                'label' => __('finance::field.who'),
                                'required' => true,
                            ])
                        </div>

                        <x-ui.field name="monthly_cap" type="number" step="0.01" numeric
                                    :label="__('finance::field.monthly_cap')"
                                    :hint="__('finance::field.no_cap')" />

                        {{-- ⛔ আগে বোতামটা একটা `flex items-end`-এ পুরো প্রস্থে টানা ছিল।
                             ⓘ এখন অন্য ফর্মের মতো নিজের সারিতে, নিচে বাঁয়ে। --}}
                        <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('core.action.save') }}
                            </x-ui.button>
                        </div>
                    </form>
                </section>
            @endif
        @else
            {{-- ── যা যা তোলা হয়েছে ──────────────────────────────────────────
                 প্রতিটা সংখ্যা তার ভাউচারে নামায় — নিয়ম ১। --}}
            <x-ui.table
                :compact="request()->boolean('compact')"
                :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_withdrawal_yet')"
                :rows="$rows"
                :columns="$wdColumns" />

            <x-ui.pager :rows="$rows" />
        @endif
    </div>
</x-layouts.app>
