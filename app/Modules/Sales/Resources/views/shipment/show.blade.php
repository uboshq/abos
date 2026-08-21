{{--
    একটা ট্রিপ।

    ── কেন হিসাব বুঝে নেওয়ার ঘরটা প্রতিটা সারিতেই ──────────────────────
    সন্ধ্যায় চালক ফিরে পাশে দাঁড়িয়ে থাকেন আর একটা একটা করে বলেন —
    "এইটা দিয়ে এসেছি, এইটা ফেরত"। প্রতিটা সারির পাশেই ঘরটা থাকলে যিনি
    লিখছেন তাঁকে অন্য পর্দায় যেতে হয় না, আর ক্রম হারিয়েও যায় না।
--}}
@php
    use App\Core\Support\DocumentStatus;
    use App\Modules\Sales\Models\ShipmentLine;

    $settled = $shipment->lines->filter->isSettled()->count();
    $onTheRoad = $shipment->status === DocumentStatus::CONFIRMED;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $shipment->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$shipment->document_no"
                          :subtitle="$shipment->vehiclePlate() ?: __('sales::shipment.title')">
            <x-slot:actions>
                @can('update', $shipment)
                    <x-ui.button tone="secondary" :href="route('sales.shipment.edit', $shipment)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.shipment.dispatch', $shipment) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">
                            {{ __('sales::shipment.dispatch') }}
                        </x-ui.button>
                    </form>
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

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($shipment->trx_date),
                    'sales::shipment.warehouse' => $shipment->warehouse?->name() ?: '-',
                    'sales::shipment.vehicle' => $shipment->vehiclePlate() ?: '-',
                    'sales::shipment.driver' => $shipment->driverName() ?: '-',
                    'sales::shipment.helper' => $shipment->helper_name ?: '-',
                    'sales::shipment.route' => $shipment->route?->name() ?: '-',
                    'sales::shipment.opening_km' => $shipment->opening_km ?: '-',
                    'sales::shipment.closing_km' => $shipment->closing_km ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$shipment" /></dd>
                </div>

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::shipment.challans') }}</dt>
                    <dd class="mt-0.5">
                        {{ __('sales::shipment.settled_count', [
                            'done' => $settled, 'total' => $shipment->lines->count(),
                        ]) }}
                    </dd>
                </div>
            </dl>

            @if ($shipment->narration)
                <p class="mt-3 text-sm text-(--color-ink-muted)">{{ $shipment->narration }}</p>
            @endif
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::shipment.loaded') }}
            </h2>

            @if ($shipment->lines->isEmpty())
                <p class="px-4 py-6 text-sm text-(--color-ink-muted)">
                    {{ __('sales::shipment.nothing_loaded') }}
                </p>
            @else
                <ul>
                    @foreach ($shipment->lines as $line)
                        <li class="border-b border-(--color-border) px-4 py-3 last:border-b-0">
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="w-40 shrink-0 font-medium">
                                    @if ($line->challan)
                                        <x-sales::doc-link :document="$line->challan"
                                                           route="sales.challan.show" />
                                    @endif
                                </span>

                                <span class="min-w-0 flex-1 truncate">
                                    {{ $line->challan?->customer?->name() }}
                                </span>

                                <span class="tabular w-28 shrink-0 text-end">
                                    {{ \App\Core\Support\Money::format($line->challan?->total ?? '0') }}
                                </span>

                                <span @class([
                                    'w-40 shrink-0 text-end text-xs',
                                    'text-(--color-badge-warning-ink)' => $line->needsGoodsBack(),
                                    'text-(--color-ink-muted)' => ! $line->needsGoodsBack(),
                                ])>{{ $outcomes[$line->outcome] ?? $line->outcome }}</span>
                            </div>

                            @if ($line->outcome_note)
                                <p class="mt-1 text-xs text-(--color-ink-muted)">{{ $line->outcome_note }}</p>
                            @endif

                            @if ($onTheRoad)
                                @can('create', \App\Modules\Sales\Models\Shipment::class)
                                    <form method="POST"
                                          action="{{ route('sales.shipment.settle', [$shipment, $line]) }}"
                                          class="mt-2 flex flex-wrap items-end gap-2">
                                        @csrf
                                        <x-ui.select name="outcome" :label="__('sales::shipment.outcome')"
                                                     :options="$outcomes" :selected="$line->outcome"
                                                     class="w-52" />
                                        <x-ui.field name="outcome_note"
                                                    :label="__('sales::shipment.outcome_note')"
                                                    :value="$line->outcome_note" class="min-w-64 flex-1" />
                                        <x-ui.button type="submit" tone="secondary">
                                            {{ __('sales::shipment.settle') }}
                                        </x-ui.button>
                                    </form>
                                @endcan
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($onTheRoad)
            @can('create', \App\Modules\Sales\Models\Shipment::class)
                <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('sales::shipment.close') }}</h2>

                    <form method="POST" action="{{ route('sales.shipment.close', $shipment) }}"
                          class="flex flex-wrap items-end gap-2">
                        @csrf
                        <x-ui.field name="closing_km" type="number" step="0.01"
                                    :label="__('sales::shipment.closing_km')" class="w-48" />
                        <x-ui.field name="narration" :label="__('sales::field.narration')"
                                    class="min-w-64 flex-1" />
                        <x-ui.button type="submit" tone="primary">
                            {{ __('sales::shipment.close') }}
                        </x-ui.button>
                    </form>
                </section>
            @endcan
        @endif

        @if (in_array($shipment->status, [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED], true))
            @can('delete', $shipment)
                <x-sales::cancel-form :action="route('sales.shipment.cancel', $shipment)" />
            @endcan
        @endif
    </div>
</x-layouts.app>
