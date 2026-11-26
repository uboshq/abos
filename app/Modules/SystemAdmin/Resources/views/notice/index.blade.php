{{--
    নোটিশের তালিকা — এক পর্দা, দুই রকম পাঠক।

    ⓘ যাঁর চাবি আছে তিনি সবগুলো দেখেন (মেয়াদ শেষ হওয়াগুলোসহ), বাকিরা
    কেবল নিজেদেরগুলো। ⚠️ দুইটা আলাদা পর্দা বানালে একদিন একটায় নিয়ম
    বদলাত আর অন্যটায় নয়।
--}}
@php
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! ($abosHead && $canManage) ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('status', __('core.table.status'),
            collect(\App\Core\Support\NoticeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
        \App\Core\ListFilter\Facets::pick('priority', __('core.notice.priority_label'),
            collect(\App\Core\Support\NoticePriority::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::notice.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::notice.title')"
                          :subtitle="__('system_admin::notice.note')">
            @if ($canManage)
                <x-slot:actions>
                    <x-ui.button :href="route('system_admin.notice.create')" tone="primary">
                        {{ __('system_admin::notice.new') }}
                    </x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @php $rows = $canManage ? $notices : $mine; @endphp

    @if ($abosHead)
    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('system_admin::notice.title')" :info="__('system_admin::notice.note')"
                          :count="trans_choice('core.count.records', $canManage ? $notices->total() : count($rows), ['count' => $canManage ? $notices->total() : count($rows)])"
                          :search-placeholder="__('system_admin::notice.field_title')" :facets="$facetPanel" :export="false" :share="false"
                          :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest')]">
                @if ($canManage)
                    <x-slot:actions>
                        <x-ui.button :href="route('system_admin.notice.create')" tone="primary" icon="plus">{{ __('system_admin::notice.new') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>
        @if (count($rows) === 0)
            <x-ui.empty-state :message="$canManage ? __('system_admin::notice.none') : __('system_admin::notice.none_for_you')" />
        @else
            @include('system_admin::notice.partials.table')
        @endif
        @if ($canManage)
            <x-ui.pager :rows="$notices" />
        @endif
        <x-ui.list-totals :rows="$canManage ? $notices : count($rows)" />
    </div>
    </x-ui.filter-layout>
    @else
    @if (count($rows) === 0)
        <x-ui.empty-state :message="$canManage
            ? __('system_admin::notice.none')
            : __('system_admin::notice.none_for_you')" />
    @else
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            @include('system_admin::notice.partials.table')
        </div>

        @if ($canManage)
            <x-ui.pager :rows="$notices" />
        @endif
    @endif
    <x-ui.list-totals :rows="$notices" />
    @endif
</x-layouts.app>
