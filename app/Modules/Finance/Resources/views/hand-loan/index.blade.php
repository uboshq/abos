{{--
    হাতধার — কে আমার কাছে পায়, আর আমি কার কাছে পাই।

    ── কেন একটাই তালিকা, দুইটা নয় ──────────────────────────────────────
    "পাওনা" আর "দেনা" আলাদা দুইটা পর্দা হলে কেউ ওদের মিলিয়ে দেখত না, আর
    একই মানুষ দুই তালিকায় থাকতে পারতেন — একদিকে পাঁচ হাজার পাওনা,
    অন্যদিকে তিন হাজার দেনা, অথচ সত্যিটা দুই হাজার। চিহ্নটাই ভাগ করে
    দেয়, আর যোগফল দুইটা উপরে আলাদা করে বলা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.hand_loan') }}</x-slot:title>

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

    {{--
        ⭐ কে কোথায় দাঁড়িয়ে — এখন উপরে, অন্য সব তালিকার মতো (১৯ সেপ্টেম্বর ২০২৬)।

        ── মালিকের প্রশ্ন ────────────────────────────────────────────────
        *"এগুলোর লিস্ট, পিপল লিস্ট কোথায়?"* ⓘ তালিকাটা ছিল, কিন্তু লম্বা
        ফর্মের নিচে চাপা — পাতা খুললে প্রথমে চোখে পড়ত খালি ঘর, মানুষ নয়।
        ⭐ এখন সব তালিকার একই গড়ন: টুলবার (শিরোনাম · বর্ণনা · + নতুন হাতধার)
        → দুই দিকের যোগফল → তালিকা। ফর্মটা নিজের পাতায় (`create`), অন্য
        মডিউলের মতো — তালিকার উপরে বসানো নয় (মালিক, abos-8b-এর মাধ্যমে)।
    --}}
    @php
        $hlColumns = [
            ['key' => 'person', 'label' => __('finance::field.person_name'),
             'render' => fn ($r) => view('finance::hand-loan.partials.person', ['row' => $r])],
            ['key' => 'side', 'label' => __('finance::field.hl_side'), 'width' => '10rem',
             'render' => fn ($r) => view('finance::hand-loan.partials.side', ['row' => $r])],
            ['key' => 'principal', 'label' => __('finance::field.hl_total'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($r) => $r['account']->principal === null ? '—'
                 : \App\Core\Support\Money::format($r['account']->principal)],
            ['key' => 'returned', 'label' => __('finance::field.hl_returned'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($r) => view('finance::hand-loan.partials.returned', ['row' => $r])],
            ['key' => 'balance', 'label' => __('finance::field.hand_loan_balance'),
             'numeric' => true, 'width' => '11rem',
             'render' => fn ($r) => view('finance::hand-loan.partials.balance', ['row' => $r])],
            ['key' => 'due', 'label' => __('finance::field.hl_due'), 'width' => '8rem',
             'render' => fn ($r) => ($r['account']->next_due_on ?? $r['account']->due_on) === null ? '—'
                 : \App\Core\Support\DateFormat::format($r['account']->next_due_on ?? $r['account']->due_on)],
            ['key' => 'state', 'label' => __('finance::field.hl_state'), 'width' => '9rem',
             'render' => fn ($r) => view('finance::hand-loan.partials.state', ['row' => $r])],
            ['key' => 'do', 'label' => __('core.table.actions'), 'width' => '7rem',
             'render' => fn ($r) => view('finance::hand-loan.partials.open-it', ['row' => $r])],
        ];
    @endphp

    <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ খোঁজা বা ঘনত্ব বদলালেও ট্যাবটা থাকে --}}
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif

            {{-- ⭐ ১৯ সেপ্টেম্বর ২০২৬ — *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"* --}}
            <x-ui.toolbar :title="__('finance::menu.hand_loan')"
                          :subtitle="__('finance::message.hand_loan_note')"
                          :columns="$hlColumns"
                          :search-placeholder="__('finance::message.hand_loan_search')"
                          :quiet="['tab']">
                <x-slot:actions>
                    @can('finance.hand_loan.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.hand_loan.create')">
                            {{ __('finance::action.new_hand_loan') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        {{-- ── দুই দিকের যোগফল — টুলবারের নিচে, তালিকার উপরে ──────────────
             ⓘ খোঁজায় ছাঁকা হয় না: "কত পাব, কত দেব" পুরো প্রতিষ্ঠানের প্রশ্ন। --}}
        <section class="grid gap-3 border-b border-(--color-border) p-3 sm:grid-cols-3">
            @foreach ([
                ['finance::field.owed_to_us', \App\Core\Support\Money::format($standing['owed_to_us'])],
                ['finance::field.we_owe', \App\Core\Support\Money::format($standing['we_owe'])],
                ['finance::field.how_many_people', count($standing['rows'])],
            ] as [$label, $value])
                <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-app) p-3">
                    <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        {{-- ⭐ ট্যাবের সারি — মূলধনের পাতার হুবহু গড়ন (মালিকের নমুনা, ১৯ সেপ্টেম্বর ২০২৬) --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.hand_loan') }}">
            @foreach (['all' => __('finance::field.hl_tab_all'),
                       'they' => __('finance::message.hand_loan_they_owe'),
                       'we' => __('finance::message.hand_loan_we_owe')] as $key => $label)
                <a href="{{ route('finance.hand_loan.index', array_filter([
                        'tab' => $key === 'all' ? null : $key,
                        'q' => request('q'),
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

        <x-ui.table :rows="$rows"
                    :columns="$hlColumns"
                    :compact="request()->boolean('compact')"
                    :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_hand_loan_yet')" />
    </div>

</x-layouts.app>
