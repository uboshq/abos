{{--
    ⭐ বিজ্ঞপ্তির আর্কাইভ — খোঁজা, ছাঁকা, আর চাবি থাকলে নামানো (মালিকের স্পেক §৪ "Archive"; ধাপ ৪)।
    ⓘ রাখার মেয়াদ পর্দায় লেখা; প্রতিটা সারি কেন্দ্রের বিস্তারিত পাতায় খোলে।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('module', __('core.notify.module_label'),
            collect($modules)->mapWithKeys(fn ($m) => [$m => \App\Core\Support\NotificationKinds::sourceLabel($m)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('priority', __('core.notify.priority_label'),
            collect(\App\Core\Support\NotificationKinds::PRIORITIES)->mapWithKeys(fn ($p) => [$p => __('core.notify.priority.'.$p)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::archive.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::archive.title')" :info="($archiveDays > 0 ? __('notification::archive.policy_archive', ['days' => $archiveDays]) : __('notification::archive.policy_no_archive')).' '.($retentionDays > 0 ? __('notification::archive.policy_retention', ['days' => max(\App\Core\Notifications\RetentionService::MIN_RETENTION_DAYS, $retentionDays)]) : __('notification::archive.policy_keep'))" :search-placeholder="__('notification::archive.search')" :export="$canExport" :share="false" :facets="$facetPanel">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)" data-archive-policy>
            {{ $archiveDays > 0 ? __('notification::archive.policy_archive', ['days' => $archiveDays]) : __('notification::archive.policy_no_archive') }}
            {{ $retentionDays > 0 ? __('notification::archive.policy_retention', ['days' => max(\App\Core\Notifications\RetentionService::MIN_RETENTION_DAYS, $retentionDays)]) : __('notification::archive.policy_keep') }}
        </p>

        {{-- ⓘ সাধারণ টেবিল — রপ্তানি (CSV/XLSX) এটাই ধরে, চাবি থাকলে --}}
        @php
            $columns = [
                ['key' => 'title', 'label' => __('core.notify.col.title'), 'render' => fn ($e) => $e->title],
                ['key' => 'module', 'label' => __('core.notify.col.source'), 'width' => '9rem', 'render' => fn ($e) => \App\Core\Support\NotificationKinds::sourceLabel($e->module)],
                ['key' => 'priority', 'label' => __('core.notify.col.priority'), 'width' => '6rem', 'render' => fn ($e) => __('core.notify.priority.'.$e->priority)],
                ['key' => 'branch', 'label' => __('notification::archive.branch'), 'width' => '9rem', 'render' => fn ($e) => $e->branch?->name_bn ?? '—'],
                ['key' => 'recipients', 'label' => __('notification::archive.recipients'), 'numeric' => true, 'width' => '6rem', 'render' => fn ($e) => (string) $e->recipients],
                ['key' => 'created_at', 'label' => __('core.notify.col.when'), 'width' => '8rem', 'render' => fn ($e) => \App\Core\Support\DateFormat::formatWithTime($e->created_at)],
                ['key' => 'archived_at', 'label' => __('notification::archive.archived_at'), 'width' => '8rem', 'render' => fn ($e) => \App\Core\Support\DateFormat::formatWithTime($e->archived_at)],
            ];
        @endphp
        <div data-archive-table>
            <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::archive.empty')"
                        :view-url="fn ($e) => route('notification.center.show', $e)" />
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
