{{-- ⓘ A5 সংস্করণ — A4-এর special_db থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), গড়ন একই। মালিক, ৩ অক্টোবর ২০২৬: "সেম টেমপ্লেটটা A5 ও দিয়ে দাও"। QR ২৮mm — এর চেয়ে ছোটে ফোন ধরতে দেরি করে। --}}
{{--
    Special for DB — বিল + হিসাবের বিবরণী, বাঁয়ে সাদা ফিতা। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "২গ", নাম মালিকের)।

    ⓘ ফিতায় উপর থেকে নিচে: INVOICE / With Accounts Statement, বিল নম্বর · তারিখ, ক্রেতা, ড্রাইভার, টার্গেট রিমাইন্ডার,
    বড় QR, আর তলায় INVOICE SUMMARY — শেষ লাইন ডিলারের ভাষায় Due / Advance / No Due, আর বেশি দিলে "Extra Paid"
    ([[InvoicePaperView::balanceWord()]], [[billLeftWord()]])। ডানে মাথায় কোম্পানি আর লোগো, তারপর পণ্য (Grand Total-এর
    টাকার ঘর ফ্রেমে), তারপর বিলের মাসের হিসাবের চলাচল ([[InvoicePaperView::movement()]], জের "… Due / … Advance")।
    ⛔ কোনো ভরাট রং নেই — মালিক: "kono solid color hobena ei template"; আলাদা করে কেবল নীল দাগ, ফ্রেম আর মোটা অক্ষর।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ; ফিতার ড্রাইভার বাক্স (`data-transport`) আর সারাংশের আগের বকেয়া
    (`data-previous-due`) এখানে, একই সুইচে। লক্ষ্য না থাকলে বাক্সটাই নেই ([[InvoicePaperView::target()]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#123b5e';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.22pt; color: #15202b; }
    table { border-collapse: collapse; }
    table.page { width: 100%; }
    td.side { width: 37.44mm; vertical-align: top; padding: 0 2.88mm 0 0; border-right: 0.58mm solid {{ $accent }}; }
    td.main { vertical-align: top; padding: 0 0 0 3.6mm; }
    .title { font-size: 17pt; font-weight: bold; color: {{ $accent }}; letter-spacing: 0.43mm; line-height: 1; }
    .subtitle { font-size: 7.65pt; font-weight: bold; color: {{ $accent }}; }
    .no { font-size: 9.35pt; font-weight: bold; font-family: dejavusans; margin-top: 1.44mm; }
    .muted { color: #4a5560; }
    .cap { font-size: 6.5pt; font-weight: bold; letter-spacing: 0.29mm; color: {{ $accent }}; padding-bottom: 0.58mm; }
    .box { border: 0.18mm solid #9db4ca; padding: 1.08mm 1.44mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.29mm 0; font-size: 6.8pt; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.rule td { border-top: 0.18mm solid #9db4ca; padding-top: 0.58mm; }
    table.kv tr.final td { font-weight: bold; font-size: 8.5pt; color: {{ $accent }}; border-top: 0.43mm solid {{ $accent }}; border-bottom: 0.86mm double {{ $accent }}; padding: 0.72mm 0; }
    table.rows { width: 100%; }
    table.rows td { vertical-align: top; line-height: 1.35; }
    table.rows td.sec { padding-top: 2.52mm; }
    .qr { text-align: center; }
    .party { font-weight: bold; }
    table.head { width: 100%; border-bottom: 0.43mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 1.44mm; }
    .co-name { font-size: 11.9pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 6.5pt; color: #4a5560; }
    .dup { font-size: 6.5pt; font-weight: bold; color: #4a5560; }
    .notice { text-align: center; font-weight: bold; border: 0.29mm solid #b42318; color: #b42318; padding: 0.72mm; margin-top: 1.44mm; font-size: 8.5pt; }
    table.items { width: 100%; margin-top: 1.8mm; }
    table.items th { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; padding: 0.58mm 0.86mm; text-align: left; border-bottom: 0.36mm solid {{ $accent }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 0.86mm; border-bottom: 0.14mm solid #dde5ec; font-size: 7.22pt; line-height: 1.2; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.36mm solid {{ $accent }}; border-bottom: 0.36mm solid {{ $accent }}; }
    /* ⓘ মোটের প্রতিটা অঙ্ক নিজের বাক্সে, নিজের কলামের নিচে — মালিক, ৩ অক্টোবর ২০২৬ (S-0001-এর ছবি) */
    table.items tr.grand td.num { border: 0.3mm solid {{ $accent }}; }
    table.items tr.grand td.grand-amount { color: {{ $accent }}; font-size: 8.07pt; border: 0.36mm solid {{ $accent }}; border-bottom: 0.86mm double {{ $accent }}; }
    .sub { font-size: 6.5pt; color: #4a5560; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .words { margin-top: 1.08mm; font-size: 6.8pt; color: #4a5560; }
    .section { margin-top: 2.52mm; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.29mm; color: {{ $accent }}; }
    /* ⭐ হিসাবের চলাচল কথায়-লেখা টাকা থেকে একটু নিচে, সরু দাগে আলাদা — মালিক, ৪ অক্টোবর ২০২৬ ("kicuta namiye daw") */
    /* ⚠️ mPDF টেবিলের ঘরের ভিতরে margin/padding দুটোই ফেলে দেয় — ফাঁকটা নিচে একটা খালি লাইন */
    .section.movement { margin-top: 0; }
    table.ledger { width: 100%; margin-top: 0.72mm; }
    table.ledger th { font-size: 6.5pt; color: #4a5560; font-weight: bold; text-align: left; padding: 0.43mm 0.86mm; border-bottom: 0.29mm solid {{ $accent }}; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.43mm 0.86mm; border-bottom: 0.14mm solid #dde5ec; font-size: 6.8pt; }
    table.ledger tr.close td { font-weight: bold; border-bottom: 0.36mm solid {{ $accent }}; }
    table.pay { width: 100%; margin-top: 0.72mm; }
    table.pay th { font-size: 6.5pt; font-weight: bold; color: #4a5560; text-align: left; padding: 0.43mm; border-bottom: 0.22mm solid #15202b; }
    table.pay td { font-size: 6.8pt; padding: 0.43mm; border-bottom: 0.14mm solid #dde5ec; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 1.44mm; }
    .footnote { margin-top: 1.44mm; font-size: 7.65pt; font-weight: bold; color: #b42318; font-family: hindsiliguri; }
    table.signatures { width: 100%; margin-top: 7.2mm; }
    table.signatures td { text-align: center; padding: 0 2.88mm; font-size: 7.22pt; }
    .sig-line { border-top: 0.18mm solid #15202b; padding-top: 0.72mm; }
    .printed { margin-top: 1.44mm; font-size: 6.5pt; color: #4a5560; }
</style>

<table class="page">
    <tr>
        <td class="side">
            {{-- ⓘ প্রতিটা অংশ নিজের সারিতে, উপরে ফাঁক — মালিকের ছবি, ৩ অক্টোবর ২০২৬: "বামে বিলের ইনফরমেশন সব জমাট বেঁধে গেছে"।
                 ⚠️ mPDF টেবিল-ঘরের ভিতরের div-এর margin মানে না, তাই `.cap`-এর margin-top কাগজে পৌঁছাত না; ঘরের padding মানে। --}}
            <table class="rows">
                <tr><td>
                    <div class="title">INVOICE</div>
                    <div class="subtitle">With Accounts Statement</div>
                </td></tr>
                <tr><td class="sec">
                    <div class="no">{{ $facts['bill']['bill_no'] }}</div>
                    <div>{{ $facts['bill']['bill_date'] }}</div>
                    @if ($v->shows('order_no'))<div class="muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
                    @if ($v->shows('invoice_type'))<div class="muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
                    @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
                </td></tr>
                <tr><td class="sec">
                    @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])
                </td></tr>

                @if ($v->shows('transport'))
                    <tr><td class="sec">
                        <div class="cap">DRIVER</div>
                        <div class="box" data-transport>
                            @php($driver = ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? ''))
                            @if ($driver !== '')<div>{{ $driver }}</div>@endif
                            @if (($transport['driver_phone'] ?? '') !== '')<div>{{ $transport['driver_phone'] }}</div>@endif
                            @if (($transport['vehicle'] ?? '') !== '')<div class="muted">{{ $transport['vehicle'] }}</div>@endif
                            @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                        </div>
                    </td></tr>
                @endif

                @if ($target !== null)
                    <tr><td class="sec">
                        <div class="cap">TARGET REMINDER</div>
                        <table class="kv" data-target>
                            <tr><td>{{ $target['month'] }} target</td><td class="num">{{ $target['target'] }}</td></tr>
                            <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                            <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                            <tr><td class="muted">Closes {{ $target['closes_on'] }}</td><td class="num muted">{{ $target['bank_days'] }} bank days</td></tr>
                        </table>
                    </td></tr>
                @endif

                <tr><td class="sec qr">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '28mm'])</td></tr>

                <tr><td class="sec">
                    <div class="cap">INVOICE SUMMARY</div>
                    <div class="box">
                        <table class="kv" data-invoice-summary>
                            <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                            <tr><td>{{ $v->label('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
                            @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                            <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                            <tr class="strong rule"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                            <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                            <tr class="strong"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
                            @if ($v->shows('previous_due'))
                                {{-- ⓘ বিলের আগের জের — বেশি দেওয়া টাকা কাটার আগের অঙ্ক ([[InvoicePaperView::previousBeforeBill()]]) --}}
                                <tr class="rule" data-previous-due><td>{{ $v->label('previous_due') }}</td><td class="num">{{ $v->previousBeforeBill() }}</td></tr>
                                <tr class="final"><td data-balance-word>{{ $v->balanceWord() }}</td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                            @endif
                        </table>
                    </div>
                </td></tr>
            </table>
        </td>

        <td class="main">
            <table class="head">
                <tr>
                    <td>
                        <div class="co-name">{{ $v->head['name'] }}</div>
                        @include('sales::print.partials.invoice-company', ['v' => $v])
                    </td>
                    <td style="width: 18.72mm; text-align: right">@if ($v->logo)<img src="{{ $v->logo }}" style="max-height: 14.4mm; max-width: 18.72mm;" alt="">@endif</td>
                </tr>
            </table>

            @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

            @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

            @if ($v->shows('previous_due'))
                <div style="font-size: 4pt; line-height: 13pt">&nbsp;</div>
                <div class="section movement">{{ mb_strtoupper($t('movement')) }}</div>
                <table class="ledger" data-movement>
                    <tr>
                        <th style="width: 12.96mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num" style="width: 17.28mm">{{ $t('debit') }}</th>
                        <th class="num" style="width: 17.28mm">{{ $t('credit') }}</th>
                        <th class="num" style="width: 23.04mm">{{ $t('balance') }}</th>
                    </tr>
                    @foreach ($v->movement($doc->payments) as $row)
                        <tr @class(['close' => $loop->last])>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['text'] }}</td>
                            <td class="num">{{ $row['debit'] }}</td>
                            <td class="num">{{ $row['credit'] }}</td>
                            <td class="num">{{ $row['balance'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

            <div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
            @include('sales::print.partials.invoice-signatures', ['v' => $v])
            <div class="printed">{{ $v->printedAt() }} · Special for DB · A5</div>
        </td>
    </tr>
</table>
