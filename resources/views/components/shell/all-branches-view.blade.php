{{--
    ⛔ "সব শাখা" = কেবল দেখা — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[AllBranchesView]])।

    দুইটা লাইন, একটাই জায়গা (খোলসে, প্রতিটা পাতায় — ১০০টা ফর্মে আলাদা করে নয়):
      · "সব শাখা"-তে: সরু ফিতা "সব শাখা — শুধু দেখার জন্য"; লেখার বোতাম ধূসর ([[all-branches-view.js]]), সার্ভারও থামায়
        ([[RefusesWritesInAllBranchesView]])।
      · একটা শাখা বাছা থাকলে, নতুন/বদলের ফর্মে: রঙিন লাইন "আপনি এখন X শাখায় লিখছেন" — কাগজটা কোথায় বসবে, জমার আগেই চোখে।
    ⓘ সব রূপে একই — রং টোকেন থেকে, তাই রূপ বদলালে নিজেই মানায়।
--}}
@props([
    // ⓘ পপআপে (পিক) ফিতা নয় — পেছনের পাতায় আগেই আছে; কেবল ফর্মের "কোন শাখায় লিখছেন"
    'banner' => true,
])

@php
    $branchView = app(\App\Core\Services\AllBranchesView::class);
    $branchViewer = auth()->user();
    $readOnlyView = $branchView->on($branchViewer);
    $writingIn = $readOnlyView ? null : $branchView->writingBranch($branchViewer, request()->route()?->getName());
@endphp

{{-- ⛔ "সব শাখা"-তে পাতার মাথায় ফিতা নেই — মালিকের নিয়ম, ১৮–১৯ নভেম্বর ২০২৬: পাতায় কোনো ফিতা/লাইন নয়। ধূসর বোতামের উপর রাখলে
     কারণটা দেখায় ("লিখতে হলে ওপর থেকে একটা শাখা বাছুন"), আর সার্ভার থামায় — ফিতা ছাড়াই বোঝা যায়। --}}
@if (! $readOnlyView && $writingIn !== null)
    <div role="status" data-writing-branch
         class="mb-3 flex items-center gap-2 rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-1.5 text-sm font-semibold text-(--color-badge-info-ink)">
        <span class="size-2 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
        <span>{{ __('core.all_branches.writing_in', ['branch' => $writingIn]) }}</span>
    </div>
@endif
