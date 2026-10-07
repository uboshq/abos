@props(['rows'])

{{--
    পাতার সারি — কোথায় আছি, আর কতটার মধ্যে।

    ── কেন এক পাতাতেও দেখা যায় ─────────────────────────────────────────
    আগে প্রতিটা তালিকায় লেখা ছিল `@if ($rows->hasPages())` — অর্থাৎ এক
    পাতায় সব ধরলে নিচে কিছুই থাকত না। যুক্তিটা বোধগম্য: যেখানে যাওয়ার
    জায়গা নেই সেখানে তীর দেখিয়ে লাভ কী।

    কিন্তু প্রশ্নটা কেবল "যাওয়া যায় কি না" নয়। "এই তালিকায় মোট কয়টা"
    — এটা রপ্তানি করার আগে, ছাপার আগে, বা কাউকে সংখ্যা বলার আগে
    প্রতিবার দরকার হয়। ছাঁকনি দিয়ে ৩২৬ থেকে ৩৭-এ নামলে ওই ৩৭ সংখ্যাটাই
    সবচেয়ে জরুরি তথ্য, আর তখনই ঠিক পাতা ভাগ হয় না বলে ওটা উধাও হত।

    D365 এটাই করে — "১ - ৩ / ৩" সবসময় থাকে, তীরগুলো কেবল নিষ্ক্রিয় হয়।

    ── কেন ২৯টা পর্দায় হাতে না লিখে একটা কম্পোনেন্ট ────────────────────
    আকৃতিটা ২৯ জায়গায় হুবহু এক ছিল। রূপ বদলালে পেজারও বদলাবে (D365-এ
    তীর, Fiori-তে নিচের বার) — ২৯ জায়গায় ছড়ানো থাকলে একটা রূপ ঠিক করতে
    ২৯টা ফাইল ছুঁতে হত, আর একটা ভুলে গেলে সেটা কেবল ওই পর্দাতেই ধরা
    পড়ত।
--}}
@php
    $total = $rows->total();
    $from = $total > 0 ? $rows->firstItem() : 0;
    $to = $total > 0 ? $rows->lastItem() : 0;
@endphp

<div data-pager
     {{ $attributes->merge([
         'class' => 'pager flex flex-wrap items-center gap-3 border-t border-(--color-border) px-3 py-2',
     ]) }}>

    {{-- সীমাটা আগে, তীর পরে — মানুষ সংখ্যাটা পড়তে আসেন, তীরে হাত
         দিতে নয়। --}}
    <span class="num shrink-0 text-2xs text-(--color-ink-muted)">
        {{ __('core.table.range', ['from' => $from, 'to' => $to, 'total' => $total]) }}
    </span>

    {{-- ⓘ নিজের তীর, বাংলায় — Laravel-এর ডিফল্ট `links()` "« Previous / Next » / Showing 1 to 50 of 60 results" লিখত,
         আর সংখ্যাটা উপরে আগেই আছে (৬ অক্টোবর ২০২৬-এর বিক্রয় ধারার পরীক্ষা, গ্রাহকের খাতা) --}}
    @if ($rows->hasPages())
        <nav data-pager-links class="ms-auto flex items-center gap-2 text-xs" aria-label="{{ __('pager.label') }}">
            @if ($rows->onFirstPage())
                <span class="rounded-(--radius-field) border border-(--color-border) px-2.5 py-1 text-(--color-ink-muted) opacity-50">‹ {{ __('pager.previous') }}</span>
            @else
                <a href="{{ $rows->previousPageUrl() }}" rel="prev"
                   class="rounded-(--radius-field) border border-(--color-border) px-2.5 py-1 text-(--color-link) hover:bg-(--color-surface-hover)">‹ {{ __('pager.previous') }}</a>
            @endif
            <span class="tabular text-(--color-ink-muted)">{{ __('pager.page_of', ['page' => $rows->currentPage(), 'last' => $rows->lastPage()]) }}</span>
            @if ($rows->hasMorePages())
                <a href="{{ $rows->nextPageUrl() }}" rel="next"
                   class="rounded-(--radius-field) border border-(--color-border) px-2.5 py-1 text-(--color-link) hover:bg-(--color-surface-hover)">{{ __('pager.next') }} ›</a>
            @else
                <span class="rounded-(--radius-field) border border-(--color-border) px-2.5 py-1 text-(--color-ink-muted) opacity-50">{{ __('pager.next') }} ›</span>
            @endif
        </nav>
    @endif
</div>
