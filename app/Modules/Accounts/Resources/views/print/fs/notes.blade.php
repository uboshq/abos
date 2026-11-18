{{--
    ⭐ নির্বাচিত নোট — স্থায়ী সম্পদের চলাচল, নগদ ও ব্যাংক, সঞ্চিত মুনাফা, ঋণের ভাগ, আয়কর (নমুনা `rs-bs-print` পাতা 2)।
    ⓘ নম্বর বিবরণীর লাইনের একই ([[FinancialStatements]]-এর NOTE_*)।
--}}
@php
    $FS = \App\Modules\Accounts\Services\FinancialStatements::class;
    $paren ??= fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day ??= fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $n = $fs['notes'];
    $ppe = $n[$FS::NOTE_PPE];
    $head = fn (int $no, string $key) => '<div class="sec-title">'.e(__('accounts::fs.notes.n', ['n' => $no])).' · '.e(__('accounts::fs.notes.'.$key)).'</div>';
    $r = fn (string $label, string $v, string $class = 'ln') => '<tr class="'.$class.'"><td>'.e($label).'</td><td class="num">'.$paren($v).'</td></tr>';
@endphp

<div class="sec-title" style="font-size: 12pt;">{{ __('accounts::fs.notes.title') }} @if ($locale === 'bn')<span class="sec-en">· {{ __('accounts::fs.notes.title_en') }}</span>@endif</div>

<div data-note="{{ $FS::NOTE_PPE }}">
    {!! $head($FS::NOTE_PPE, 'ppe') !!}
    <table class="st" width="100%">
        <thead><tr><th class="l">{{ __('accounts::fs.bs.particulars') }}</th><th width="30%">{{ __('accounts::fs.notes.amount') }}</th></tr></thead>
        <tbody>
            {!! $r(__('accounts::fs.notes.cost_at', ['date' => $day($fs['prior_as_of'])]), $ppe['cost_open']) !!}
            {!! $r(__('accounts::fs.notes.additions'), $ppe['additions']) !!}
            {!! $r(__('accounts::fs.notes.disposals'), bcmul($ppe['disposals'], '-1', 4)) !!}
            {!! $r(__('accounts::fs.notes.cost_at', ['date' => $day($fs['as_of'])]), $ppe['cost_close'], 'sub') !!}
            {!! $r(__('accounts::fs.notes.dep_at', ['date' => $day($fs['prior_as_of'])]), $ppe['dep_open']) !!}
            {!! $r(__('accounts::fs.notes.dep_charge'), $ppe['charge']) !!}
            {!! $r(__('accounts::fs.notes.dep_disposals'), bcmul($ppe['dep_disposals'], '-1', 4)) !!}
            {!! $r(__('accounts::fs.notes.dep_at', ['date' => $day($fs['as_of'])]), $ppe['dep_close'], 'sub') !!}
            {!! $r(__('accounts::fs.notes.nbv'), $ppe['nbv'], 'grand') !!}
        </tbody>
    </table>
</div>

<div data-note="{{ $FS::NOTE_CASH }}">
    {!! $head($FS::NOTE_CASH, 'cash') !!}
    <table class="st" width="100%">
        <thead><tr><th class="l">{{ __('accounts::fs.bs.particulars') }}</th><th width="22%">{{ $day($fs['as_of']) }}</th><th width="22%">{{ $day($fs['prior_as_of']) }}</th></tr></thead>
        <tbody>
            @foreach ($n[$FS::NOTE_CASH] as $c)
                <tr class="ln"><td>{{ $c['label'] }}</td><td class="num">{{ $paren($c['now']) }}</td><td class="num">{{ $paren($c['then']) }}</td></tr>
            @endforeach
            @php $cashLine = collect($fs['assets']['current'])->firstWhere('kind', 'cash'); @endphp
            <tr class="grand"><td>{{ __('accounts::fs.notes.total') }}</td><td class="num">{{ $paren($cashLine['now'] ?? '0') }}</td><td class="num">{{ $paren($cashLine['then'] ?? '0') }}</td></tr>
        </tbody>
    </table>
</div>

<div data-note="{{ $FS::NOTE_RE }}">
    {!! $head($FS::NOTE_RE, 're') !!}
    <table class="st" width="100%">
        <thead><tr><th class="l">{{ __('accounts::fs.bs.particulars') }}</th><th width="30%">{{ __('accounts::fs.notes.amount') }}</th></tr></thead>
        <tbody>
            {!! $r(__('accounts::fs.notes.re_open', ['date' => $day($fs['prior_as_of'])]), $n[$FS::NOTE_RE]['opening']) !!}
            {!! $r(__('accounts::fs.notes.re_profit'), $n[$FS::NOTE_RE]['profit']) !!}
            {!! $r(__('accounts::fs.notes.re_other'), bcsub(bcsub($n[$FS::NOTE_RE]['closing'], $n[$FS::NOTE_RE]['opening'], 4), $n[$FS::NOTE_RE]['profit'], 4)) !!}
            {!! $r(__('accounts::fs.notes.re_close', ['date' => $day($fs['as_of'])]), $n[$FS::NOTE_RE]['closing'], 'grand') !!}
        </tbody>
    </table>
</div>

<div data-note="{{ $FS::NOTE_LOANS }}">
    {!! $head($FS::NOTE_LOANS, 'loans') !!}
    <table class="st" width="100%">
        <thead><tr><th class="l">{{ __('accounts::fs.bs.particulars') }}</th><th width="30%">{{ __('accounts::fs.notes.amount') }}</th></tr></thead>
        <tbody>
            {!! $r(__('accounts::fs.notes.loan_total'), $n[$FS::NOTE_LOANS]['total']) !!}
            {!! $r(__('accounts::fs.notes.loan_due'), $n[$FS::NOTE_LOANS]['current']) !!}
            {!! $r(__('accounts::fs.notes.loan_long'), bcsub($n[$FS::NOTE_LOANS]['total'], $n[$FS::NOTE_LOANS]['current'], 4), 'grand') !!}
        </tbody>
    </table>
    @unless ($n[$FS::NOTE_LOANS]['scheduled'])
        <div class="note">{{ __('accounts::fs.notes.loan_none') }}</div>
    @endunless
</div>

<div data-note="{{ $FS::NOTE_TAX }}">
    {!! $head($FS::NOTE_TAX, 'tax') !!}
    @if ($n[$FS::NOTE_TAX]['account'] === null)
        <div class="note">{{ __('accounts::fs.notes.tax_none') }}</div>
    @else
        <table class="st" width="100%">
            <tbody>
                {!! $r(__('accounts::fs.notes.tax_account', ['account' => $n[$FS::NOTE_TAX]['account']]), $n[$FS::NOTE_TAX]['balance']) !!}
            </tbody>
        </table>
    @endif
</div>
