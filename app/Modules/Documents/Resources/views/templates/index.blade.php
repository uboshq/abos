{{--
    ছাঁচের তালিকা (§২ Document Templates; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — প্রতিটা ছাঁচ, ভরে কাগজ বানানো, বা বদল।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.templates') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.templates')" :subtitle="__('documents::message.templates_subtitle')">
            <x-slot:actions>
                @can('documents.templates')
                    <x-ui.button tone="primary" icon="plus" :href="route('documents.templates.create')">{{ __('documents::action.new_template') }}</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
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

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
