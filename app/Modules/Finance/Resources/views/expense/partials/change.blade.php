{{-- বেড়েছে না কমেছে।

     ── কেন শতাংশ, টাকা নয় ─────────────────────────────────────────────
     "৪,৩০০ বেশি" বড় শোনায় বেতনে, ছোট শোনায় জ্বালানিতে — অথচ দুইটার
     গুরুত্ব উল্টো হতে পারে। শতাংশ দুইটাকে এক মাপে আনে।

     ── আগে শূন্য হলে ──────────────────────────────────────────────────
     শূন্য দিয়ে ভাগ করা যায় না, আর "অসীম শতাংশ" কিছু বোঝায় না। তখন
     লেখা হয় "নতুন" — কারণ ঘটনাটা ওটাই: এই খাতে আগে কিছু যায়নি। --}}
@php
    $now = $row['now'];
    $before = $row['before'];
    $up = bccomp($now, $before, 4) > 0;
    $same = bccomp($now, $before, 4) === 0;
    $fresh = bccomp($before, '0', 4) === 0;
    $pct = $fresh ? null : bcdiv(bcmul(bcsub($now, $before, 4), '100', 4), $before, 1);
@endphp

@if ($same)
    <span class="text-2xs text-(--color-ink-muted)">—</span>
@elseif ($fresh)
    <span class="rounded-(--radius-field) bg-(--color-badge-pending-bg) px-2 py-0.5 text-2xs
                 text-(--color-badge-pending-ink)">{{ __('finance::field.new_head') }}</span>
@else
    <span @class([
        'num text-2xs',
        'text-(--color-danger)' => $up,
        'text-(--color-success)' => ! $up,
    ])>{{ $up ? '▲' : '▼' }} {{ rtrim(rtrim(ltrim($pct, '-'), '0'), '.') }}%</span>
@endif
