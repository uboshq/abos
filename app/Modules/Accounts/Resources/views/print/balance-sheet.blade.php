{{--
    ⭐ স্থিতিপত্রের কাগজ — IAS 1 ছকে, উপর থেকে নিচে, লেজারের প্যাডে (মালিক, ১৬ নভেম্বর ২০২৬: *"ব্যালেন্স শিটটাকে কীভাবে ইন্টারন্যাশনাল মানের
    আপডেট করা যায় … সবগুলাই যাতে প্রিন্ট করা যায়"*)।

    ⓘ ক্রম IAS 1-এর: সম্পদ → মোট সম্পদ → মালিকানা স্বত্ব (চলতি বছরের লাভসহ) → দায় → মোট মালিকানা স্বত্ব ও দায়। পাশে তুলনা — আগের অর্থবছরের
      শেষ দিন (IAS 1.38, [[BalanceSheetService::withComparative()]])। শেষে ডান পাশে মিল: পার্থক্য 0.00 — না মিললে লেখা থাকে।
    ⓘ সংখ্যা এখানে কষা হয় না — পর্দার একই [[BalanceSheetService::build()]]।
--}}
@php
    $ink = '#000000';
    $muted = '#444444';
    $line = '#bdbdbd';
    $money = fn ($v) => \App\Core\Support\Money::format((string) $v);
    $paren = fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day = fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $logo = $company->logoData();
    $extras = array_values(array_filter([
        bccomp((string) $sheet['profit_earlier'], '0', 4) !== 0
            ? ['label' => __('accounts::field.profit_earlier_years'), 'amount' => $sheet['profit_earlier'], 'prior' => $sheet['prior']['profit_earlier'] ?? '0'] : null,
        ['label' => __('accounts::field.profit_this_year'), 'amount' => $sheet['profit_this_year'], 'prior' => $sheet['prior']['profit_this_year'] ?? '0'],
    ]));
    $priorDate = $sheet['prior']['as_of'] ?? null;
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 10mm 10mm 14mm 10mm; margin-footer: 6mm; footer: html_bsFoot; }
    body { font-family: hindsiliguri; font-size: 9pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 8pt; text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 14pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 2mm; }
    table.info { margin-top: 1.5mm; border-bottom: 0.25mm solid {{ $line }}; font-size: 8.6pt; }
    table.info td { padding: 0.8mm 4mm 1.4mm 0; white-space: nowrap; }
    table.info td.facts { white-space: normal; padding-top: 0; color: {{ $muted }}; }
    .k { color: {{ $muted }}; }
    table.bs { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 3mm; }
    .bs th { font-weight: bold; text-align: center; padding: 1.6mm 1.2mm; border-top: 0.6mm solid {{ $ink }}; border-bottom: 0.4mm solid {{ $ink }}; }
    .bs td { padding: 1.2mm 1.6mm; border-bottom: 0.1mm solid {{ $line }}; }
    .bs tr.side td { background-color: #dcdcdc; font-weight: bold; font-size: 10pt; border-top: 0.4mm solid {{ $ink }}; }
    .bs tr.grp td { font-weight: bold; background-color: #f3f3f3; }
    .bs tr.ln td.name { padding-left: 7mm; }
    .bs tr.z td { background-color: #ececec; }
    .bs tr.sub td { font-weight: bold; border-top: 0.3mm solid #777777; }
    .bs tr.grand td { font-weight: bold; border-top: 0.5mm solid {{ $ink }}; border-bottom: 0.6mm double {{ $ink }}; background-color: #e2e2e2; }
    .recon td { padding: 0.8mm 2mm; font-size: 9pt; }
    .recon tr.last td { font-weight: bold; border-top: 0.3mm solid {{ $ink }}; border-bottom: 0.8mm double {{ $ink }}; }
    .note { font-size: 7.5pt; color: {{ $muted }}; margin-top: 2mm; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 9pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpagefooter name="bsFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }}@if (! $signatures) · {{ __('core.listing.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

<table class="pad" width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif</div>@endif
        @if ($company->bin || $company->tin)
            <div class="co-meta">@if ($company->bin)BIN {{ $company->bin }}@endif @if ($company->bin && $company->tin)·@endif @if ($company->tin)TIN {{ $company->tin }}@endif</div>
        @endif
    </td>
    <td width="75mm" style="text-align: right;">
        <div class="title">{{ __('accounts::menu.balance_sheet') }}</div>
        @if ($locale === 'bn')<div class="title-en">Statement of Financial Position</div>@endif
    </td>
</tr></table>
<div class="rule"></div>

{{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬) --}}
<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('accounts::field.as_of') }}:</span> <b>{{ $day($sheet['as_of']) }}</b></td>
    @if ($priorDate)<td><span class="k">{{ __('accounts::statement.bs.compared') }}:</span> <b>{{ $day($priorDate) }}</b></td>@endif
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td style="text-align: right;"><span class="k">{{ __('core.listing.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr>
<tr><td colspan="4" class="facts">{{ __('accounts::statement.bs.layout') }}@if (! $sheet['agrees']) · <b>{{ __('accounts::message.sheet_does_not_agree', ['gap' => $money($sheet['difference'])]) }}</b>@endif</td></tr>
</table>

<table class="bs" width="100%" repeat_header="1">
    <thead><tr>
        <th style="text-align: left;">{{ __('accounts::statement.bs.particulars') }}</th>
        <th width="34mm">{{ $day($sheet['as_of']) }}</th>
        @if ($priorDate)<th width="34mm">{{ $day($priorDate) }}</th>@endif
    </tr></thead>
    <tbody>
        @foreach ([['assets', __('accounts::field.assets'), $sheet['assets'], [], 'assets'],
                   ['equity', __('accounts::statement.bs.equity'), $sheet['equity'], $extras, 'equity'],
                   ['liabilities', __('accounts::statement.bs.liabilities'), $sheet['liabilities'], [], 'liabilities']] as [$key, $label, $groups, $more, $total])
            <tr class="side"><td colspan="{{ $priorDate ? 3 : 2 }}">{{ $label }}</td></tr>
            @foreach ($groups as $group)
                <tr class="grp"><td>{{ $group['head']->name() }}</td><td class="num">{{ $paren($group['total']) }}</td>@if ($priorDate)<td class="num">{{ $paren($group['prior'] ?? '0') }}</td>@endif</tr>
                @foreach ($group['lines'] as $i => $ln)
                    <tr @class(['ln', 'z' => $i % 2 === 1])><td class="name">{{ $ln['label'] ?? $ln['account']->name() }}</td><td class="num">{{ $paren($ln['amount']) }}</td>@if ($priorDate)<td class="num">{{ $paren($ln['prior'] ?? '0') }}</td>@endif</tr>
                @endforeach
            @endforeach
            @foreach ($more as $extra)
                <tr class="grp"><td>{{ $extra['label'] }}</td><td class="num">{{ $paren($extra['amount']) }}</td>@if ($priorDate)<td class="num">{{ $paren($extra['prior']) }}</td>@endif</tr>
            @endforeach
            <tr class="{{ $key === 'assets' ? 'grand' : 'sub' }}">
                <td>{{ __('accounts::statement.bs.total_'.$key) }}</td>
                <td class="num">{{ $paren($sheet['totals'][$total]) }}</td>
                @if ($priorDate)<td class="num">{{ $paren($sheet['prior']['totals'][$total] ?? '0') }}</td>@endif
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ __('accounts::field.total_funding') }}</td>
            <td class="num">{{ $paren($sheet['totals']['funding']) }}</td>
            @if ($priorDate)<td class="num">{{ $paren($sheet['prior']['totals']['funding'] ?? '0') }}</td>@endif
        </tr>
    </tbody>
</table>

{{-- ⭐ শেষে ডান পাশে মিল — সম্পদ = মালিকানা স্বত্ব + দায় --}}
<table class="recon" align="right" style="margin-top: 3mm;" data-recon>
    <tr><td>{{ __('accounts::field.total_assets') }}</td><td class="num">{{ $paren($sheet['totals']['assets']) }}</td></tr>
    <tr><td>{{ __('accounts::field.total_funding') }}</td><td class="num">{{ $paren($sheet['totals']['funding']) }}</td></tr>
    <tr class="last"><td>{{ __('accounts::statement.bs.difference') }}</td><td class="num">{{ $paren($sheet['difference']) }}</td></tr>
</table>

@if ($branchNote)
    <div class="note" style="clear: both;">{{ __('accounts::message.branch_sheet_may_not_agree') }}</div>
@endif

@if ($signatures)
    <table width="100%" style="margin-top: 18mm; clear: both;" data-signs><tr>
        <td width="35%" class="sign">{{ __('core.listing.sign_prepared') }}</td>
        <td width="30%"></td>
        <td width="35%" class="sign">{{ __('core.listing.sign_approved') }}</td>
    </tr></table>
@endif

</body>
</html>
