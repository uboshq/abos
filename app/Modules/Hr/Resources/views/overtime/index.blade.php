{{--
    ⭐ অধিকালের শিট — এক মাসের, কর্মী ধরে (BLA ধারা ১০৮; a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।

    হাজিরা থেকে বাড়তি ঘণ্টা গুনে শিট "সইয়ের অপেক্ষায়" বসে; কর্মীর ব্যবস্থাপক "সই দিন" চাপলে "অনুমোদিত", আর কেবল তখনই
    বেতনের রান ঘণ্টাগুলো নেয়। এখানে টাকার অঙ্ক নেই — সেটা বেতনের স্লিপে, বেতনের চাবির পিছনে।
--}}
@php
    $columns = [
        ['key' => 'employee', 'label' => __('hr::field.employee'),
         'render' => fn ($s) => $s->employee?->label() ?? '—'],
        ['key' => 'days', 'label' => __('hr::field.ot_days'), 'numeric' => true, 'width' => '7rem',
         'render' => fn ($s) => (string) $s->days],
        ['key' => 'hours', 'label' => __('hr::field.ot_hours'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($s) => bcadd((string) $s->hours, '0', 2)],
        ['key' => 'status', 'label' => __('hr::field.status'), 'width' => '10rem',
         'render' => fn ($s) => $s->isSigned() ? __('hr::kind.ot_signed') : __('hr::kind.ot_waiting')],
        ['key' => 'signer', 'label' => __('hr::field.ot_signed_by'), 'width' => '12rem',
         'render' => fn ($s) => $s->signer?->name ?? '—'],
        ['key' => 'actions', 'label' => '—', 'width' => '8rem',
         'render' => fn ($s) => view('hr::overtime.partials.sign', ['sheet' => $s, 'may' => $overtime->maySign(auth()->user(), $s)])],
    ];
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::month('month', __('hr::field.month'), $month->format('Y-m')),
        \App\Core\ListFilter\Facets::pick('status', __('hr::field.status'), ['signed' => __('hr::kind.ot_signed'), 'waiting' => __('hr::kind.ot_waiting')]),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.overtime') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
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

    {{--
        ⭐ পাতার মাথা এক সারিতে — নাম · মাস … "হাজিরা থেকে বানান" (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "no description line on any screen;
        title+tabs+buttons one row")। চালু/বন্ধ, হিসাবের সূত্র আর "শ্রমিক" কে (BLA ধারা 2(65)) — নামের পাশে ⓘ-এ, আলাদা বাক্সে নয়।
        ⓘ বানানোর ফর্মটা টুলবারের GET ফর্মের বাইরে (ফর্মের ভিতরে ফর্ম হয় না) — বোতাম `form` দিয়ে তাকে জমা দেয়।
    --}}
    @can('hr.attendance.manage')
        <form id="ot-prepare" method="POST" action="{{ route('hr.overtime.store') }}" class="hidden">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        </form>
    @endcan

    {{-- ⓘ আগের লেখাগুলো `data-page-subtitle`-এ — "ABOS" আর "Dynamic"-এ লুকায় (ⓘ-এ যায়), বাকি রূপে যেমন ছিল ([[ui/page-info]]) --}}
    <div data-page-subtitle class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-2 text-sm">
            {{ __($enabled ? 'hr::message.ot_on_note' : 'hr::message.ot_off_note', ['hours' => $dailyHours, 'types' => $eligible === '' ? '—' : $eligible]) }}
        </p>
        <p class="text-2xs text-(--color-ink-muted)">{{ __('hr::message.ot_formula_note') }}</p>
        {{-- ⭐ BLA ধারা 2(65) — ব্যবস্থাপক/প্রশাসনিক কর্মী শ্রমিক নন, অধিকাল নেই (a6, ২১ অক্টোবর ২০২৬) --}}
        <p class="mt-1 text-2xs text-(--color-ink-muted)" data-worker-note>{{ __('hr::message.worker_note') }}</p>
    </div>

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::menu.overtime') . ' · ' . $month->translatedFormat('M Y')" :columns="$columns" :search="$abosHead" :search-placeholder="__('hr::field.employee')" :facets="$facetPanel"
                          :sort="$abosHead ? ['employee' => __('hr::field.employee'), 'hours' => __('hr::field.ot_hours'), 'days' => __('hr::field.ot_days')] : []"
                          :info="__($enabled ? 'hr::message.ot_on_note' : 'hr::message.ot_off_note', ['hours' => $dailyHours, 'types' => $eligible === '' ? '—' : $eligible])
                              .' '.__('hr::message.ot_formula_note').' '.__('hr::message.worker_note')">
                @unless ($abosHead)
                <x-ui.month name="month" :value="$month->format('Y-m')"
                            class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm" />
                @endunless
                @can('hr.attendance.manage')
                    <x-slot:actions>
                        <x-ui.button type="submit" form="ot-prepare" tone="primary">{{ __('hr::action.ot_prepare') }}</x-ui.button>
                    </x-slot:actions>
                @endcan
            </x-ui.toolbar>
        </form>

        {{-- ⭐ নিচে যোগের সারি — দিন আর ঘণ্টা (fe, ২১ অক্টোবর ২০২৬, দফা ৯) --}}
        <x-ui.table :rows="$sheets" :columns="$columns" :empty="__('hr::message.ot_none')"
                    :totals="[
                        'days' => (string) collect($sheets->items())->sum('days'),
                        'hours' => collect($sheets->items())->reduce(fn ($c, $s) => bcadd($c, (string) $s->hours, 2), '0.00'),
                    ]"
                    :totalsLabel="__('core.print.total')" />

        <x-ui.pager :rows="$sheets" />
        <x-ui.list-totals :rows="$sheets" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
