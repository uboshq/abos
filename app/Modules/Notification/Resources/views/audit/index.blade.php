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
    $columns = [
        ['key' => 'when', 'label' => __('notification::audit.when'), 'width' => '10rem', 'render' => fn ($log) => $log->created_at?->format('d/m/Y H:i:s')],
        ['key' => 'actor', 'label' => __('notification::audit.actor'), 'render' => fn ($log) => $log->actor?->name ?? __('notification::audit.system')],
        ['key' => 'action', 'label' => __('notification::audit.action'), 'render' => fn ($log) => __('notification::audit.actions.'.$log->action)],
        ['key' => 'target', 'label' => __('notification::audit.target'), 'render' => fn ($log) => $log->target_type ? $log->target_type.' #'.$log->target_id : '—'],
        ['key' => 'outcome', 'label' => __('notification::audit.outcome'), 'render' => fn ($log) => __('notification::audit.outcomes.'.$log->outcome)],
        ['key' => 'detail', 'label' => __('notification::audit.detail'), 'render' => fn ($log) => $log->detail ? json_encode($log->detail, JSON_UNESCAPED_UNICODE) : '—'],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::audit.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::audit.title')" :info="__('notification::audit.note')" :search="false" :export="false" :share="false" :facets="$facetPanel" :columns="$columns"
                      :sort="\App\Core\ListFilter\ListSort::labels(['newest', 'oldest'])">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::audit.note') }}</p>

        {{-- ⭐ পুরো বার — সাধারণ টেবিল, কলাম বাছাই আর সাজানো (মালিক, ২৪ নভেম্বর ২০২৬; ২৭ নভেম্বর) --}}
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::audit.empty')"
                    :row-attributes="fn ($log) => ['data-audit-row' => $log->id]" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
