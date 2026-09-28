{{--
    গেট পাস — তালিকা। রওনার মুহূর্তে নিজে তৈরি ([[GatePassService]]); এখানে কেবল খোঁজা, দেখা, ছাপা।
    ⓘ "নতুন" বোতাম নেই, ইচ্ছে করে — হাতে বানানো গেট পাস গেটে মিলত না।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Modules\Sales\Models\GatePass;

    $columns = [
        [
            'key' => 'document_no',
            'label' => __('sales::gate_pass.column.number'),
            'width' => '10rem',
            'render' => fn ($p) => new \Illuminate\Support\HtmlString('<a class="text-(--color-brand-500) underline-offset-2 hover:underline" href="'
                .e(route('sales.gate_pass.show', $p)).'">'.e($p->document_no).'</a>'),
        ],
        [
            'key' => 'challan',
            'label' => __('sales::gate_pass.column.challan'),
            'width' => '10rem',
            'render' => fn ($p) => $p->challan?->document_no ?? '—',
        ],
        [
            'key' => 'customer',
            'label' => __('sales::gate_pass.column.customer'),
            'render' => fn ($p) => $p->challan?->customer?->name() ?? '—',
        ],
        [
            'key' => 'vehicle',
            'label' => __('sales::gate_pass.column.vehicle'),
            'width' => '10rem',
            'render' => fn ($p) => $p->vehicle_no ?: '—',
        ],
        [
            'key' => 'issued_at',
            'label' => __('sales::gate_pass.column.issued_at'),
            'width' => '10rem',
            'render' => fn ($p) => DateFormat::format($p->issued_at),
        ],
        [
            'key' => 'status',
            'label' => __('sales::gate_pass.column.status'),
            'width' => '8rem',
            'render' => fn ($p) => new \Illuminate\Support\HtmlString('<span data-gate-pass="'.e($p->status).'" class="inline-flex rounded-full px-2 py-0.5 text-2xs '
                .($p->status === GatePass::CANCELLED
                    ? 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)'
                    : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)')
                .'">'.e(__('sales::gate_pass.status.'.$p->status)).'</span>'),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::gate_pass.title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::gate_pass.title')" :count="__('sales::gate_pass.subtitle')"
                          :columns="$columns" :search-placeholder="__('sales::gate_pass.search')">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::gate_pass.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table :empty="__('sales::gate_pass.empty')" :rows="$passes" :columns="$columns" />

        <x-ui.pager :rows="$passes" />
    </div>
</x-layouts.app>
