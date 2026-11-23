{{-- বেতনের রানের তালিকা — নতুন মাস আগে।
     ⛔ seen_* — সীমিত ম্যানেজারের নাগালের বেতনশিটের মোট (পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ৫); না থাকলে রানের নিজের মোট। --}}
@php
    // ⛔ বেতনের অঙ্ক কেবল `hr.salary.view`-এ — চাবি ছাড়া ঘরে ঢাকনা, যোগফলের পট্টিও নয়; তালিকার CSV পর্দার ঘরই নেয় (fe, ২১ অক্টোবর ২০২৬)
    $pay = $pay ?? auth()->user()?->can('hr.salary.view');
    $money = fn ($value) => $pay ? \App\Core\Support\Money::format($value) : \App\Core\Security\FieldSecurity::mask();
    $columns = [
        ['key' => 'document_no', 'label' => __('hr::field.document_no'), 'width' => '11rem',
         'render' => fn ($r) => view('hr::payroll.partials.no', ['run' => $r])],
        ['key' => 'month', 'label' => __('hr::field.month'), 'width' => '10rem',
         'render' => fn ($r) => $r->month->locale(app()->getLocale())->translatedFormat('F Y')],
        ['key' => 'employee_count', 'label' => __('hr::field.employee_count'), 'numeric' => true,
         'width' => '7rem', 'render' => fn ($r) => $r->seen_count ?? $r->employee_count],
        ['key' => 'gross_total', 'total' => $pay ? 'money' : null, 'label' => __('hr::field.gross'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => $money($r->seen_gross ?? $r->gross_total)],
        ['key' => 'deduction_total', 'total' => $pay ? 'money' : null, 'label' => __('hr::field.deductions'), 'numeric' => true,
         'width' => '10rem', 'render' => fn ($r) => $money($r->seen_deductions ?? $r->deduction_total)],
        ['key' => 'net_total', 'total' => $pay ? 'money' : null, 'label' => __('hr::field.net'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => $money($r->seen_net ?? $r->net_total)],
        ['key' => 'status', 'label' => __('hr::field.status'), 'width' => '8rem',
         'render' => fn ($r) => __('core.status.' . $r->status)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.payroll') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ⭐ শিরোনাম, "নতুন রান" আর খোঁজা — সব এক বাক্সে, বাকি তালিকার মতো।
             মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*। --}}
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::menu.payroll')"
                :columns="$columns"
                :search-placeholder="__('hr::message.payroll_search')">
                <x-slot:actions>
                    {{-- ⭐ দেওয়ার অপেক্ষায় বকেয়া মজুরি (fe, ২১ অক্টোবর ২০২৬) --}}
                    <x-ui.button :href="route('hr.payroll.held.index')">{{ __('hr::payroll.held_ready_title') }}</x-ui.button>
                    @can('hr.payroll.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('hr.payroll.create')">
                            {{ __('hr::action.new_run') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand ?? []"
            :row-menu="fn ($r) => view('hr::payroll.partials.row-menu', ['run' => $r])"
            :compact="\App\Core\Support\Ui::compact()"
            :empty="request('q') ? __('core.empty.no_results') : __('hr::message.no_runs')"
            :rows="$runs"
            :columns="$columns" />
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>
    <x-ui.list-totals :rows="$runs" :grand="$grand ?? []" :columns="$columns" />
</x-layouts.app>
