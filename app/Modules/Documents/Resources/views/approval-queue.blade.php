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
    $columns = [
        ['key' => 'document_no', 'label' => __('documents::field.document_no'),
         'render' => fn ($r) => view('documents::partials.queue-paper', ['paper' => $documents[$r->approvable_id] ?? null, 'party' => $parties[$r->approvable_id] ?? null])],
        ['key' => 'name', 'label' => __('documents::field.name'), 'wrap' => true, 'render' => fn ($r) => ($documents[$r->approvable_id] ?? null)?->name ?? (string) $r->requested_reason],
        ['key' => 'requester', 'label' => __('documents::field.requester'), 'render' => fn ($r) => (string) $r->requesterName()],
        ['key' => 'level', 'label' => __('documents::field.level'), 'numeric' => true, 'render' => fn ($r) => (string) $r->current_level],
        ['key' => 'due', 'label' => __('documents::field.due_date'), 'render' => fn ($r) => $r->due_at
            ? new \Illuminate\Support\HtmlString('<span class="whitespace-nowrap'.($r->due_at->isPast() ? ' text-(--color-danger)' : '').'">'.e(DateFormat::formatWithTime($r->due_at)).'</span>')
            : '—'],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString(
            '<a href="'.e(route('approval.inbox.show', $r->id)).'" class="text-(--color-link) hover:underline">'.e($mine ? __('documents::action.open') : __('documents::action.review')).'</a>')],
    ];
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
                <x-ui.toolbar :title="__('documents::menu.approval')" :info="__('documents::message.queue_subtitle')" :search-placeholder="__('documents::field.document_no').', '.__('documents::field.name')" :share="false" :columns="$columns" :facets="$facetPanel" :quiet="['mine']"></x-ui.toolbar>
            @else
                <x-ui.filter-bar :facets="$facetPanel" />
            @endif
        </form>
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('documents::message.queue_empty')" :row-attributes="fn ($r) => ['data-approval' => $r->id]" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
