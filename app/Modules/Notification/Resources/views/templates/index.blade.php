{{--
    ⭐ টেমপ্লেটের তালিকা — কোড, নাম, শ্রেণি, প্রকাশিত সংস্করণ (মালিকের স্পেক §৪ "Templates", §৯গ; ধাপ ৩)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::template.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::template.title')" :search-placeholder="__('notification::template.search')" :export="false" :share="false" />

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::template.note') }}</p>
            <a href="{{ route('notification.templates.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::template.new') }}</a>
        </div>

        @include('notification::partials.flash')

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::template.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::template.code') }}</th>
                            <th class="text-start">{{ __('notification::template.name') }}</th>
                            <th class="text-start">{{ __('notification::template.category') }}</th>
                            <th class="text-start">{{ __('notification::template.published') }}</th>
                            <th class="text-end">{{ __('notification::template.versions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $template)
                            <tr class="border-t border-(--color-border)" data-template="{{ $template->id }}">
                                <td class="font-mono text-2xs">{{ $template->code }}</td>
                                <td><a href="{{ route('notification.templates.edit', $template) }}" class="hover:underline">{{ $template->name }}</a></td>
                                <td class="text-2xs">{{ __('core.notify.category.'.$template->category) }}</td>
                                <td class="text-2xs">{{ $template->published ? 'v'.$template->published->version : __('notification::template.draft_only') }}</td>
                                <td class="text-end text-2xs tabular-nums">{{ $template->versions_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
