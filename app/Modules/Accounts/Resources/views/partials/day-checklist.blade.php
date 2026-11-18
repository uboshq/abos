{{--
    ⭐ দিন বন্ধের চেকলিস্ট — মালিক, ১৮ নভেম্বর ২০২৬: *"চেকলিস্ট আকারে … যেটি যেটি করা হয় নাই লিঙ্কে ক্লিক করে এই কাজগুলো সম্পন্ন করে ফেললো"*।
    ⓘ উৎস [[DayChecklist::for()]]; দিন শেষের হিসাবের পাতা আর মাঝখানের সতর্কতা — দুই জায়গায় একই তালিকা। কেবল সতর্কতা, কিছু আটকায় না।
    @param list<array> $items
--}}
@php
    $m = fn ($v) => \App\Core\Support\Money::format($v);
    [$tone, $icon] = [
        ['danger' => 'text-(--color-danger)', 'warning' => 'text-(--color-warning-hover)', 'info' => 'text-(--color-badge-info-ink)'],
        ['danger' => 'alert-triangle', 'warning' => 'clock', 'info' => 'info'],
    ];
@endphp
@if ($items === [])
    <p class="text-sm text-(--color-success)" data-check-done>
        <x-ui.icon name="check-circle" :size="16" class="inline align-text-bottom" /> {{ __('accounts::dayclose.check.all_done') }}
    </p>
@else
    <ul class="grid gap-1 text-sm" data-day-checklist>
        @foreach ($items as $it)
            <li class="flex items-start gap-2" data-check="{{ $it['key'] }}">
                <x-ui.icon :name="$icon[$it['tone']] ?? 'info'" :size="16" class="mt-0.5 shrink-0 {{ $tone[$it['tone']] ?? '' }}" />
                <span class="min-w-0 flex-1">
                    {{ __('accounts::dayclose.check.'.$it['key'], ['count' => $it['count'], 'amount' => $it['amount'] !== null ? $m($it['amount']) : '']) }}
                    @if (! empty($it['names']))
                        <span class="block text-xs font-semibold">{{ implode(' · ', $it['names']) }}</span>
                    @endif
                </span>
                @if ($it['url'])
                    <a href="{{ $it['url'] }}" class="shrink-0 font-medium text-(--color-link) underline-offset-2 hover:underline">
                        {{ __($it['tone'] === 'info' ? 'accounts::dayclose.check.see' : 'accounts::dayclose.check.go') }} →</a>
                @endif
            </li>
        @endforeach
    </ul>
@endif
