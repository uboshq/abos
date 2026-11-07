{{--
    ⭐ প্রতিদিনের সারাংশ — A4 আড়াআড়ি কাগজ (মালিক, ৭ নভেম্বর ২০২৬: "a4 sizer pdf e … lagle landscpe e dite paro"; নমুনা "ok")।
    ⓘ mPDF — গ্রিড নয়, ছক; পাতা ১-এ মাথা, বিক্রি, গাড়ি-ভাড়া, কেনা, নজরে রাখুন; পাতা ২-এ কাউন্টার ধরে টাকা আর অর্ডার দিতে হবে।
    চাই: $s ([[DailySummary::for()]])। রাতের ইমেইলের সংযুক্তিও এই কাগজ।
--}}
@php
    $m = fn ($v) => \App\Core\Support\Money::format((string) $v);
    $t = fn (string $k, array $r = []) => __('executive::daily.'.$k, $r);
    $k = $s['kpis'];
    $branchName = $s['branch_id'] ? \App\Models\Branch::query()->find($s['branch_id'])?->name() : $t('all_branches');
    $head = 'color: #ffffff; font-weight: bold; padding: 1.6mm 2.5mm; font-size: 9.5pt;';
    $cell = 'padding: 1.1mm 2mm; border-bottom: 0.2mm solid #dbe2e9;';
    $num = 'text-align: right; white-space: nowrap;';
    $pill = ['paid' => '#0f8a5f', 'due' => '#c81e1e', 'missing' => '#c81e1e', 'none' => '#5d6c7b'];
