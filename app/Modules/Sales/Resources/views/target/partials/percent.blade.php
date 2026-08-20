{{-- টার্গেট না থাকলে ড্যাশ, শূন্য নয়: "টার্গেট নেই" আর "০% হয়েছে"
     দুইটা আলাদা কথা, আর দ্বিতীয়টা অন্যায়। --}}
<span @class([
    'tabular font-medium',
    'text-(--color-badge-success-ink)' =>
        $row['percent'] !== null && bccomp($row['percent'], '100', 1) >= 0,
    'text-(--color-badge-warning-ink)' =>
        $row['percent'] !== null && bccomp($row['percent'], '100', 1) < 0,
])>{{ $row['percent'] === null ? '—' : $row['percent'].'%' }}</span>
