{{--
    ⭐ রেওয়ামিল — হিসাবের ধরন ধরে দল, উপমোট আর রং; কোড নিজের কলামে, নামের নিচে দল; উপরে অবস্থার সারি
    (মালিক, ১৭ নভেম্বর ২০২৬ "ok" — NEXUS-এর নমুনার মানে, আমাদের নিয়মে; [[TrialBalanceStatement]])।

    ⓘ অঙ্ক রিপোর্ট-ইঞ্জিনের একই রেওয়ামিল থেকে — CSV, ফোন আর কাগজ একই কথা বলে। প্রতিটা অঙ্কে চাপলে ঐ হিসাবের খতিয়ান,
      একই সময় ও শাখায় ([[CoreReports::ledgerOf()]])।
--}}
@php
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $cells = \App\Modules\Accounts\Services\TrialBalanceStatement::CELLS;
    $byKey = collect($report->columns)->keyBy('key');
    $tb = $statement;
@endphp

{{-- ── অবস্থার সারি: মেলে কি না · বাদ পড়া ভাউচার · উল্টো জের · তালা দেওয়া মাস ── --}}
<div data-tb-status class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-(--color-border) bg-(--color-surface-app) px-4 py-2 text-sm">
    <span data-tb-agrees="{{ $tb['agrees'] ? 'yes' : 'no' }}" @class([
        'font-semibold',
        'text-(--color-badge-success-ink)' => $tb['agrees'],
        'text-(--color-badge-danger-ink)' => ! $tb['agrees'],
    ])>
        {{ $tb['agrees'] ? __('accounts::fs.tb.agrees') : __('accounts::fs.tb.does_not_agree', ['gap' => \App\Core\Support\Money::format($tb['gaps']['closing'])]) }}
    </span>
    <span data-tb-held="{{ $tb['held']['count'] }}">
        {{ $tb['held']['count'] === 0
            ? __('accounts::fs.tb.held_none')
            : __('accounts::fs.tb.held', ['count' => $tb['held']['count'], 'awaiting' => $tb['held']['awaiting'], 'amount' => \App\Core\Support\Money::format($tb['held']['amount'])]) }}
    </span>
    <span data-tb-wrong="{{ count($tb['wrong_side']) }}" @class(['text-(--color-badge-danger-ink) font-semibold' => $tb['wrong_side'] !== []])>
        @if ($tb['wrong_side'] === [])
            {{ __('accounts::fs.tb.wrong_none') }}
        @else
            <x-ui.icon name="alert-triangle" :size="14" /> {{ __('accounts::fs.tb.wrong_some', ['list' => collect($tb['wrong_side'])->map(fn ($w) => $w['code'].' '.$w['name'])->implode(', ')]) }}
        @endif
    </span>
    <span data-tb-locked="{{ count($tb['locked']) }}">
        {{ $tb['locked'] === [] ? __('accounts::fs.tb.locked_none') : __('accounts::fs.tb.locked', ['months' => implode(', ', $tb['locked'])]) }}
    </span>
</div>

<div class="overflow-x-auto">
    <table class="ui-grid" data-tb-table>
        <thead>
            <tr>
                <th scope="col" rowspan="2" style="width: 5rem">{{ __('accounts::fs.tb.code') }}</th>
                <th scope="col" rowspan="2">{{ __('core.print.account') }}</th>
                <th scope="colgroup" colspan="2" class="text-center">{{ $tb['all_time'] ? __('accounts::fs.tb.opening_start') : __('accounts::fs.tb.opening_on', ['date' => \App\Core\Support\DateFormat::format($tb['from'])]) }}</th>
                <th scope="colgroup" colspan="2" class="text-center">{{ __('accounts::fs.tb.movement') }}</th>
                <th scope="colgroup" colspan="2" class="text-center">{{ __('accounts::fs.tb.closing_on', ['date' => \App\Core\Support\DateFormat::format($tb['to'])]) }}</th>
            </tr>
            <tr>
                @foreach ($cells as $i => $cell)
                    <th scope="col" class="num">{{ $i % 2 === 0 ? __('core.table.debit') : __('core.table.credit') }}</th>
                @endforeach
            </tr>
        </thead>
        @foreach ($tb['groups'] as $group)
            <tbody data-tb-class="{{ $group['type'] }}" data-account-type="{{ $group['type'] }}">
                <tr data-tb-group>
                    <td colspan="2" class="font-semibold">{{ __('accounts::fs.class.'.$group['type']) }}</td>
                    @foreach ($cells as $cell)
                        <td class="num font-semibold" data-tb-sum="{{ $cell }}">{{ $money($group['sum'][$cell]) }}</td>
                    @endforeach
                </tr>
                @foreach ($group['rows'] as $row)
                    <tr class="transition-colors hover:bg-(--color-surface-hover)" data-report-row data-tb-account="{{ $row['code'] }}">
                        <td class="num">{{ $row['code'] }}</td>
                        <td>
                            <span>{{ $row['name'] }}</span>
                            @if ($row['wrong_side'])
                                <span data-tb-flag class="font-semibold text-(--color-badge-danger-ink)"><x-ui.icon name="alert-triangle" :size="14" /> {{ __('accounts::fs.tb.wrong_flag') }}</span>
                            @endif
                            @if ($row['group'] !== '')
                                <div class="text-xs text-(--color-ink-muted)">{{ $row['group'] }}</div>
                            @endif
                        </td>
                        @foreach ($cells as $cell)
                            @php
                                $shown = $money($row['values'][$cell]);
                                $href = $shown !== '' && $byKey->has($cell) ? $byKey[$cell]->drillHref(['account_id' => $row['account_id']], $filters) : null;
                            @endphp
                            <td class="num">
                                @if ($href !== null)
                                    <a href="{{ $href }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)"
                                       aria-label="{{ $byKey[$cell]->drillLabel($shown) }}" data-report-drill="{{ $cell }}">{{ $shown }}</a>
                                @else
                                    {{ $shown }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        @endforeach
        <tfoot>
            <tr class="font-semibold">
                <td colspan="2">{{ __('core.print.total') }}</td>
                @foreach ($cells as $cell)
                    <td class="num" data-tb-total="{{ $cell }}">{{ \App\Core\Support\Money::format($tb['total'][$cell]) }}</td>
                @endforeach
            </tr>
            <tr>
                <td colspan="2">{{ __('accounts::fs.tb.difference') }}</td>
                @foreach (['opening', 'period', 'closing'] as $pair)
                    <td colspan="2" @class(['num font-semibold', 'text-(--color-badge-danger-ink)' => bccomp($tb['gaps'][$pair], '0', 2) !== 0]) data-tb-gap="{{ $pair }}">
                        {{ \App\Core\Support\Money::format($tb['gaps'][$pair]) }}{{ bccomp($tb['gaps'][$pair], '0', 2) === 0 ? ' ✓' : '' }}
                    </td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</div>