@endphp
<style>
    body { font-size: 8.6pt; color: #142130; }
    table { border-collapse: collapse; width: 100%; }
    th { font-size: 7.6pt; color: #5d6c7b; text-align: left; background: #eef3f8; padding: 1mm 2mm; }
    .box { border: 0.25mm solid #dbe2e9; }
</style>

{{-- মাথা --}}
<table style="background: #0e5fa8; margin-bottom: 2.5mm;">
    <tr>
        <td style="padding: 2.5mm 3mm; color: #fff; font-size: 15pt; font-weight: bold;">{{ $t('title') }}</td>
        <td style="padding: 2.5mm 3mm; color: #fff; text-align: right; font-size: 10pt;">
            {{ \App\Core\Support\DateFormat::format($s['date']) }} · {{ $s['company']?->name() }} · {{ $branchName }}
        </td>
    </tr>
</table>
<table style="margin-bottom: 3mm;">
    <tr>
        @foreach ([
            ['delivered', '৳ '.$m($k['delivered']['value']), $t('vs_yesterday', ['amount' => $m($k['delivered']['before'])])],
            ['collected', '৳ '.$m($k['collected']['value']), $t('vs_yesterday', ['amount' => $m($k['collected']['before'])])],
            ['fare', '৳ '.$m($k['fare']['value']), $t('fare_due_short', ['amount' => $m($k['fare']['due'])])],
            ['received', '৳ '.$m($k['received']['value']), $t('suppliers', ['count' => $k['received']['suppliers']])],
            ['cash_left', '৳ '.$m($k['cash_left']['value']), $t('tills', ['count' => $k['cash_left']['tills']])],
            ['reorder', $t('products', ['count' => $k['reorder']['value']]), $t('below_level')],
        ] as [$key, $value, $sub])
            {{-- ⓘ ঘরের নিজের জমিন — mPDF ঘরের ভেতরের div-এর জমিন কেবল লেখার মাপে আঁকে --}}
            <td style="width: 16.6%; padding: 1.6mm 2mm; background: #f2f6fb; border: 0.25mm solid #dbe2e9;">
                <span style="font-size: 7.6pt; color: #5d6c7b;">{{ $t('k_'.$key) }}</span><br>
                <span style="font-size: 12pt; font-weight: bold;">{{ $value }}</span><br>
                <span style="font-size: 7pt; color: #5d6c7b;">{{ $sub }}</span>
            </td>
        @endforeach
    </tr>
</table>

{{-- পাতা ১: বিক্রি | গাড়ি-ভাড়া --}}
<table style="margin-bottom: 3mm;">
    <tr>
        <td style="width: 32%; vertical-align: top; padding-right: 2mm;">
            <table class="box">
                <tr><td colspan="2" style="background: #0f8a5f; {{ $head }}">{{ $t('s_sales') }} · {{ $t('challans', ['count' => $s['sales']['challans']]) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('delivered') }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['sales']['delivered']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('bills') }} · {{ $s['sales']['bills'] }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['sales']['billed']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('returned') }}</td><td style="{{ $cell }} {{ $num }} color: #c81e1e;">{{ bccomp((string) $s['sales']['returned'], '0', 4) > 0 ? '-' : '' }}{{ $m($s['sales']['returned']) }}</td></tr>
                <tr><td style="{{ $cell }} font-weight: bold;">{{ $t('net_sales') }}</td><td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($s['sales']['net']) }}</td></tr>
            </table>
        </td>
        <td style="width: 68%; vertical-align: top;">
            <table class="box">
                <tr><td colspan="5" style="background: #c2410c; {{ $head }}">{{ $t('s_fare') }} · {{ $t('vehicles', ['count' => $s['fare']['rows']->count()]) }}</td></tr>
                @if ($s['fare']['rows']->isEmpty())
                    <tr><td colspan="5" style="{{ $cell }} color: #5d6c7b;">{{ $t('no_vehicles') }}</td></tr>
                @else
                    <tr><th>{{ $t('driver') }}</th><th>{{ $t('vehicle') }}</th><th>{{ $t('paper') }}</th><th style="{{ $num }}">{{ $t('fare') }}</th><th>{{ $t('state') }}</th></tr>
                    @foreach ($s['fare']['rows'] as $r)
                        <tr>
                            <td style="{{ $cell }}">{{ $r['driver'] ?: '—' }}@if ($r['driver_phone']) · {{ $r['driver_phone'] }}@endif</td>
                            <td style="{{ $cell }}">{{ $r['vehicle'] ?: '—' }}</td>
                            <td style="{{ $cell }}">{{ $r['no'] }}</td>
                            <td style="{{ $cell }} {{ $num }}">{{ $m($r['amount']) }}</td>
                            <td style="{{ $cell }} color: {{ $pill[$r['state']] ?? '#5d6c7b' }}; font-weight: bold;">{{ $t('st_'.$r['state']) }}</td>
                        </tr>
                    @endforeach
                    <tr><td colspan="3" style="{{ $cell }} font-weight: bold; background: #eef3f8;">{{ $t('fare_total', ['paid' => $m($s['fare']['paid']), 'due' => $m($s['fare']['due'])]) }}</td>
                        <td style="{{ $cell }} {{ $num }} font-weight: bold; background: #eef3f8;">{{ $m($s['fare']['total']) }}</td><td style="{{ $cell }} background: #eef3f8;"></td></tr>
                @endif
                <tr><td colspan="3" style="{{ $cell }}">{{ $t('fare_outstanding') }}</td><td style="{{ $cell }} {{ $num }} color: #c81e1e; font-weight: bold;">{{ $m($s['fare']['outstanding']) }}</td><td style="{{ $cell }}"></td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- পাতা ১: কেনা | নজরে রাখুন --}}
<table>
    <tr>
        <td style="width: 32%; vertical-align: top; padding-right: 2mm;">
            <table class="box">
                <tr><td colspan="2" style="background: #7c3aed; {{ $head }}">{{ $t('s_purchase') }}</td></tr>
                @forelse ($s['purchase']['rows'] as $r)
                    <tr><td style="{{ $cell }}">{{ $r['supplier'] }} <span style="color: #5d6c7b;">{{ $r['documents'] }}</span></td><td style="{{ $cell }} {{ $num }}">{{ $m($r['amount']) }}</td></tr>
                @empty
                    <tr><td colspan="2" style="{{ $cell }} color: #5d6c7b;">{{ $t('no_goods') }}</td></tr>
                @endforelse
                <tr><td style="{{ $cell }} font-weight: bold;">{{ $t('received_total') }}</td><td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($s['purchase']['received']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('supplier_paid') }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['purchase']['paid']) }}</td></tr>
            </table>
        </td>
        <td style="width: 68%; vertical-align: top;">
            <table class="box">
                <tr><td colspan="2" style="background: #0a467c; {{ $head }}">{{ $t('s_watch') }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('pending', ['count' => $s['watch']['pending_count']]) }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['watch']['pending_amount']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('crossed', ['count' => $s['watch']['crossed_customers']]) }}</td><td style="{{ $cell }} {{ $num }} color: #c81e1e;">{{ $m($s['watch']['crossed_amount']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('profit') }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['watch']['profit']) }}</td></tr>
                <tr><td style="{{ $cell }}">{{ $t('expenses') }}</td><td style="{{ $cell }} {{ $num }}">{{ $m($s['watch']['expenses']) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- পাতা ২: কাউন্টার ধরে টাকা --}}
