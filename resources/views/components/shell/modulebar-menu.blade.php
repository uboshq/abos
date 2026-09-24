@props([
    'label',

    /*
     * ⭐ ভাঁজটার চাবি — অনূদিত নামটা নয়, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ মাপার একটা স্থায়ী হাতল লাগে, আর `label` সেটা হতে পারে না:
     * ⛔ ভাষা বদলালে ওটা বদলায়, তাই বাংলায় চালানো পরীক্ষা ইংরেজি
     * নাম খুঁজে কিছুই পেত না — আর সবুজ-লালটা ভাষার উপর দাঁড়াত।
     */
    'fold' => null,
    'icon' => null,
    'tint' => '',
    'items',
])

{{--
    উপরের সারির একটা ড্রপডাউন — দলের (Master, প্রতিবেদন…) আর দলের ভিতরের
    ভাঁজের (ভাউচার) দুই জায়গাতেই একই।

    ⓘ ১৯ সেপ্টেম্বর ২০২৬ পর্যন্ত এই কোড [[shell.modulebar]]-এর ভিতরে ছিল,
    কেবল দলের জন্য। মালিক ভাউচারের ছয়টা ঘর এক বোতামে চাইলেন (*"eigulo
    mile ekta group korlei hoy"*) — ⚠️ একই ড্রপডাউন দ্বিতীয়বার লিখলে একদিন
    দুইটা দুই রকম দেখাত, তাই এখানে সরানো।

    ⚠️ খোলা-বন্ধের অবস্থা inline (`{ open: false }`), কম্পোনেন্ট নয় — CSP-Alpine
    এটা পড়ে, আর নতুন বান্ডেল লাগে না।
--}}
@php
    $activeItem = collect($items)->firstWhere('active', true);
@endphp

<div x-data="{ open: false }" class="relative shrink-0">
    <button type="button"
            @click="open = ! open" @click.outside="open = false"
            @keydown.escape.window="open = false"
            :aria-expanded="open.toString()"
            @class([
                'flex items-center gap-1 whitespace-nowrap -ms-px px-3 py-1 text-xs transition-colors first:ms-0',
                'modulebar-cell modulebar-cell-on' => $activeItem !== null,
                'modulebar-cell' => $activeItem === null,
            ])>
        @if ($icon)
            <x-ui.icon :name="$icon" :size="14" :class="$tint" />
        @endif

        {{ $label }}

        @if ($activeItem !== null)
            <span class="opacity-70" aria-hidden="true">›</span>
            <span class="max-w-32 truncate">{{ $activeItem['label'] }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         @if ($fold) data-modulebar-fold="{{ $fold }}" @endif
         class="absolute start-0 top-full z-30 mt-1 max-h-80 min-w-48 overflow-y-auto
                rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) py-1 shadow-lg">
        @foreach ($items as $item)
            <a href="{{ $item['url'] }}"
               @class([
                   'flex items-center gap-2 px-3 py-1.5 text-xs transition-colors hover:bg-(--color-surface-muted)',
                   'font-semibold text-(--color-brand-600)' => $item['active'] ?? false,
                   'text-(--color-ink-body)' => ! ($item['active'] ?? false),
               ])
               @if ($item['active'] ?? false) aria-current="page" @endif>
                <x-ui.icon :name="$item['icon'] ?? $icon" :size="14" :class="$tint" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</div>
