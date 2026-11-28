{{-- ⭐ এই পাতা (বা অংশ) নিজের CSS চায় — `<x-page-css name="counter" />` ([[PageCss::PAGES]])।
     ⓘ যে ফাইল ক্লাসগুলো লেখে সেখানেই বসানো, পাতায় নয় — তাই অংশটা যে পাতাতেই ঢুকুক, CSS সঙ্গে যায়। একাধিকবার চাইলেও একবারই আসে। --}}
@props(['name'])
@php($sheet = \App\Core\Support\PageCss::page($name))
@pushOnce('page-css', 'page-css-'.$name)
    @vite([$sheet])
@endPushOnce
