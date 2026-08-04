@props(['tone' => 'draft', 'icon' => null])

{{--
    ব্যাজ — ছোট লেখায় স্ট্যাটাস দেখানোর একমাত্র নিরাপদ পথ।

    মডিউলের রং সরাসরি ছোট লেখায় বসালে WCAG AA ভাঙে (সেকশন ১৪.৭): অ্যাম্বার
    সাদায় ২.১৫, সায়ান ২.৪৩। তাই টিন্টেড জোড়া — সবগুলো ৬.৩:১-এর উপরে।

    রং কখনো একা অর্থ বহন করে না — লেখাটাই মূল, রং শুধু সাহায্য করে। কালার-
    ব্লাইন্ড ব্যবহারকারী ও সাদাকালো প্রিন্ট, দুটোতেই এটাই একমাত্র পথ।
--}}
@php
    $tones = [
        'success' => ['bg' => 'var(--color-badge-success-bg)', 'ink' => 'var(--color-badge-success-ink)'],
        'pending' => ['bg' => 'var(--color-badge-pending-bg)', 'ink' => 'var(--color-badge-pending-ink)'],
        'danger' => ['bg' => 'var(--color-badge-danger-bg)', 'ink' => 'var(--color-badge-danger-ink)'],
        'info' => ['bg' => 'var(--color-badge-info-bg)', 'ink' => 'var(--color-badge-info-ink)'],
        'draft' => ['bg' => 'var(--color-badge-draft-bg)', 'ink' => 'var(--color-badge-draft-ink)'],
        'inventory' => ['bg' => 'var(--color-badge-inventory-bg)', 'ink' => 'var(--color-badge-inventory-ink)'],
    ];

    $picked = $tones[$tone] ?? $tones['draft'];
@endphp

<span {{ $attributes->merge([
        'class' => 'inline-flex items-center gap-1 rounded-(--radius-badge) px-2 py-0.5 text-xs font-medium whitespace-nowrap',
    ]) }}
      style="background: {{ $picked['bg'] }}; color: {{ $picked['ink'] }}">
    @if ($icon)
        <span aria-hidden="true">{{ $icon }}</span>
    @endif
    {{ $slot }}
</span>
