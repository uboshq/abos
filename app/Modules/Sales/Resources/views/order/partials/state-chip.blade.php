{{--
    ⭐ আদেশের (বা একটা লাইনের) অবস্থা — চিপ: অবস্থা, চালান, বিল; পাশে ব্যাক অর্ডার আর পুরনো খসড়া
    (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; [[SalesOrderStatus]], [[OrderProgress]])।

    ⓘ এখানে কেবল আঁকা। $status (মাথার অবস্থা; লাইনে খালি), $lineStatus (লাইনে), $delivery, $billing, আর ঐচ্ছিক
    $back, $stale, $days। ⓘ "চালান হয়নি" / "বিল হয়নি" চিপ আঁকা হয় না — অবস্থার চিপই সেটা বলে।
    ⚠️ পুরনো খসড়া লাল (ব্যাজের "danger" রং) — পরিকল্পনার §৪.৩: "৩ দিন পড়ে থাকলে তালিকায় লাল"।
--}}
@php
    $S = \App\Modules\Sales\Support\SalesOrderStatus::class;
    $status = isset($status) ? (string) $status : null;
    $lineStatus = isset($lineStatus) ? (string) $lineStatus : null;
    $delivery = (string) ($delivery ?? $S::NONE);
    $billing = (string) ($billing ?? $S::NONE);
    $chip = 'inline-flex items-center rounded-full px-2 py-0.5 text-2xs whitespace-nowrap';
    // ⓘ এক সারিতে, একটা ফাঁকে — পরীক্ষা আর স্ক্রিপ্ট ঘরগুলো একসাথে পড়ে
    $attrs = collect([
        'data-order-state' => $status,
        'data-line-state' => $lineStatus,
        'data-delivery-state' => $delivery,
        'data-billing-state' => $billing,
    ])->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => $k.'="'.e($v).'"')->implode(' ');
@endphp
<span class="inline-flex flex-wrap items-center gap-1" {!! $attrs !!}>
    @if ($status !== null)
        @php $tone = $S::tone($status); @endphp
        <span class="{{ $chip }} bg-(--color-badge-{{ $tone }}-bg) text-(--color-badge-{{ $tone }}-ink)">
            {{ $S::label($status) }}
        </span>
    @endif

    @if ($lineStatus !== null && $lineStatus !== $S::LINE_OPEN)
        <span class="{{ $chip }} bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)">
            {{ __('sales::order_status.line.'.$lineStatus) }}
        </span>
    @endif

    @if ($delivery !== $S::NONE)
        @php $tone = $S::progressTone($delivery); @endphp
        <span class="{{ $chip }} bg-(--color-badge-{{ $tone }}-bg) text-(--color-badge-{{ $tone }}-ink)">
            {{ $S::deliveryLabel($delivery) }}
        </span>
    @endif

    @if ($billing !== $S::NONE)
        @php $tone = $S::progressTone($billing); @endphp
        <span class="{{ $chip }} bg-(--color-badge-{{ $tone }}-bg) text-(--color-badge-{{ $tone }}-ink)">
            {{ $S::billingLabel($billing) }}
        </span>
    @endif

    @if (! empty($back))
        <span data-back-order class="{{ $chip }} bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)"
              title="{{ __('sales::order_status.back_order_hint') }}">
            {{ __('sales::order_status.back_order') }}
        </span>
    @endif

    @if (! empty($stale))
        <span data-stale-draft class="{{ $chip }} font-semibold bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)"
              title="{{ __('sales::order_status.stale_hint', ['days' => (int) ($days ?? 0)]) }}">
            {{ __('sales::order_status.stale', ['days' => (int) ($days ?? 0)]) }}
            @if (! empty($paperDate))
                · <span data-stale-paper>{{ __('sales::order_status.stale_paper', ['date' => $paperDate]) }}</span>
            @endif
        </span>
    @endif
</span>
