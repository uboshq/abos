{{--
    গেট পাস — একটা। ⓘ কেবল দেখা: রওনার মুহূর্তের ছবি (গাড়ি, চালক, কে দিলেন কখন), চালানের পণ্য
    আর ফ্রি, ছাপার বোতাম, আর কারণসহ বাতিল ([[GatePassService::cancel()]])।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::gate_pass.title') }} {{ $pass->document_no }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <div data-boxed class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ __('sales::gate_pass.title') }} {{ $pass->document_no }}</h2>
            <span data-gate-pass="{{ $pass->status }}" class="rounded-full px-2 py-0.5 text-2xs
                {{ $pass->isCancelled() ? 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' }}">
                {{ __('sales::gate_pass.status.'.$pass->status) }}
            </span>
        </div>

        <p class="text-xs text-(--color-ink-muted)">{{ __('sales::gate_pass.view_only') }}</p>

        @if ($pass->isCancelled())
            <p class="text-sm text-(--color-danger)">
                {{ __('sales::gate_pass.cancelled_note', [
                    'reason' => $pass->cancel_reason,
                    'by' => $pass->canceller?->name ?? '—',
                    'at' => DateFormat::format($pass->cancelled_at),
                ]) }}
            </p>
        @endif

        <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.challan') }}</dt>
                {{-- ⓘ চালানের পাতা কেবল তার চাবিধারীকে (ওখানে দর ও টাকা); গুদামের লোক যান ডেলিভারির
                     পাতায় — একই চালান, দাম ছাড়া (লাইভের যাচাই, ২৯ সেপ্টেম্বর ২০২৬) --}}
                <dd>@if ($pass->challan)
                        @if (auth()->user()?->can('view', $pass->challan))
                            <a class="text-(--color-brand-500) underline-offset-2 hover:underline"
                               href="{{ route('sales.challan.show', $pass->challan) }}">{{ $pass->challan->document_no }}</a>
                        @elseif (auth()->user()?->can('sales.delivery.view'))
                            <a class="text-(--color-brand-500) underline-offset-2 hover:underline"
                               href="{{ route('sales.delivery.show', $pass->challan) }}">{{ $pass->challan->document_no }}</a>
                        @else
                            {{ $pass->challan->document_no }}
                        @endif
                    @else — @endif</dd></div>
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.customer') }}</dt>
                <dd>{{ $pass->challan?->customer?->name() ?? '—' }}</dd></div>
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.trip') }}</dt>
                <dd>{{ $pass->shipment?->document_no ?? '—' }}</dd></div>
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.vehicle') }}</dt>
                <dd>{{ $pass->vehicle_no ?: '—' }}</dd></div>
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.driver') }}</dt>
                <dd>{{ trim(($pass->driver_name ?? '').' '.($pass->driver_phone ?? '')) ?: '—' }}</dd></div>
            <div><dt class="text-(--color-ink-muted)">{{ __('sales::gate_pass.column.issued_by') }}</dt>
                <dd>{{ $pass->issuer?->name ?? '—' }} · {{ DateFormat::format($pass->issued_at) }}</dd></div>
        </dl>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-(--color-border) text-(--color-ink-muted)">
                    <th class="py-1 text-start">{{ __('sales::field.product') }}</th>
                    <th class="py-1 text-end">{{ __('sales::field.qty') }}</th>
                    <th class="py-1 text-end">{{ __('sales::field.free_qty') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pass->challan?->lines ?? [] as $line)
                    <tr class="border-b border-(--color-border) last:border-b-0">
                        <td class="py-1">{{ $line->product?->name() }}</td>
                        <td class="num py-1 text-end">{{ Money::format($line->delivered_qty) }}</td>
                        <td class="num py-1 text-end">{{ Money::format($line->free_qty ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="flex flex-wrap gap-2">
            <x-ui.button tone="primary" icon="printer" :href="route('sales.print.gate_pass', $pass)">
                {{ __('sales::gate_pass.print') }}
            </x-ui.button>
        </div>

        @can('sales.gate_pass.cancel')
            @unless ($pass->isCancelled())
                <x-sales::cancel-form :action="route('sales.gate_pass.cancel', $pass)" />
            @endunless
        @endcan
    </div>
</x-layouts.app>
