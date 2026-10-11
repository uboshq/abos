{{--
    সই কেন্দ্র (§১১; চতুর্থ ধাপ, ৯ অক্টোবর ২০২৬)।

    ⓘ তিন ট্যাব: আমার সইয়ের অপেক্ষায় (ইনবক্সের নিজের প্রশ্ন, কেবল ডকুমেন্টের সই), আমার চাওয়া, আর
    সইয়ের ইতিহাস — কে, কবে, কোন ভার্সনের কোন হ্যাশে। সই দেওয়া, "না" আর সংশোধনে ফেরত ইনবক্সের পাতায়।
--}}
@php
    use App\Core\Support\DateFormat;

    $tabs = collect(['to_sign', 'asked', 'signed'])->map(fn ($key) => [
        'key' => $key,
        'label' => __('documents::message.sign_tab_'.$key),
        'url' => route('documents.signatures', ['tab' => $key]),
        'active' => $tab === $key,
    ])->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.signature') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.signature')" :subtitle="__('documents::message.sign_subtitle')" />
    </x-slot:header>

    <x-ui.list-tabs :tabs="$tabs" :label="__('documents::menu.signature')" />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="overflow-x-auto">
            <table class="ui-list w-full border-collapse text-sm">
                @if ($tab === 'signed')
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('documents::field.document_no') }}</th>
                            <th class="text-start">{{ __('documents::field.name') }}</th>
                            <th class="text-start">{{ __('documents::field.signer') }}</th>
                            <th class="text-end">{{ __('documents::field.level') }}</th>
                            <th class="text-start">{{ __('documents::field.version') }}</th>
                            <th class="text-start">{{ __('documents::field.date') }}</th>
                            <th class="text-start">{{ __('documents::field.file_hash') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr data-signature="{{ $row->id }}">
                                <td class="num whitespace-nowrap">{{ $row->document?->document_no }}</td>
                                <td>
                                    @if ($row->document)
                                        <a href="{{ route('documents.show', $row->document) }}#signatures" class="text-(--color-link) hover:underline">{{ $row->document->name }}</a>
                                    @endif
                                </td>
                                <td>{{ $row->signer?->name ?? '—' }}</td>
                                <td class="num text-end">{{ $row->level }}</td>
                                <td>v{{ $row->version?->label() }}</td>
                                <td class="whitespace-nowrap">{{ DateFormat::formatWithTime($row->signed_at) }}</td>
                                <td class="num text-2xs" title="{{ $row->file_hash }}">{{ substr($row->file_hash, 0, 12) }}…</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state :message="__('documents::message.sign_empty')" /></td></tr>
                        @endforelse
                    </tbody>
                @else
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('documents::field.document_no') }}</th>
                            <th class="text-start">{{ __('documents::field.name') }}</th>
                            <th class="text-start">{{ __('documents::field.version') }}</th>
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
                                <td>{{ $row->payload['version'] ?? '—' }}</td>
                                <td>{{ $row->requesterName() }}</td>
                                <td class="num text-end">{{ $row->current_level }}</td>
                                <td class="whitespace-nowrap">{{ $row->due_at ? DateFormat::formatWithTime($row->due_at) : '—' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('approval.inbox.show', $row->id) }}" class="text-(--color-link) hover:underline">
                                        {{ $tab === 'asked' ? __('documents::action.open') : __('documents::action.review') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state :message="__('documents::message.sign_empty')" /></td></tr>
                        @endforelse
                    </tbody>
                @endif
            </table>
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
