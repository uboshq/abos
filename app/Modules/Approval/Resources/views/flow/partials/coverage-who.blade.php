{{--
    কে সই করবেন — ধাপ ধরে।

    ⓘ ধাপগুলো ক্রমে, আর একই ধাপের একাধিক জন পাশাপাশি। ⚠️ ধাপ না দেখালে
    "দুইজনের সই" আর "যেকোনো একজন" দুইটা এক দেখাত, অথচ ব্যবসার দিক থেকে
    ওগুলো সম্পূর্ণ আলাদা।
--}}
@php
    $steps = $row['flow']?->steps?->sortBy('level')->groupBy('level') ?? collect();
@endphp

@forelse ($steps as $level => $group)
    <span class="me-2 whitespace-nowrap text-2xs">
        <span class="text-(--color-ink-muted)">{{ $level }}.</span>
        {{ $group->map(fn ($s) => $names[$s->approver_type][$s->approver_id] ?? '#'.$s->approver_id)->implode(' / ') }}
    </span>
@empty
    <span class="text-2xs text-(--color-ink-muted)">—</span>
@endforelse
