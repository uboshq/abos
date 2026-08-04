@props(['tone' => 'secondary', 'href' => null, 'icon' => null])

{{--
    বাটন — সেকশন ১৪.৭-এর সংশোধিত রঙে।

    মূল প্যালেটের Success (#10B981), Warning (#F59E0B) ও Danger (#EF4444)
    সাদা লেখা নিয়ে AA পাশ করত না। এখানে সংশোধিত মানগুলো, আর অ্যাম্বার তার
    রং রেখে গাঢ় লেখা নেয় — গাঢ় অ্যাম্বার আর বাদামির পার্থক্য থাকে না।

    উচ্চতা ৪৮px (সেকশন ১৬.৮), যা ন্যূনতম টাচ টার্গেটের (৪৪px) উপরে।
--}}
@php
    $tones = [
        'primary' => 'bg-(--color-brand-500) text-(--color-ink-inverse) hover:bg-(--color-brand-600)',
        'success' => 'bg-(--color-success) text-(--color-ink-inverse) hover:bg-(--color-success-hover)',
        'warning' => 'bg-(--color-warning) text-(--color-warning-ink) hover:bg-(--color-warning-hover) hover:text-(--color-ink-inverse)',
        'danger' => 'bg-(--color-danger) text-(--color-ink-inverse) hover:bg-(--color-danger-hover)',
        'secondary' => 'border border-(--color-border) bg-(--color-surface-card) text-(--color-ink) hover:bg-(--color-surface-app)',
        'ghost' => 'text-(--color-ink-muted) hover:bg-(--color-surface-hover) hover:text-(--color-ink)',
    ];

    $classes = 'inline-flex min-h-(--spacing-touch) items-center justify-center gap-2 rounded-(--radius-field) '
        . 'px-4 text-sm font-medium transition-colors ' . ($tones[$tone] ?? $tones['secondary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<span aria-hidden="true">{{ $icon }}</span>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $attributes->get('type', 'button') }}"
            {{ $attributes->except('type')->merge(['class' => $classes]) }}>
        @if ($icon)<span aria-hidden="true">{{ $icon }}</span>@endif
        {{ $slot }}
    </button>
@endif
