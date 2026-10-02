{{--
    দোকানির নিজের বিক্রি কোথায় — ডেলিভারি ট্র্যাকিং (মালিক, ২ অক্টোবর ২০২৬)। কেবল নিজের ([[PortalController::tracking()]])।
    ⓘ প্রতি ৩০ সেকেন্ডে নিজে নতুন ([[screens.js::liveRefresh]]); রং কর্মীর পাতার হুবহু ([[SaleTracking::COLOURS]])।
--}}
@php $trackingColours = \App\Modules\Sales\Services\SaleTracking::COLOURS; @endphp
<x-sales::portal.layout :customer="$customer">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::tracking.title') }}</h1>

    <div class="grid gap-3" data-live x-data="liveRefresh({ url: '{{ route('sales.portal.tracking') }}', seconds: 30 })">
        @forelse ($list['rows'] as $row)
            <a href="{{ route('sales.portal.tracking.show', [$row['kind'], $row['id']]) }}" data-tracking-row
               class="block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3"
               style="border-left: 4px solid {{ $trackingColours[$row['category']] ?? '#9CA3AF' }}">
                <div class="font-semibold">{{ $row['no'] }}</div>
                <div class="text-sm text-(--color-ink-muted)">{{ $row['date'] }}</div>
                <div class="num text-sm">{{ \App\Core\Support\Money::format($row['total']) }}</div>
                <div class="text-sm font-medium" style="color: {{ $trackingColours[$row['category']] ?? '#111827' }}">
                    {{ __('sales::tracking.step.'.$row['step']) }}
                </div>
            </a>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::tracking.empty') }}</p>
        @endforelse
    </div>

    <a href="{{ route('sales.portal.home') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal.title') }}
    </a>
</x-sales::portal.layout>
