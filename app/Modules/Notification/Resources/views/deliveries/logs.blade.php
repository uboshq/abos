{{--
    ⭐ চেষ্টার লগ — প্রতিটা চেষ্টা: কখন, কার কাছে, মাধ্যম, প্রোভাইডার, রেফারেন্স, ফল, ভুল, সময় (মালিকের স্পেক §৪, §১৪; ধাপ ২)।
    ⛔ ভুলের লেখা আগেই গোপন জিনিস ছেঁটে রাখা ([[DeliveryResult::clean()]]) — এখানে কেবল দেখানো।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('channel', __('notification::delivery.channel'),
            collect(\App\Models\NotificationChannel::ALL)->mapWithKeys(fn ($c) => [$c => __('notification::channel.names.'.$c)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('outcome', __('notification::delivery.outcome'),
            collect(['sent', 'transient', 'permanent'])->mapWithKeys(fn ($o) => [$o => __('notification::delivery.outcomes.'.$o)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.logs_title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::delivery.logs_title')" :info="__('notification::delivery.logs_note')" :search="false" :export="false" :share="false" :facets="$facetPanel">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.logs_note') }}</p>

        @if ($rows->isEmpty())
            @if (\App\Core\Support\AbosHead::on())
                <x-ui.empty-state :message="__('notification::delivery.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.empty') }}</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::delivery.when') }}</th>
                            <th class="text-start">{{ __('notification::delivery.recipient') }}</th>
                            <th class="text-start">{{ __('notification::delivery.channel') }}</th>
                            <th class="text-start">{{ __('notification::delivery.provider') }}</th>
                            <th class="text-start">{{ __('notification::delivery.reference') }}</th>
                            <th class="text-start">{{ __('notification::delivery.outcome') }}</th>
                            <th class="text-start">{{ __('notification::delivery.error') }}</th>
                            <th class="text-end">{{ __('notification::delivery.duration') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $attempt)
                            <tr class="border-t border-(--color-border)" data-attempt="{{ $attempt->id }}">
                                <td class="whitespace-nowrap text-2xs">{{ $attempt->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="text-2xs">{{ $attempt->job?->user?->name ?? __('notification::delivery.none') }}</td>
                                <td class="text-2xs">{{ __('notification::channel.names.'.$attempt->channel) }}</td>
                                <td class="text-2xs">{{ $attempt->provider ?? __('notification::delivery.none') }}</td>
                                <td class="font-mono text-2xs">{{ $attempt->provider_ref ?? __('notification::delivery.none') }}</td>
                                <td class="text-2xs">{{ __('notification::delivery.outcomes.'.$attempt->outcome) }} <span class="text-(--color-ink-muted)">#{{ $attempt->attempt }}</span></td>
                                <td class="text-2xs">{{ $attempt->error ?? __('notification::delivery.none') }}</td>
                                <td class="text-end text-2xs tabular-nums">{{ $attempt->duration_ms }}</td>
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
