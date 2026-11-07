{{--
    ⭐ "গাড়ি ও ভাড়া" — চালান আর বিলের পাতায় এক জায়গায় (মালিক, ৭ নভেম্বর ২০২৬, নমুনা "ok"): বাহক ও মোবাইল, গাড়ি, চালক ও
    মোবাইল, ভাড়ার অঙ্ক আর অবস্থা, ভাউচারের লিংক। ⛔ আগে চালান আর বিলের পাতায় ভাড়া দেখাই যেত না — "পরিবহন" চেপে আরেক পাতায়।

    চাই: $challan। ⓘ ভাড়া বসানো বাকি হলে ([[FarePayment::owesAnAmount()]]) লাল, পাশে "ভাড়া লিখুন" — বিল ছাপা আর নিশ্চিত তখন আটকে।
--}}
@php
    $fares = app(\App\Modules\Sales\Services\FarePayment::class);
    $owed = $fares->owesAnAmount($challan);
    $due = $fares->isDue($challan);
    $cost = is_numeric($challan->transport_cost) && bccomp((string) $challan->transport_cost, '0', 4) > 0 ? (string) $challan->transport_cost : null;
    $voucher = $challan->fare_voucher_id ? \App\Modules\Accounts\Models\Voucher::query()->find($challan->fare_voucher_id) : null;
    $carrierPhone = $challan->carrier_id !== null
        ? (string) ($challan->carrier?->phone ?: $challan->carrier?->contact_phone ?: '')
        : (string) ($challan->carrier_phone ?? '');
    $owner = $challan->vehicle_owner ? __('sales::field.owner_'.$challan->vehicle_owner) : null;
    $noFare = match (true) {
        $challan->vehicle_owner === 'customer' => __('sales::fare.no_fare_customer_vehicle'),
        $challan->vehicle_owner === 'none' => __('sales::fare.no_fare_no_vehicle'),
        $challan->fare_paid_by === 'customer' => __('sales::fare.no_fare_customer_pays'),
        $challan->fare_paid_by === 'none' => __('sales::fare.no_fare_nothing'),
        default => null,
    };
    $state = match (true) {
        $owed => __('sales::fare.amount_before_bill', ['challan' => $challan->document_no]),
        $due => __('sales::fare.state_due'),
        $challan->fare_status === 'due' => __('sales::fare.state_due_paid'),
        $challan->fare_status === 'now' => __('sales::fare.state_now'),
        $cost !== null => __('sales::fare.state_old'),
        default => $noFare,
    };
    $tone = $owed || $due ? 'danger' : ($cost !== null ? 'success' : 'neutral');
@endphp

<section data-boxed data-vehicle-fare="{{ $owed ? 'owed' : ($due ? 'due' : ($cost !== null ? 'set' : 'none')) }}"
         @class([
             'rounded-(--radius-card) border-2 border-s-8 p-4',
             'border-(--color-badge-danger-ink) bg-(--color-badge-danger-bg)' => $tone === 'danger',
             'border-(--color-badge-success-ink) bg-(--color-badge-success-bg)' => $tone === 'success',
             'border-(--color-border) bg-(--color-surface-card)' => $tone === 'neutral',
         ])>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="text-base font-bold"><span aria-hidden="true">🚚</span> {{ __('sales::field.vehicle_and_fare') }}</h2>
        @if ($owed || $due)
            <x-ui.button tone="primary" class="ms-auto" :href="route('sales.challan.transport', $challan)" data-vehicle-fare-write>
                {{ $owed ? __('sales::fare.write_fare') : __('sales::fare.pay_now') }}
            </x-ui.button>
        @endif
    </div>

    <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::field.carrier') }}</dt>
            <dd class="mt-0.5 font-semibold">{{ $challan->transportLabel() ?: '—' }}@if ($carrierPhone !== '') <span class="num font-normal"> · {{ $carrierPhone }}</span>@endif</dd>
        </div>
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::field.vehicle_no') }}</dt>
            <dd class="mt-0.5 font-semibold">{{ $challan->vehiclePlate() ?: '—' }}@if ($owner) <span class="font-normal"> · {{ $owner }}</span>@endif</dd>
        </div>
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::field.driver_name') }}</dt>
            <dd class="mt-0.5 font-semibold">{{ $challan->driver_name ?: '—' }}@if ($challan->driver_phone) <span class="num font-normal"> · {{ $challan->driver_phone }}</span>@endif</dd>
        </div>
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.amount') }}</dt>
            <dd class="num mt-0.5 text-lg font-bold" data-vehicle-fare-amount>{{ $cost !== null ? '৳ '.\App\Core\Support\Money::format($cost) : '—' }}</dd>
        </div>
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.state') }}</dt>
            <dd @class(['mt-0.5 font-semibold', 'text-(--color-badge-danger-ink)' => $tone === 'danger', 'text-(--color-badge-success-ink)' => $tone === 'success'])>{{ $state ?: '—' }}</dd>
        </div>
        <div>
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.voucher') }}</dt>
            <dd class="mt-0.5">
                @if ($voucher)
                    <a href="{{ route('accounts.voucher.show', $voucher) }}" class="font-semibold text-(--color-link) underline" data-vehicle-fare-voucher>{{ $voucher->document_no }} →</a>
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>
</section>
