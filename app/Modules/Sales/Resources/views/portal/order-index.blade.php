{{--
    ⭐ গ্রাহকের নিজের বিক্রয় আদেশ — তালিকা আর "নতুন আদেশ" (DO+SO মেশানো, ধাপ ৯, ৫ অক্টোবর ২০২৬; [[PortalOrderController]])।
    ⓘ কেবল নিজের — অফিস বা SR-এর লেখা নিজের আদেশও এখানে।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::portal_order.title') }}</h1>

    @if ($canWrite)
        <a href="{{ route('sales.portal.order.create') }}" data-portal-order-new
           class="mb-4 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center font-medium text-white">
            {{ __('sales::portal_order.new') }}
        </a>
    @endif

    <div class="grid gap-2">
        @forelse ($orders as $order)
            <a href="{{ route('sales.portal.order.show', $order->public_id) }}" data-portal-order-row
               class="block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <div class="font-semibold">{{ $order->document_no }}</div>
                <div class="text-sm text-(--color-ink-muted)">{{ $order->trx_date?->format('d/m/Y') }}</div>
                <div class="num text-sm">{{ \App\Core\Support\Money::format($order->total) }}</div>
                <div class="text-sm font-medium">{{ \App\Modules\Sales\Support\SalesOrderStatus::label((string) $order->status) }}</div>
            </a>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal_order.none') }}</p>
        @endforelse
    </div>

    <x-ui.pager :rows="$orders" />
</x-sales::portal.layout>
