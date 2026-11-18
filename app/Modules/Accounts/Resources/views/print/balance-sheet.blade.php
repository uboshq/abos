{{--
    ⭐ স্থিতিপত্রের কাগজ — IAS 1 ছকে, NEXUS-এর নমুনার মানে, আমাদের নিয়মে (মালিক, ১৬ নভেম্বর ২০২৬; ১৭ নভেম্বর ২০২৬ "ok", নমুনা `rs-bs-print`)।

    ⓘ পাতা 1: আর্থিক অবস্থার বিবরণী — অ-চলতি/চলতি ভাগ, প্রতি লাইনে নোট, আগের অর্থবছরের শেষ দিন (IAS 1.38), পরিবর্তন আর %;
      অনুপাতের সারি; "সম্পদ = মালিকের অংশ + দেনা" যাচাই; কোম্পানি আইনের চার সই (সেটিংসে বন্ধ করা যায়)।
    ⓘ পাতা 2: নির্বাচিত নোট আর মালিকানা স্বত্বের পরিবর্তন।
    ⓘ সংখ্যা এখানে কষা হয় না — [[FinancialStatements::position()]], যা পর্দার একই [[BalanceSheetService::build()]] পড়ে।
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
@include('accounts::print.fs.notes')
@include('accounts::print.fs.soce')

</body>
</html>
