@props(['text' => null])
{{--
    ⭐ নামের পাশে ⓘ — পাতার নিজের ব্যাখ্যার লাইন এর ভিতরে যায় (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "no description line on any screen";
    ২০ নভেম্বর ২০২৬ — যে পাতাগুলো [[ui/page-header]] ব্যবহার না করে নিজে h1 আর ম্লান লাইন লেখে, তাদের জন্য)।

    ব্যবহার: শিরোনামের **ভিতরে** `<x-ui.page-info :text="…" />`, আর ব্যাখ্যার `<p>`-তে `data-page-subtitle`।
    ⓘ [[ui/page-header]]-এর `subtitle`-এর হুবহু একই জোড়া: ⓘ চিহ্নটা বাকি রূপে `hidden` (একটা পিক্সেলও নেয় না), লাইনটা কেবল "ABOS"
      আর "Dynamic"-এ লুকায় ([[abos-look.css]], [[dynamic-look.css]]) — Navy, Blue, SAP, D365-এ লাইনটা যেমন ছিল তেমনই থাকে।
    ⓘ জাভাস্ক্রিপ্ট লাগে না — [[abos-list-head.js]] foldPageNotes() কেবল যে লাইন ধরতে পারে (প্রথম বাক্সের আগের), এটা সব।
--}}
@if (filled($text))<span data-page-info class="hidden" title="{{ $text }}" aria-label="{{ $text }}"><x-ui.icon name="info" :size="15" /></span>@endif
