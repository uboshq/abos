{{--
    ⭐ খসড়ার অবস্থা — কেন আটকে, আর ৩ দিন পেরোলে লাল (বিক্রয় পরিকল্পনা §৪.৩, ৬ অক্টোবর ২০২৬)।
    ⓘ বয়স গোনা লেখার সময় থেকে (created_at), আদেশের "পুরনো খসড়া"-র একই সীমা ([[OrderProgress::staleCutoff()]])।
    চাই: $draft, $why
--}}
@php
    $stale = $draft->created_at !== null && $draft->created_at->lte(\App\Modules\Sales\Services\OrderProgress::staleCutoff());
    $days = $draft->created_at === null ? 0 : (int) $draft->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
@endphp
@if ($stale)
    <span data-stale-draft class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs whitespace-nowrap font-semibold bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)"
          title="{{ __('sales::order_status.stale_hint', ['days' => $days]) }}">{{ __('sales::order_status.stale', ['days' => $days]) }}</span>
@endif
{{ $why }}
