{{--
    লোডিং শিট — একটা ট্রিপ: পণ্য ধরে যোগফল (কী তুলবেন), চালান ধরে সারি (কার জন্য), ছাপা, আর
    "লোডিং নিশ্চিত" ([[LoadingSheetController::confirm()]])।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\DocumentStatus;
    use App\Core\Support\Money;
    use App\Modules\Sales\Services\DeliveryStage;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::loading.title') }} {{ $trip->document_no }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <div data-boxed class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ __('sales::loading.title') }} {{ $trip->document_no }}</h2>
            <span class="text-sm text-(--color-ink-muted)">
                {{ DateFormat::format($trip->trx_date) }} · {{ $trip->vehicle_no ?: '—' }} · {{ $trip->driver_name ?: '—' }}@if ($trip->driver_phone) · <span data-trip-driver-phone>{{ $trip->driver_phone }}</span>@endif
            </span>
        </div>

        <section>
            <h3 class="mb-2 font-semibold">{{ __('sales::loading.by_product') }}</h3>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) text-(--color-ink-muted)">
                        <th class="py-1 text-start">{{ __('sales::field.product') }}</th>
                        <th class="py-1 text-end">{{ __('sales::field.qty') }}</th>
                        <th class="py-1 text-end">{{ __('sales::field.free_qty') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($totals as $row)
                        <tr class="border-b border-(--color-border) last:border-b-0">
                            <td class="py-1">{{ $row['product'] }}</td>
                            <td class="num py-1 text-end">{{ Money::format($row['qty']) }} {{ $row['unit'] }}</td>
                            <td class="num py-1 text-end">{{ Money::format($row['free']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-2 text-(--color-ink-muted)">{{ __('sales::loading.no_lines') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section>
            <h3 class="mb-2 font-semibold">{{ __('sales::loading.by_challan') }}</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($trip->lines as $tripLine)
                    @php($challan = $tripLine->challan)
                    <li class="rounded-(--radius-field) border border-(--color-border) p-2">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span class="font-medium">{{ $challan?->document_no }} · {{ $challan?->customer?->name() }}</span>
                            @if ($challan && isset($stages[$challan->id]))
                                @include('sales::delivery.partials.badge', ['stage' => $stages[$challan->id]])
                            @endif
                        </div>
                        <ul class="mt-1 text-(--color-ink-muted)">
                            @foreach ($challan?->lines ?? [] as $line)
                                <li>{{ $line->product?->name() }} — {{ Money::format($line->delivered_qty) }}
                                    @if (bccomp((string) ($line->free_qty ?? '0'), '0', 4) > 0) (+{{ Money::format($line->free_qty) }}) @endif</li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button icon="printer" :href="route('sales.print.loading_sheet', $trip)">
                {{ __('sales::loading.print') }}
            </x-ui.button>

            @if ($trip->status === DocumentStatus::DRAFT)
                @can('sales.delivery.update')
                    <form method="POST" action="{{ route('sales.loading_sheet.confirm', $trip) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary" icon="check-circle">
                            {{ __('sales::loading.confirm') }}
                        </x-ui.button>
                    </form>
                    <span class="text-xs text-(--color-ink-muted)">{{ __('sales::loading.confirm_hint') }}</span>
                @endcan
            @endif
        </div>
    </div>
</x-layouts.app>
