{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 1 — সমস্যা কেন্দ্র (10 ঘর) আর ডান কোণায় মজুদের মূল্য ও মাপ
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV, 10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
    ⓘ অঙ্ক [[InventoryBoard]] থেকে; রং নমুনার হুবহু, নামের টোকেনে (--color-dash-*); সংখ্যা ইংরেজি অঙ্কে।
    ⓘ ঘরের রং অবস্থা থেকে: জরুরি লাল, আজই কমলা, এ সপ্তাহে সোনালি, ঠিক আছে সবুজ।
--}}
@php
    $paint = [
        'urgent' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fb7185) 0%, var(--color-dash-e11d48) 45%, var(--color-dash-9f1239) 100%)', 'glow' => 'var(--color-dash-225-29-72-a35)', 'ink' => 'var(--color-dash-dc2626)', 'sev' => __('inventory::board.sev.urgent')],
        'today' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fdba74) 0%, var(--color-dash-f97316) 45%, var(--color-dash-c2410c) 100%)', 'glow' => 'var(--color-dash-249-115-22-a33)', 'ink' => 'var(--color-dash-ea580c)', 'sev' => __('inventory::board.sev.today')],
        'week' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fcd34d) 0%, var(--color-dash-f59e0b) 45%, var(--color-dash-b45309) 100%)', 'glow' => 'var(--color-dash-245-158-11-a32)', 'ink' => 'var(--color-dash-ca8a04)', 'sev' => __('inventory::board.sev.week')],
        'ok' => ['bg' => 'linear-gradient(145deg, var(--color-dash-6ee7b7) 0%, var(--color-dash-10b981) 45%, var(--color-dash-047857) 100%)', 'glow' => 'var(--color-dash-16-185-129-a32)', 'ink' => 'var(--color-dash-16a34a)', 'sev' => __('inventory::board.sev.ok')],
    ];
@endphp
<div data-inv-board-top style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-problems style="grid-column: span 3; background: var(--color-dash-ffffff); border-radius: 1.375rem; padding: 1.125rem 1.25rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-239-68-68-a12); display: flex; flex-direction: column; gap: 0.75rem; border-top: 0.375rem solid var(--color-dash-ef4444); color: var(--color-dash-111827)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.1875rem; font-weight: 800">{{ __('inventory::board.problems') }}</h2>
            <span style="font-size: 0.8125rem; color: var(--color-dash-4b5563)">
                <b style="color: var(--color-dash-dc2626)">●</b> {{ __('inventory::board.sev.urgent') }} &nbsp;
                <b style="color: var(--color-dash-ea580c)">●</b> {{ __('inventory::board.sev.today_long') }} &nbsp;
                <b style="color: var(--color-dash-ca8a04)">●</b> {{ __('inventory::board.sev.week') }}
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.75rem">
            @foreach ($problems as $x)
                @php $p = $paint[$x['severity']] ?? $paint['week']; @endphp
                <a href="{{ $x['href'] }}" data-inv-problem="{{ $x['key'] }}"
                   style="display: flex; flex-direction: column; gap: 0.25rem; border-radius: 1rem; padding: 0.75rem 0.875rem; background: {{ $p['bg'] }}; color: var(--color-dash-ffffff); box-shadow: 0 0.625rem 1.375rem {{ $p['glow'] }}; text-decoration: none">
                    <span style="display: flex; justify-content: space-between; align-items: center; gap: 0.375rem">
                        <span style="font-size: 0.75rem; font-weight: 700">{{ $x['label'] }}</span>
                        <span style="flex-shrink: 0; font-size: 0.625rem; font-weight: 800; background: var(--color-dash-255-255-255-a24); border-radius: 999px; padding: 0.0625rem 0.5rem">{{ $p['sev'] }}</span>
                    </span>
                    <span style="font-size: 1.875rem; font-weight: 800; line-height: 1.1">{{ $x['count'] }}</span>
                    <span style="font-size: 0.75rem; font-weight: 700">{{ $x['value'] }}</span>
                    <span style="font-size: 0.6875rem; opacity: 0.9; line-height: 1.4">{{ $x['text'] }}</span>
                    <span style="font-size: 0.6875rem; font-weight: 800; background: var(--color-dash-ffffff); color: {{ $p['ink'] }}; border-radius: 0.5rem; padding: 0.1875rem 0.5625rem; width: fit-content; margin-top: 0.125rem">{{ $x['action'] }} →</span>
                </a>
            @endforeach
        </div>
    </section>

    <section data-inv-value-corner style="border-radius: 1.375rem; padding: 1.125rem 1.25rem; background: linear-gradient(160deg, var(--color-dash-082f49), var(--color-dash-0c4a6e) 50%, var(--color-dash-3730a3)); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.375rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-14-116-144-a30)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--color-dash-ffffff)">{{ __('inventory::board.value_title') }}</h2>
        <span style="font-size: 2.125rem; font-weight: 800" data-inv-value>{{ $corner['cost'] }}</span>
        <span style="font-size: 0.75rem; opacity: 0.85">{{ $corner['sub'] }}</span>
        @foreach ($corner['rows'] as $r)
            <span style="display: flex; justify-content: space-between; align-items: baseline; font-size: 0.8125rem; padding: 0.375rem 0; border-top: 1px solid var(--color-dash-255-255-255-a14)">
                <span style="opacity: 0.9">{{ $r['label'] }}</span><b style="font-size: 0.9375rem; color: {{ $r['tone'] }}">{{ $r['value'] }}</b>
            </span>
        @endforeach
    </section>
</div>
