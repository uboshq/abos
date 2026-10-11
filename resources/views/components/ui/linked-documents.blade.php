{{--
    একটা রেকর্ডের সাথে জোড়া কাগজ (ডকুমেন্ট পরিকল্পনা §১৫; ৯ অক্টোবর ২০২৬)।

    ⓘ যেকোনো ড্রিলের রেকর্ডে বসে — `<x-ui.linked-documents :record="$supplier" />`। তালিকাটা আসে
    [[LinkedDocuments]] চুক্তি থেকে, তাই এই পাতার মডিউল ডকুমেন্টের কোনো ক্লাস চেনে না। ⓘ যিনি দেখছেন
    তিনি যে কাগজ দেখতে পান না সেটা তালিকায় আসেই না; কিছু না থাকলে অংশটাই বসে না।
--}}
@props(['record'])

@php
    $viewer = auth()->user();
    $papers = $viewer instanceof \App\Models\User && $record instanceof \App\Core\Contracts\Drillable
        ? app(\App\Core\Contracts\LinkedDocuments::class)->forRecord($record::drillSourceType(), (int) $record->getKey(), $viewer)
        : [];
@endphp

@if ($papers !== [])
    <section data-boxed data-linked-documents
             class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('core.linked_documents.title') }}
        </h2>

        <ul class="divide-y divide-(--color-border) text-sm">
            @foreach ($papers as $paper)
                <li class="flex items-center gap-3 px-4 py-2">
                    <span class="num whitespace-nowrap text-(--color-ink-muted)">{{ $paper['no'] }}</span>
                    <a href="{{ $paper['url'] }}" class="min-w-0 truncate text-(--color-link) hover:underline">
                        {{ $paper['name'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
