{{--
    ⭐ কাগজের সইয়ের পাতা — ২৮ সেপ্টেম্বর ২০২৬, মালিকের কাজ।

    ⛔ আগে এই পাতায় ছিল পক্ষের নাম, অঙ্ক আর কাগজের একটা লিংক। সারি, মাধ্যম,
    লেনদেন নম্বর — কিছুই না। সইকারী হয় লিংকে গিয়ে কাগজটা খুঁজতেন, নয় না
    দেখেই সই দিতেন।

    ⓘ কী দেখানো হয় তা কাগজ নিজে বলে ([[ShowsItselfForSigning]]); এই পাতা
    কেবল সাজায়। প্রথম কয়েকটা সারি পাতায়, পুরোটা "পুরো কাগজ" জানালায়।

    ⚠️ জানালাটা ছাপার পাতা নয়: খসড়া কাউন্টার বিক্রির চালান-বিল ছাপা
    ইচ্ছাকৃতভাবে আটকানো, আর মালিক বলেছেন — দেখা যাবে, ছাপা নয়
    (সমন্বয়কারী abos-69)। তাই একই `$sheet` থেকে আঁকা, নতুন কোনো পথ নেই।

    ⓘ `<dialog>` নয়, x-show — `showModal()` ডাকতে CSP-Alpine-এ পদ্ধতি লাগত,
    বাকি জানালাগুলো যেভাবে চলে এটাও সেভাবে ([[draft-actions]])।
--}}
@php
    $preview = 6;
    $columns = array_map(fn (array $c) => [
        'key' => $c['key'],
        'label' => $c['label'],
        'numeric' => $c['numeric'] ?? false,
        'render' => fn (array $row) => $row[$c['key']] ?? '',
    ], $sheet['columns']);
    $rows = $sheet['rows'];
    $hiddenRows = max(0, count($rows) - $preview);
@endphp

<div x-data="{ open: false }" data-signing-sheet
     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <div class="flex items-start justify-between gap-4">
        <h2 class="text-sm font-semibold">{{ __('approval::field.sheet') }}</h2>

        <button type="button" x-on:click="open = true"
                class="rounded-(--radius-field) bg-(--color-brand-600) px-3 py-1 text-xs font-semibold text-white
                       hover:bg-(--color-brand-700)">
            {{ __('approval::action.view_whole_paper') }}
        </button>
    </div>

    @include('approval::inbox.partials.sheet-body', ['rows' => array_slice($rows, 0, $preview)])

    @if ($hiddenRows > 0)
        <p class="mt-2 text-2xs text-(--color-ink-muted)">
            {{ __('approval::message.more_rows_in_paper', ['count' => $hiddenRows]) }}
        </p>
    @endif

    <div x-show="open" x-cloak role="dialog" aria-modal="true"
         x-on:keydown.escape.window="open = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
        <div x-on:click.outside="open = false"
             class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-5 shadow-lg">
            <div class="flex items-start justify-between gap-4">
                <p class="text-lg font-bold text-(--color-ink)">
                    {{ $document?->drillDocumentNo() ?? __('approval::field.document') }}
                </p>

                <button type="button" x-on:click="open = false"
                        class="rounded-(--radius-field) border border-(--color-border) px-3 py-1 text-xs">
                    {{ __('approval::action.close') }}
                </button>
            </div>

            @include('approval::inbox.partials.sheet-body', ['rows' => $rows])
        </div>
    </div>
</div>
