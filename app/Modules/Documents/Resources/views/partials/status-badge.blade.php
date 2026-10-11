{{-- অবস্থা (§২২) — প্রতিটার নিজের রং।
     ⓘ ভাগের শব্দগুলো (খসড়া, বাতিল, মেয়াদোত্তীর্ণ) [[StatusTone::MAP]]-এর সাথে মেলে (EveryStatusWearsOneColourTest)। বাকি শব্দ ঐ মানচিত্রে
     বসানো হয়নি ইচ্ছা করে (documents রিভিউ, ১১ অক্টোবর ২০২৬): "অনুমোদিত" আর "জমা" অফার আর অর্ডারে ইচ্ছাকৃত "info" — সেখানে কাজ শেষ নয়;
     কাগজে "অনুমোদিত" মানে শেষ। এক রং চাপালে ঐ দুই মডিউলের অর্থ বদলাত। --}}
@php
    $tone = match ($status) {
        'approved', 'published' => 'success',
        'submitted', 'under_review' => 'pending',
        'changes_requested', 'rejected', 'expired', 'deleted' => 'danger',
        'archived', 'published_unapproved' => 'info',
        default => 'draft',
    };
@endphp
<x-ui.badge :tone="$tone">{{ __('documents::catalog.status.'.$status) }}</x-ui.badge>
