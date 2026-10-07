{{--
    পরিবহন বরাদ্দ — নিশ্চিত চালান, তিন ট্যাবে ([[TransportAssignmentController]], মালিক, ৩ অক্টোবর ২০২৬)।
    ⓘ নিজের কোনো ফর্ম নেই: সারির বোতাম চালানের "মাল কীভাবে যাবে" পপআপ খোলে ([[ChallanTransportController]]) —
    ঠিকানার শেষে চালানের নম্বর, তাই পপআপে। গেট পাসের পরে বোতামটা নেই, abos-af-এর নিয়মই ([[ChallanTransportController::locked()]])।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Modules\Sales\Http\Controllers\ChallanTransportController;

    $columns = [
        [
            'key' => 'trx_date',
            'label' => __('sales::field.date'),
            'width' => '7rem',
            'render' => fn ($d) => DateFormat::format($d->trx_date),
        ],
        [
            'key' => 'document_no',
            'label' => __('sales::field.document_no'),
            'width' => '11rem',
            'render' => fn ($d) => view('sales::components.doc-link', ['document' => $d, 'route' => 'sales.challan.show']),
        ],
        [
            'key' => 'customer_id',
            'label' => __('sales::field.customer'),
            'render' => fn ($d) => $d->customer?->name(),
        ],
        [
            // ⭐ গ্রাহকের পরে পয়েন্ট — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: সব তালিকায়
            'key' => 'point',
            'label' => __('customer::field.point'),
            'width' => '9rem',
            'render' => fn ($d) => $d->customer?->location?->name() ?? '—',
        ],
        [
            'key' => 'mode',
            'label' => __('sales::transport.mode'),
            'width' => '9rem',
            'render' => function ($d) {
                $mode = ChallanTransportController::mode($d);

                return new \Illuminate\Support\HtmlString('<span data-transport-mode="'.e($mode ?? 'none').'" class="inline-flex rounded-full px-2 py-0.5 text-2xs '
                    .($mode === null
                        ? 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
                        : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)')
                    .'">'.e($mode === null ? __('sales::transport_board.not_set') : __('sales::transport.mode_'.$mode)).'</span>');
            },
        ],
        [
            'key' => 'vehicle',
            'label' => __('sales::field.vehicle_no'),
            'width' => '9rem',
            'render' => fn ($d) => $d->vehicle_no ?: ($d->vehicle?->registration_no ?: ($d->vehicle?->code ?? '—')),
        ],
        [
            'key' => 'driver',
            'label' => __('sales::field.driver_name'),
            'width' => '11rem',
            'render' => fn ($d) => collect([$d->driver_name, $d->driver_phone])->filter()->implode(' · ') ?: '—',
        ],
        [
            'key' => 'transport_cost',
            'total' => 'money',
            'label' => __('sales::transport_board.fare'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($d) => $d->transport_cost !== null && bccomp((string) $d->transport_cost, '0', 4) > 0
                ? \App\Core\Support\Money::format((string) $d->transport_cost) : '—',
        ],
        [
            'key' => 'gate_pass',
            'label' => __('sales::transport_board.gate_pass'),
            'width' => '9rem',
            'render' => fn ($d) => $passes[$d->id] ?? '—',
        ],
        [
            'key' => 'assign',
            'label' => '',
            'width' => '9rem',
            'render' => fn ($d) => $canAssign && ! ChallanTransportController::locked($d)
                ? new \Illuminate\Support\HtmlString('<a data-transport-assign href="'.e(route('sales.challan.transport', [$d, 'from' => 'transport'])).'"'
                    .' class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border border-(--color-border) px-3 text-sm hover:bg-(--color-surface-hover)">'
                    .e(ChallanTransportController::mode($d) === null ? __('sales::transport_board.assign') : __('sales::transport_board.change')).'</a>')
                : '',
        ],
    ];

    /*
     * ⭐ সারি থেকেই "পৌঁছেছে" — মালিক, ৩ অক্টোবর ২০২৬: "sob jaygathekei"। কেবল রওনার পরে আর ধাপ বদলানোর চাবিতে
     * ([[DeliveryRowActions]]); ফর্ম যায় পুরনো `sales.delivery.move`-এ, আর পাশের ⋯-এ আংশিক/পৌঁছায়নি।
     */
    $arrive = app(\App\Modules\Sales\Services\DeliveryRowActions::class)->forChallans(collect($challans->items())->pluck('id'));

    if ($arrive !== []) {
        $columns[] = [
            'key' => 'arrive',
            'label' => __('sales::delivery.column.next'),
            'width' => '10rem',
            'render' => fn ($d) => isset($arrive[$d->id])
                ? view('sales::delivery.partials.row-action', ['challan' => $d, 'choices' => $arrive[$d->id]['choices'], 'trip' => null, 'vehicles' => collect()])
                : '',
        ];
    }

    $field = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::transport_board.title') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- তিন ট্যাব — ছাঁকনি ট্যাব বদলালেও থেকে যায়; সংখ্যা একই ছাঁকনিতে গোনা --}}
    <nav data-transport-tabs class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('sales::transport_board.title') }}">
        @foreach (\App\Modules\Sales\Http\Controllers\TransportAssignmentController::TABS as $each)
            <a href="{{ route('sales.transport.index', [...request()->except(['tab', 'page']), 'tab' => $each]) }}"
               @if ($tab === $each) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $tab === $each
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ __('sales::transport_board.tab_'.$each) }}
                <span data-tab-count="{{ $each }}" class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $counts[$each] ?? 0 }}
                </span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <x-ui.toolbar :title="__('sales::transport_board.tab_'.$tab)" :quiet="['tab']"
                          :count="__('sales::transport_board.subtitle')"
                          :columns="$columns" :search-placeholder="__('sales::message.challan_search')">
                <x-ui.date-range :dates="$dates" />

                @if ($vehicles->isNotEmpty())
                    <select name="vehicle" class="{{ $field }}" aria-label="{{ __('sales::field.vehicle_no') }}">
                        <option value="">{{ __('sales::transport_board.any_vehicle') }}</option>
                        @foreach ($vehicles as $v)
                            <option value="{{ $v->id }}" @selected($vehicle === $v->id)>{{ $v->registration_no ?: $v->code }}</option>
                        @endforeach
                    </select>
                @endif

                <input type="search" name="driver" value="{{ $driver }}" class="{{ $field }}"
                       placeholder="{{ __('sales::transport_board.driver_filter') }}" aria-label="{{ __('sales::field.driver_name') }}">

                <select name="customer" class="{{ $field }}" aria-label="{{ __('sales::field.customer') }}">
                    <option value="">{{ __('sales::transport_board.any_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected($customer === $c->id)>{{ $c->name() }}</option>
                    @endforeach
                </select>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand"
            :view-url="fn ($d) => route('sales.challan.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('sales::transport_board.empty_'.$tab)"
            :rows="$challans"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$challans" />
        <x-ui.list-totals :rows="$challans" :grand="$grand ?? []" :columns="$columns" />
    </div>
</x-layouts.app>
