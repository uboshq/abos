{{--
    ⭐ উপরের হলুদ ব্যানার — যন্ত্রের লাল সতর্কতা (ব্যাকআপ নেই, দ্বিতীয় কপি নেই বা থেমে গেছে), কেবল যিনি ব্যাকআপ
    দেখেন তাঁর পাতায় ([[StatusNotices::forBanner()]])।

    ── কেন নিচের বার থেকে সরল (পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬) ─────────────
    আগে প্রতিটা পাতার নিচে সবসময় দুটো লাল বার্তা ঘুরত — মানুষ লাল দেখাই বন্ধ করে দেয়, আর যেদিন সত্যিকারের নতুন
    বিপদ আসে সেদিনও চোখ পড়ে না। ⓘ এখন সতর্কতাটা একবার, উপরে, হলুদে; "সরান" চাপলে এই লগইনে আর আসে না।
    সতর্কতা নিজে হারায় না — ঘণ্টায় লাল ব্যাজসহ থাকে।

    ⓘ জাভাস্ক্রিপ্ট ছাড়া — ছোট একটা ফর্ম, সেশনে মনে রাখে ([[NotificationController::closeBanner()]])। ছাপায় আসে না।
--}}
@php
    $closed = (array) session('shell.banner.closed', []);
    $banners = auth()->check()
        ? array_values(array_filter(
            app(\App\Core\Services\StatusNotices::class)->forBanner(),
            fn (array $n): bool => ! in_array(sha1($n['text']), $closed, true),
        ))
        : [];
@endphp

@foreach ($banners as $banner)
    <div data-notice-banner role="status"
         class="print-hide mb-3 flex items-center gap-3 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-ink)">
        <x-ui.icon name="alert-triangle" :size="16" class="shrink-0 text-(--color-warning-hover)" />

        @if ($banner['url'])
            <a href="{{ $banner['url'] }}" class="min-w-0 flex-1 hover:underline">{{ $banner['text'] }}</a>
        @else
            <span class="min-w-0 flex-1">{{ $banner['text'] }}</span>
        @endif

        <form method="POST" action="{{ route('notifications.banner.close') }}" class="shrink-0">
            @csrf
            <input type="hidden" name="key" value="{{ sha1($banner['text']) }}">
            <button type="submit"
                    class="rounded-(--radius-field) px-2 py-0.5 text-xs text-(--color-ink-muted) hover:bg-(--color-surface-hover)">
                {{ __('core.notice.dismiss') }}
            </button>
        </form>
    </div>
@endforeach
