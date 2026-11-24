{{--
    অনুমোদনের সারি (§১০ Approval Queue; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।

    ⭐ ABOS-এর সইয়ের ইনবক্স, কেবল ডকুমেন্ট — কলাম মালিকের: কাগজ, অনুরোধকারী, স্তর, সময়সীমা, কাজ।
    ⓘ "কাজ" ইনবক্সের নিজের পাতা খোলে; সই, "না" আর সংশোধনে ফেরত সেখানেই।
--}}
@php
    use App\Core\Support\DateFormat;

    $tabs = [
        ['key' => 'mine_to_sign', 'label' => __('documents::message.queue_to_sign'),
            'url' => route('documents.approval'), 'active' => ! $mine],
        ['key' => 'sent', 'label' => __('documents::message.queue_sent'),
            'url' => route('documents.approval', ['mine' => 1]), 'active' => $mine],
    ];

    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('documents::field.due_date')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.approval') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.approval')" :subtitle="__('documents::message.queue_subtitle')" />
    </x-slot:header>
    @endunless

    <x-ui.list-tabs :tabs="$tabs" :label="__('documents::menu.approval')" />

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($mine)
                <input type="hidden" name="mine" value="1">
            @endif
            @if ($abosHead)
                <x-ui.toolbar :title="__('documents::menu.approval')" :info="__('documents::message.queue_subtitle')" :search="false" :export="false" :share="false" :facets="$facetPanel" :quiet="['mine']"></x-ui.toolbar>
            @else
                <x-ui.filter-bar :facets="$facetPanel" />
            @endif
        </form>
        <div class="overflow-x-auto">
            <table class="ui-list w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::field.document_no') }}</th>
                        <th class="text-start">{{ __('documents::field.name') }}</th>
                        <th class="text-start">{{ __('documents::field.requester') }}</th>
                        <th class="text-end">{{ __('documents::field.level') }}</th>
                        <th class="text-start">{{ __('documents::field.due_date') }}</th>
                        <th class="text-end">{{ __('core.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($paper = $documents[$row->approvable_id] ?? null)
                        <tr data-approval="{{ $row->id }}">
                            <td class="whitespace-nowrap">
                                {{-- ⭐ নম্বরের পাশে পক্ষ (মালিক, ১৯ অক্টোবর ২০২৬) — জোড়া রেকর্ড থেকে, পাতার একবারে ([[DocumentParties]]) --}}
                                <x-ui.party-cell :party="$parties[$row->approvable_id] ?? null"><span class="num">{{ $paper?->document_no ?? '—' }}</span></x-ui.party-cell>
                            </td>
                            <td>{{ $paper?->name ?? $row->requested_reason }}</td>
                            <td>{{ $row->requesterName() }}</td>
                            <td class="num text-end">{{ $row->current_level }}</td>
                            <td class="whitespace-nowrap @if ($row->due_at && $row->due_at->isPast()) text-(--color-danger) @endif">
                                {{ $row->due_at ? DateFormat::formatWithTime($row->due_at) : '—' }}
                            </td>
                            <td class="text-end">
                                <a href="{{ route('approval.inbox.show', $row->id) }}" class="text-(--color-link) hover:underline">
                                    {{ $mine ? __('documents::action.open') : __('documents::action.review') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state :message="__('documents::message.queue_empty')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
