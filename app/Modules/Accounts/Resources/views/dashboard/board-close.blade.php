{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 4 — মাস বন্ধের ধাপ (সময়রেখা, দুই ভাগ চওড়া), ব্যাংক মিলকরণ, কর ও সরকারি দায়
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS)।
    ⓘ সময়রেখা মাস-শেষের চেকলিস্টের ধাপ ([[MonthEndChecklist]]); খাতা কোন ধাপ কবে শেষ হয়েছিল বা কে করেছিলেন রাখে না — তাই বার
    শুরু থেকে আজকের কার্যদিবস পর্যন্ত, রং অবস্থা ধরে (শেষ সবুজ, বাকি থাকলে আজ পর্যন্ত সোনালি — চলছে, তালা ধূসর — বাকি); লাল দাগ লক্ষ্যের দিন।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);
    $monthShort = __('accounts::board.months_short');
    $date = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->day.' '.$monthShort[\Illuminate\Support\Carbon::parse($d)->month];
    $c = $close;
    $span = max(10, $c['day']);
    $target = \App\Modules\Accounts\Dashboard\AccountsBoard::CLOSE_TARGET_DAYS;
    $taxPaint = ['vat' => ['var(--color-dash-a855f7)', 'var(--color-dash-faf5ff)'], 'ait' => ['var(--color-dash-ec4899)', 'var(--color-dash-fdf2f8)'], 'vds' => ['var(--color-dash-f97316)', 'var(--color-dash-fff7ed)']];
