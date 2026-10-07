{{--
    গ্রাহকের নিজের DO — তালিকা আর "নতুন DO" (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬)। কেবল নিজের ([[PortalDeliveryOrderController]])।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::delivery_order.title') }}</h1>

    {{-- ⭐ কোম্পানি বিক্রয় আদেশে চলে গেলে নতুন DO নয় — খোলা DO-গুলো নিচে আগের মতো (নকশার ধাপ ১২) --}}
    @if ($newStopped ?? false)
        <p class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-4 py-3 text-sm text-(--color-badge-warning-ink)" role="status" data-portal-do-stopped>
            {{ __('sales::delivery_order.write_an_order_now') }}
        </p>
    @else
        <a href="{{ route('sales.portal.do.create') }}" data-portal-do-new
           class="mb-4 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center font-medium text-white">
            {{ __('sales::delivery_order.new') }}
        </a>
    @endif

    <div class="grid gap-2">
        @forelse ($orders as $order)
            <a href="{{ route('sales.portal.do.show', $order->public_id) }}" data-portal-do-row
               class="block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <div class="font-semibold">{{ $order->document_no }}</div>
                <div class="text-sm text-(--color-ink-muted)">{{ $order->trx_date?->format('d/m/Y') }}</div>
                <div class="num text-sm">{{ \App\Core\Support\Money::format($order->total) }}</div>
                <div class="text-sm font-medium">{{ \App\Modules\Sales\Support\DeliveryOrderStatus::label($order->status) }}</div>
            </a>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::delivery_order.none') }}</p>
        @endforelse
    </div>

    <x-ui.pager :rows="$orders" />
</x-sales::portal.layout>
