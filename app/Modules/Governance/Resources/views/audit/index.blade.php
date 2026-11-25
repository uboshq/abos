{{--
    অডিট ট্রেইল — সব মডিউলের পরিবর্তন এক তালিকায়।

    প্রতিটা সারি একটা ঘটনা, আর নিচে ছোট করে কী কী ঘর বদলেছে তার সারাংশ।
    বিস্তারিত দেখতে সারিতে যাওয়া যায়, কিন্তু বেশিরভাগ প্রশ্নের উত্তর
    তালিকাতেই মেলে — আর সেটাই উদ্দেশ্য: প্রতিবার ভেতরে ঢুকতে হলে কেউ
    অডিট দেখতই না।
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
         'render' => fn ($t) => \App\Core\Support\DateFormat::formatWithTime($t->created_at)],
        ['key' => 'user', 'label' => __('governance::field.who'), 'width' => '11rem',
         'render' => fn ($t) => view('governance::partials.user-link', [
             'id' => $t->user_id,
             'label' => $t->user?->name ?? __('governance::message.system'),
         ])],
        ['key' => 'action', 'label' => __('governance::field.action'), 'width' => '8rem',
         'render' => fn ($t) => \App\Models\AuditTrail::actionInWords($t->action)],
        ['key' => 'record', 'label' => __('governance::field.record'),
         'render' => fn ($t) => view('governance::audit.partials.record', ['trail' => $t])],
        ['key' => 'changes', 'label' => __('governance::field.changes'),
         'render' => fn ($t) => view('governance::audit.partials.summary', ['trail' => $t])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('user', __('governance::field.who'),
            collect($users)->mapWithKeys(fn ($u) => [(string) $u->id => $u->name])->all(), __('governance::label.all_users')),
        \App\Core\ListFilter\Facets::pick('action', __('governance::field.action'),
            collect($actions)->mapWithKeys(fn ($a) => [$a => \App\Models\AuditTrail::actionInWords($a)])->all(), __('governance::label.all_actions')),
        \App\Core\ListFilter\Facets::pick('module', __('governance::field.module'), collect($modules)->all(), __('governance::label.all_modules')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.audit_trail') }}</x-slot:title>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.read_only') }}
    </p>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{--
            ছাঁকনিগুলো শেয়ার্ড টুলবারের ভেতরে, নিজের ফর্মে নয়।

            আগে এখানে ছয় ঘরের নিজস্ব একটা ফর্ম ছিল — দেখতে অন্য প্রতিটা
            তালিকার চেয়ে আলাদা, আর খোঁজার ঘরটাও নিজে লেখা। যে ব্যবহারকারী
            বিলের তালিকায় বাঁ প্রান্তে Filter By চাপতে শিখেছেন, তাঁকে এই
            এক পর্দার জন্য আলাদা করে শিখতে হত।

            চারটা প্রশ্ন — কে, কী কাজ, কোন মডিউল, কোন সময়ে — ফিল্টার
            প্যানেলে; খোঁজার ঘরটা টুলবারের নিজের।
        --}}
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('governance::menu.audit_trail')" :count="trans_choice('core.count.records', $trails->total(), ['count' => $trails->total()])"
                :sort="$sortOptions"
                :columns="$columns" :search-placeholder="__('governance::label.search_hint')" :facets="$facetPanel">
                            </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="\App\Core\Support\Ui::compact()"
            :empty="array_filter($filters) ? __('governance::message.none') : __('governance::message.nothing_yet')"
            :rows="$trails"
            :columns="$columns" />

        <x-ui.pager :rows="$trails" />
        <x-ui.list-totals :rows="$trails" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
