{{--
    ⭐ একটা বিক্রয় আদেশ — লাইন, অবস্থা, আর নিজের খসড়া হলে "জমা দিন" (DO+SO মেশানো, ধাপ ৯)।
    ⓘ সুপারভাইজার পরিমাণ কমালে চাওয়া আর অনুমোদিত দুটোই; বাকির সীমায় আটকালে কত কম।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="text-lg font-semibold">{{ $order->document_no }}</h1>
    <div class="mb-3 text-sm font-medium" data-portal-order-status="{{ $order->status }}">
        {{ \App\Modules\Sales\Support\SalesOrderStatus::label((string) $order->status) }}
    </div>

    @if ($order->status === \App\Modules\Sales\Support\SalesOrderStatus::CREDIT_HELD && $order->credit_short !== null)
        <p class="mb-3 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)" data-portal-order-short>
            {{ __('sales::portal_order.credit_short', ['amount' => \App\Core\Support\Money::format($order->credit_short)]) }}
        </p>
    @endif

    <div class="grid gap-2">
        @foreach ($order->lines as $line)
            @php($asked = $line->requested_qty ?? $line->ordered_qty)
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <div class="font-semibold">{{ $line->product?->name() }}</div>
                <div class="text-sm">{{ __('sales::portal_order.asked') }}: {{ rtrim(rtrim((string) $asked, '0'), '.') }}</div>
                @if (bccomp((string) $asked, (string) $line->ordered_qty, 4) !== 0)
                    <div class="text-sm font-medium">{{ __('sales::portal_order.approved') }}: {{ rtrim(rtrim((string) $line->ordered_qty, '0'), '.') }}</div>
                @endif
                <div class="num text-sm">{{ \App\Core\Support\Money::format($line->amount) }}</div>
            </div>
        @endforeach
    </div>

    <div class="num mt-3 text-base font-semibold">{{ \App\Core\Support\Money::format($order->total) }}</div>

    @if ($canSubmit)
        <form method="POST" action="{{ route('sales.portal.order.submit', $order->public_id) }}" class="mt-4" data-portal-order-submit>
            @csrf
            <button type="submit" class="h-(--spacing-field) w-full rounded-(--radius-field) bg-(--color-brand-500) font-medium text-white">
                {{ __('sales::delivery_order.submit') }}
            </button>
        </form>
    @endif

    <a href="{{ route('sales.portal.order.index') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal_order.title') }}
    </a>
</x-sales::portal.layout>
