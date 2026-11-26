{{--
    স্থায়ী সম্পদের খাতা।

    উপরে মাস শেষের দৌড়, নিচে তালিকা — ক্রমটা ইচ্ছাকৃত। এই পাতাটার
    সাথে মানুষের দেখা হয় মাসে একবার, আর তখন কাজটা একটাই: গত মাসের
    অবচয় বসানো। তালিকাটা তার পরের প্রশ্ন।
--}}
@php
    /*
        কলামগুলো এখানে, স্লটে নয়।

        `x-ui.table` স্লট পড়ে না — সে `:rows` আর `:columns` থেকে নিজে
        সারি আঁকে, আর প্রতিটা কলামে `key` ও `label` দুইটাই চায়। প্রথম
        লেখায় ভেতরে হাতে `<tr>` বসানো ছিল, ফলে পর্দাটা খালি অবস্থায়
        ঠিক চলত আর প্রথম সম্পদ যোগ হওয়ামাত্র ৫০০ দিত।
    */
    $columns = [
        [
            'key' => 'name',
            'label' => __('accounts::asset.name'),
            'render' => fn ($a) => view('accounts::asset.partials.name', ['asset' => $a]),
        ],
        [
            'key' => 'account',
            'label' => __('accounts::asset.account'),
            'render' => fn ($a) => $a->assetAccount?->label(),
        ],
        // ⭐ শ্রেণি — স্থায়ী সম্পদ ধাপ ১
        [
            'key' => 'category',
            'label' => __('accounts::asset.category'),
            'width' => '10rem',
            'render' => fn ($a) => $a->category?->name() ?? '—',
        ],
        [
            'key' => 'acquired_on',
            'label' => __('accounts::asset.acquired_on'),
            'width' => '9rem',
            'render' => fn ($a) => \App\Core\Support\DateFormat::format($a->acquired_on),
        ],
        [
            'key' => 'cost',
            'total' => 'money',
            'label' => __('accounts::asset.cost'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->cost]),
        ],
        [
            'key' => 'accumulated',
            'label' => __('accounts::asset.accumulated'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->accumulated()]),
        ],
        [
            'key' => 'book_value',
            'label' => __('accounts::asset.book_value'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->bookValue()]),
        ],
        [
            'key' => 'status',
            'label' => __('accounts::asset.status'),
            'width' => '8rem',
            'render' => fn ($a) => view('accounts::asset.partials.status', ['asset' => $a]),
        ],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('category', __('accounts::asset.category'),
            collect($categories)->mapWithKeys(fn ($category) => [$category->id => $category->name()])->all(),
            __('accounts::asset.all_categories')),
        \App\Core\ListFilter\Facets::pick('status', __('accounts::asset.status'),
            collect(\App\Modules\Accounts\Models\FixedAsset::STATUSES)->mapWithKeys(fn ($status) => [$status => __('accounts::asset.status_'.$status)])->all(),
            __('accounts::asset.all_statuses')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.title') }}</x-slot:title>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
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

    @can('accounts.asset.manage')
        {{--
            মাসটা ডিফল্টে গত মাস, চলতি মাস নয়।

            অবচয় বসে মাস শেষ হওয়ার পরে। ডিফল্টে চলতি মাস দিলে প্রতি
            মাসে কেউ না কেউ অর্ধেক মাসের ক্ষয় পুরো মাস হিসেবে বসিয়ে
            ফেলতেন, আর সংখ্যাটা দেখতে বৈধই লাগত।
        --}}
        {{-- ⭐ আগে দেখা, তারপর বসানো — স্থায়ী সম্পদ ধাপ ২। ⓘ এই বোতাম কিছু লেখে না; পরের পাতায় কোন সম্পদে কত বসবে
             দেখে "বসান" চাপলে তবে খাতায় ([[DepreciationEngine::preview()]])। --}}
        <form method="GET" action="{{ route('accounts.asset.run.preview') }}"
              class="mb-5 flex flex-wrap items-end gap-3 rounded-(--radius-card) border
                     border-(--color-border) bg-(--color-surface-card) p-4">

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.run_month') }}</span>
                <x-ui.month name="month" required :value="old('month', $defaultMonth)"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2" />
            </label>

            <x-ui.button type="submit" tone="primary">
                {{ __('accounts::asset.run_preview') }}
            </x-ui.button>
        </form>
    @endcan

    {{-- ⭐ শিরোনাম, "নতুন সম্পদ" আর খোঁজা এক বাক্সে, তালিকার মাথায় — মালিকের
         নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।
         ⓘ অবচয়ের দৌড়টা বাক্সের বাইরে, উপরেই — ওটা তালিকার অংশ নয়, মাসের কাজ। --}}
    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :sort="\App\Core\ListFilter\ListSort::labels(['newest', 'oldest', 'amount_high', 'amount_low', 'code'])" :title="__('accounts::asset.title')"
                :subtitle="__('accounts::asset.subtitle')"
                :search-placeholder="__('accounts::message.asset_search')"
                :columns="$columns"
                :filter-labels="['category' => __('accounts::asset.category'), 'status' => __('accounts::asset.status')]"
                :facets="$facetPanel">
                {{-- শর্তটা হুবহু সেটাই যেটায় আগে নিচের ফর্মটা দেখা যেত। --}}
                <x-slot:actions>
                    {{-- ⭐ লেবেল ছাপা — এই তালিকার ছাঁকা শাখার সব চালু সম্পদ (ধাপ ৪) --}}
                    <x-ui.button tone="secondary" icon="printer" target="_blank"
                                 :href="route('accounts.asset.labels', array_filter(['branch_id' => request('branch')]))">
                        {{ __('accounts::asset.labels_action') }}
                    </x-ui.button>
                    @can('accounts.asset.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('accounts.asset.create')">
                            {{ __('accounts::action.new_asset') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$assets"
                    :grand="$grand ?? []"
                    :columns="$columns"
                    :compact="\App\Core\Support\Ui::compact()"
                    :empty="$q ? __('core.empty.no_results') : __('accounts::asset.empty')" />
    </div>

    <div class="mt-3">{{ $assets->links() }}</div>
    <x-ui.list-totals :rows="$assets" :grand="$grand ?? []" :columns="$columns" />
    </x-ui.filter-layout>
</x-layouts.app>