@endphp
<div data-acc-board-close style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-timeline style="grid-column: span 2; {{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-6366f1)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('accounts::board.r4.timeline', ['month' => __('accounts::board.months')[$c['month']]]) }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-10b981)">■</b> {{ __('accounts::board.r4.done') }} &nbsp; <b style="color: var(--color-dash-f59e0b)">■</b> {{ __('accounts::board.r4.doing') }} &nbsp; <b style="color: var(--color-dash-cbd5e1)">■</b> {{ __('accounts::board.r4.todo') }} &nbsp; <b style="color: var(--color-dash-ef4444)">|</b> {{ __('accounts::board.r4.target', ['n' => $target]) }}</span>
        </div>
        <div style="display: grid; grid-template-columns: 11rem minmax(0, 1fr); gap: 0.375rem 0.75rem; font-size: 0.75rem; align-items: center">
            <span></span>
            <div style="display: grid; grid-template-columns: repeat({{ $span }}, minmax(0, 1fr)); font-size: 0.625rem; color: var(--color-dash-6b7280); text-align: center">@for ($d = 1; $d <= $span; $d++)<span>{{ $d }}</span>@endfor</div>
            @foreach ($c['steps'] as $s)
                @php
                    $state = $s['done'] ? 'done' : ($s['key'] === 'locked' ? 'todo' : 'doing');
                    $paint = ['done' => 'var(--color-dash-10b981)', 'doing' => 'var(--color-dash-f59e0b)', 'todo' => 'var(--color-dash-cbd5e1)'][$state];
                    $w = $state === 'todo' ? 1 : min($span, max(1, $c['day']));
                @endphp
                <a href="{{ $s['href'] }}" style="font-weight: 600; color: var(--color-dash-111827); text-decoration: none">{{ __('accounts::board.close.step.'.$s['key']) }}</a>
                <div data-close-step="{{ $s['key'] }}" data-state="{{ $state }}" style="position: relative; height: 1.375rem; background: repeating-linear-gradient(90deg, var(--color-dash-f8fafc) 0 {{ 100 / $span }}%, var(--color-dash-f1f5f9) {{ 100 / $span }}% {{ 200 / $span }}%); border-radius: 0.375rem">
                    <span style="position: absolute; top: 3px; height: 1rem; left: {{ $state === 'todo' ? round(($span - 1) / $span * 100, 2) : 0 }}%; width: {{ round($w / $span * 100, 2) }}%; background: {{ $paint }}; border-radius: 0.3125rem; color: var(--color-dash-ffffff); font-size: 0.625rem; font-weight: 700; display: flex; align-items: center; padding-left: 0.375rem; white-space: nowrap; overflow: hidden">{{ $state === 'done' ? '✓' : ($state === 'doing' ? __('accounts::board.r4.pending_n', ['n' => $s['count']]) : __('accounts::board.r4.todo')) }}</span>
                    <span style="position: absolute; top: -2px; left: {{ round($target / $span * 100, 2) }}%; width: 2px; height: 1.625rem; background: var(--color-dash-ef4444)"></span>
                </div>
            @endforeach
        </div>
    </section>

    <section data-acc-banks style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-3b82f6)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r4.banks') }}</h2>
            @if (\Illuminate\Support\Facades\Route::has('accounts.reconciliation.index'))<a href="{{ route('accounts.reconciliation.index') }}" style="font-size: 0.8125rem; font-weight: 600">{{ __('accounts::board.r4.go') }}</a>@endif
        </div>
        @forelse ($banks as $b)
            @php $chip = $b['statement'] === null ? 'var(--color-dash-94a3b8)' : ($b['unmatched'] === 0 ? 'var(--color-dash-10b981)' : ($b['unmatched'] > 5 ? 'var(--color-dash-ef4444)' : 'var(--color-dash-f59e0b)')); @endphp
            <div data-acc-bank style="display: flex; flex-direction: column; gap: 0.3125rem; padding: 0.5rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">
                <span style="display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.8125rem"><b style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $b['name'] }}</b><span style="flex: none; font-size: 0.75rem; font-weight: 700; color: var(--color-dash-ffffff); background: {{ $chip }}; border-radius: 999px; padding: 1px 0.5625rem">{{ $b['statement'] === null ? __('accounts::board.r4.no_statement') : ($b['unmatched'] === 0 ? __('accounts::board.r4.matched') : __('accounts::board.r4.left', ['n' => $b['unmatched']])) }}</span></span>
                <span style="display: flex; height: 0.625rem; border-radius: 999px; overflow: hidden; background: var(--color-dash-eef0f6)"><span style="width: {{ $b['pct'] ?? 0 }}%; background: linear-gradient(90deg, var(--color-dash-3b82f6), var(--color-dash-06b6d4))"></span></span>
                <span style="display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; color: var(--color-dash-4b5563)"><span>{{ __('accounts::board.r4.book_vs', ['book' => $short($b['book']), 'stmt' => $b['statement'] === null ? __('accounts::board.r4.no_statement') : $short($b['statement'])]) }}</span><span>{{ $b['last'] === null ? __('accounts::board.r4.never') : __('accounts::board.r4.last', ['date' => $date($b['last'])]) }}</span></span>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r4.no_banks') }}</span>
        @endforelse
    </section>

    <section data-acc-tax style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-a855f7)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r4.tax') }}</h2>
        @foreach ($taxes as $t)
            @php
                [$ink, $soft] = $taxPaint[$t['key']];
                $none = bccomp($t['amount'], '0', 4) <= 0;
                $when = $t['days'] < 0 ? __('accounts::board.r4.overdue', ['date' => $date($t['due']), 'n' => -$t['days']]) : ($t['days'] === 0 ? __('accounts::board.r4.due_today', ['date' => $date($t['due'])]) : __('accounts::board.r4.days_left', ['date' => $date($t['due']), 'n' => $t['days']]));
            @endphp
            <div data-acc-tax-row="{{ $t['key'] }}" style="display: flex; gap: 0.625rem; align-items: center; background: {{ $soft }}; border-radius: 0.875rem; padding: 0.625rem">
                <svg aria-hidden="true" viewBox="0 0 48 48" style="width: 3rem; height: 3rem; flex-shrink: 0">
                    <circle cx="24" cy="24" r="19" fill="none" stroke="var(--color-dash-ffffff)" stroke-width="6"></circle>
                    @if (! $none && $t['share'] > 0)<circle cx="24" cy="24" r="19" fill="none" stroke="{{ $ink }}" stroke-width="6" stroke-linecap="round" stroke-dasharray="{{ round($t['share'] * 119) }} 200" transform="rotate(-90 24 24)"></circle>@endif
                </svg>
                <span style="display: flex; flex-direction: column; font-size: 0.75rem"><b style="font-size: 0.8125rem">{{ __('accounts::board.r4.'.$t['key']) }}</b><span>{{ $none ? __('accounts::board.r4.nothing') : $short($t['amount']) }}</span>@unless ($none)<span style="font-weight: 700; color: {{ $ink }}">{{ $when }}</span>@endunless</span>
            </div>
        @endforeach
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('accounts::board.r4.tax_note') }}</span>
    </section>
</div>
