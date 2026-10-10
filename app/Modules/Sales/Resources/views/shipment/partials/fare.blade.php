{{-- ⭐ ট্রিপের ভাড়া — অবস্থা, ভাউচারের লিংক, আর পরে-দেব ভাড়া দেওয়া (মালিক, ৭ অক্টোবর ২০২৬; সিদ্ধান্ত ঘ; [[FarePayment]])।
     ⓘ বাকি ভাড়া দেওয়া PV — সই আর লেখক ≠ পাকাকারী খাটে; টাকা নড়ে, তাই ভাউচার লেখার চাবিও লাগে। --}}
@php
    $fares = app(\App\Modules\Sales\Services\FarePayment::class);
    $hasFare = $fares->isOurs($shipment);
    $due = $fares->isDue($shipment);
    $canPay = auth()->user()?->can('create', \App\Modules\Accounts\Models\Voucher::class) && auth()->user()?->can('sales.challan.create');
    $voucher = $shipment->fare_voucher_id ? \App\Modules\Accounts\Models\Voucher::query()->find($shipment->fare_voucher_id) : null;
@endphp

@if ($hasFare)
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" data-trip-fare>
        <h2 class="mb-2 font-semibold">{{ __('sales::fare.section') }}</h2>
        <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.amount') }}</dt>
            <dd class="num">{{ \App\Core\Support\Money::format((string) $shipment->transport_cost) }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.state') }}</dt>
            <dd data-fare-state>{{ $due ? __('sales::fare.state_due') : ($shipment->fare_status === 'due'
                ? ($voucher ? __('sales::fare.state_due_paid') : __('sales::fare.state_trip_waits'))
                : ($voucher ? __('sales::fare.state_now') : __('sales::fare.state_trip_waits'))) }}</dd>
            @if ($voucher)
                <dt class="text-(--color-ink-muted)">{{ __('sales::fare.voucher') }}</dt>
                <dd><a href="{{ route('accounts.voucher.show', $voucher) }}" class="text-(--color-link) underline">{{ $voucher->document_no }}</a>
                    · {{ __('core.status.'.$voucher->status) }}</dd>
            @endif
        </dl>

        @if ($due && $canPay)
            <form method="POST" action="{{ route('sales.shipment.fare.pay', $shipment) }}" class="mt-3 grid gap-2" data-trip-fare-pay data-no-peek>
                @csrf
                @include('sales::challan.partials.fare-money', ['options' => app(\App\Modules\Sales\Services\DirectSaleOptions::class)])
                <x-ui.button type="submit" tone="primary">{{ __('sales::fare.pay_now') }}</x-ui.button>
            </form>
        @endif

        @foreach (['fare', 'fare_account_id', 'fare_reference', 'fare_payer_id'] as $field)
            @error($field)
                <p class="mt-1 text-sm text-(--color-danger)">{{ $message }}</p>
            @enderror
        @endforeach
    </section>
@endif
