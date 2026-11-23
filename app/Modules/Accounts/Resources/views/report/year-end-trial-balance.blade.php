{{--
    ⭐ বছর-শেষের রেওয়ামিল — সমন্বয়ের আগে → নম্বর দেওয়া সমন্বয় জাবেদা → সমন্বয়ের পরে, পাশে আগের বছর-শেষ (মালিক, ১৭ নভেম্বর ২০২৬
    "ok": রেওয়ামিল আর স্থিতিপত্র NEXUS-এর মানে; [[YearEndTrialBalance]])।

    ⓘ দল, উপমোট, অবস্থার সারি আর রং সাধারণ রেওয়ামিলের একই ([[accounts::report.partials.trial-balance]]); "নং" কলামে কোন কোন
      সমন্বয় জাবেদা খাতটা ছুঁয়েছে, নিচে নম্বরের তালিকা। সমন্বয়ের পরের অঙ্কে চাপলে ঐ খাতের খতিয়ান, এই বছর আর শাখায়।
    ⓘ পাতার মাথায় বর্ণনার লাইন নেই — শিরোনাম আর বোতাম এক সারিতে (মালিক, ১৯ নভেম্বর ২০২৬)।
--}}
@php
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $day = fn (string $d) => \App\Core\Support\DateFormat::format($d);
    $cells = \App\Modules\Accounts\Services\YearEndTrialBalance::CELLS;
    $y = $ytb;
    $query = array_filter(['year_id' => $y['year']->id, 'branch_id' => $branchId]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::fs.ytb.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::fs.ytb.title')">
            <x-slot:actions>
                {{-- ⭐ কাগজ — বাংলা বা ইংরেজি, A4 আড়াআড়ি; "তালিকা নামানো" চাবিতে (স্থিতিপত্রের একই নিয়ম) --}}
                @can('system_admin.list.export')
                    <x-ui.button tone="secondary" icon="print" target="_blank" rel="noopener" data-paper="print"
                                 :href="route('accounts.year_end_trial_balance', [...$query, 'export' => 'print', 'lang' => 'bn'])">
                        {{ __('accounts::fs.ytb.print_bn') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" target="_blank" rel="noopener" data-paper="print-en"
                                 :href="route('accounts.year_end_trial_balance', [...$query, 'export' => 'print', 'lang' => 'en'])">
                        {{ __('accounts::fs.ytb.print_en') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" data-paper="pdf"
                                 :href="route('accounts.year_end_trial_balance', [...$query, 'export' => 'pdf'])">
                        {{ __('core.toolbar.export_pdf') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ⭐ ঘরগুলো ডান পাশের ছাঁকনির প্যানেলে, একই চাবিতে (মালিক, ২৩ নভেম্বর ২০২৬; [[filter-bar]]); চলে "দেখান" বা Enter-এ --}}
    @php
        $panel = \App\Core\ListFilter\Facets::panel([
            // ⓘ অর্থবছরে "সব" নেই (আগের ঘরেও ছিল না) — চলতিটা শুরুতে বাছা
            \App\Core\ListFilter\Facets::one('year_id', __('accounts::fs.ytb.year'), $years->mapWithKeys(fn ($fy) => [(string) $fy->id => __('accounts::fs.ytb.year_range', [
                'name' => $fy->name, 'from' => $day((string) $fy->starts_on), 'to' => $day((string) $fy->ends_on)])])->all(), (string) $y['year']->id),
            $branches->count() > 1
                ? \App\Core\ListFilter\Facets::pick('branch_id', __('core.company.branch'), $branches->mapWithKeys(fn ($b) => [(string) $b->id => $b->name()])->all(), __('accounts::field.all_branches'))->look('blue', '🏬')
                : null,
        ]);
    @endphp
    <x-ui.filter-layout :filters="$panel" form="list-form" :report="true">
        <form id="list-form" method="GET" class="contents" data-ytb-filter data-report>
            <x-ui.filter-bar :facets="$panel" />
        </form>
    </x-ui.filter-layout>

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ── অবস্থার সারি: মেলে কি না · বন্ধের আগের · সমন্বয় কয়টা · বাদ পড়া ভাউচার · উল্টো জের · তালা দেওয়া মাস ── --}}
        <div data-tb-status class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-(--color-border) bg-(--color-surface-app) px-4 py-2 text-sm">
            <span data-ytb-agrees="{{ $y['agrees'] ? 'yes' : 'no' }}" @class([
                'font-semibold',
                'text-(--color-badge-success-ink)' => $y['agrees'],
                'text-(--color-badge-danger-ink)' => ! $y['agrees'],
            ])>
                {{ $y['agrees'] ? __('accounts::fs.ytb.agrees') : __('accounts::fs.ytb.does_not_agree', ['gap' => \App\Core\Support\Money::format($y['gaps']['after'])]) }}
            </span>
            <span>{{ __('accounts::fs.ytb.pre_closing') }} · {{ $y['closed'] ? __('accounts::fs.ytb.closed') : __('accounts::fs.ytb.open') }}</span>
            <span data-ytb-adjusting="{{ count($y['adjusting']) }}">
                {{ $y['adjusting'] === []
                    ? __('accounts::fs.ytb.no_adjusting')
                    : __('accounts::fs.ytb.adjusting_count', ['count' => count($y['adjusting']), 'amount' => \App\Core\Support\Money::format($y['total']['adj_debit'])]) }}
            </span>
            <span data-tb-held="{{ $y['held']['count'] }}">
                {{ $y['held']['count'] === 0
                    ? __('accounts::fs.tb.held_none')
                    : __('accounts::fs.tb.held', ['count' => $y['held']['count'], 'awaiting' => $y['held']['awaiting'], 'amount' => \App\Core\Support\Money::format($y['held']['amount'])]) }}
            </span>
            <span data-tb-wrong="{{ count($y['wrong_side']) }}" @class(['text-(--color-badge-danger-ink) font-semibold' => $y['wrong_side'] !== []])>
                @if ($y['wrong_side'] === [])
                    {{ __('accounts::fs.tb.wrong_none') }}
                @else
                    <x-ui.icon name="alert-triangle" :size="14" /> {{ __('accounts::fs.tb.wrong_some', ['list' => collect($y['wrong_side'])->map(fn ($w) => $w['code'].' '.$w['name'])->implode(', ')]) }}
                @endif
            </span>
            <span data-tb-locked="{{ count($y['locked']) }}">
                {{ $y['locked'] === [] ? __('accounts::fs.tb.locked_none') : __('accounts::fs.tb.locked', ['months' => implode(', ', $y['locked'])]) }}
            </span>
        </div>

        @if ($y['groups'] === [])
            <p class="px-4 py-6 text-sm text-(--color-ink-muted)">{{ __('core.empty.no_results') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid" data-ytb-table>
                    <thead>
                        <tr>
                            <th scope="col" rowspan="2" style="width: 5rem">{{ __('accounts::fs.tb.code') }}</th>
                            <th scope="col" rowspan="2">{{ __('core.print.account') }}</th>
                            <th scope="colgroup" colspan="2" class="text-center">{{ __('accounts::fs.ytb.before') }}</th>
                            <th scope="colgroup" colspan="3" class="text-center">{{ __('accounts::fs.ytb.adjustments') }}</th>
                            <th scope="colgroup" colspan="2" class="text-center">{{ __('accounts::fs.ytb.after', ['date' => $day($y['to'])]) }}</th>
                            <th scope="colgroup" colspan="2" class="text-center">{{ __('accounts::fs.ytb.prev', ['date' => $day($y['prev_end'])]) }}</th>
                        </tr>
                        <tr>
                            <th scope="col" class="num">{{ __('core.table.debit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.credit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.debit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.credit') }}</th>
                            <th scope="col">{{ __('accounts::fs.ytb.refs') }}</th>
                            <th scope="col" class="num">{{ __('core.table.debit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.credit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.debit') }}</th>
                            <th scope="col" class="num">{{ __('core.table.credit') }}</th>
                        </tr>
                    </thead>
                    @foreach ($y['groups'] as $group)
                        <tbody data-tb-class="{{ $group['type'] }}" data-account-type="{{ $group['type'] }}">
                            <tr data-tb-group>
                                <td colspan="2" class="font-semibold">{{ __('accounts::fs.class.'.$group['type']) }}</td>
                                @foreach ($cells as $cell)
                                    <td class="num font-semibold" data-ytb-sum="{{ $cell }}">{{ $money($group['sum'][$cell]) }}</td>
                                    @if ($cell === 'adj_credit')<td></td>@endif
                                @endforeach
                            </tr>
                            @foreach ($group['rows'] as $row)
                                <tr class="transition-colors hover:bg-(--color-surface-hover)" data-report-row data-ytb-account="{{ $row['code'] }}">
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
                                        @php $shown = $money($row['values'][$cell]); @endphp
                                        <td class="num" data-ytb-cell="{{ $cell }}">
                                            @if ($shown !== '' && in_array($cell, ['after_debit', 'after_credit'], true))
                                                {{-- ⓘ অঙ্কে চাপলে ঐ খাতের খতিয়ান, এই বছর আর শাখায় (নিয়ম ১) --}}
                                                <a href="{{ route('accounts.report.tb_ledger', array_filter(['account_id' => $row['account_id'], 'from' => $y['from'], 'to' => $y['to'], 'branch_id' => $y['branch_id']])) }}"
                                                   class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)" data-report-drill="{{ $cell }}">{{ $shown }}</a>
                                            @else
                                                {{ $shown }}
                                            @endif
                                        </td>
                                        @if ($cell === 'adj_credit')
                                            <td class="text-xs text-(--color-ink-muted)" data-ytb-refs>{{ $row['refs'] === [] ? '' : '('.implode(', ', $row['refs']).')' }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="2">{{ __('core.print.total') }}</td>
                            @foreach ($cells as $cell)
                                <td class="num" data-ytb-total="{{ $cell }}">{{ \App\Core\Support\Money::format($y['total'][$cell]) }}</td>
                                @if ($cell === 'adj_credit')<td></td>@endif
                            @endforeach
                        </tr>
                        <tr>
                            <td colspan="2">{{ __('accounts::fs.ytb.difference') }}</td>
                            @foreach (\App\Modules\Accounts\Services\YearEndTrialBalance::PAIRS as $pair)
                                <td colspan="{{ $pair === 'adj' ? 3 : 2 }}" @class(['num font-semibold', 'text-(--color-badge-danger-ink)' => bccomp($y['gaps'][$pair], '0', 2) !== 0]) data-ytb-gap="{{ $pair }}">
                                    {{ \App\Core\Support\Money::format($y['gaps'][$pair]) }}{{ bccomp($y['gaps'][$pair], '0', 2) === 0 ? ' ✓' : '' }}
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    {{-- ── নম্বর দেওয়া সমন্বয় জাবেদার তালিকা ── --}}
    @if ($y['adjusting'] !== [])
        <section data-boxed class="mt-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-ytb-schedule>
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::fs.ytb.schedule') }}</h2>
            <table class="ui-grid">
                <thead>
                    <tr>
                        <th scope="col" class="num" style="width: 4rem">{{ __('accounts::fs.ytb.no') }}</th>
                        <th scope="col">{{ __('accounts::fs.ytb.date') }}</th>
                        <th scope="col">{{ __('accounts::fs.ytb.voucher') }}</th>
                        <th scope="col">{{ __('accounts::fs.ytb.narration') }}</th>
                        <th scope="col" class="num">{{ __('accounts::fs.ytb.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($y['adjusting'] as $entry)
                        <tr data-ytb-entry="{{ $entry['no'] }}">
                            <td class="num">{{ $entry['no'] }}</td>
                            <td>{{ $day($entry['date']) }}</td>
                            <td><a href="{{ route('accounts.voucher.show', $entry['id']) }}" class="underline decoration-dotted underline-offset-2">{{ $entry['document_no'] }}</a></td>
                            <td>{{ $entry['narration'] }}@if ($entry['reversal']) <span class="text-xs text-(--color-ink-muted)">· {{ __('accounts::fs.ytb.reversal') }}</span>@endif</td>
                            <td class="num">{{ \App\Core\Support\Money::format($entry['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-layouts.app>
