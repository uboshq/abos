{{--
    ছয়টা মাস্টার তালিকার যেকোনো একটা।

    কলামগুলো সংজ্ঞা থেকে আসে (spec.columns), তাই সপ্তম তালিকা যোগ করতে
    এই ফাইলটা ছুঁতে হয় না — কন্ট্রোলারের KINDS-এ একটা সারি লিখলেই হয়।

    ডিফল্ট সারিটা আলাদা করে চিহ্নিত: নতুন লেনদেনে ওটাই আপনা থেকে বসে,
    আর কোনটা বসবে তা না জানলে ভুল ধরা যায় না।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::flag('inactive', __('master_data::action.show_inactive')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($spec['title']) }}</x-slot:title>

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

    @if ($canInstallDefaults)
        {{-- খালি তালিকা — এখান থেকেই শুরু। একক, কর, শর্ত ও কারণ কোড
             ছাড়া প্রথম বিলটাই লেখা যায় না, তাই "নিজে বানান" বলাটা
             কাজ ঠেলে দেওয়া। --}}
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('master_data::message.empty_lists') }}</h2>

            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('master_data::message.empty_lists_note') }}
            </p>

            @can('master_data.manage')
                <form method="POST" action="{{ route('master_data.' . $spec['route'] . '.install') }}"
                      class="mt-4">
                    @csrf
                    <x-ui.button type="submit" tone="primary">
                        {{ __('master_data::action.install_defaults') }}
                    </x-ui.button>
                </form>
            @endcan
        </div>
    @endif

    {{-- ⓘ কলামগুলো একবার — টেবিল আর বারের "কলাম" একই তালিকা পড়ে (অডিট, ২৫ নভেম্বর ২০২৬) --}}
    @php
        $listColumns = array_merge(
                [
                    ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '9rem',
                     'render' => fn ($r) => view('master_data::list.partials.code', ['record' => $r, 'spec' => $spec])],
                    ['key' => 'name_en', 'label' => __('master_data::field.name'),
                     'render' => fn ($r) => view('master_data::list.partials.name', ['record' => $r])],
                ],
                collect($spec['columns'])->map(fn ($column) => [
                    'key' => $column,
                    'label' => __($spec['fields'][$column]['label']),
                    'numeric' => ($spec['fields'][$column]['type'] ?? '') === 'number',
                    'width' => '10rem',
                    'render' => fn ($r) => view('master_data::list.partials.value', [
                        'record' => $r, 'column' => $column,
                        'field' => $spec['fields'][$column], 'options' => $options, 'spec' => $spec,
                    ]),
                ])->all(),
                [
                    ['key' => 'actions', 'label' => __('core.table.action_menu'), 'width' => '9rem',
                     'render' => fn ($r) => view('master_data::list.partials.actions', ['record' => $r, 'spec' => $spec])],
                ],
            );
    @endphp

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            {{-- ⚠️ গোনাটা `total()`, `count()` নয় — `count()` এই পাতার
                 সারি গোনে, তাই তিনশো একক থাকলেও টুলবারে লেখা উঠত
                 "৫০টি"। ছাঁকনি দেওয়ার পর "কয়টা রইল" প্রশ্নের উত্তর
                 ঠিক এই সংখ্যাটাই, আর সেটা পাতার নয়, তালিকার। --}}
            <x-ui.toolbar :title="__($spec['title'])" :count="trans_choice('master_data::message.count', $records->total(), ['count' => $records->total()])" :sort="$sortOptions" :columns="$listColumns" :facets="$facetPanel">
        <x-slot:actions>
            @can('master_data.manage')
                    <x-ui.button tone="primary" icon="plus"
                                 :href="route('master_data.' . $spec['route'] . '.create')">
                        {{ __('master_data::action.new') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="\App\Core\Support\Ui::compact()"
            :empty="$q ? __('core.empty.no_results') : __('master_data::message.none_yet')"
            :rows="$records"
            :columns="$listColumns" />

        <x-ui.pager :rows="$records" />
        <x-ui.list-totals :rows="$records" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
