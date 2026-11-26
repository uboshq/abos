{{--
    নোটিশের ধরন — আর তার নিজের ডিফল্ট।

    ⓘ ক্যাটাগরি বলে "এই ধরনের নোটিশ সাধারণত কতটা জরুরি, আর সই লাগে কি
    না"। ⚠️ ডিফল্টটা বসানোর মূল কারণ ভুলে যাওয়া: "নিরাপত্তা সতর্কতা"
    লিখতে গিয়ে প্রতিবার হাতে জরুরি বাছতে হলে কোনো একদিন কেউ ভুলতেন।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('core.table.code'), 'width' => '8rem'],
        ['key' => 'name', 'label' => __('core.table.name'),
         'render' => fn ($r) => $r->name()],
        ['key' => 'default_priority', 'label' => __('core.notice.priority_label'), 'width' => '9rem',
         'render' => fn ($r) => $r->default_priority?->label() ?? '—'],
        ['key' => 'needs_approval', 'label' => __('core.notice.needs_approval'), 'width' => '9rem',
         'render' => fn ($r) => $r->needs_approval ? __('core.yes') : __('core.no')],
    ];
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('priority', __('core.notice.priority_label'), $priorities->all()),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notice.categories_title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('core.notice.categories_title')" />
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('core.notice.category_new') }}</h2>

            <form method="POST" action="{{ route('system_admin.notice.category.store') }}" class="space-y-3">
                @csrf

                <x-ui.field name="code" :label="__('core.table.code')" required maxlength="32" />
                {{-- ⓘ দুটো ঘরের নামই "নাম" ছিল — কোনটা কোন ভাষা বোঝা যেত না (সিস্টেম পর্দার নকশা §৫, ১০ অক্টোবর ২০২৬) --}}
                <x-ui.field name="name_en" :label="__('master_data::field.name_en')" required maxlength="120" />
                <x-ui.field name="name_bn" :label="__('master_data::field.name_bn')" maxlength="120" />

                <x-ui.select name="default_priority"
                             :label="__('core.notice.priority_label')"
                             :options="$priorities"
                             value="normal" />

                <label class="flex items-start gap-2">
                    {{-- ⓘ চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, তাই
                         লুকানো শূন্যটা দরকার — নাহলে টিক তোলা যেত না --}}
                    <input type="hidden" name="needs_approval" value="0">
                    <input type="checkbox" name="needs_approval" value="1" class="mt-1 size-4">
                    <span class="text-sm">{{ __('core.notice.needs_approval') }}</span>
                </label>

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            @if ($abosHead)
                <form id="list-form" method="GET" class="contents">
                    <x-ui.toolbar :title="__('core.notice.categories_title')" :count="trans_choice('core.count.records', $categories->total(), ['count' => $categories->total()])"
                                  :search-placeholder="__('core.table.code').', '.__('core.table.name')" :columns="$columns" :facets="$facetPanel" :share="false"
                                  :sort="['code' => __('core.table.code'), 'name' => __('core.table.name')]" />
                </form>
            @endif
            <x-ui.table
                :empty="__('core.notice.no_categories')"
                :rows="$categories"
                compact
                :columns="$columns" />

            <x-ui.pager :rows="$categories" />
            <x-ui.list-totals :rows="$categories" />
        </section>
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
