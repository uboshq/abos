{{--
    ⭐ চূড়ান্ত পাওনার অঙ্ক — খাত, হিসাব, অঙ্ক; নিচে মোট, অগ্রিমের কর্তন আর নিট ([[FinalSettlements::compute()]])।
    ⓘ `$f` = compute()-এর ফল, বা কাগজের নিজের ঘর (দুটোই একই চাবি)।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $d = (array) ($f['details'] ?? []);
    $rows = [
        ['wages', __('hr::settlement.basis_wages', ['days' => $d['wage_days'] ?? 0, 'from' => $d['unpaid_from'] ?? '—', 'gross' => $money($d['monthly_gross'] ?? '0')])],
        ['leave_encashment', __('hr::settlement.basis_leave', ['days' => $d['leave_days'] ?? '0', 'rate' => $money($d['leave_daily_rate'] ?? '0')])],
        ['gratuity', __('hr::settlement.basis_gratuity', ['years' => $d['gratuity_years'] ?? 0, 'days' => $d['gratuity_days'] ?? 0, 'basic' => $money($d['gratuity_basic'] ?? '0')])],
        ['provident_fund', __('hr::settlement.basis_pf', ['own' => $money($d['pf_own'] ?? '0'), 'vested' => $money($d['pf_vested'] ?? '0'),
            'employer' => $money($d['pf_employer'] ?? '0'), 'forfeited' => $money($d['pf_forfeited'] ?? '0')])],
    ];
@endphp
<section data-boxed data-settlement-figures class="mb-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
    <table class="w-full text-sm">
        <thead class="bg-(--color-section-head)">
            <tr>
                <th class="px-4 py-3 text-start font-semibold">{{ __('hr::settlement.item') }}</th>
                <th class="px-4 py-3 text-start font-semibold">{{ __('hr::settlement.basis') }}</th>
                <th class="px-4 py-3 text-end font-semibold">{{ __('hr::settlement.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as [$key, $basis])
                <tr data-line="{{ $key }}" class="border-t border-(--color-border)">
                    <th scope="row" class="px-4 py-2 text-start font-medium">{{ __('hr::settlement.'.$key) }}</th>
                    <td class="px-4 py-2 text-(--color-ink-muted)">{{ $basis }}</td>
                    <td class="px-4 py-2 text-end tabular-nums">{{ $money($f[$key]) }}</td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-(--color-border) font-semibold">
                <th scope="row" colspan="2" class="px-4 py-2 text-start">{{ __('hr::settlement.earnings') }}</th>
                <td class="px-4 py-2 text-end tabular-nums">{{ $money($f['earnings']) }}</td>
            </tr>
            <tr data-line="advance_recovered" class="border-t border-(--color-border)">
                <th scope="row" class="px-4 py-2 text-start font-medium">{{ __('hr::settlement.advance_recovered') }}</th>
                <td class="px-4 py-2 text-(--color-ink-muted)">{{ __('hr::settlement.basis_advance', ['outstanding' => $money($d['advance_outstanding'] ?? '0')]) }}</td>
                <td class="px-4 py-2 text-end tabular-nums">{{ $money($f['advance_recovered']) }}</td>
            </tr>
            <tr class="border-t-2 border-(--color-border) font-semibold">
                <th scope="row" colspan="2" class="px-4 py-2 text-start">{{ __('hr::settlement.net') }}</th>
                <td data-settlement-net class="px-4 py-2 text-end tabular-nums">{{ $money($f['net']) }}</td>
            </tr>
        </tbody>
    </table>
</section>
