{{--
    গ্রাহকের এক নজরের সারাংশ — তালিকার 👁 (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।

    ⛔ এখানে খাতার টেবিল নেই। খাতায় যাওয়ার একটাই পথ: বকেয়ার অঙ্কটা —
    নিয়ম ১, অঙ্কটাই তার উৎসে নিয়ে যায়।

    ⓘ প্রতিটা অঙ্কে `data-figure` — পরীক্ষা ঐ ঘরটাই পড়ে, পাতার অন্য কোথাও
    একই সংখ্যা থাকলেও ভুল ঘরে পাস করে না।

    ⚠️ Alpine নেই, ইচ্ছাকৃত: পাতাটা কেবল দেখায়, আর CSP-Alpine-এর ফাঁদে
    পড়ার মতো কিছুই এখানে দরকার নেই।
--}}
@php
    $money = fn ($value) => \App\Core\Support\Money::format($value);
    $date = fn ($value) => \App\Core\Support\DateFormat::format($value);
    $limitSet = bccomp((string) $customer->credit_limit, '0', 4) > 0;
    $available = $customer->availableLimit();
    $ledgerUrl = route('customer.show', $customer).'#transactions';
    $card = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
    $link = 'text-(--color-brand-500) underline-offset-2 hover:underline';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $customer->name() }} · {{ __('customer::summary.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$customer->name()" :subtitle="$customer->code.' · '.__('customer::summary.title')">
            <x-slot:actions>
                @include('customer::partials.state-badge', ['customer' => $customer])
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div data-customer-summary class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

        {{-- বকেয়া — খাতার একমাত্র দরজা --}}
        <section data-boxed class="{{ $card }}">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('customer::summary.outstanding') }}</h2>

            <p class="mt-1 text-2xl font-semibold">
                <a href="{{ $ledgerUrl }}" data-figure="outstanding"
                   title="{{ __('customer::summary.open_ledger') }}"
                   class="num {{ $link }} {{ bccomp($outstanding, '0', 4) < 0 ? 'text-(--color-danger)' : '' }}">{{ $money($outstanding) }}</a>
            </p>

            @if ($trade !== null)
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __('customer::summary.pending_bills') }}</dt>
                        <dd class="num font-semibold" data-figure="pending-count">{{ $trade->pendingCount }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __('customer::summary.pending_items') }}</dt>
                        <dd class="num font-semibold" data-figure="pending-items">{{ $trade->pendingItems }}</dd>
                    </div>
                </dl>
            @endif
        </section>

        {{-- বিল — এই মাস ও সব মিলিয়ে --}}
        <section data-boxed class="{{ $card }}">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('customer::summary.invoices') }}</h2>

            @if ($trade === null)
                <p class="mt-2 text-sm text-(--color-ink-muted)">{{ __('customer::summary.no_sales') }}</p>
            @else
                <dl class="mt-2 space-y-3 text-sm">
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('customer::summary.this_month') }}</dt>
                        <dd class="flex items-baseline justify-between gap-2">
                            <span data-figure="month-count">{{ __('customer::summary.invoice_count', ['count' => $trade->monthCount]) }}</span>
                            <span class="num text-lg font-semibold" data-figure="month-amount">{{ $money($trade->monthAmount) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('customer::summary.lifetime') }}</dt>
                        <dd class="flex items-baseline justify-between gap-2">
                            <a href="{{ $trade->invoicesUrl }}" class="{{ $link }}" data-figure="lifetime-count">{{ __('customer::summary.invoice_count', ['count' => $trade->lifetimeCount]) }}</a>
                            <span class="num text-lg font-semibold" data-figure="lifetime-amount">{{ $money($trade->lifetimeAmount) }}</span>
                        </dd>
                    </div>
                </dl>
            @endif
        </section>

        {{-- শেষ কেনা ও শেষ জমা --}}
        <section data-boxed class="{{ $card }}">
            <dl class="space-y-3 text-sm">
                @foreach ([
                    'last-purchase' => ['customer::summary.last_purchase', $trade?->lastPurchaseDate, $trade?->lastPurchaseAmount, $trade?->lastPurchaseUrl],
                    'last-payment' => ['customer::summary.last_payment', $trade?->lastPaymentDate, $trade?->lastPaymentAmount, $trade?->lastPaymentUrl],
                ] as $key => [$label, $when, $amount, $url])
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                        @if ($when === null)
                            <dd class="text-(--color-ink-muted)" data-figure="{{ $key }}-none">{{ __('customer::summary.never') }}</dd>
                        @else
                            <dd class="flex items-baseline justify-between gap-2">
                                <span data-figure="{{ $key }}-date">{{ $date($when) }}</span>
                                @if ($url !== null)
                                    <a href="{{ $url }}" class="num font-semibold {{ $link }}" data-figure="{{ $key }}-amount">{{ $money($amount) }}</a>
                                @else
                                    <span class="num font-semibold" data-figure="{{ $key }}-amount">{{ $money($amount) }}</span>
                                @endif
                            </dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- অবশিষ্ট সীমা — কাউন্টারের হুবহু (সীমা − বকেয়া − আটকে থাকা) --}}
        <section data-boxed class="{{ $card }}">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('customer::summary.available_credit') }}</h2>

            @if (! $creditLimitOn)
                <p class="mt-2 text-sm text-(--color-ink-muted)" data-figure="available-credit-off">{{ __('customer::summary.limit_off') }}</p>
            @elseif (! $limitSet || $available === null)
                <p class="mt-2 text-sm text-(--color-ink-muted)" data-figure="available-credit-none">{{ __('customer::summary.no_limit') }}</p>
            @else
                <p class="mt-1 text-2xl font-semibold">
                    <span data-figure="available-credit"
                          class="num {{ bccomp($available, '0', 4) > 0 ? 'text-(--color-success)' : 'text-(--color-danger)' }}">{{ $money($available) }}</span>
                </p>
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('customer::summary.credit_limit') }}:
                    <span class="num" data-figure="credit-limit">{{ $money($customer->credit_limit) }}</span>
                </p>

                @if ($customer->wouldExceedCreditLimit($customer->heldCredit()))
                    <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-1
                              text-2xs text-(--color-badge-danger-ink)">
                        {{ __('customer::message.over_limit') }}
                    </p>
                @endif
            @endif
        </section>
    </div>
</x-layouts.app>
