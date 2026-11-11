{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 1 — সমস্যা কেন্দ্র (10 ঘর, খাতার প্রচলিত নিয়ন্ত্রণ) আর ডানে মাস বন্ধ
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS, 10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
    ⓘ অঙ্ক [[AccountsBoard]] থেকে; রং মজুদের সমস্যা কেন্দ্রের একই নিয়মে — জরুরি লাল, আজই কমলা, এ সপ্তাহে সোনালি, ঠিক আছে সবুজ।
--}}
@php
    $paint = [
        'urgent' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fb7185) 0%, var(--color-dash-e11d48) 45%, var(--color-dash-9f1239) 100%)', 'glow' => 'var(--color-dash-225-29-72-a35)', 'ink' => 'var(--color-dash-dc2626)'],
        'today' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fdba74) 0%, var(--color-dash-f97316) 45%, var(--color-dash-c2410c) 100%)', 'glow' => 'var(--color-dash-249-115-22-a33)', 'ink' => 'var(--color-dash-ea580c)'],
        'week' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fcd34d) 0%, var(--color-dash-f59e0b) 45%, var(--color-dash-b45309) 100%)', 'glow' => 'var(--color-dash-245-158-11-a32)', 'ink' => 'var(--color-dash-ca8a04)'],
        'ok' => ['bg' => 'linear-gradient(145deg, var(--color-dash-6ee7b7) 0%, var(--color-dash-10b981) 45%, var(--color-dash-047857) 100%)', 'glow' => 'var(--color-dash-16-185-129-a32)', 'ink' => 'var(--color-dash-16a34a)'],
    ];
    $word = fn (string $k) => \Illuminate\Support\Facades\Lang::has('accounts::board.p.'.$k) ? __('accounts::board.p.'.$k) : '';
    $c = $close;
    $total = count($c['steps']);
    $pct = $total === 0 ? 0 : (int) round($c['done'] / $total * 100);
    $C = 2 * M_PI * 48;
    $monthName = __('accounts::board.months')[$c['month']];
