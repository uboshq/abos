{{--
    ছকের একটা ঘর — চাপলে ঐ কোম্পানি ও শাখার মডিউল-ড্যাশবোর্ড।

    ⓘ `—` = এই সংখ্যা শাখা ধরে ভাগ হয় না; `••••` = দেখার অনুমতি নেই — দুইটাই চাপা যায় না।
--}}
@php
    $hidden = $value === \App\Modules\Executive\Services\Figures::HIDDEN;
    $text = match (true) {
        $value === null => '—',
        $hidden => $value,
        \App\Modules\Executive\Services\Figures::definition($key)['money'] => \App\Core\Support\Money::format($value, 0),
        default => (string) (int) $value,
    };
@endphp
<td class="tabular px-3 py-1.5 text-right" data-cell="{{ $key }}">
    @if ($value === null || $hidden)
        <span class="text-(--color-ink-muted)" title="{{ $hidden ? __('executive::today.hidden') : __('executive::today.not_by_branch') }}">{{ $text }}</span>
    @else
        <button type="submit" form="executive-open" name="go" class="tabular hover:underline"
                value="{{ \App\Modules\Executive\Support\Go::to($company, $branch, 'module.dashboard', ['module' => \App\Modules\Executive\Services\Figures::dashboardOf($key)]) }}"
                title="{{ __('executive::today.open', ['what' => __('executive::figure.'.$key)]) }}">{{ $text }}</button>
    @endif
</td>
