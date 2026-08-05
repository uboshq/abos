{{-- বেতনের রানের তালিকা — নতুন মাস আগে। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.payroll') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::menu.payroll')">
            <x-slot:actions>
                @can('hr.payroll.manage')
                    <x-ui.button tone="primary" icon="+" :href="route('hr.payroll.create')">
                        {{ __('hr::action.new_run') }}
                    </x-ui.button>
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('hr::message.no_runs')"
            :rows="$runs"
            :columns="[
                ['key' => 'document_no', 'label' => __('hr::field.document_no'), 'width' => '11rem',
                 'render' => fn ($r) => view('hr::payroll.partials.no', ['run' => $r])],
                ['key' => 'month', 'label' => __('hr::field.month'), 'width' => '10rem',
                 'render' => fn ($r) => $r->month->format('M Y')],
                ['key' => 'employee_count', 'label' => __('hr::field.employee_count'), 'numeric' => true,
                 'width' => '7rem', 'render' => fn ($r) => $r->employee_count],
                ['key' => 'gross_total', 'label' => __('hr::field.gross'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($r) => number_format((float) $r->gross_total, 2)],
                ['key' => 'deduction_total', 'label' => __('hr::field.deductions'), 'numeric' => true,
                 'width' => '10rem', 'render' => fn ($r) => number_format((float) $r->deduction_total, 2)],
                ['key' => 'net_total', 'label' => __('hr::field.net'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($r) => number_format((float) $r->net_total, 2)],
                ['key' => 'status', 'label' => __('hr::field.status'), 'width' => '8rem',
                 'render' => fn ($r) => __('core.status.' . $r->status)],
            ]" />
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>
</x-layouts.app>
