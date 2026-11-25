{{--
    ⭐ বিজ্ঞপ্তির নিরীক্ষার খাতা — কে, কী, কখন, কোনটায়, ফল (মালিকের স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)। কেবল পড়া।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('action', __('notification::audit.action'),
            collect($actions)->mapWithKeys(fn ($a) => [$a => __('notification::audit.actions.'.$a)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('outcome', __('notification::audit.outcome'),
            collect(['done', 'denied', 'failed'])->mapWithKeys(fn ($o) => [$o => __('notification::audit.outcomes.'.$o)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::audit.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::audit.title')" :info="__('notification::audit.note')" :search="false" :export="false" :share="false" :facets="$facetPanel">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::audit.note') }}</p>

        @if ($rows->isEmpty())
            @if (\App\Core\Support\AbosHead::on())
                <x-ui.empty-state :message="__('notification::audit.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::audit.empty') }}</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::audit.when') }}</th>
                            <th class="text-start">{{ __('notification::audit.actor') }}</th>
                            <th class="text-start">{{ __('notification::audit.action') }}</th>
                            <th class="text-start">{{ __('notification::audit.target') }}</th>
                            <th class="text-start">{{ __('notification::audit.outcome') }}</th>
                            <th class="text-start">{{ __('notification::audit.detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $log)
                            <tr class="border-t border-(--color-border)" data-audit-row="{{ $log->id }}">
                                <td class="whitespace-nowrap text-2xs">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="text-2xs">{{ $log->actor?->name ?? __('notification::audit.system') }}</td>
                                <td class="text-2xs">{{ __('notification::audit.actions.'.$log->action) }}</td>
                                <td class="font-mono text-2xs">{{ $log->target_type ? $log->target_type.' #'.$log->target_id : '—' }}</td>
                                <td class="text-2xs">{{ __('notification::audit.outcomes.'.$log->outcome) }}</td>
                                <td class="font-mono text-2xs">{{ $log->detail ? json_encode($log->detail, JSON_UNESCAPED_UNICODE) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
