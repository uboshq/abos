@props(['module' => '', 'group' => 'dashboard'])

{{--
    আইকন — ইনলাইন SVG, কোনো আইকন লাইব্রেরি নয়।

    সেকশন ২০.৭: CDN থেকে লাইব্রেরি নয়। আর একটা পূর্ণ আইকন প্যাক (৩০০+ আইকন)
    নামানো মানে যে ৯টা আসলে ব্যবহার হয় তার জন্য বাকি ২৯০টার ওজন বওয়া।

    রংটা মডিউল থেকে আসে (সেকশন ১৪.২) — শুধু আইকনে, পুরো পাতায় নয়।
--}}
@php
    $paths = [
        'dashboard' => 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6V11h-6v9Zm0-16v5h6V4h-6Z',
        'master' => 'M12 3 2 8l10 5 10-5-10-5Zm0 7.2L4.6 8 12 4.8 19.4 8 12 10.2ZM2 12l10 5 10-5v2l-10 5-10-5v-2Z',
        'transactions' => 'M7 7h10v3l4-4-4-4v3H5v6h2V7Zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4Z',
        'approval' => 'm9 16.2-3.5-3.5-1.4 1.4L9 19 20 8l-1.4-1.4L9 16.2Z',
        'reports' => 'M5 3h9l5 5v13H5V3Zm8 1.5V9h4.5L13 4.5ZM7 12h10v2H7v-2Zm0 4h10v2H7v-2Z',
        'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9.4 4a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-2-1.2L16.5 3h-4l-.4 2.6c-.7.3-1.4.7-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2Z',
    ];

    $colours = [
        'dashboard' => 'var(--color-module-dashboard)',
        'accounts' => 'var(--color-module-finance)',
        'sales' => 'var(--color-module-sales)',
        'purchase' => 'var(--color-module-purchase)',
        'inventory' => 'var(--color-module-inventory)',
        'hr' => 'var(--color-module-hr)',
        'customer' => 'var(--color-module-crm)',
        'supplier' => 'var(--color-module-crm)',
        'approval' => 'var(--color-module-approval)',
        'master_data' => 'var(--color-module-reports)',
        'system_admin' => 'var(--color-module-security)',
    ];
@endphp

<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
     class="size-(--spacing-icon) shrink-0"
     style="fill: {{ $colours[$module] ?? 'currentColor' }}">
    <path d="{{ $paths[$group] ?? $paths['dashboard'] }}" />
</svg>
