{{--
    ⭐ আর্থিক বিবরণীর পূর্ণ সেট — IAS 1.10: আর্থিক অবস্থা, লাভ-ক্ষতি (আগের বছরের একই সময়ের পাশে), মালিকানা স্বত্বের পরিবর্তন,
    নগদ প্রবাহ (পরোক্ষ), নোট — বাংলা বা ইংরেজি কাগজে (`?lang=en`; মালিক, ১৭ নভেম্বর ২০২৬ "ok")।
    ⓘ প্রতিটা বিবরণী নিজের পাতায়; অঙ্ক [[FinancialStatements]] থেকে।
--}}
@php
    $paren = fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day = fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
@endphp
<html>
<head>
@include('accounts::print.fs.style')
</head>
<body>

<htmlpagefooter name="fsFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: #444444;">{{ __('core.listing.printed_at') }}: {{ $printedAt }} · ABOS @if (! $signatures && ! $board) · {{ __('core.listing.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

@include('accounts::print.fs.position')

<pagebreak />
@include('accounts::print.fs.pl')
@include('accounts::print.fs.signs')

<pagebreak />
@include('accounts::print.fs.soce')
@include('accounts::print.fs.cf')

<pagebreak />
@include('accounts::print.fs.notes')

</body>
</html>
