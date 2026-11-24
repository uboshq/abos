{{-- ⭐ প্রাপক-দলের তালিকা (মালিকের স্পেক §৪ "Recipient Management"; ধাপ ৩) --}}
@php
    // ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ, "নতুন" মাথার সারিতে — কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
    $columns = [
        ['key' => 'name', 'label' => __('notification::group.name'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString('<a href="'.e(route('notification.groups.edit', $r)).'" class="hover:underline">'.e($r->name).'</a>')],
        ...array_map(fn ($kind) => ['key' => 'kind_'.$kind, 'label' => __('notification::rule.kinds.'.$kind), 'numeric' => true, 'render' => fn ($r) => (string) count($r->ids($kind))], \App\Models\NotificationRecipientGroup::KINDS),
        ['key' => 'state', 'label' => __('notification::rule.state'), 'render' => fn ($r) => __('notification::rule.states.'.($r->is_active ? 'active' : 'inactive'))],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('state', __('notification::rule.state'),
            collect(['active', 'inactive'])->mapWithKeys(fn ($s) => [$s => __('notification::rule.states.'.$s)])->all(), __('core.notify.any')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::group.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::group.title')" :info="__('notification::group.note')" :search-placeholder="__('notification::group.name')" :export="$abosHead" :share="false" :columns="$abosHead ? $columns : []" :facets="$facetPanel">
            @if ($abosHead)
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('notification.groups.create')">{{ __('notification::group.new') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>
        </form>

        @unless ($abosHead)
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::group.note') }}</p>
                <a href="{{ route('notification.groups.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::group.new') }}</a>
            </div>
        @endunless

        @include('notification::partials.flash')

        {{-- ⓘ ABOS রূপে তালিকার সাদা কার্ড, বাকি তালিকার মতো --}}
        <div @class(['overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)' => $abosHead]) @if ($abosHead) data-boxed @endif>
        @if ($rows->isEmpty())
            @if ($abosHead)
                <x-ui.empty-state :message="__('notification::group.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::group.empty') }}</p>
            @endif
        @else
            @if ($abosHead)
            <x-ui.table :rows="$rows" :columns="$columns" :row-attributes="fn ($r) => ['data-group' => $r->id]" />
            @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::group.name') }}</th>
                            @foreach (\App\Models\NotificationRecipientGroup::KINDS as $kind)
                                <th class="text-end">{{ __('notification::rule.kinds.'.$kind) }}</th>
                            @endforeach
                            <th class="text-start">{{ __('notification::rule.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $group)
                            <tr class="border-t border-(--color-border)" data-group="{{ $group->id }}">
                                <td><a href="{{ route('notification.groups.edit', $group) }}" class="hover:underline">{{ $group->name }}</a></td>
                                @foreach (\App\Models\NotificationRecipientGroup::KINDS as $kind)
                                    <td class="text-end text-2xs tabular-nums">{{ count($group->ids($kind)) }}</td>
                                @endforeach
                                <td class="text-2xs">{{ __('notification::rule.states.'.($group->is_active ? 'active' : 'inactive')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        @endif
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
