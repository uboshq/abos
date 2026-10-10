{{--
    ডানের টাকার সারি — মালিকের নমুনার ক্রমে, সব নকশার ভাগের। চাই: $v, $paper; ঐচ্ছিক $upper, $lang।
    ⓘ "আগের বকেয়া" সুইচ বন্ধ হলে "মোট বকেয়া"-ও যায় — দুইটা একসাথে অর্থ বহন করে।
    নকশা সাজায়: `table.sums`, `tr.net`, `tr.owed`, `.num`।
--}}
@php
    $upper = $upper ?? false;
    $lang = $lang ?? 'en';
    $t = fn (string $key) => $upper ? mb_strtoupper($v->t($key, $lang)) : $v->t($key, $lang);
    $s = $v->sums;
@endphp
<table class="sums">
    <tr><td>{{ $t('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
    <tr><td>{{ $t('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
    @if ($v->showVat)<tr><td>{{ $t('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
    {{-- ⓘ দামের ভিতরের ভ্যাট আর ভাড়া — শূন্য হলে সারিই নেই (PR #17 রিভিউ ⛔৩) --}}
    @if ($v->showVat && bccomp((string) ($s['vat_included'] ?? '0'), '0', 4) > 0)<tr data-vat-included><td>{{ $t('vat_included') }}</td><td class="num">{{ $paper->money($s['vat_included']) }}</td></tr>@endif
    @if (bccomp((string) ($s['freight'] ?? '0'), '0', 4) > 0)<tr data-freight><td>{{ $t('freight') }}</td><td class="num">{{ $paper->money($s['freight']) }}</td></tr>@endif
    <tr><td>{{ $t('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
    <tr class="net"><td>{{ $t('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
    <tr><td>{{ $t('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
    <tr><td>{{ $t('invoice_due') }}</td><td class="num">{{ $paper->money($s['invoice_due']) }}</td></tr>
    @if ($v->shows('previous_due'))
        <tr data-previous-due><td>{{ $t('previous_due') }}</td><td class="num">{{ $paper->money($s['previous_due']) }}</td></tr>
        <tr class="owed"><td>{{ $t('total_due') }}</td><td class="num">{{ $paper->money($s['outstanding']) }}</td></tr>
    @endif
</table>
