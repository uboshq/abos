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
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.approval') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.approval')" :subtitle="__('documents::message.queue_subtitle')" />
    </x-slot:header>

    <x-ui.list-tabs :tabs="$tabs" :label="__('documents::menu.approval')" />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
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
                            <td class="num whitespace-nowrap">{{ $paper?->document_no ?? '—' }}</td>
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
                        <tr><td colspan="6" class="text-(--color-ink-muted)">{{ __('documents::message.queue_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
