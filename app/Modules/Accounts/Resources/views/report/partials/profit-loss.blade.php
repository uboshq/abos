{{--
    ⭐ লাভ-ক্ষতি পর্দায় — কার্যভিত্তিক (IAS 1.103): বিক্রয় → বিক্রীত পণ্যের ব্যয় → মোট মুনাফা → পরিচালন মুনাফা → কর-পূর্ব → নিট, পাশে আগের
    বছরের একই সময় (অডিট, ২৫ নভেম্বর ২০২৬; কাগজের অনুমোদিত ছক, মালিক ১৭ নভেম্বর ২০২৬ — একই [[FinancialStatements::profitOrLoss()]])।

    ⓘ ডেবিট-ক্রেডিটের পুরোনো তালিকা হারায়নি — মাথার "খাত ধরে" ট্যাবে; Excel আর কাগজ আগের মতো। ⓘ খরচ বন্ধনীতে। অঙ্কে চাপলে ঐ খাতের
      খতিয়ান, একই সময় ও শাখায় ([[CoreReports::ledgerOf()]])।
--}}
@php
    $fmt = fn ($v) => bccomp((string) $v, '0', 4) < 0
        ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')'
        : \App\Core\Support\Money::format((string) $v);
    // ⓘ দুই সময়েই শূন্য হলে "নতুন" নয়, ফাঁকা
    $pct = fn (array $l) => $l['pct'] === null ? (bccomp((string) $l['now'], '0', 4) === 0 ? '' : __('accounts::fs.bs.new')) : $l['pct'].'%';
    $day = fn (string $d) => \App\Core\Support\DateFormat::format($d);
    $link = fn ($id) => $id === null ? null : route('accounts.report.tb_ledger', array_filter([
        'account_id' => $id, 'from' => $pl['from'], 'to' => $pl['to'], 'branch_id' => $pl['branch_id'],
    ]));
    $plan = [
        ['section', 'revenue'], ['section', 'cost_of_sales'], ['total', 'gross_profit'],
        ['section', 'other_income'], ['section', 'operating_expenses'], ['total', 'operating_profit'],
        ['section', 'finance_costs'], ['total', 'profit_before_tax'],
        ['section', 'income_tax'], ['grand', 'profit'],
    ];
@endphp

<div class="overflow-x-auto">
    <table class="ui-grid" data-pl-table>
        <thead>
            <tr>
                <th scope="col">{{ __('accounts::fs.bs.particulars') }}</th>
                <th scope="col" class="num">{{ $day($pl['from']) }} – {{ $day($pl['to']) }}</th>
                <th scope="col" class="num">{{ $day($pl['prior_from']) }} – {{ $day($pl['prior_to']) }}</th>
                <th scope="col" class="num">{{ __('accounts::fs.bs.change') }}</th>
                <th scope="col" class="num">%</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan as [$kind, $key])
                @php
                    $sum = $pl['totals'][$key];
                    $tone = match (true) {
                        $kind === 'section' => 'background: var(--color-surface-hover)',
                        $kind === 'total' => 'background: var(--color-surface-selected)',
                        bccomp((string) $sum['now'], '0', 4) >= 0 => 'background: var(--color-badge-success-bg)',
                        default => 'background: var(--color-badge-danger-bg)',
                    };
                @endphp
                <tr data-pl="{{ $key }}" class="font-semibold">
                    <td style="{{ $tone }}">{{ __('accounts::fs.pl.'.$key) }}</td>
                    <td class="num" style="{{ $tone }}">{{ $fmt($sum['now']) }}</td>
                    <td class="num" style="{{ $tone }}">{{ $fmt($sum['then']) }}</td>
                    <td class="num" style="{{ $tone }}">{{ $fmt($sum['change']) }}</td>
                    <td class="num" style="{{ $tone }}">{{ $pct($sum) }}</td>
                </tr>
                @foreach ($kind === 'section' ? $pl['sections'][$key] : [] as $line)
                    @php
                        $href = $link($line['account_id'] ?? null);
                    @endphp
                    <tr data-pl-line class="transition-colors hover:bg-(--color-surface-hover)">
                        <td style="padding-inline-start: 2rem">{{ $line['label'] }}</td>
                        <td class="num">
                            @if ($href !== null)
                                <a href="{{ $href }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)">{{ $fmt($line['now']) }}</a>
                            @else
                                {{ $fmt($line['now']) }}
                            @endif
                        </td>
                        <td class="num">{{ $fmt($line['then']) }}</td>
                        <td class="num">{{ $fmt($line['change']) }}</td>
                        <td class="num">{{ $pct($line) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
