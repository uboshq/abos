{{--
    ⭐ নিয়মের তালিকা — কোন ঘটনায়, কোন শর্তে, কার কাছে; চালু কি না, কোন সংস্করণ (মালিকের স্পেক §৪ "Rules", §৮; ধাপ ৩)।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('module', __('notification::rule.module'),
            collect($modules)->mapWithKeys(fn ($m) => [$m => \App\Core\Support\NotificationKinds::sourceLabel($m)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('state', __('notification::rule.state'),
            collect(['active', 'inactive'])->mapWithKeys(fn ($s) => [$s => __('notification::rule.states.'.$s)])->all(), __('core.notify.any')),
    ]);
@endphp
@php
    // ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ, "নতুন" মাথার সারিতে — কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::rule.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::rule.title')" :info="__('notification::rule.note')" :search-placeholder="__('notification::rule.search')" :export="false" :share="false" :facets="$facetPanel">
            @if ($abosHead)
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('notification.rules.create')">{{ __('notification::rule.new') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>
        </form>

        @unless ($abosHead)
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::rule.note') }}</p>
                <a href="{{ route('notification.rules.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::rule.new') }}</a>
            </div>
        @endunless

        @include('notification::partials.flash')

        {{-- ⓘ ABOS রূপে তালিকার সাদা কার্ড, বাকি তালিকার মতো --}}
        <div @class(['overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)' => $abosHead]) @if ($abosHead) data-boxed @endif>
        @if ($rows->isEmpty())
            @if ($abosHead)
                <x-ui.empty-state :message="__('notification::rule.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::rule.empty') }}</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::rule.name') }}</th>
                            <th class="text-start">{{ __('notification::rule.event') }}</th>
                            <th class="text-start">{{ __('notification::rule.conditions') }}</th>
                            <th class="text-start">{{ __('notification::rule.priority') }}</th>
                            <th class="text-start">{{ __('notification::rule.template') }}</th>
                            <th class="text-start">{{ __('notification::rule.state') }}</th>
                            <th class="text-end">{{ __('notification::rule.version') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $rule)
                            <tr class="border-t border-(--color-border)" data-rule="{{ $rule->id }}">
                                <td><a href="{{ route('notification.rules.edit', $rule) }}" class="hover:underline">{{ $rule->name }}</a></td>
                                <td class="text-2xs">
                                    {{ \App\Core\Support\NotificationKinds::sourceLabel($rule->module) }} ·
                                    {{ __(\App\Core\Support\NotificationKinds::all()[$rule->event] ?? $rule->event) }}
                                </td>
                                <td class="text-2xs">{{ count((array) $rule->conditions) }}</td>
                                <td class="text-2xs">{{ $rule->priority ? __('core.notify.priority.'.$rule->priority) : __('notification::rule.as_module') }}</td>
                                <td class="text-2xs">{{ $rule->template?->name ?? __('notification::rule.as_module') }}</td>
                                <td class="text-2xs">{{ __('notification::rule.states.'.($rule->is_active ? 'active' : 'inactive')) }}</td>
                                <td class="text-end text-2xs tabular-nums">v{{ $rule->version }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
