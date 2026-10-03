{{--
    ডিপোর যাচাই — হিসাবে অনুমোদিত DO (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)।

    ⓘ প্রতিটা সারিতে সতর্কবার্তা, এক লাইনে একটা (মালিকের নিয়ম, ১ অক্টোবর ২০২৬: এক লাইনে এক কথা), আর বোতাম
    "যাচাই করে বিক্রয়ে খুলুন" — সরাসরি বিক্রয়ের পাতা, সারি আগে থেকে ভরা ([[DepotCheckController]])।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;
    use App\Modules\Sales\Http\Controllers\DepotCheckController;
    use Illuminate\Support\HtmlString;

    $columns = [
        [
            'key' => 'document_no',
            'label' => __('sales::counter_source.column.do'),
            'width' => '9rem',
            'render' => fn ($o) => $o->document_no,
        ],
        [
            'key' => 'trx_date',
            'label' => __('sales::counter_source.column.date'),
            'width' => '8rem',
            'render' => fn ($o) => DateFormat::format($o->trx_date),
        ],
        [
            'key' => 'customer',
            'label' => __('sales::counter_source.column.customer'),
            'render' => fn ($o) => $o->customer?->name() ?? '—',
        ],
        [
            'key' => 'lines',
            'label' => __('sales::counter_source.column.lines'),
            'width' => '5rem',
            'numeric' => true,
            'render' => fn ($o) => (string) $o->lines->count(),
        ],
        [
            'key' => 'total',
            'label' => __('sales::counter_source.column.total'),
            'width' => '8rem',
            'numeric' => true,
            'total' => 'money',
            'render' => fn ($o) => Money::format($o->total),
        ],
        [
            'key' => 'status',
            'label' => __('sales::counter_source.column.status'),
            'width' => '9rem',
            'render' => fn ($o) => __('sales::delivery_order.status.'.$o->status),
        ],
        [
            'key' => 'warnings',
            'label' => __('sales::counter_source.column.warnings'),
            'render' => fn ($o) => new HtmlString(collect((array) ($o->accounts_warnings ?? []))
                ->filter(fn ($w) => is_array($w))
                ->map(fn (array $w) => '<span class="block text-2xs text-(--color-badge-pending-ink)">⚠️ '
                    .e(DepotCheckController::warningText($w)).'</span>')
                ->implode('') ?: '—'),
        ],
        [
            'key' => 'action',
            'label' => __('sales::counter_source.column.action'),
            'width' => '12rem',
            // ⓘ POST ফর্ম, নিজের পাতায় — টোকেনসহ ([[depot_check/partials/open]])
            'render' => fn ($o) => new HtmlString(view('sales::depot_check.partials.open', ['order' => $o])->render()),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::counter_source.title') }}</x-slot:title>

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

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('sales::counter_source.title')" :count="__('sales::counter_source.subtitle')"
                      :search-placeholder="__('sales::counter_source.search')" :columns="$columns" />

        {{-- ⓘ "দেখুন" — ডেলিভারি ট্র্যাকিংয়ে এই DO-র নম্বরে; খোলা (যাচাই) শেষের বোতামে --}}
        <x-ui.table
            :grand="$grand"
            :view-url="fn ($o) => route('sales.tracking.index', ['q' => $o->document_no])"
            :empty="$ready ? __('sales::counter_source.empty') : __('sales::counter_source.unavailable')"
            :rows="$orders"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
    </div>
</x-layouts.app>
