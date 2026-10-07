{{--
    ডেলিভারি ট্র্যাকিং — প্রতিটা বিক্রি এখন কোথায় (মালিক, ২ অক্টোবর ২০২৬: *"Delivery Traking"*, ফোন আর ওয়েব দুই জায়গায়)।

    ⭐ সাধারণ টুলবার আর টেবিল — মালিক: *"Delivery tracking টুলবার dibe"*: খোঁজা, ছাঁকনি (ধাপ, তারিখ, গ্রাহক, শাখা),
    সাজানো, কলাম, রপ্তানি, ছাপা, ঘনত্ব, শেয়ার, নতুন করে আনা; নিচে সর্বমোট, সারিতে "দেখুন"।
    ⓘ ফোনের সাথে একই হিসাব ([[SaleTracking]]) — দুই জায়গায় দুই রকম ধাপ কখনো নয়। ধাপের ট্যাব থাকে।
    ⓘ প্রতি ৩০ সেকেন্ডে কেবল টেবিলটা নিজে নতুন ([[screens.js::liveRefresh]]) — খোঁজা আর ছাঁকনি যেমন ছিল তেমনই।
--}}
@php
    $trackingColours = \App\Modules\Sales\Services\SaleTracking::COLOURS;

    $columns = [
        [
            'key' => 'date',
            'label' => __('sales::field.date'),
            'width' => '7rem',
            'render' => fn ($r) => $r['date'] ? \App\Core\Support\DateFormat::format($r['date']) : '—',
        ],
        [
            'key' => 'no',
            'label' => __('sales::field.document_no'),
            'width' => '11rem',
            'render' => fn ($r) => new \Illuminate\Support\HtmlString(
                '<a href="'.e(route('sales.tracking.show', [$r['kind'], $r['id']])).'" class="font-semibold text-(--color-brand-500) hover:underline">'.e($r['no']).'</a>'),
        ],
        [
            'key' => 'customer',
            'label' => __('sales::field.customer'),
            'render' => fn ($r) => $r['customer'] ?? '—',
        ],
        [
            'key' => 'total',
            'total' => 'money',
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['total']),
        ],
        [
            'key' => 'step',
            'label' => __('sales::field.state'),
            'width' => '10rem',
            'render' => fn ($r) => new \Illuminate\Support\HtmlString(
                '<span class="font-medium" style="color: '.e($trackingColours[$r['category']] ?? '#111827').'">'
                .e(__('sales::tracking.step.'.$r['step'])).'</span>'),
        ],
        [
            'key' => 'billed',
            'label' => __('sales::tracking.step.billed'),
            'width' => '7rem',
            'render' => fn ($r) => $r['billed'] ? __('sales::tracking.yes') : '—',
        ],
    ];

    /*
     * ⭐ সারি থেকেই "পৌঁছেছে" — মালিক, ৩ অক্টোবর ২০২৬: "sob jaygathekei"। রওনা হওয়া চালানে, ধাপ বদলানোর চাবিতে
     * ([[DeliveryRowActions]]); ফর্ম যায় পুরনো `sales.delivery.move`-এ। পুরো পাতার এক প্রশ্নে।
     */
    $challanRows = \App\Modules\Sales\Models\DeliveryChallan::query()
        ->whereIn('public_id', collect($rows->items())->where('kind', 'challan')->pluck('id'))
        ->with('customer')->get()->keyBy('public_id');
    $arrive = app(\App\Modules\Sales\Services\DeliveryRowActions::class)->forChallans($challanRows->pluck('id'));

    if ($arrive !== []) {
        $columns[] = [
            'key' => 'arrive',
            'label' => __('sales::delivery.column.next'),
            'width' => '10rem',
            'render' => function ($r) use ($challanRows, $arrive) {
                $own = $r['kind'] === 'challan' ? ($challanRows[$r['id']] ?? null) : null;

                return $own !== null && isset($arrive[$own->id])
                    ? view('sales::delivery.partials.row-action', ['challan' => $own, 'choices' => $arrive[$own->id]['choices'], 'trip' => null, 'vehicles' => collect()])
                    : '';
            },
        ];
    }
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::tracking.title') }}</x-slot:title>

    {{-- ধাপের ট্যাব — রং আর সংখ্যাসহ --}}
    <div class="mb-3 flex flex-wrap gap-2" data-tracking-steps>
        @foreach (['all', ...\App\Modules\Sales\Services\SaleTracking::STEPS] as $key)
            @php $on = ($step ?? 'all') === $key; @endphp
            <a href="{{ route('sales.tracking.index', array_filter([...request()->except(['step', 'page']), 'step' => $key === 'all' ? null : $key])) }}"
               data-no-peek
               @class([
                   'rounded-(--radius-field) border px-3 py-1.5 text-sm',
                   'border-(--color-brand-500) text-(--color-brand-500)' => $on,
                   'border-(--color-border)' => ! $on,
               ])
               @if ($key !== 'all' && ! $on) style="border-left: 4px solid {{ $trackingColours[\App\Modules\Sales\Services\SaleTracking::CATEGORY_OF_STEP[$key]] }}" @endif>
                {{ $key === 'all' ? __('sales::tracking.all') : __('sales::tracking.step.'.$key) }}
                ({{ $list['counts'][$key] ?? 0 }})
            </a>
        @endforeach
    </div>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            @if ($step)
                <input type="hidden" name="step" value="{{ $step }}">
            @endif
            <x-ui.toolbar :title="__('sales::tracking.title')" :columns="$columns"
                          :search-placeholder="__('sales::tracking.search')" :sort="$sortOptions" :quiet="['step']">
                <x-ui.date-range :dates="$dates" />
                <x-ui.select name="customer" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="request('customer')" placeholder="—" />
                <x-ui.select name="branch" :label="__('sales::tracking.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="request('branch')" placeholder="—" />
            </x-ui.toolbar>
        </form>

        {{-- ⭐ প্রতি ৩০ সেকেন্ডে নিজে নতুন — কেবল এই ঘরটা ([[screens.js::liveRefresh]]) --}}
        <div data-live x-data="liveRefresh({ url: '{{ request()->fullUrl() }}', seconds: 30 })">
            <x-ui.table
                :grand="$grand"
                :view-url="fn ($r) => route('sales.tracking.show', [$r['kind'], $r['id']])"
                :empty="$q ? __('core.empty.no_results') : __('sales::tracking.empty')"
                :rows="$rows"
                :compact="request()->boolean('compact')"
                :columns="$columns" />

            <x-ui.pager :rows="$rows" />
            <x-ui.list-totals :rows="$rows" :grand="$grand ?? []" :columns="$columns" />
        </div>
    </div>
</x-layouts.app>
