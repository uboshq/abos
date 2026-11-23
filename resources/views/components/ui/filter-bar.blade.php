@props([
    /** [[Panel::plain()]] / [[Facets::panel()]]-এর তথ্য — প্যানেল ([[filter-layout]]) যা আঁকে, বার তারই বোতাম, চিপ আর তারিখ */
    'facets',
])

{{--
    ⭐ টুলবার ছাড়া রিপোর্টের সরু ছাঁকনির বার — স্থিতিপত্র, ডে বুক, দিনের হিসাব বন্ধ, মালিকানা স্বত্ব, দলের হিসাব, নিট সম্পদ,
    বছর শেষের রেওয়ামিল (মালিক, ২৩ নভেম্বর ২০২৬: *"রিপোর্টে ছাঁকা যায় না"*)।

    ⓘ টুলবারের দ্বিতীয় সারির একই তিন জিনিস, একই ছাঁচে ([[filter.toggle]], [[filter.date-bar]]): "ছাঁকনি (n)" বোতাম, চালু
      ছাঁকনির চিপ, আর "📅 তারিখ ▾"। ঘরগুলো ডান পাশের প্যানেলে; রিপোর্ট চলে প্যানেলের "দেখান" বা Enter-এ।
    ⓘ পাতার শিরোনাম আর বোতাম আগের মতোই পাতার মাথায় — এখানে কেবল ছাঁকনি।
--}}
@php
    $filterMode = \App\Core\Support\Ui::filters(\App\Core\Support\LookRegistry::lookFor(
        \App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)
    ));
@endphp

<div {{ $attributes->merge(['class' => 'print-hide mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)']) }} data-filter-bar>
    <div class="toolbar-view flex flex-col bg-(--color-toolbar)">
        <div class="order-2 flex flex-wrap items-center gap-2 px-2 py-1.5">
            @include('components.ui.filter.toggle', ['facets' => $facets, 'filterMode' => $filterMode])

            @if (($facets['date'] ?? null) !== null)
                @include('components.ui.filter.date-bar', ['dateBar' => $facets['date']])
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
