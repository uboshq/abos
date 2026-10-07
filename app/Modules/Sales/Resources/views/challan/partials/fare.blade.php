{{-- ⭐ ভাড়া — চালানের পরিবহন পর্দায় (মালিক, ৭ অক্টোবর ২০২৬; [[FarePayment]], [[ChallanFareController]])।
     ⓘ তিন অবস্থা: ভাড়া লেখা আছে (দেখা, ভাউচারের লিংকসহ) · পরে-দেব ভাড়া বাকি (দেওয়ার ফর্ম) · পাকা চালানে ভাড়া এখনো লেখা হয়নি (লেখার ফর্ম)।
     ⓘ গেট পাস হলেও দেখায় — ভাড়া প্রায়ই মাল বেরোনোর পরে মেটে। টাকার খাতের তালিকা কাউন্টারের একই ([[DirectSaleOptions::moneyAccounts()]])। --}}
@php
    $fares = app(\App\Modules\Sales\Services\FarePayment::class);
    $options = app(\App\Modules\Sales\Services\DirectSaleOptions::class);
    $canPay = auth()->user()?->can('create', \App\Modules\Accounts\Models\Voucher::class) ?? false;
    $due = $fares->isDue($challan);
    $hasFare = $challan->fare_rule !== null || (is_numeric($challan->transport_cost) && bccomp((string) $challan->transport_cost, '0', 4) > 0);
    $canRecord = ! $hasFare && $challan->status === \App\Core\Support\DocumentStatus::CONFIRMED;
    $voucher = $challan->fare_voucher_id ? \App\Modules\Accounts\Models\Voucher::query()->find($challan->fare_voucher_id) : null;
@endphp

<section class="mx-auto mt-4 max-w-xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" data-challan-fare>
    <h2 class="mb-2 text-base font-semibold">{{ __('sales::fare.section') }}</h2>

    @if ($hasFare)
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.amount') }}</dt>
            <dd class="num">{{ \App\Core\Support\Money::format((string) ($challan->transport_cost ?? '0')) }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('sales::fare.state') }}</dt>
            <dd data-fare-state>{{ $due ? __('sales::fare.state_due') : ($challan->fare_status === 'due' ? __('sales::fare.state_due_paid') : ($challan->fare_status === 'now' ? __('sales::fare.state_now') : __('sales::fare.state_old'))) }}</dd>
            @if ($voucher)
                <dt class="text-(--color-ink-muted)">{{ __('sales::fare.voucher') }}</dt>
                <dd><a href="{{ route('accounts.voucher.show', $voucher) }}" class="text-(--color-link) underline">{{ $voucher->document_no }}</a>
                    · {{ __('core.status.'.$voucher->status) }}</dd>
            @endif
        </dl>
    @endif

    @if ($due && $canPay)
        {{-- পরে-দেব ভাড়া দেওয়া — PV, সই আর লেখক ≠ পাকাকারী-সহ --}}
        <form method="POST" action="{{ route('sales.challan.fare.pay', $challan) }}" class="mt-3 grid gap-2" data-fare-pay data-no-peek>
            @csrf
            @include('sales::challan.partials.fare-money', ['options' => $options])
            <x-ui.button type="submit" tone="primary">{{ __('sales::fare.pay_now') }}</x-ui.button>
        </form>
    @elseif ($canRecord && $canPay)
        {{-- পাকা চালানে প্রথমবার ভাড়া লেখা — "বিলে যোগ" নয় (বিল আগেই খাতায়) --}}
        <form method="POST" action="{{ route('sales.challan.fare.record', $challan) }}" class="mt-1 grid gap-2" data-fare-record data-no-peek
              x-data="{ who: @js((string) old('fare_paid_by', 'us')), fareWhen: @js((string) old('fare_when', 'now')) }">
            @csrf
            <div class="ds-seg" role="radiogroup">
                @foreach (['us', 'customer', 'none'] as $choice)
                    <button type="button" @click="who = '{{ $choice }}'" :class="who === '{{ $choice }}' ? 'is-on' : ''">{{ __('sales::field.fare_'.$choice) }}</button>
                @endforeach
            </div>
            <input type="hidden" name="fare_paid_by" :value="who">

            <div class="grid gap-2" x-show="who === 'us'">
                <x-ui.field name="transport_cost" type="number" step="0.01" min="0" :label="__('sales::fare.amount')" :value="old('transport_cost')" />
                <x-ui.select name="carrier_id" :label="__('sales::fare.carrier')" :options="$options->carriers()->pluck('label', 'id')"
                             :selected="old('carrier_id', $challan->carrier_id)" placeholder="-" />
                <div class="ds-seg" role="radiogroup">
                    <button type="button" @click="fareWhen = 'now'" :class="fareWhen === 'now' ? 'is-on' : ''">{{ __('sales::fare.now') }}</button>
                    <button type="button" @click="fareWhen = 'later'" :class="fareWhen === 'later' ? 'is-on' : ''">{{ __('sales::fare.later') }}</button>
                </div>
                <input type="hidden" name="fare_when" :value="fareWhen">
                <div x-show="fareWhen === 'now'">
                    @include('sales::challan.partials.fare-money', ['options' => $options])
                </div>
                <p class="text-2xs text-(--color-ink-muted)" x-show="fareWhen === 'later'">{{ __('sales::fare.later_hint') }}</p>
            </div>

            <x-ui.button type="submit" tone="primary">{{ __('sales::fare.record') }}</x-ui.button>
        </form>
    @elseif (! $hasFare)
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::fare.none_yet') }}</p>
    @endif

    @foreach (['fare', 'fare_account_id', 'fare_reference', 'fare_payer_id', 'fare_paid_by', 'transport_cost', 'carrier_id'] as $field)
        @error($field)
            <p class="mt-1 text-sm text-(--color-danger)" data-fare-error>{{ $message }}</p>
        @enderror
    @endforeach
</section>
