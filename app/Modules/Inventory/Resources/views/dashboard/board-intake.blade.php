{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 7 — মাল ঢোকার ধাপ · তিন-দিক মিলান (ফানেল), গুদাম-বদলি · পথে, নষ্ট/ফেরত/আটকে রাখা মাল · এ মাস
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV)। ⓘ ফানেলের চওড়া নমুনার ধাপে (100/86/72/60%); সংখ্যা আসল।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Inventory\Dashboard\InventoryBoard::short($v);
    $steps = $receiving['steps'];
    $funnel = [
        ['t' => __('inventory::board.i.orders'), 'n' => $steps['orders'], 'w' => '100%', 'c' => 'linear-gradient(90deg, var(--color-dash-a5b4fc), var(--color-dash-6366f1))'],
        ['t' => __('inventory::board.i.received'), 'n' => $steps['received'], 'w' => '86%', 'c' => 'linear-gradient(90deg, var(--color-dash-7dd3fc), var(--color-dash-0ea5e9))'],
        ['t' => __('inventory::board.i.passed'), 'n' => $steps['passed'], 'w' => '72%', 'c' => 'linear-gradient(90deg, var(--color-dash-6ee7b7), var(--color-dash-10b981))'],
        ['t' => __('inventory::board.i.matched'), 'n' => $steps['matched'], 'w' => '60%', 'c' => 'linear-gradient(90deg, var(--color-dash-fcd34d), var(--color-dash-f59e0b))'],
    ];
    $oldest = max(1, ...array_map(fn ($t) => $t['days'], $transit ?: [['days' => 1]]));
    $rows = array_values(array_filter([
        ['t' => __('inventory::board.i.broken'), 'v' => $damage['broken'], 'c' => 'linear-gradient(90deg, var(--color-dash-f43f5e), var(--color-dash-fb7185))'],
        ['t' => __('inventory::board.i.expired'), 'v' => $damage['expired'], 'c' => 'linear-gradient(90deg, var(--color-dash-f97316), var(--color-dash-fdba74))'],
        $damage['returned_in'] === null ? null : ['t' => __('inventory::board.i.returned_in'), 'v' => $damage['returned_in'], 'c' => 'linear-gradient(90deg, var(--color-dash-10b981), var(--color-dash-6ee7b7))'],
        ['t' => __('inventory::board.i.held'), 'v' => $damage['held'], 'c' => 'linear-gradient(90deg, var(--color-dash-8b5cf6), var(--color-dash-c4b5fd))'],
        $damage['returned_out'] === null ? null : ['t' => __('inventory::board.i.returned_out'), 'v' => $damage['returned_out'], 'c' => 'linear-gradient(90deg, var(--color-dash-0ea5e9), var(--color-dash-7dd3fc))'],
    ]));
    $top = array_reduce($rows, fn (float $c, array $r) => max($c, (float) $r['v']), 0.0);
    $lost = bcadd($damage['broken'], $damage['expired'], 4);
    $times = bccomp($damage['last_month'], '0', 4) > 0 ? (float) bcdiv($lost, $damage['last_month'], 4) : null;
@endphp
<div data-inv-board-intake style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-grn style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-6366f1)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.i.grn') }}</h2>
        @foreach ($funnel as $u)
            <div style="display: flex; justify-content: center"><div style="width: {{ $u['w'] }}; background: {{ $u['c'] }}; color: var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5625rem 0.75rem; display: flex; justify-content: space-between; font-size: 0.8125rem; font-weight: 600"><span>{{ $u['t'] }}</span><b>{{ $u['n'] }}</b></div></div>
        @endforeach
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563); text-align: center">{{ __('inventory::board.i.grn_note') }}@if ($receiving['unbilled']['receipts'] > 0) · {{ __('inventory::board.i.unbilled', ['n' => $receiving['unbilled']['receipts'], 'value' => $short($receiving['unbilled']['value'])]) }}@endif</span>
    </section>

    <section data-inv-transit style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-38bdf8)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.i.transit') }}</h2>
        @forelse ($transit as $t)
            <div style="display: flex; flex-direction: column; gap: 0.25rem; padding: 0.4375rem 0; border-top: 1px solid var(--color-dash-f1f2f7); font-size: 0.8125rem">
                <span style="display: flex; justify-content: space-between"><b>{{ $t['from'] }} → {{ $t['to'] }}</b><span style="font-size: 0.6875rem; font-weight: 700; color: var(--color-dash-ffffff); background: {{ $t['days'] >= 2 ? 'var(--color-dash-ef4444)' : 'var(--color-dash-10b981)' }}; border-radius: 999px; padding: 1px 0.5rem">{{ $t['days'] === 0 ? __('inventory::board.i.today') : __('inventory::board.days', ['n' => $t['days']]) }}</span></span>
                <span style="display: flex; height: 0.5rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="width: {{ max(8, (int) round($t['days'] / $oldest * 80)) }}%; background: linear-gradient(90deg, var(--color-dash-38bdf8), var(--color-dash-6366f1)); border-radius: 999px"></span></span>
                <span style="color: var(--color-dash-4b5563); font-size: 0.75rem">{{ __('inventory::board.i.units', ['qty' => rtrim(rtrim(bcadd($t['qty'], '0', 2), '0'), '.')]) }} · {{ $short($t['value']) }}</span>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.i.transit_none') }}</span>
        @endforelse
    </section>

    <section data-inv-damage style="{{ $card }} gap: 0.5625rem; border-top: 0.3125rem solid var(--color-dash-a855f7)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.i.damage') }}</h2>
        @foreach ($rows as $r)
            <div style="display: grid; grid-template-columns: 8.75rem minmax(0, 1fr) 5rem; gap: 0.625rem; align-items: center; font-size: 0.8125rem">
                <span>{{ $r['t'] }}</span>
                <span style="height: 0.875rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.875rem; width: {{ $top > 0 ? round((float) $r['v'] / $top * 100) : 0 }}%; background: {{ $r['c'] }}; border-radius: 999px"></span></span>
                <b style="text-align: right">{{ $short($r['v']) }}</b>
            </div>
        @endforeach
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ $times !== null && $times >= 1.5 ? __('inventory::board.i.twice', ['value' => $short($lost), 'times' => rtrim(rtrim(number_format($times, 1, '.', ''), '0'), '.')]) : __('inventory::board.i.vs_last', ['value' => $short($lost), 'last' => $short($damage['last_month'])]) }}@if ($damage['reason'] !== '') · {{ __('inventory::board.i.cause', ['reason' => $damage['reason']]) }}@endif</span>
    </section>
</div>
