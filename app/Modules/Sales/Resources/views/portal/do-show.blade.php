{{--
    ডিলারের একটা DO — লাইন, অবস্থা, আর খসড়া হলে "জমা দিন"। সুপারভাইজার পরিমাণ বদলালে দুটোই দেখায়।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="text-lg font-semibold">{{ $order->document_no }}</h1>
    <div class="mb-3 text-sm font-medium" data-portal-do-status="{{ $order->status }}">
        {{ \App\Modules\Sales\Support\DeliveryOrderStatus::label($order->status) }}
    </div>

    <div class="grid gap-2">
        @foreach ($order->lines as $line)
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <div class="font-semibold">{{ $line->product?->name() }}</div>
                <div class="text-sm">{{ __('sales::delivery_order.qty') }}: {{ rtrim(rtrim((string) $line->qty, '0'), '.') }}</div>
                @if ($line->approved_qty !== null)
                    <div class="text-sm font-medium">{{ __('sales::delivery_order.approved_qty') }}: {{ rtrim(rtrim((string) $line->approved_qty, '0'), '.') }}</div>
                @endif
                <div class="num text-sm">{{ \App\Core\Support\Money::format($line->line_total) }}</div>
            </div>
        @endforeach
    </div>

    <div class="num mt-3 text-base font-semibold">{{ \App\Core\Support\Money::format($order->total) }}</div>

    @if ($order->isEditableByWriter())
        <form method="POST" action="{{ route('sales.portal.do.submit', $order->public_id) }}" class="mt-4">
            @csrf
            <button type="submit" class="h-(--spacing-field) w-full rounded-(--radius-field) bg-(--color-brand-500) font-medium text-white">
                {{ __('sales::delivery_order.submit') }}
            </button>
        </form>
    @endif

    <a href="{{ route('sales.portal.do.index') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::delivery_order.title') }}
    </a>
</x-sales::portal.layout>
