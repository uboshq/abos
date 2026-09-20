{{-- এ পর্যন্ত কত ফেরত — মোট থেকে বাকি বাদ।

     ⚠️ মোট (`principal`) ঐচ্ছিক — পরিচিতের ধারে প্রায়ই লেখা থাকে না।
     ⓘ তখন "—", কারণ বানানো সংখ্যা না লেখার চেয়েও খারাপ। --}}
@php
    $principal = $row['account']->principal;
    $left = ltrim((string) $row['balance'], '-');
    $back = $principal === null ? null : bcsub((string) $principal, $left, 4);
@endphp

@if ($back === null)
    —
@else
    {{ \App\Core\Support\Money::format(bccomp($back, '0', 4) > 0 ? $back : '0') }}
@endif
