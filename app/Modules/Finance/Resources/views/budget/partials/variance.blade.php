{{-- ফারাক — খরচে বেশি লাল, আয়ে কম লাল; শূন্য হলে রং নেই --}}
@php
    $bad = $row['over'] === $row['over_is_bad'] && bccomp($row['variance'], '0', 4) !== 0;
@endphp
<span @class([
    'num',
    'text-(--color-badge-danger-ink)' => $bad,
    'text-(--color-badge-success-ink)' => ! $bad && bccomp($row['variance'], '0', 4) !== 0,
])>{{ \App\Core\Support\Money::format($row['variance']) }}</span>
