{{--
    ছাঁচের তালিকা (§২ Document Templates; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — প্রতিটা ছাঁচ, ভরে কাগজ বানানো, বা বদল।
--}}
@php
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $columns = [
        ['key' => 'code', 'label' => __('documents::field.code'), 'width' => '9rem', 'render' => fn ($r) => (string) $r->code],
        ['key' => 'title', 'label' => __('documents::field.template_title'), 'wrap' => true, 'render' => fn ($r) => (string) $r->title],
        ['key' => 'doc_type', 'label' => __('documents::field.doc_type'), 'render' => fn ($r) => $choices->typeName($r->doc_type)],
        ['key' => 'folder', 'label' => __('documents::field.folder'), 'render' => fn ($r) => $choices->folderName($r->folder)],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'render' => fn ($r) => view('documents::partials.template-actions', ['row' => $r])],
    ];
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('doc_type', __('documents::field.doc_type'), $choices->types(true)),
        \App\Core\ListFilter\Facets::pick('folder', __('documents::field.folder'), $choices->folders(true)),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.templates') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.templates')" :subtitle="__('documents::message.templates_subtitle')">
            <x-slot:actions>
                @can('documents.templates')
                    <x-ui.button tone="primary" icon="plus" :href="route('documents.templates.create')">{{ __('documents::action.new_template') }}</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($abosHead)
                <x-ui.toolbar :title="__('documents::menu.templates')" :info="__('documents::message.templates_subtitle')" :search-placeholder="__('documents::field.code').', '.__('documents::field.template_title')" :share="false" :sort="['title' => __('documents::field.template_title'), 'code' => __('documents::field.code')]" :columns="$columns" :facets="$facetPanel">
                    <x-slot:actions>
                        @can('documents.templates')
                            <x-ui.button tone="primary" icon="plus" :href="route('documents.templates.create')">{{ __('documents::action.new_template') }}</x-ui.button>
                        @endcan
                    </x-slot:actions>
                </x-ui.toolbar>
            @else
                <x-ui.filter-bar :facets="$facetPanel" />
            @endif
        </form>
        @if ($abosHead)
        {{-- ⭐ খালি তালিকায় পরের কাজ — নতুন ছাঁচ, যাঁর চাবি আছে; খোঁজা বা ছাঁকনিতে খালি হলে টেবিল নিজেই বলে --}}
        @if ($rows->isEmpty() && ! request()->hasAny(['q', 'doc_type', 'folder']))
            <x-ui.empty-state :message="__('documents::message.no_templates')"
                :action="auth()->user()?->can('documents.templates') ? ['url' => route('documents.templates.create'), 'label' => __('documents::action.new_template')] : null" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" :empty="__('documents::message.no_templates')"
                        :row-attributes="fn ($r) => ['data-template' => $r->code, 'class' => $r->is_active ? '' : 'opacity-60']" />
        @endif
        @else
        <table class="ui-list w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th class="text-start">{{ __('documents::field.code') }}</th>
                    <th class="text-start">{{ __('documents::field.template_title') }}</th>
                    <th class="text-start">{{ __('documents::field.doc_type') }}</th>
                    <th class="text-start">{{ __('documents::field.folder') }}</th>
                    <th class="text-end">{{ __('core.table.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr data-template="{{ $row->code }}" @class(['opacity-60' => ! $row->is_active])>
                        <td class="num">{{ $row->code }}</td>
                        <td>{{ $row->title }}</td>
                        <td>{{ $choices->typeName($row->doc_type) }}</td>
                        <td>{{ $choices->folderName($row->folder) }}</td>
                        <td class="text-end">
                            <div class="flex flex-wrap justify-end gap-3">
                                @if ($row->is_active)
                                    @can('documents.upload')
                                        <a href="{{ route('documents.templates.fill', $row) }}" class="text-(--color-link) hover:underline">{{ __('documents::action.use_template') }}</a>
                                    @endcan
                                @endif
                                @can('documents.templates')
                                    <a href="{{ route('documents.templates.edit', $row) }}" class="text-(--color-link) hover:underline">{{ __('documents::action.edit_template') }}</a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    {{-- ⭐ খালি তালিকায় পরের কাজ — নতুন ছাঁচ, যাঁর চাবি আছে (documents রিভিউ; [[x-ui.empty-state]]) --}}
                    <tr><td colspan="5"><x-ui.empty-state :message="__('documents::message.no_templates')"
                        :action="auth()->user()?->can('documents.templates') ? ['url' => route('documents.templates.create'), 'label' => __('documents::action.new_template')] : null" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
