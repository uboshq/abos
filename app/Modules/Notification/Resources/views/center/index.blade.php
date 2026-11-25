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
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::center.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::center.title')" :info="__('notification::center.note')"
                      :search-placeholder="__('notification::center.search')"
                      :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest'), 'priority' => __('core.notify.sort.priority'), 'recipients' => __('notification::center.sort_recipients')]"
                      :export="false" :share="false" :facets="$facetPanel">
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

                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                @if ($canManage)
                                    <th class="w-8"></th>
                                @endif
                                <th class="text-start">{{ __('notification::center.col_title') }}</th>
                                <th class="text-start">{{ __('notification::center.col_source') }}</th>
                                <th class="text-start">{{ __('notification::center.col_priority') }}</th>
                                <th class="text-start">{{ __('notification::center.col_branch') }}</th>
                                <th class="text-end">{{ __('notification::center.col_recipients') }}</th>
                                <th class="text-end">{{ __('notification::center.col_read') }}</th>
                                <th class="text-start">{{ __('notification::center.col_when') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $event)
                                <tr class="border-t border-(--color-border)" data-center-event="{{ $event->id }}">
                                    @if ($canManage)
                                        <td><input type="checkbox" name="ids[]" value="{{ $event->id }}" aria-label="{{ $event->title }}"></td>
                                    @endif
                                    <td>
                                        <a href="{{ route('notification.center.show', $event) }}" class="hover:underline">{{ $event->title }}</a>
                                        <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.notify.category.'.$event->category) }}</span>
                                    </td>
                                    <td class="text-2xs">{{ \App\Core\Support\NotificationKinds::sourceLabel($event->module) }}</td>
                                    <td class="text-2xs">{{ __('core.notify.priority.'.$event->priority) }}</td>
                                    <td class="text-2xs">{{ $event->branch?->name() ?? __('notification::center.no_branch') }}</td>
                                    <td class="num text-end tabular-nums">{{ $event->recipients }}</td>
                                    <td class="num text-end tabular-nums">{{ $event->read_count }}</td>
                                    <td class="whitespace-nowrap text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::formatWithTime($event->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
