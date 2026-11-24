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

    $columns = $tab === 'signed' ? [
        ['key' => 'document_no', 'label' => __('documents::field.document_no'), 'render' => fn ($r) => view('documents::partials.queue-paper', ['paper' => $r->document, 'party' => $parties[$r->document_id] ?? null])],
        ['key' => 'name', 'label' => __('documents::field.name'), 'wrap' => true, 'render' => fn ($r) => $r->document
            ? new \Illuminate\Support\HtmlString('<a href="'.e(route('documents.show', $r->document)).'#signatures" class="text-(--color-link) hover:underline">'.e($r->document->name).'</a>') : ''],
        ['key' => 'signer', 'label' => __('documents::field.signer'), 'render' => fn ($r) => $r->signer?->name ?? '—'],
        ['key' => 'level', 'label' => __('documents::field.level'), 'numeric' => true, 'render' => fn ($r) => (string) $r->level],
        ['key' => 'version', 'label' => __('documents::field.version'), 'render' => fn ($r) => 'v'.$r->version?->label()],
        ['key' => 'date', 'label' => __('documents::field.date'), 'width' => '11rem', 'render' => fn ($r) => DateFormat::formatWithTime($r->signed_at)],
        ['key' => 'hash', 'label' => __('documents::field.file_hash'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString('<span class="num text-2xs" title="'.e($r->file_hash).'">'.e(substr($r->file_hash, 0, 12)).'…</span>')],
    ] : [
        ['key' => 'document_no', 'label' => __('documents::field.document_no'), 'render' => fn ($r) => view('documents::partials.queue-paper', ['paper' => $documents[$r->approvable_id] ?? null, 'party' => $parties[$r->approvable_id] ?? null])],
        ['key' => 'name', 'label' => __('documents::field.name'), 'wrap' => true, 'render' => fn ($r) => ($documents[$r->approvable_id] ?? null)?->name ?? (string) $r->requested_reason],
        ['key' => 'version', 'label' => __('documents::field.version'), 'render' => fn ($r) => (string) ($r->payload['version'] ?? '—')],
        ['key' => 'requester', 'label' => __('documents::field.requester'), 'render' => fn ($r) => (string) $r->requesterName()],
        ['key' => 'level', 'label' => __('documents::field.level'), 'numeric' => true, 'render' => fn ($r) => (string) $r->current_level],
        ['key' => 'due', 'label' => __('documents::field.due_date'), 'width' => '11rem', 'render' => fn ($r) => $r->due_at ? DateFormat::formatWithTime($r->due_at) : '—'],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString('<a href="'.e(route('approval.inbox.show', $r->id)).'" class="text-(--color-link) hover:underline">'.e($tab === 'asked' ? __('documents::action.open') : __('documents::action.review')).'</a>')],
    ];

    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates($tab === 'signed' ? __('documents::field.date') : __('documents::field.due_date')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.signature') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.signature')" :subtitle="__('documents::message.sign_subtitle')" />
    </x-slot:header>
    @endunless

    <x-ui.list-tabs :tabs="$tabs" :label="__('documents::menu.signature')" />

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($abosHead)
                <x-ui.toolbar :title="__('documents::menu.signature')" :info="__('documents::message.sign_subtitle')" :search-placeholder="__('documents::field.document_no').', '.__('documents::field.name')" :share="false" :columns="$columns" :facets="$facetPanel" :quiet="['tab']"></x-ui.toolbar>
            @else
                <x-ui.filter-bar :facets="$facetPanel" />
            @endif
        </form>
        @if ($abosHead)
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('documents::message.sign_empty')"
                    :row-attributes="fn ($r) => [$tab === 'signed' ? 'data-signature' : 'data-approval' => $r->id]" />
        @else
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
                                <td class="whitespace-nowrap">
                                    {{-- ⭐ নম্বরের পাশে পক্ষ (মালিক, ১৯ অক্টোবর ২০২৬; [[DocumentParties]]) --}}
                                    <x-ui.party-cell :party="$parties[$row->document_id] ?? null"><span class="num">{{ $row->document?->document_no }}</span></x-ui.party-cell>
                                </td>
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
                                <td class="whitespace-nowrap">
                                    {{-- ⭐ নম্বরের পাশে পক্ষ (মালিক, ১৯ অক্টোবর ২০২৬) — জোড়া রেকর্ড থেকে, পাতার একবারে ([[DocumentParties]]) --}}
                                    <x-ui.party-cell :party="$parties[$row->approvable_id] ?? null"><span class="num">{{ $paper?->document_no ?? '—' }}</span></x-ui.party-cell>
                                </td>
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
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
