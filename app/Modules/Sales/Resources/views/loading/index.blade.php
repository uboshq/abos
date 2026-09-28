{{--
    লোডিং শিট — খোলা ট্রিপের তালিকা (এখনো বেরোয়নি)। ⓘ বেরোনো ট্রিপ ডিসপ্যাচ রেজিস্টারে।
--}}
@php
    use App\Core\Support\DateFormat;

    $columns = [
        [
            'key' => 'document_no',
            'label' => __('sales::loading.column.trip'),
            'width' => '10rem',
            'render' => fn ($t) => new \Illuminate\Support\HtmlString('<a class="text-(--color-brand-500) underline-offset-2 hover:underline" href="'
                .e(route('sales.loading_sheet.show', $t)).'">'.e($t->document_no).'</a>'),
        ],
        [
            'key' => 'trx_date',
            'label' => __('sales::loading.column.date'),
            'width' => '8rem',
            'render' => fn ($t) => DateFormat::format($t->trx_date),
        ],
        [
            'key' => 'vehicle',
            'label' => __('sales::loading.column.vehicle'),
            'render' => fn ($t) => $t->vehicle_no ?: '—',
        ],
        [
            'key' => 'driver',
            'label' => __('sales::loading.column.driver'),
            'render' => fn ($t) => $t->driver_name ?: '—',
        ],
        [
            'key' => 'challans',
            'label' => __('sales::loading.column.challans'),
            'width' => '7rem',
            'numeric' => true,
            'render' => fn ($t) => (string) $t->lines_count,
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::loading.title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('sales::loading.title')" :count="__('sales::loading.subtitle')" :columns="$columns" />

        <x-ui.table :empty="__('sales::loading.empty')" :rows="$trips" :columns="$columns" />

        <x-ui.pager :rows="$trips" />
    </div>
</x-layouts.app>
