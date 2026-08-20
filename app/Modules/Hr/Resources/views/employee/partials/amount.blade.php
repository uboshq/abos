{{-- ঘরটা খালি, আর placeholder-এ আজকের মান। খালি রেখে সংরক্ষণ করলে
     আগেরটাই থাকে; সংখ্যা বসালে সেটাই নতুন মান। বসানো মান দেখালে কেউ
     বুঝত না কোনটা প্রস্তাব আর কোনটা বর্তমান। --}}
@php
    $current = collect($components)->firstWhere('head.id', $head->id);
@endphp
<input type="number" step="0.0001" min="0"
       name="amounts[{{ $head->id }}]"
       value="{{ old('amounts.'.$head->id) }}"
       placeholder="{{ $current ? \App\Core\Support\Money::format($current['amount']) : '—' }}"
       class="num h-(--spacing-field-compact) w-32 rounded-(--radius-field) border
              border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
