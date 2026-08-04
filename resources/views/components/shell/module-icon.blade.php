{{--
    shape — কোন আঁকাটা: 'module' (মডিউল চেনার) নাকি 'group' (কাজের ধরন)
    tone  — কোন রঙে: 'white' (গাঢ় রেলে), 'module' (মডিউলের রং), 'current'

    দুটো আলাদা প্রপ, একটা নয়: bottom nav-এ মডিউলের আকার লাগে কিন্তু সাদা
    রং নয় (পটভূমি সাদা)। একটা প্রপে দুই সিদ্ধান্ত বাঁধা থাকলে সেই সমন্বয়টা
    পাওয়াই যেত না।
--}}
@props(['module' => '', 'group' => 'dashboard', 'shape' => 'group', 'tone' => 'module'])

{{--
    আইকন — ইনলাইন SVG, কোনো আইকন লাইব্রেরি নয়।

    সেকশন ২০.৭: CDN থেকে লাইব্রেরি নয়। আর একটা পূর্ণ আইকন প্যাক (৩০০+)
    নামানো মানে যে কয়টা আসলে ব্যবহার হয় তার জন্য বাকিগুলোর ওজন বওয়া।

    দুই রকম আইকন, দুই কাজে:

      • রেলে — মডিউলের নিজের আইকন। পনেরোটা মডিউল আলাদা করে চেনার একমাত্র
        উপায়। সবগুলোতে একই আইকন দিলে রেলটা অর্থহীন হয়ে যায়, আর
        ব্যবহারকারীকে প্রতিবার হোভার করে পড়তে হয়।

      • তালিকায় — কাজের ধরনের আইকন (মাস্টার, লেনদেন, রিপোর্ট)।
--}}
@php
    // মডিউলভিত্তিক — রেলের জন্য
    $modules = [
        'dashboard' => 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6V11h-6v9Zm0-16v5h6V4h-6Z',
        // হিসাব — দাঁড়িপাল্লা
        'accounts' => 'M12 3v2.2L5.6 7.4 3 15h6l-2.6-7.6L12 5.6l5.6 1.8L15 15h6l-2.6-7.6L12 5.2V3h-1ZM11 6h2v13h5v2H6v-2h5V6ZM4.7 15h4.6L7 8.7 4.7 15Zm10 0h4.6L17 8.7 14.7 15Z',
        // বিক্রয় — গাড়ি
        'sales' => 'M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM1 2h3.3l.9 4H22l-2.6 9H7.1l-.4 2H19v2H5.2L3.6 4H1V2Zm5.6 6 1.1 5h9.8l1.4-5H6.6Z',
        // ক্রয় — ব্যাগ
        'purchase' => 'M12 2a4 4 0 0 0-4 4H5l-1 15h16L19 6h-3a4 4 0 0 0-4-4Zm0 2a2 2 0 0 1 2 2h-4a2 2 0 0 1 2-2ZM6.8 8h10.4l.8 11H6l.8-11Z',
        // মজুদ — বাক্স
        'inventory' => 'M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.2 6.6 3.3L12 10.8 5.4 7.5 12 4.2ZM5 9.3l6 3v7.4l-6-3V9.3Zm8 10.4v-7.4l6-3v7.4l-6 3Z',
        // গ্রাহক — মানুষ
        'customer' => 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z',
        // সরবরাহকারী — গুদাম
        'supplier' => 'M3 21V9l9-6 9 6v12h-7v-6h-4v6H3Zm2-2h3v-6h8v6h3v-9l-7-4.7L5 10v9Z',
        // কর্মী — দুইজন
        'hr' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM9 13c-3.9 0-7 1.8-7 4v3h14v-3c0-2.2-3.1-4-7-4Zm7 0c-.6 0-1.2 0-1.7.1 1.7.9 2.7 2.2 2.7 3.9v3h5v-3c0-2.2-2.7-4-6-4Z',
        // অনুমোদন — টিক
        'approval' => 'm9 16.2-3.5-3.5-1.4 1.4L9 19 20 8l-1.4-1.4L9 16.2Z',
        // মাস্টার ডাটা — স্তর
        'master_data' => 'M12 3 2 8l10 5 10-5-10-5Zm0 7.2L4.6 8 12 4.8 19.4 8 12 10.2ZM2 12l10 5 10-5v2l-10 5-10-5v-2Z',
        // প্রশাসন — ঢাল
        'system_admin' => 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm0 2.2 6 2.2V11c0 4-2.6 7.9-6 9-3.4-1.1-6-5-6-9V6.4l6-2.2Z',
    ];

    // কাজের ধরনভিত্তিক — তালিকার জন্য
    $groups = [
        'dashboard' => $modules['dashboard'],
        'master' => $modules['master_data'],
        'transactions' => 'M7 7h10v3l4-4-4-4v3H5v6h2V7Zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4Z',
        'approval' => $modules['approval'],
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

    $path = $shape === 'module'
        ? ($modules[$module] ?? $modules['dashboard'])
        : ($groups[$group] ?? $groups['dashboard']);

    // গাঢ় রেলে মডিউলের রং বসালে ৩:১ কনট্রাস্টও থাকে না — emerald বা navy
    // কালো দলা হয়ে যায়। মডিউলের রং তার নিজের জায়গায় (ট্যাব ইন্ডিকেটর,
    // ব্যাজ, বড় ফিল) কাজে লাগে, ২০px আইকনে নয়।
    $fill = match ($tone) {
        'white' => '#ffffff',
        'current' => 'currentColor',
        default => $colours[$module] ?? 'currentColor',
    };
@endphp

<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
     class="size-(--spacing-icon) shrink-0"
     style="fill: {{ $fill }}">
    <path d="{{ $path }}" />
</svg>
