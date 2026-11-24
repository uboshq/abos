{{--
    ডকুমেন্টের অডিট ট্রেইল (§১৮; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — কে · কী · কোন কাগজ · কবে · IP · যন্ত্র।

    ⛔ কোনো মোছার বোতাম নেই, আর থাকবেও না।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Models\AuditTrail;

    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $columns = [
        ['key' => 'when', 'label' => __('documents::field.when'), 'width' => '11rem', 'render' => fn ($r) => DateFormat::formatWithTime($r->created_at)],
        ['key' => 'who', 'label' => __('documents::field.who'), 'render' => fn ($r) => $r->user?->name ?? '—'],
        ['key' => 'what', 'label' => __('documents::field.what'), 'render' => fn ($r) => AuditTrail::actionInWords($r->action)],
        ['key' => 'document_no', 'label' => __('documents::field.document_no'), 'width' => '9rem', 'render' => fn ($r) => (string) $r->document_no],
        ['key' => 'name', 'label' => __('documents::field.name'), 'wrap' => true, 'render' => fn ($r) => (string) $r->label],
        ['key' => 'note', 'label' => __('documents::field.note'), 'wrap' => true, 'render' => fn ($r) => (string) $r->reason],
        ['key' => 'ip', 'label' => __('documents::field.ip'), 'width' => '9rem', 'render' => fn ($r) => $r->ip_address ?? '—'],
        ['key' => 'device', 'label' => __('documents::field.device'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString(
            '<span class="block max-w-[14rem] truncate text-2xs text-(--color-ink-muted)" title="'.e($r->user_agent).'">'.e($r->user_agent ?? '—').'</span>')],
    ];
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
        \App\Core\ListFilter\Facets::pick('user_id', __('documents::field.who'), $people),
        \App\Core\ListFilter\Facets::text('action', __('documents::field.what'), __('documents::message.audit_action_hint')),
    ], shown: ['from' => (string) $filters['from'], 'to' => (string) $filters['to']]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.audit') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.audit')" :subtitle="__('documents::message.audit_subtitle')" />
    </x-slot:header>
    @endunless

    <x-ui.filter-layout :filters="$abosHead ? $facetPanel : \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        @if ($abosHead)
        <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('documents::menu.audit')" :info="__('documents::message.audit_subtitle')" :search-placeholder="__('documents::field.document_no').', '.__('documents::field.name')" :export="false" :share="false" :columns="$columns" :facets="$facetPanel"></x-ui.toolbar>
        </form>
        @endif
        @unless ($abosHead)
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-(--color-border) px-4 py-3">
            <x-ui.field name="from" type="date" :label="__('documents::field.date_from')" :value="$filters['from']" />
            <x-ui.field name="to" type="date" :label="__('documents::field.date_to')" :value="$filters['to']" />
            <x-ui.select name="user_id" :label="__('documents::field.who')" :options="$people" :selected="$filters['user_id']" placeholder="—" />
            <x-ui.field name="action" :label="__('documents::field.what')" :value="$filters['action']" maxlength="24"
                        :hint="__('documents::message.audit_action_hint')" />
            <x-ui.button type="submit" icon="search">{{ __('documents::action.find') }}</x-ui.button>
        </form>
        @endunless

        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('core.empty.no_results')" :row-attributes="fn ($r) => ['data-audit' => $r->action]" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
