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
    $columns = [
        ['key' => 'when', 'label' => __('notification::delivery.when'), 'width' => '10rem', 'render' => fn ($a) => $a->created_at?->format('d/m/Y H:i:s')],
        ['key' => 'recipient', 'label' => __('notification::delivery.recipient'), 'render' => fn ($a) => $a->job?->user?->name ?? __('notification::delivery.none')],
        ['key' => 'channel', 'label' => __('notification::delivery.channel'), 'render' => fn ($a) => __('notification::channel.names.'.$a->channel)],
        ['key' => 'provider', 'label' => __('notification::delivery.provider'), 'render' => fn ($a) => $a->provider ?? __('notification::delivery.none')],
        ['key' => 'reference', 'label' => __('notification::delivery.reference'), 'render' => fn ($a) => $a->provider_ref ?? __('notification::delivery.none')],
        ['key' => 'outcome', 'label' => __('notification::delivery.outcome'), 'render' => fn ($a) => new \Illuminate\Support\HtmlString(
            e(__('notification::delivery.outcomes.'.$a->outcome)).' <span class="text-(--color-ink-muted)">#'.e((string) $a->attempt).'</span>')],
        ['key' => 'error', 'label' => __('notification::delivery.error'), 'render' => fn ($a) => $a->error ?? __('notification::delivery.none')],
        ['key' => 'duration', 'label' => __('notification::delivery.duration'), 'numeric' => true, 'width' => '6rem', 'render' => fn ($a) => $a->duration_ms],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.logs_title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::delivery.logs_title')" :info="__('notification::delivery.logs_note')" :search="false" :export="false" :share="false" :facets="$facetPanel" :columns="$columns"
                      :sort="\App\Core\ListFilter\ListSort::labels(['newest', 'oldest'])">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.logs_note') }}</p>

        {{-- ⭐ পুরো বার — সাধারণ টেবিল, কলাম বাছাই আর সাজানো (মালিক, ২৪ নভেম্বর ২০২৬; ২৭ নভেম্বর) --}}
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::delivery.empty')"
                    :row-attributes="fn ($attempt) => ['data-attempt' => $attempt->id]" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
