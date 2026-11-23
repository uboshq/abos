{{--
    ⭐ দৈনিক খতিয়ান — এক দিনের খাতা, জার্নাল ধরে (মালিকের অনুমোদিত নমুনা, ১৬ নভেম্বর ২০২৬; [[DayBookController]])।

    ⓘ পর্দায় পাতা ভাগ নেই — পুরো দিন এক টেবিলে; জের টানা কেবল কাগজে ([[DayBookPaper]])। সংখ্যা এখানে কষা হয় না —
      কাগজের একই [[DayBookService::build()]]।
--}}
@php
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $sum = fn ($v) => \App\Core\Support\Money::format((string) $v);
    $day = \Illuminate\Support\Carbon::parse($book['date']);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::daybook.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::daybook.title')" :subtitle="__('accounts::daybook.subtitle')">
            <x-slot:actions>
                @can('system_admin.list.export')
                    <x-ui.button tone="secondary" icon="print" target="_blank" rel="noopener" data-paper="print"
                                 :href="url()->current().'?'.http_build_query(request()->query() + ['date' => $book['date'], 'export' => 'print'])">
                        {{ __('core.action.print') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" data-paper="pdf" target="_blank" rel="noopener"
                                 :href="url()->current().'?'.http_build_query(request()->query() + ['date' => $book['date'], 'export' => 'pdf'])">
                        {{ __('core.toolbar.export_pdf') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide" data-daybook-filter>
        <x-ui.field name="date" type="date" :label="__('accounts::daybook.date')" :value="$book['date']" />

        @if ($branches->count() > 1)
            <x-ui.select name="branch_id" :label="__('core.company.branch')"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                         :placeholder="__('accounts::field.all_branches')"
                         :selected="$branchId" />
        @endif

        <x-ui.button type="submit" tone="secondary">{{ __('accounts::daybook.show') }}</x-ui.button>
    </form>

    {{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬) --}}
    <p class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm" data-info-line>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::daybook.date') }}:</span>
            <b>{{ $day->format('d/m/Y') }} ({{ $day->locale(app()->getLocale())->translatedFormat('l') }})</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::daybook.journals') }}:</span>
            <b>{{ __('accounts::daybook.journals_value', ['count' => count($book['journals'])]) }}</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::daybook.status') }}:</span> <b>{{ __('accounts::daybook.status_value') }}</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::daybook.entries') }}:</span>
            <b>{{ __('accounts::daybook.entries_value', ['count' => $book['entries']]) }}</b></span>
    </p>

    @if ($book['entries'] === 0)
        <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-6 text-center" data-daybook-empty>
            {{ __('accounts::daybook.empty') }}
        </p>
    @else
        @if (bccomp($book['debit'], $book['credit'], 2) !== 0)
            <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)" data-daybook-unequal>
                {{ __('accounts::daybook.not_equal', ['gap' => $sum(bcsub($book['debit'], $book['credit'], 4))]) }}
            </p>
        @endif

        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="ui-grid w-full text-sm" data-daybook>
                <thead>
                    <tr>
                        <th>{{ __('accounts::daybook.col.time') }}</th>
                        <th>{{ __('accounts::daybook.col.number') }}</th>
                        <th>{{ __('accounts::daybook.col.reference') }}</th>
                        <th>{{ __('accounts::daybook.col.party') }}</th>
                        <th>{{ __('accounts::daybook.col.code') }}</th>
                        <th>{{ __('accounts::daybook.col.account') }}</th>
                        <th class="num">{{ __('accounts::daybook.col.debit') }}</th>
                        <th class="num">{{ __('accounts::daybook.col.credit') }}</th>
                        <th>{{ __('accounts::daybook.col.who') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($book['journals'] as $journal)
                        <tr class="bg-(--color-surface-muted) font-semibold" data-journal="{{ $journal['key'] }}">
                            <td colspan="9">{{ __('accounts::daybook.journal.'.$journal['key']) }}</td>
                        </tr>
                        @foreach ($journal['entries'] as $entry)
                            @foreach ($entry['lines'] as $i => $line)
                                <tr @if ($i === 0) data-entry="{{ $entry['number'] }}" @endif>
                                    @if ($i === 0)
                                        <td class="whitespace-nowrap">{{ $entry['time'] }}</td>
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $entry['number'] }}</td>
                                        <td class="whitespace-nowrap font-mono text-xs">
                                            @php($href = app(\App\Core\Engines\Drill\DrillResolver::class)->describe($entry['source_type'], $entry['source_id'])['route'] ?? null)
                                            @if (is_array($href))
                                                <a class="text-(--color-link) hover:underline" href="{{ route($href[0], $href[1] ?? []) }}">{{ $entry['document_no'] }}</a>
                                            @else
                                                {{ $entry['document_no'] }}
                                            @endif
                                        </td>
                                        <td class="min-w-56">
                                            @if ($entry['party']){{ $entry['party'] }}@endif
                                            @if ($entry['reversal'])<span class="ms-1 text-xs text-(--color-danger)">({{ __('accounts::daybook.reversal') }})</span>@endif
                                            @if ($entry['narration'])<span class="block text-xs text-(--color-ink-muted)">{{ $entry['narration'] }}</span>@endif
                                        </td>
                                    @else
                                        <td colspan="4"></td>
                                    @endif
                                    <td class="font-mono text-xs">{{ $line['code'] }}</td>
                                    {{-- ⓘ ক্রেডিটের সারি একটু ভেতরে (খাতার রীতি) — ঘরের প্যাডিং থিমের, ভেতরের লেখায় সরানো --}}
                                    <td><span @class(['ms-6 inline-block' => bccomp($line['credit'], '0', 4) > 0])>{{ $line['account'] }}</span></td>
                                    <td class="num">{{ $money($line['debit']) }}</td>
                                    <td class="num">{{ $money($line['credit']) }}</td>
                                    <td class="whitespace-nowrap">@if ($i === 0){{ $entry['who'] }}@endif</td>
                                </tr>
                            @endforeach
                        @endforeach
                        <tr class="bg-(--color-surface-muted) font-semibold" data-journal-total="{{ $journal['key'] }}">
                            <td colspan="6" class="text-end">{{ __('accounts::daybook.journal_total', ['journal' => __('accounts::daybook.journal_short.'.$journal['key']), 'count' => count($journal['entries'])]) }}</td>
                            <td class="num">{{ $sum($journal['debit']) }}</td>
                            <td class="num">{{ $sum($journal['credit']) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="font-bold" data-day-total>
                        <td colspan="6" class="text-end">{{ __('accounts::daybook.day_total', ['count' => $book['entries']]) }}</td>
                        <td class="num">{{ $sum($book['debit']) }}</td>
                        <td class="num">{{ $sum($book['credit']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
