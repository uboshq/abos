{{--
    ভাউচার · নকশা 17 — দ্বিভাষিক — প্রতিটা ঘরের নাম ইংরেজি আর বাংলা দুটোতেই।
    মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*; ভাগের কাজ [[VoucherPaper]]-এ।
--}}
@php
    $p = \App\Modules\Accounts\Support\VoucherPaper::from(get_defined_vars());
    $v = $p->v;
    $h = $p->head();
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    .num { text-align: right; white-space: nowrap; font-family: {{ $p->numFont() }}; }
</style>
@php $both = fn (string $k) => $p->tIn('en', $k).' / '.$p->tIn('bn', $k); @endphp
<div style="text-align: center">{!! $p->logo(10) !!}<div style="font-size: 12.5pt; font-weight: bold">{{ $h['name'] }}</div>
    <div style="margin-top: 1.5mm"><span style="border: 0.4mm solid #111; padding: 0.8mm 6mm; font-weight: bold">{{ $v['type_label'] }}</span></div>{!! $p->duplicateMark() !!}
</div>
{!! $p->loudNotice() !!}
<table style="width: 100%; margin-top: 3mm; font-size: 8.8pt">
    <tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm; width: 34%">{{ $both('voucher_no') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm; font-weight: bold">{{ $v['document_no'] }}</td></tr>
    <tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('date') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm">{{ $v['date'] }}</td></tr>
    @if ($p->counterparty() !== '')<tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('party') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm; font-weight: bold">{{ $p->counterparty() }}</td></tr>@endif
    <tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('account') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm">{{ implode(' · ', array_map(fn ($l) => $l['account'], $v['lines'])) }}</td></tr>
    @if ($p->method() !== '')<tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('mode') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm">{{ $p->method() }}</td></tr>@endif
    @if ($p->shows('narration') && $v['narration'] !== '')<tr data-narration><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('narration') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm">{{ $v['narration'] }}</td></tr>@endif
    <tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('amount') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm"><b>{{ $v['total_debit'] }}</b> · {{ \App\Core\Support\BanglaDigits::of($v['total_debit']) }}</td></tr>
    @if ($p->shows('words'))<tr><td style="border: 0.25mm solid #333; background: #f4f6f9; padding: 1.3mm 2mm">{{ $both('in_words') }}</td><td style="border: 0.25mm solid #333; padding: 1.3mm 2mm">{{ $v['amount_in_words'] }}</td></tr>@endif
</table>
{!! $p->signatures('line', [$both('received_by'), $both('prepared_by'), $both('approved_by')], 12) !!}
