{{--
    ⭐ ভবিষ্য তহবিলের প্রস্তাবিত বদল (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund::proposal()]])।

    প্রতিজনের আজকের কর্মী-ভাগ আর প্রস্তাবিত ভাগ পাশাপাশি। কিছুই নিজে বসে না — "বসান" চাপলে তবে বেছে নেওয়া তারিখ থেকে,
    কেবল যে সদস্যদের হার আলাদা, বেতনের কাঠামোর সাধারণ দরজা দিয়ে (দ্বিতীয় সই চালু থাকলে সেটাও লাগে)।
    ⛔ পুরো পাতাটাই টাকা — দরজায় বেতনের চাবি।
--}}
@php
    // ⓘ হার সবসময় চার দশমিকে আসে ("10.0000") — শেষের শূন্য ছাঁটা, পুরো শূন্য হলে "0"
    $percent = fn (?string $p) => $p === null ? '—' : (rtrim(rtrim(bcadd($p, '0', 4), '0'), '.') ?: '0').'%';
    $columns = [
        ['key' => 'employee', 'label' => __('hr::field.employee'),
         'render' => fn ($r) => $r['employee']->label()],
        ['key' => 'member', 'label' => __('hr::field.pf_member'), 'width' => '8rem',
         'render' => fn ($r) => $r['member'] ? __('hr::kind.pf_member') : __('hr::kind.pf_not_member')],
        ['key' => 'current_percent', 'label' => __('hr::field.pf_current_percent'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($r) => $percent($r['current_percent'])],
        ['key' => 'current', 'label' => __('hr::field.pf_current'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['current'])],
        ['key' => 'proposed_percent', 'label' => __('hr::field.pf_proposed_percent'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($r) => $r['member'] ? $percent($r['proposed_percent']) : '—'],
        ['key' => 'proposed', 'label' => __('hr::field.pf_proposed'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['proposed'])],
        ['key' => 'employer', 'label' => __('hr::field.pf_employer'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['employer'])],
        ['key' => 'changes', 'label' => __('hr::field.pf_changes'), 'width' => '7rem',
         'render' => fn ($r) => $r['changes'] ? __('hr::kind.pf_changes') : '—'],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.pf_proposal') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::menu.pf_proposal')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
            @if (session('pf_skipped'))
                <ul class="list-inside list-disc">
                    @foreach (session('pf_skipped') as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-2 text-sm">
            {{ __($enabled ? 'hr::message.pf_on_note' : 'hr::message.pf_off_note', ['employee' => $percent($employeePercent), 'employer' => $percent($employerPercent)]) }}
        </p>
        <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('hr::message.pf_proposal_note') }}</p>

        <form method="GET" action="{{ route('hr.pf.proposal') }}" class="grid gap-3 sm:grid-cols-[14rem_1fr] sm:items-end">
            <x-ui.field name="on" type="date" :label="__('hr::field.effective_from')" :value="$on->toDateString()" required />
            <x-ui.button type="submit">{{ __('hr::action.pf_preview') }}</x-ui.button>
        </form>
    </div>

    @if ($head === null)
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-8 text-center">
            <p class="text-sm text-(--color-ink-muted)">{{ __('hr::validation.pf_head_missing') }}</p>
        </div>
    @else
        @php
            // ⭐ প্রস্তাব কেবল সদস্যদের — অ-সদস্যরা নিচে আলাদা তালিকায় (fe, দফা ১); নিচে যোগের সারি (দফা ৯)
            $members = $rows->where('member', true)->values();
            $sum = fn ($list, string $key) => \App\Core\Support\Money::format($list->reduce(fn ($c, $r) => bcadd($c, (string) $r[$key], 4), '0'));
        @endphp
        <h2 class="mb-2 font-semibold">{{ __('hr::message.pf_members_title') }}</h2>
        <x-ui.table :rows="$members" :columns="$columns" :empty="__('core.empty.no_results')"
                    :totals="['current' => $sum($members, 'current'), 'proposed' => $sum($members, 'proposed'), 'employer' => $sum($members, 'employer')]"
                    :totalsLabel="__('core.print.total')" />

        @can('hr.salary.manage')
            @if ($rows->contains('changes', true))
                <form method="POST" action="{{ route('hr.pf.store') }}"
                      class="mt-3 flex flex-wrap items-center justify-end gap-2">
                    @csrf
                    <input type="hidden" name="effective_from" value="{{ $on->toDateString() }}">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('hr::message.pf_apply_note', ['date' => $on->format('d M Y')]) }}</span>
                    <x-ui.button type="submit" tone="primary">{{ __('hr::action.pf_apply') }}</x-ui.button>
                </form>
            @endif
        @endcan

        {{-- ⭐ সদস্য নন, অথচ এক বছরের চাকরি — যোগ দেওয়ার অধিকার আছে (BLA ধারা ২৬৪); কেউ নিজে থেকে যোগ হন না, প্রতিজনের আলাদা বোতাম --}}
        <h2 class="mb-2 mt-4 font-semibold" data-pf-candidates>{{ __('hr::message.pf_candidates_title') }}</h2>
        <x-ui.table :rows="$candidates" :empty="__('hr::message.pf_no_candidates')"
                    :columns="[
                        ['key' => 'employee', 'label' => __('hr::field.employee'), 'render' => fn ($r) => $r['employee']->label()],
                        ['key' => 'proposed', 'label' => __('hr::field.pf_proposed'), 'numeric' => true, 'width' => '9rem',
                         'render' => fn ($r) => \App\Core\Support\Money::format($r['proposed'])],
                        ['key' => 'employer', 'label' => __('hr::field.pf_employer'), 'numeric' => true, 'width' => '9rem',
                         'render' => fn ($r) => \App\Core\Support\Money::format($r['employer'])],
                        ['key' => 'enrol', 'label' => '—', 'width' => '10rem',
                         'render' => fn ($r) => view('hr::pf.partials.enrol', ['employee' => $r['employee'], 'on' => $on])],
                    ]"
                    :totals="['proposed' => $sum($candidates, 'proposed'), 'employer' => $sum($candidates, 'employer')]"
                    :totalsLabel="__('core.print.total')" />
    @endif
</x-layouts.app>
