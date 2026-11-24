{{--
    ⭐ খাতা — কে কী করল, কী খুঁজল, কোন পাতা খুলল, কী নামাল, কী বদলাল, কখন ঢুকল; কার আইডি থেকে, কখন, কোথা থেকে, কীভাবে
    (মালিক, ১০ ও ১১ নভেম্বর ২০২৬; পূর্ণ পরিকল্পনা অংশ ১২)। ⓘ তিন খাতা এক সময়-রেখায় ([[AssistantController::audit()]])।
    ⓘ কেবল মালিকের চাবি; ছাঁকা যায় — তারিখ, ধরন, ব্যক্তি, লেখা, IP; বদলানো বা মোছা যায় না।
--}}
@php
    $tone = [
        'change' => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
        'login' => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
        'search' => 'bg-(--color-surface-selected) text-(--color-link)',
        'blocked' => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
    ];
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
        \App\Core\ListFilter\Facets::pick('kind', __('assistant::audit.kind'),
            collect($kinds)->mapWithKeys(fn ($kind) => [$kind => __('assistant::audit.kinds.'.$kind)])->all(),
            __('assistant::audit.any')),
        \App\Core\ListFilter\Facets::pick('user_id', __('assistant::audit.who'),
            collect($people)->mapWithKeys(fn ($person) => [(string) $person->id => $person->name])->all(),
            __('assistant::audit.anyone')),
        \App\Core\ListFilter\Facets::text('q', __('assistant::audit.search')),
        \App\Core\ListFilter\Facets::text('ip', 'IP'),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('assistant::audit.title', ['name' => $name]) }}</x-slot:title>

    @if (\App\Core\Support\AbosHead::on())
        {{-- ⭐ এক সারি: নাম ⓘ · ছাঁকনি (মালিক, ২৪ নভেম্বর ২০২৬) — কেবল তিন ABOS রূপে --}}
        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
        <div class="space-y-4" data-assistant-audit>
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('assistant::audit.title', ['name' => $name])" :info="__('assistant::audit.note')" :search="false" :export="false" :share="false" :facets="$facetPanel" />
            </form>

            @include('assistant::partials.audit-rows')
        </div>
        </x-ui.filter-layout>
    @else
    <div class="space-y-4" data-assistant-audit>
        <x-ui.toolbar :title="__('assistant::audit.title', ['name' => $name])" :info="__('assistant::audit.note')" :search="false" :export="false" :share="false" />

        {{-- ⭐ ব্যাখ্যা নামের পাশে ⓘ-এ, লাইনটা "ABOS"/"Dynamic"-এ লুকায় ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত) --}}
        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('assistant::audit.note') }}</p>

        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
            <form id="list-form" method="GET" class="contents">
                <x-ui.filter-bar :facets="$facetPanel" />
            </form>
        </x-ui.filter-layout>

        @include('assistant::partials.audit-rows')
    </div>
    @endif
</x-layouts.app>
