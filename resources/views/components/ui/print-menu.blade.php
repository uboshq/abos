@props([
    'documents' => [],
])

{{--
    ছাপার বোতাম — কাগজের মাপ সহ।

    ── কেন প্রতিটা মাপের জন্য আলাদা লিংক, একটা ড্রপডাউন নয় ──────────────
    দোকানে যে কাগজে ছাপা হবে সেটা মেশিনের উপর নির্ভর করে, ব্যবহারকারীর
    পছন্দের উপর নয়। কাউন্টারে ৮০mm রোল, অফিসে A4 — একই লোক দিনে দুইটাই
    ব্যবহার করেন। ড্রপডাউনে "মনে রাখা" পছন্দ থাকলে ভুল মেশিনে পাঠিয়ে
    কাগজ নষ্ট হত, তাই তিনটাই সরাসরি দেখা যায়।

    target="_blank" — PDF নতুন ট্যাবে খোলে, তাই ছাপার পর ব্যবহারকারী
    ডকুমেন্টের পাতাতেই থাকেন। একই ট্যাবে খুললে ফিরতে হত ব্রাউজারের পিছনে
    যাওয়ার বোতাম দিয়ে, আর PDF থেকে ফেরাটা সব ব্রাউজারে সমান কাজ করে না।

    @param $documents  ['লেবেল' => 'রুটের নাম' => ['param' => value]] আকারে
                       নয় — সরল রাখা হয়েছে: ['label' => ..., 'url' => ...]
--}}
@php
    $papers = \App\Core\Engines\Print\PaperSize::all();
@endphp

<div x-data="{ open: false }" class="relative print-hide">
    <button type="button"
            @click="open = ! open"
            @click.outside="open = false"
            :aria-expanded="open ? 'true' : 'false'"
            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field)
                   border border-(--color-border) px-3 text-sm transition-colors
                   hover:bg-(--color-surface-hover)">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
            <path d="M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z"/>
        </svg>
        {{ __('core.print.print') }}
    </button>

    <div x-show="open"
         x-cloak
         class="absolute end-0 z-20 mt-1 w-64 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">

        @foreach ($documents as $document)
            <div class="border-b border-(--color-border) last:border-b-0">
                <div class="px-3 pt-2 text-2xs font-medium text-(--color-ink-muted)">
                    {{ $document['label'] }}
                </div>

                <div class="flex gap-1 p-2">
                    @foreach ($papers as $paper)
                        <a href="{{ $document['url'] }}?paper={{ $paper }}"
                           target="_blank" rel="noopener"
                           class="flex-1 rounded-(--radius-field) border border-(--color-border)
                                  px-2 py-1 text-center text-2xs transition-colors
                                  hover:bg-(--color-surface-hover)">
                            {{ \App\Core\Engines\Print\PaperSize::of($paper)->label() }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
