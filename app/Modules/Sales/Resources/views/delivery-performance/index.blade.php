{{--
    ডেলিভারির মাপকাঠি — OTIF %, আদেশ থেকে রওনার গড় সময়, দেরির তালিকা ([[DeliveryPerformanceController]], ৪ অক্টোবর ২০২৬)।
    ⓘ সংজ্ঞা একটাই জায়গায় — [[DeliveryPerformance]]; এখানে কেবল দেখানো। মালিকের নিয়ম: এক লাইনে এক জিনিস।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Modules\Sales\Services\DeliveryStage;

    $today = \Illuminate\Support\Carbon::today();
    $hours = $summary['avg_hours'];

    $cards = [
        ['otif', __('sales::delivery_performance.otif'),
            $summary['percent'] === null ? '—' : $summary['percent'].'%',
            __('sales::delivery_performance.otif_hint', ['otif' => $summary['otif'], 'due' => $summary['due']])],
        ['on_time', __('sales::delivery_performance.on_time'), $summary['on_time'].' / '.$summary['due'], __('sales::delivery_performance.on_time_hint')],
        ['in_full', __('sales::delivery_performance.in_full'), $summary['in_full'].' / '.$summary['due'], __('sales::delivery_performance.in_full_hint')],
        ['lead', __('sales::delivery_performance.lead'),
            $hours === null ? '—' : __('sales::delivery_performance.days_hours', ['days' => intdiv($hours, 24), 'hours' => $hours % 24]),
            __('sales::delivery_performance.lead_hint')],
        ['late', __('sales::delivery_performance.late'), (string) $summary['late'], __('sales::delivery_performance.late_hint')],
    ];

    $columns = [
        [
            'key' => 'document_no',
            'label' => __('sales::field.document_no'),
            'width' => '11rem',
            'render' => fn ($d) => view('sales::components.doc-link', ['document' => $d, 'route' => 'sales.challan.show']),
        ],
        [
            'key' => 'customer_id',
            'label' => __('sales::field.customer'),
            'render' => fn ($d) => $d->customer?->name() ?? '—',
        ],
        [
            'key' => 'order_no',
            'label' => __('sales::delivery_performance.order'),
            'width' => '9rem',
            'render' => fn ($d) => $d->order_no ?: '—',
        ],
        [
            'key' => 'promised_on',
            'label' => __('sales::delivery_performance.promised'),
            'width' => '8rem',
            'render' => fn ($d) => DateFormat::format($d->promised_on),
        ],
        [
            'key' => 'arrived_on',
            'label' => __('sales::delivery_performance.arrived'),
            'width' => '9rem',
            'render' => fn ($d) => $d->arrived_on ? DateFormat::format($d->arrived_on) : __('sales::delivery_performance.not_yet'),
        ],
        [
            'key' => 'days_late',
            'label' => __('sales::delivery_performance.days_late'),
            'width' => '7rem',
            'numeric' => true,
            // ⓘ পূর্ণ দিন, অ্যাপের ঘড়িতে — পৌঁছায়নি তো আজ পর্যন্ত
            'render' => fn ($d) => (string) (int) \Illuminate\Support\Carbon::parse($d->promised_on)
                ->diffInDays($d->arrived_on ? \Illuminate\Support\Carbon::parse($d->arrived_on) : $today),
        ],
        [
            'key' => 'stage',
            'label' => __('sales::field.state'),
            'width' => '9rem',
            'render' => fn ($d) => $d->stage ? DeliveryStage::label((string) $d->stage) : '—',
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::delivery_performance.title') }}</x-slot:title>

    <section data-performance-cards class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($cards as [$key, $label, $value, $hint])
            <div data-card="{{ $key }}" data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-sm text-(--color-ink-muted)">{{ $label }}</p>
                <p class="tabular text-2xl font-bold text-(--color-brand-700)">{{ $value }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ $hint }}</p>
            </div>
        @endforeach
    </section>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::delivery_performance.late_list')" :search="false"
                          :count="__('sales::delivery_performance.range', ['from' => DateFormat::format($dates['from']), 'to' => DateFormat::format($dates['to'])])"
                          :columns="$columns">
                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :view-url="fn ($d) => route('sales.challan.show', $d)"
            :empty="__('sales::delivery_performance.nothing_late')"
            :rows="$late"
            :columns="$columns" />

        <x-ui.pager :rows="$late" />
    </div>
</x-layouts.app>