<pagebreak />
<table class="box" style="margin-bottom: 3mm;">
    <tr><td colspan="6" style="background: #2563eb; {{ $head }}">{{ $t('s_money') }}</td></tr>
    <tr><th>{{ $t('account') }}</th><th>{{ $t('holder') }}</th><th style="{{ $num }}">{{ $t('opening') }}</th><th style="{{ $num }}">{{ $t('in') }}</th><th style="{{ $num }}">{{ $t('out') }}</th><th style="{{ $num }}">{{ $t('closing') }}</th></tr>
    @foreach ($s['money']['rows'] as $r)
        <tr>
            <td style="{{ $cell }}">{{ $r['name'] }} <span style="color: #5d6c7b;">{{ $t('kind_'.$r['kind']) }}</span></td>
            <td style="{{ $cell }}">{{ $r['holder'] ?: '—' }}</td>
            <td style="{{ $cell }} {{ $num }}">{{ $m($r['opening']) }}</td>
            <td style="{{ $cell }} {{ $num }}">{{ $m($r['in']) }}</td>
            <td style="{{ $cell }} {{ $num }}">{{ $m($r['out']) }}</td>
            <td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($r['closing']) }}</td>
        </tr>
    @endforeach
    <tr style="background: #eef3f8;"><td colspan="3" style="{{ $cell }} font-weight: bold;">{{ $t('collected_line', ['total' => $m($s['money']['collected']), 'cash' => $m($s['money']['in_by_kind']['cash']), 'bank' => $m($s['money']['in_by_kind']['bank']), 'mfs' => $m($s['money']['in_by_kind']['mfs'])]) }}</td>
        <td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($s['money']['in_total']) }}</td><td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($s['money']['out_total']) }}</td><td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ $m($s['money']['closing_total']) }}</td></tr>
</table>

{{-- পাতা ২: অর্ডার দিতে হবে --}}
<table class="box">
    <tr><td colspan="4" style="background: #b91c1c; {{ $head }}">{{ $t('s_reorder') }} · {{ $t('products', ['count' => $s['reorder']->count()]) }}</td></tr>
    @if ($s['reorder']->isEmpty())
        <tr><td colspan="4" style="{{ $cell }} color: #5d6c7b;">{{ $t('no_reorder') }}</td></tr>
    @else
        <tr><th>{{ $t('product') }}</th><th style="{{ $num }}">{{ $t('have') }}</th><th style="{{ $num }}">{{ $t('level') }}</th><th style="{{ $num }}">{{ $t('order') }}</th></tr>
        @foreach ($s['reorder'] as $r)
            <tr>
                <td style="{{ $cell }}">{{ $r['name'] }}</td>
                <td style="{{ $cell }} {{ $num }}">{{ \App\Core\Support\Money::format($r['available']) }} {{ $r['unit'] }}</td>
                <td style="{{ $cell }} {{ $num }}">{{ \App\Core\Support\Money::format($r['level']) }} {{ $r['unit'] }}</td>
                <td style="{{ $cell }} {{ $num }} font-weight: bold;">{{ \App\Core\Support\Money::format($r['order']) }} {{ $r['unit'] }}</td>
            </tr>
        @endforeach
        <tr><td colspan="4" style="{{ $cell }} font-size: 7.4pt; color: #5d6c7b;">{{ $t('order_hint') }}</td></tr>
    @endif
</table>

<p style="margin-top: 2mm; font-size: 7.4pt; color: #5d6c7b; text-align: right;">
    {{ $t('printed', ['at' => now()->format('d-m-Y h:i A'), 'by' => auth()->user()?->name ?? 'ABOS']) }}
</p>
