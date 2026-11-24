{{--
    ⭐ গ্র্যাচুইটি — একটা দিনে প্রতিজনের দায়, নিচে যোগ; পাশে এ পর্যন্ত খাতায় বসা সঞ্চিতি (BLA ধারা 2(10), IAS 19; fe, ২১ অক্টোবর ২০২৬, দফা ৮)।
    ⛔ পুরো পাতাটাই টাকা — দরজায় বেতনের চাবি।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $columns = [
        ['key' => 'employee', 'label' => __('hr::field.employee'), 'render' => fn ($r) => $r['employee']->label()],
        ['key' => 'joining', 'label' => __('hr::field.joining_date'), 'width' => '8rem',
         'render' => fn ($r) => $r['employee']->joining_date?->format('d M Y') ?? '—'],
        ['key' => 'years', 'label' => __('hr::field.gratuity_years'), 'numeric' => true, 'width' => '7rem', 'render' => fn ($r) => (string) $r['years']],
        ['key' => 'days', 'label' => __('hr::field.gratuity_days'), 'numeric' => true, 'width' => '7rem', 'render' => fn ($r) => (string) $r['days']],
        ['key' => 'basic', 'label' => __('hr::field.gratuity_basic'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($r) => $money($r['basic'])],
        ['key' => 'obligation', 'label' => __('hr::field.gratuity_obligation'), 'numeric' => true, 'width' => '10rem', 'render' => fn ($r) => $money($r['obligation'])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.report_gratuity') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::menu.report_gratuity')" />
    </x-slot:header>

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

    {{-- ⭐ নিরীক্ষকের জন্য এক লাইন — IAS 19-এর সরলীকরণ (fe, ২১ অক্টোবর ২০২৬) --}}
    <p class="mb-2 text-2xs text-(--color-ink-muted)" data-ias19-note>{{ __('hr::message.gratuity_ias19') }}</p>

    <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-2 text-sm">{{ __($enabled ? 'hr::message.gratuity_on_note' : 'hr::message.gratuity_off_note') }}</p>
        <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('hr::message.gratuity_formula_note') }}</p>

        <form method="GET" action="{{ route('hr.gratuity.report') }}" class="grid gap-3 sm:grid-cols-[14rem_1fr] sm:items-end">
            <x-ui.field name="on" type="date" :label="__('hr::field.as_of')" :value="$on->toDateString()" required />
            <x-ui.button type="submit">{{ __('hr::action.pf_preview') }}</x-ui.button>
        </form>

        @can('hr.payroll.manage')
            <form method="POST" action="{{ route('hr.gratuity.store') }}" class="mt-3">
                @csrf
                <input type="hidden" name="month" value="{{ $on->format('Y-m') }}">
                <x-ui.button type="submit" tone="primary">{{ __('hr::action.gratuity_book', ['month' => $on->format('M Y')]) }}</x-ui.button>
            </form>
        @endcan
    </div>

    <x-ui.table :rows="$rows" :columns="$columns" :empty="__('core.empty.no_results')"
                :totals="['obligation' => $money($total)]" :totalsLabel="__('core.print.total')" />

    <p class="mt-3 text-sm" data-gratuity-booked>
        {{ __('hr::message.gratuity_booked_so_far', ['booked' => $money($booked), 'total' => $money($total)]) }}
    </p>
</x-layouts.app>
