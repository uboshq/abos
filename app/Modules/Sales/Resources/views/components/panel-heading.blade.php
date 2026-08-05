@props(['tone' => 'plain'])

{{-- ডান পাশের প্যানেলের ভাগের শিরোনাম।

     ভাগগুলো আলাদা রঙে, কারণ ওরা আলাদা প্রশ্নের উত্তর দেয়: এই চালানে কত,
     এখন কত দিতে হবে, আর পার্টির মোট কত বাকি। এক রঙে সব থাকলে চোখ ওগুলোকে
     এক তালিকা ভাবত আর যোগফল মেলাতে বসত। --}}
@php
    $classes = match ($tone) {
        'success' => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
        'pending' => 'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)',
        default => 'bg-(--color-surface-app) text-(--color-ink-muted)',
    };
@endphp

<p class="border-y border-(--color-border) px-3 py-1 text-2xs font-semibold uppercase tracking-wide {{ $classes }}">
    {{ $slot }}
</p>