@endphp
<div data-acc-board-top style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-problems style="grid-column: span 3; background: var(--color-dash-ffffff); border-radius: 1.375rem; padding: 1.125rem 1.25rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-239-68-68-a12); display: flex; flex-direction: column; gap: 0.75rem; border-top: 0.375rem solid var(--color-dash-ef4444); color: var(--color-dash-111827)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.1875rem; font-weight: 800">{{ __('accounts::board.p.title') }}</h2>
            <span style="font-size: 0.8125rem; color: var(--color-dash-4b5563)">
                <b style="color: var(--color-dash-dc2626)">●</b> {{ __('accounts::board.sev.urgent') }} &nbsp;
                <b style="color: var(--color-dash-ea580c)">●</b> {{ __('accounts::board.sev.today_long') }} &nbsp;
                <b style="color: var(--color-dash-ca8a04)">●</b> {{ __('accounts::board.sev.week') }} &nbsp;
                <b style="color: var(--color-dash-16a34a)">●</b> {{ __('accounts::board.sev.ok') }}
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.75rem">
            @foreach ($problems as $x)
                @php $p = $paint[$x['sev']] ?? $paint['week']; @endphp
                <a href="{{ $x['href'] }}" data-acc-problem="{{ $x['key'] }}" data-sev="{{ $x['sev'] }}"
                   style="display: flex; flex-direction: column; gap: 0.25rem; border-radius: 1rem; padding: 0.75rem 0.875rem; background: {{ $p['bg'] }}; color: var(--color-dash-ffffff); box-shadow: 0 0.625rem 1.375rem {{ $p['glow'] }}; text-decoration: none; min-width: 0">
                    <span style="display: flex; justify-content: space-between; align-items: center; gap: 0.375rem">
                        <span style="font-size: 0.75rem; font-weight: 700">{{ $word($x['key']) }}</span>
                        <span style="flex-shrink: 0; font-size: 0.625rem; font-weight: 800; background: var(--color-dash-255-255-255-a24); border-radius: 999px; padding: 0.0625rem 0.5rem">{{ __('accounts::board.sev.'.$x['sev']) }}</span>
                    </span>
                    <span style="font-size: 1.875rem; font-weight: 800; line-height: 1.1">{{ $x['n'] }}</span>
                    <span style="font-size: 0.75rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $x['detail'] }}</span>
                    <span style="font-size: 0.6875rem; opacity: 0.9; line-height: 1.4">{{ $x['note'] !== '' ? $x['note'] : $word($x['key'].'_note') }}</span>
                    <span style="font-size: 0.6875rem; font-weight: 800; background: var(--color-dash-ffffff); color: {{ $p['ink'] }}; border-radius: 0.5rem; padding: 0.1875rem 0.5625rem; width: fit-content; margin-top: 0.125rem">{{ $word($x['key'].'_go') }} →</span>
                </a>
            @endforeach
        </div>
    </section>

    <section data-acc-close style="border-radius: 1.375rem; padding: 1.125rem 1.25rem; background: linear-gradient(160deg, var(--color-dash-1e1b4b), var(--color-dash-3730a3) 55%, var(--color-dash-6d28d9)); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.5rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-67-56-202-a32)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--color-dash-ffffff)">{{ __('accounts::board.close.title', ['month' => $monthName]) }}</h2>
        <div style="display: flex; align-items: center; gap: 0.875rem">
            <svg role="img" aria-label="{{ __('accounts::board.close.label', ['month' => $monthName, 'pct' => $pct]) }}" viewBox="0 0 120 120" style="width: 7rem; height: 7rem; flex-shrink: 0">
                <circle cx="60" cy="60" r="48" fill="none" stroke="var(--color-dash-255-255-255-a15)" stroke-width="14"></circle>
                @if ($pct > 0)<circle cx="60" cy="60" r="48" fill="none" stroke="var(--color-dash-a5f3fc)" stroke-width="14" stroke-linecap="round" stroke-dasharray="{{ round($C * $pct / 100) }} 400" transform="rotate(-90 60 60)"></circle>@endif
                <text x="60" y="66" text-anchor="middle" font-size="24" font-weight="800" fill="var(--color-dash-ffffff)">{{ $pct }}%</text>
            </svg>
            <span style="display: flex; flex-direction: column; gap: 2px; font-size: 0.8125rem">
                <b style="font-size: 1.125rem">{{ __('accounts::board.close.steps_done', ['total' => $total, 'done' => $c['done']]) }}</b>
                <span style="opacity: 0.85">{{ __('accounts::board.close.target', ['n' => \App\Modules\Accounts\Dashboard\AccountsBoard::CLOSE_TARGET_DAYS, 'day' => $c['day']]) }}</span>
                @if ($c['locked'])
                    <span style="color: var(--color-dash-86efac); font-weight: 700">{{ __('accounts::board.close.locked') }}</span>
                @elseif ($c['behind'] > 0)
                    <span style="color: var(--color-dash-fca5a5); font-weight: 700">{{ __('accounts::board.close.behind', ['n' => $c['behind']]) }}</span>
                @else
                    <span style="color: var(--color-dash-86efac); font-weight: 700">{{ __('accounts::board.close.on_time') }}</span>
                @endif
            </span>
        </div>
        @foreach (array_filter($c['steps'], fn ($s) => ! $s['done']) as $s)
            {{-- ⓘ ধাপ মাস-শেষের চেকলিস্টের, নাম ছোট করে (নমুনার মতো); চাপলে সারানোর পাতা --}}
            <a href="{{ $s['href'] }}" data-close-left="{{ $s['key'] }}" style="display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.8125rem; padding: 0.375rem 0; border-top: 1px solid var(--color-dash-255-255-255-a16); color: var(--color-dash-ffffff); text-decoration: none"><span>{{ __('accounts::board.close.step.'.$s['key']) }}</span><b style="flex: none; color: var(--color-dash-fcd34d)">{{ __('accounts::control.state_pending', ['count' => $s['count']]) }}</b></a>
        @endforeach
        <span style="display: flex; justify-content: space-between; font-size: 0.8125rem; padding: 0.375rem 0; border-top: 1px solid var(--color-dash-255-255-255-a16)"><span>{{ __('accounts::board.close.previous') }}</span><b>{{ $c['previous'] === null ? __('accounts::board.close.previous_none') : __('accounts::board.close.previous_day', ['n' => $c['previous']]) }}</b></span>
    </section>
</div>
