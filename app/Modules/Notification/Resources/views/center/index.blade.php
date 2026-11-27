{{--
    ⭐ বিজ্ঞপ্তি কেন্দ্র — কোম্পানির সব খবর (মালিকের স্পেক §২, §৪; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
    ⓘ প্রতিটা সারি একটা ঘটনা: কতজন পেলেন, কতজন পড়লেন। শিরোনামে চাপলে কে কে পেলেন আর পড়লেন।
    ⛔ নাগালের শাখার খবরই ([[NotificationCenterController]])। আর্কাইভের বোতাম কেবল যাঁর `notification.manage` আছে।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('module', __('core.notify.module_label'),
            collect($modules)->mapWithKeys(fn ($m) => [$m => \App\Core\Support\NotificationKinds::sourceLabel($m)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('category', __('core.notify.category_label'),
            collect(\App\Core\Support\NotificationKinds::CATEGORIES)->mapWithKeys(fn ($c) => [$c => __('core.notify.category.'.$c)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('priority', __('core.notify.priority_label'),
            collect(\App\Core\Support\NotificationKinds::PRIORITIES)->mapWithKeys(fn ($p) => [$p => __('core.notify.priority.'.$p)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
        \App\Core\ListFilter\Facets::flag('archived', __('notification::center.archived_only')),
    ]);
    $columns = array_values(array_filter([
        $canManage ? ['key' => 'pick', 'label' => '', 'width' => '2rem',
            'render' => fn ($event) => new \Illuminate\Support\HtmlString('<input type="checkbox" name="ids[]" value="'.e((string) $event->id).'" aria-label="'.e((string) $event->title).'">')] : null,
        ['key' => 'title', 'label' => __('notification::center.col_title'), 'render' => fn ($event) => new \Illuminate\Support\HtmlString(
            '<a href="'.e(route('notification.center.show', $event)).'" class="hover:underline">'.e((string) $event->title).'</a>'
            .'<span class="block text-2xs text-(--color-ink-muted)">'.e(__('core.notify.category.'.$event->category)).'</span>')],
        ['key' => 'source', 'label' => __('notification::center.col_source'), 'render' => fn ($event) => \App\Core\Support\NotificationKinds::sourceLabel($event->module)],
        ['key' => 'priority', 'label' => __('notification::center.col_priority'), 'render' => fn ($event) => __('core.notify.priority.'.$event->priority)],
        ['key' => 'branch', 'label' => __('notification::center.col_branch'), 'render' => fn ($event) => $event->branch?->name() ?? __('notification::center.no_branch')],
        ['key' => 'recipients', 'label' => __('notification::center.col_recipients'), 'numeric' => true, 'render' => fn ($event) => $event->recipients],
        ['key' => 'read', 'label' => __('notification::center.col_read'), 'numeric' => true, 'render' => fn ($event) => $event->read_count],
        ['key' => 'when', 'label' => __('notification::center.col_when'), 'width' => '10rem', 'render' => fn ($event) => \App\Core\Support\DateFormat::formatWithTime($event->created_at)],
    ]));
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::center.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::center.title')" :info="__('notification::center.note')"
                      :search-placeholder="__('notification::center.search')"
                      :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest'), 'priority' => __('core.notify.sort.priority'), 'recipients' => __('notification::center.sort_recipients')]"
                      :export="false" :share="false" :facets="$facetPanel" :columns="$columns">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::center.note') }}</p>

        @if (session('saved'))
            <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
        @endif

        @if ($rows->isEmpty())
            @if (\App\Core\Support\AbosHead::on())
                <x-ui.empty-state :message="__('notification::center.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::center.empty') }}</p>
            @endif
        @else
            <form method="POST" action="{{ route('notification.center.archive') }}" data-center-archive>
                @csrf

                @if ($canManage)
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('notification::center.selected') }}:</span>
                        <button type="submit" name="action" value="{{ empty($filters['archived']) ? 'archive' : 'restore' }}"
                                class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs hover:bg-(--color-surface-hover)">
                            {{ __('notification::center.'.(empty($filters['archived']) ? 'archive' : 'restore')) }}
                        </button>
                    </div>
                @endif

                {{-- ⭐ পুরো বার — সাধারণ টেবিল, কলাম বাছাই (মালিক, ২৪ নভেম্বর ২০২৬; ২৭ নভেম্বর); বাছার ঘর আগের মতোই এই ফর্মের --}}
                <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::center.empty')"
                            :row-attributes="fn ($event) => ['data-center-event' => $event->id]" />
            </form>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
