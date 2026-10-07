{{--
    ⓘ A5 রূপ — A4-এর এই partial থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_look_a5)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    ভাউচারের নকশা-কাঠামো — আদায় · পরিশোধ · খরচ · জাবেদা · কন্ট্রা, ২০ নকশা এটাকেই আলাদা সাজে ডাকে।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"ভাউচার (আদায় · পরিশোধ · খরচ · জাবেদা) ... EKHONI KORO"*।

    চাই (কন্ট্রোলারের সেই একই ডেটা — [[VoucherPrintController]]): $voucher, $signatures, $notice, $company,
    $paper, $profile, $settings; আর $look (নকশার সাজ, [[PaperLook]])।
    ⓘ $voucher['type'] আর ['type_label'] না থাকলে শিরোনাম পুরনো `$title` থেকে।

    ⚠️ সুইচ আগের মতোই মানা হয়: meta · totals · words · narration · signatures (+ পুরনো
    `accounts.print_signature_lines`) — নতুন সাজ কোনো বন্ধ করা অংশ ফিরিয়ে আনে না।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $kind = (string) ($voucher['type'] ?? '');
    $titleText = (string) ($voucher['type_label'] ?? ($title ?? ''));
    $head = \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo'));
    $ac = $look->accent();
    $isJournal = in_array($kind, ['journal', 'contra'], true);
    $amount = (string) $voucher['total_debit'];
@endphp

@include('print.partials.look-head-a5', [
    'L' => $look->look, 'head' => $head, 'title' => $titleText,
    'no' => $voucher['document_no'], 'date' => $voucher['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'qr' => '', 'qrHint' => '', 'notices' => array_filter([$notice ?? null]),
])

{{-- ── ঘর আর বড় অঙ্ক ─────────────────────────────────────────────────── --}}
<table style="width: 100%; margin-top: 4.32mm">
    <tr>
        <td style="vertical-align: top; padding-right: 4.32mm">
            @if ($profile->shows('meta'))
                <table style="width: 100%" data-meta>
                    @if (! empty($voucher['party']))
                        <tr><td class="cap" style="padding: 0.72mm 0; width: 23.04mm">{{ mb_strtoupper($t('core.print.party')) }}</td><td style="padding: 0.72mm 0; font-weight: bold">{{ $voucher['party'] }}</td></tr>
                    @endif
                    @if (! empty($voucher['branch']))
                        <tr><td class="cap" style="padding: 0.72mm 0; width: 23.04mm">{{ mb_strtoupper($t('core.company.branch')) }}</td><td style="padding: 0.72mm 0">{{ $voucher['branch'] }}</td></tr>
                    @endif
                    @if ($profile->shows('narration') && ! empty($voucher['narration']))
                        <tr data-narration><td class="cap" style="padding: 0.72mm 0; width: 23.04mm">{{ mb_strtoupper($t('core.table.narration')) }}</td><td style="padding: 0.72mm 0">{{ $voucher['narration'] }}</td></tr>
                    @endif
                </table>
            @endif
        </td>
        <td style="width: 56.16mm; vertical-align: top">
            {!! $look->amountBox(
                mb_strtoupper($isJournal ? $t('core.print.total') : $t('core.print.amount')),
                $amount,
                $profile->shows('words') ? (string) ($voucher['amount_in_words'] ?? '') : '',
            ) !!}
        </td>
    </tr>
</table>

{{-- ── খাতের সারি ──────────────────────────────────────────────────────── --}}
<table class="lines">
    <tr>
        <th style="{{ $look->th() }} width: 5.76mm">#</th>
        <th style="{{ $look->th() }}">{{ mb_strtoupper($t('core.print.account')) }}</th>
        <th style="{{ $look->th() }}">{{ mb_strtoupper($t('core.table.narration')) }}</th>
        <th class="num" style="{{ $look->th() }} width: 21.6mm">{{ mb_strtoupper($t('core.table.debit')) }}</th>
        <th class="num" style="{{ $look->th() }} width: 21.6mm">{{ mb_strtoupper($t('core.table.credit')) }}</th>
    </tr>
    @foreach ($voucher['lines'] as $i => $line)
        <tr>
            <td style="{{ $look->td($i) }}" class="muted">{{ $i + 1 }}</td>
            <td style="{{ $look->td($i) }} font-weight: bold">{{ $line['account'] }}</td>
            <td style="{{ $look->td($i) }}" class="muted">{{ $line['narration'] ?? '' }}</td>
            <td class="num" style="{{ $look->td($i) }}">{{ $line['debit'] ?: '' }}</td>
            <td class="num" style="{{ $look->td($i) }}">{{ $line['credit'] ?: '' }}</td>
        </tr>
    @endforeach
    @if ($profile->shows('totals'))
        <tr class="total" data-totals>
            <td colspan="3" style="{{ $look->totalRow() }}">{{ mb_strtoupper($t('core.print.total')) }}</td>
            <td class="num" style="{{ $look->totalRow() }}">{{ $voucher['total_debit'] }}</td>
            <td class="num" style="{{ $look->totalRow() }}">{{ $voucher['total_credit'] }}</td>
        </tr>
    @endif
</table>

{{-- ── সই — ধরন অনুযায়ী ঘর কন্ট্রোলারের ([[VoucherPrintController::signatures()]]) ── --}}
@if ($profile->shows('signatures') && $settings->get('accounts.print_signature_lines', true))
    @if (($look->look['seal'] ?? false) === true)
        <table style="width: 100%; margin-top: 7.2mm" data-signatures>
            <tr>
                @foreach ($signatures as $role)
                    <td style="width: {{ (int) round(100 / max(count($signatures), 1)) }}%; border: 0.22mm solid {{ $look->ink() }}; height: 18.72mm; vertical-align: bottom; text-align: center; font-size: 6.8pt; padding: 1.08mm; font-family: hindsiliguri">{{ $role }}</td>
                @endforeach
            </tr>
        </table>
    @else
        <table class="signatures" data-signatures>
            <tr>
                @foreach ($signatures as $role)
                    <td style="width: {{ (int) round(100 / max(count($signatures), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $role }}</td></tr></table></td>
                @endforeach
            </tr>
        </table>
    @endif
@endif
