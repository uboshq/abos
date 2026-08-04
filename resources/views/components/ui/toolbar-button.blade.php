@props(['icon', 'label'])

{{--
    টুলবারের একটা বোতাম।

    লেখাটা tooltip হিসেবে নয়, aria-label হিসেবেও — মোবাইলে hover বলে কিছু
    নেই (সেকশন ২০.৫), তাই title-এর উপর ভরসা করা যায় না। বড় স্ক্রিনে লেখাটা
    আইকনের পাশে দেখাও যায়।
--}}
@php
    $paths = [
        'filter' => 'M3 5h18v2l-7 7v5l-4 2v-7L3 7V5Z',
        'columns' => 'M4 4h5v16H4V4Zm6.5 0h3v16h-3V4ZM15 4h5v16h-5V4Z',
        'density' => 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z',
        'export' => 'M12 3v10.6l3.3-3.3 1.4 1.4L12 17.4l-4.7-5.7 1.4-1.4L12 13.6V3ZM4 19h16v2H4v-2Z',
        'print' => 'M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z',
        'refresh' => 'M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z',
    ];
@endphp

<button type="button"
        {{ $attributes->merge([
            'class' => 'flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                        text-sm text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)
                        hover:text-(--color-ink)',
        ]) }}
        aria-label="{{ $label }}">
    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
        <path d="{{ $paths[$icon] ?? $paths['filter'] }}"/>
    </svg>
    <span class="hidden xl:inline">{{ $label }}</span>
</button>
