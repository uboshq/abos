{{--
    ভাউচারের থার্মাল (৮০মিমি) রূপ — A4-এর [[voucher-look]]-এর সেই একই ২০ সাজ, সাদা-কালোয় ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A4 A5 tharmal tintiroi"*।
    ⚠️ সুইচ আগের মতোই: meta · totals · words · narration · signatures (+ `accounts.print_signature_lines`)।
    ⓘ সই একটাই — প্রথমটা, অপর পক্ষের (দিলেন/পেলেন); রোলে তিনটা পাশাপাশি সই করা যায় না (পুরনো থার্মাল ভাউচারের সেই নিয়ম)।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $kind = (string) ($voucher['type'] ?? '');
    $isJournal = in_array($kind, ['journal', 'contra'], true);
@endphp

@include('print.partials.look-head-thermal', [
    'L' => $look->look, 'head' => \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo')),
    'title' => (string) ($voucher['type_label'] ?? ($title ?? '')),
    'no' => $voucher['document_no'], 'date' => $voucher['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'notices' => array_filter([$notice ?? null]),
])

@if ($profile->shows('meta'))
    <table class="kv" data-meta>
        @if (! empty($voucher['party']))<tr><td>{{ $t('core.print.party') }}</td><td class="num"><strong>{{ $voucher['party'] }}</strong></td></tr>@endif
        @if (! empty($voucher['branch']))<tr><td>{{ $t('core.company.branch') }}</td><td class="num" style="font-family: hindsiliguri">{{ $voucher['branch'] }}</td></tr>@endif
    </table>
    @if ($profile->shows('narration') && ! empty($voucher['narration']))<div class="small" data-narration>{{ $voucher['narration'] }}</div>@endif
@endif

<div class="rule"></div>
{!! $look->thermalAmount($isJournal ? $t('core.print.total') : $t('core.print.amount'), (string) $voucher['total_debit'], $profile->shows('words') ? (string) ($voucher['amount_in_words'] ?? '') : '') !!}

<table class="it" style="margin-top: 2mm">
    <tr>
        <th>{{ $t('core.print.account') }}</th>
        <th class="num" style="width: 17mm">{{ $t('core.table.debit') }}</th>
        <th class="num" style="width: 17mm">{{ $t('core.table.credit') }}</th>
    </tr>
    @foreach ($voucher['lines'] as $line)
        <tr>
            <td><strong>{{ $line['account'] }}</strong></td>
            <td class="num">{{ $line['debit'] ?: '' }}</td>
            <td class="num">{{ $line['credit'] ?: '' }}</td>
        </tr>
        @if (! empty($line['narration']))<tr class="sub"><td colspan="3">{{ $line['narration'] }}</td></tr>@endif
    @endforeach
    @if ($profile->shows('totals'))
        <tr class="tot" data-totals>
            <td>{{ $t('core.print.total') }}</td>
            <td class="num">{{ $voucher['total_debit'] }}</td>
            <td class="num">{{ $voucher['total_credit'] }}</td>
        </tr>
    @endif
</table>

@if ($profile->shows('signatures') && $settings->get('accounts.print_signature_lines', true))
    <table class="signatures" data-signatures><tr>
        <td style="width: 30%"></td>
        <td><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $signatures[0] ?? '' }}</td></tr></table></td>
        <td style="width: 30%"></td>
    </tr></table>
@endif
