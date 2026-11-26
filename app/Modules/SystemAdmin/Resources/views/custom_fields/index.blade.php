{{--
    নিজস্ব ঘর — কোম্পানির নিজের যোগ করা ঘরগুলো, জিনিস ধরে ধরে।

    ── কেন সব এক পর্দায় ────────────────────────────────────────────────
    "গ্রাহকের ঘর গ্রাহকের পর্দায়" শুনতে যুক্তিসঙ্গত, কিন্তু তাতে মালিককে
    সাতটা পর্দা ঘুরে দেখতে হত তিনি কী কী বানিয়েছেন। এক জায়গায় থাকলে
    পুরো ছবিটা এক নজরে।
--}}
@php
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো। "নতুন ঘর" ব্রাউজারের নিজের পপআপে, ভুল ফিরলে খোলা
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('entity', __('core.custom_field.entity'), collect($entities)->mapWithKeys(fn ($label, $key) => [$key => __('core.source.'.$key)])->all()),
        \App\Core\ListFilter\Facets::pick('type', __('core.custom_field.type'), collect(\App\Core\Services\CustomFieldService::TYPES)->mapWithKeys(fn ($t) => [$t => __('core.custom_field.types.'.$t)])->all()),
        \App\Core\ListFilter\Facets::pick('state', __('core.custom_field.active'), ['on' => __('core.yes'), 'off' => __('core.no')]),
    ]);
    $addOpen = $errors->any();
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.custom_field.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header
            :title="__('core.custom_field.title')"
            :subtitle="__('core.custom_field.subtitle')" />
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
    @if ($abosHead)
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('core.custom_field.title')" :info="__('core.custom_field.subtitle')"
                              :count="trans_choice('core.count.records', $fields->total(), ['count' => $fields->total()])"
                              :search-placeholder="__('core.custom_field.key').', '.__('core.custom_field.label_bn')" :facets="$facetPanel" :share="false" :export="false"
                              :sort="['entity' => __('core.custom_field.entity'), 'newest' => __('core.notify.sort.newest')]">
                    <x-slot:actions>
                        <x-ui.button type="button" tone="primary" icon="plus" popovertarget="pop-field">{{ __('core.custom_field.add') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
        </div>
    @endif
    <div class="space-y-4">
        {{-- নতুন ঘর --}}
        @if ($abosHead)
        <div id="pop-field" @unless ($addOpen) popover="auto" @endunless role="dialog"
             @class(['fixed inset-0 m-auto h-fit max-h-[90vh] w-[56rem] max-w-[94vw] overflow-auto rounded-(--radius-card) shadow-lg', 'z-50' => $addOpen])>
        @endif
        <form method="POST" action="{{ route('system_admin.custom_field.store') }}"
              class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf

            <h2 class="mb-3 font-semibold">{{ __('core.custom_field.add') }}</h2>

            <div x-data="{ type: @js(old('type', 'text')) }" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.select name="entity" :label="__('core.custom_field.entity')"
                             :options="collect($entities)->mapWithKeys(fn ($label, $key) => [$key => __('core.source.'.$key).' — '.$label])"
                             :selected="old('entity')" placeholder="-" required />

                {{-- চাবিটা ঠিকানায় ও ফর্মের ঘরের নামে যায়, তাই ইংরেজি
                     ছোট হাতের অক্ষর ও আন্ডারস্কোর — আর তৈরির পর আর
                     বদলানো যায় না, কারণ বদলালে পুরনো মানগুলো কোন ঘরের
                     তা বলা যেত না --}}
                <x-ui.field name="key" :label="__('core.custom_field.key')"
                            :value="old('key')" :hint="__('core.custom_field.key_hint')" required />

                <x-ui.select name="type" :label="__('core.custom_field.type')"
                             :options="collect(\App\Core\Services\CustomFieldService::TYPES)
                                 ->mapWithKeys(fn ($t) => [$t => __('core.custom_field.types.'.$t)])"
                             :selected="old('type', 'text')" required
                             x-model="type" />

                <x-ui.field name="label_bn" :label="__('core.custom_field.label_bn')"
                            :value="old('label_bn')" required />

                <x-ui.field name="label_en" :label="__('core.custom_field.label_en')"
                            :value="old('label_en')" required />

                <x-ui.field name="sort" type="number" :label="__('core.custom_field.sort')"
                            :value="old('sort', 0)" />

                {{-- বিকল্পগুলো কেবল বাছাইয়ের ঘরে — অন্য ধরনে ঘরটাই
                     দেখানো হয় না, নাহলে কেউ লিখে রেখে ভাবতেন সেটা
                     কোথাও ব্যবহার হচ্ছে --}}
                <label class="block sm:col-span-2 xl:col-span-3" x-show="type === 'select'" x-cloak>
                    <span class="mb-1 block text-sm font-medium">{{ __('core.custom_field.options') }}</span>
                    <textarea name="options" rows="3"
                              class="w-full rounded-(--radius-field) border border-(--color-border)
                                     bg-(--color-surface-app) px-2 py-1.5 text-sm">{{ old('options') }}</textarea>
                    <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                        {{ __('core.custom_field.options_hint') }}
                    </span>
                </label>

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="hidden" name="is_required" value="0">
                    <input type="checkbox" name="is_required" value="1" @checked(old('is_required')) class="size-4">
                    {{ __('core.custom_field.required') }}
                </label>
            </div>

            <x-ui.button type="submit" tone="primary" class="mt-3">{{ __('core.action.save') }}</x-ui.button>
        </form>
        @if ($abosHead)
        </div>
        @endif

        {{-- যা আছে --}}
        @forelse ($fields->getCollection()->groupBy('entity') as $entity => $rows)
            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                    {{ __('core.source.'.$entity) }}
                </h2>

                <div class="divide-y divide-(--color-border)">
                    @foreach ($rows as $field)
                        <form method="POST" action="{{ route('system_admin.custom_field.update', $field) }}"
                              class="grid gap-2 px-4 py-3 sm:grid-cols-2 xl:grid-cols-6">
                            @csrf
                            @method('PUT')

                            <div class="text-sm">
                                <span class="block font-mono text-2xs text-(--color-ink-muted)">{{ $field->key }}</span>
                                <span class="text-2xs">{{ __('core.custom_field.types.'.$field->type) }}</span>
                            </div>

                            <x-ui.field :name="'label_bn'" :label="__('core.custom_field.label_bn')"
                                        :value="$field->label_bn" required />

                            <x-ui.field :name="'label_en'" :label="__('core.custom_field.label_en')"
                                        :value="$field->label_en" required />

                            <x-ui.field :name="'sort'" type="number" :label="__('core.custom_field.sort')"
                                        :value="$field->sort" />

                            <div class="flex flex-col gap-1 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="hidden" name="is_required" value="0">
                                    <input type="checkbox" name="is_required" value="1"
                                           @checked($field->is_required) class="size-4">
                                    {{ __('core.custom_field.required') }}
                                </label>

                                <label class="flex items-center gap-2">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1"
                                           @checked($field->is_active) class="size-4">
                                    {{ __('core.custom_field.active') }}
                                </label>
                            </div>

                            <div class="flex items-end">
                                <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
                            </div>

                            @if ($field->type === 'select')
                                <label class="block sm:col-span-2 xl:col-span-6">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('core.custom_field.options') }}
                                    </span>
                                    <textarea name="options" rows="2"
                                              class="w-full rounded-(--radius-field) border border-(--color-border)
                                                     bg-(--color-surface-app) px-2 py-1.5 text-sm">{{ implode("\n", $field->optionList()) }}</textarea>
                                </label>
                            @endif
                        </form>
                    @endforeach
                </div>
            </section>
        @empty
            <x-ui.empty-state :message="__('core.custom_field.none')" />
        @endforelse

        <x-ui.pager :rows="$fields" />
        <x-ui.list-totals :rows="$fields" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
