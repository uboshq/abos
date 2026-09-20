{{--
    ত্রুটির পাতার খোলস — ৪০৩, ৪০৪, ৪১৯, ৪২৯, ৫০০, ৫০৩ সবাই এটাই পরে।

    ── কেন নিজের খোলস, `x-layouts.app` নয় ──────────────────────────────
    ⛔ অ্যাপের খোলসটা `$menu` চায়, আর মেনু বানাতে লগইন করা ব্যবহারকারী
    লাগে। ⚠️ কিন্তু ত্রুটির পাতা ঠিক তখনই আসে যখন লগইন নেই (৪১৯), অথবা
    সেশন হারিয়ে গেছে, অথবা কিছু একটা ভেঙে গেছে (৫০০) — ওখানে মেনু
    বানাতে গেলে ত্রুটির পাতা নিজেই ত্রুটি দিত।

    ── ⓘ কেন কোনো CSS ফাইল টানা হয়নি ───────────────────────────────────
    ৫০০-এর একটা সাধারণ কারণ হলো বিল্ড বা স্টোরেজ ভেঙে যাওয়া। ⭐ তখন
    stylesheet টানলে পাতাটা **সাদা** আসত, আর ব্যবহারকারী কিছুই বুঝতেন না।
    তাই যা লাগে সবটুকু এখানেই, ইনলাইন।

    ⚠️ ইনলাইন `<style>` — CSP-র nonce লাগে, আর ত্রুটির পাতায় nonce
    নির্ভরযোগ্য নয়। তাই স্টাইলটা `style=""` বৈশিষ্ট্যে, কারণ নীতিতে
    `style-src-attr 'unsafe-inline'` খোলা আছে।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f5fafb;
             font-family:'Nirmala UI','Segoe UI',system-ui,sans-serif;color:#0b1f33;padding:16px">

    <main style="max-width:32rem;text-align:center">
        <p style="margin:0 0 .5rem;font-size:3.5rem;font-weight:700;color:#bce3ea;line-height:1">
            {{ $code }}
        </p>

        <h1 style="margin:0 0 .75rem;font-size:1.35rem;font-weight:600">{{ $title }}</h1>

        <p style="margin:0 0 1.5rem;font-size:.95rem;line-height:1.7;color:#41525e">{{ $body }}</p>

        {{-- ⓘ শুরুর পাতাটা রুটের নামে নয়, `/` — ত্রুটির সময় রুটের তালিকাও
             ভাঙা থাকতে পারে, আর তখন `route()` নিজেই আরেকটা ত্রুটি দিত। --}}
        <a href="/" style="display:inline-block;min-height:44px;line-height:44px;padding:0 1.25rem;
                           border-radius:8px;background:#1565c0;color:#fff;text-decoration:none;font-size:.9rem">
            {{ __('core.error.go_home') }}
        </a>
    </main>

</body>
</html>
