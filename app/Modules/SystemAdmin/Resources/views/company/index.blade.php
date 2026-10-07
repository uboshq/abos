{{--
    কোম্পানির তালিকা।

    ── কেন সব কোম্পানি দেখা যায়, কেবল চলতিটা নয় ───────────────────────
    অন্য কোম্পানিতে যেতে হলে আগে তাকে দেখতে পাওয়া লাগে। যিনি এই পাতাটা
    খুলতে পারেন তিনি system_admin.company.manage ধারী — প্রতিষ্ঠানের
    মালিক, যাঁর কাছে সবগুলোই নিজের।

    ── মোছার বোতাম নেই, ইচ্ছাকৃতভাবে ──────────────────────────────────
    একটা কোম্পানি মানে তার প্রতিটা বিল, চালান, খতিয়ানের সারি আর ব্যাংক
    মিলান। ভুল করে একবার চাপলে ফেরার পথ নেই। নিষ্ক্রিয় করা যায় — তখন
    সুইচারে আর আসে না, কিন্তু কাগজপত্র যেমন আছে তেমনই থাকে।
--}}
@php
    /* কলাম ধরে — টেবিল আর টুলবারের Columns মেনু দুইজনেই এই একটা
       তালিকা পড়ে। আগে এটা টেবিলের ভেতরেই লেখা ছিল; তখন Columns মেনুকে
       দেওয়ার মতো কিছু ছিল না। */
    $columns = [
        ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '8rem',
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('system_admin.company.edit', $c->id) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($c->code) . '</a>')],
        ['key' => 'name', 'label' => __('master_data::field.name'),
         'render' => fn ($c) => $c->name()],
        ['key' => 'branches', 'label' => __('system_admin::menu.branches'),
         'numeric' => true, 'width' => '7rem',
         'render' => fn ($c) => $c->branches_count],
        ['key' => 'phone', 'label' => __('core.print.phone'), 'width' => '11rem',
         'render' => fn ($c) => $c->phone ?: '—'],
        ['key' => 'state', 'label' => __('inventory::field.state'), 'width' => '9rem',
         'render' => fn ($c) => view('system_admin::company.partials.state', ['company' => $c])],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'width' => '6rem',
         'render' => fn ($c) => view('system_admin::company.partials.actions', ['company' => $c])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.companies') }}</x-slot:title>

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
            {{ $errors->first() }}
        </div>
    @endif

    <p class="mb-4 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
        {{ __('system_admin::message.company_note') }}
    </p>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⭐ শিরোনাম, গোনা আর "নতুন" বোতাম এখন টুলবারে — আগে ছিল
                 page-header-এ, তালিকার বাক্সের বাইরে। মালিকের নির্দেশ,
                 ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

                 ⓘ খোঁজা কোড আর দুই ভাষার নামে (CompanyController::index);
                 গোনাটা খোঁজার পরের সংখ্যা, তাই তালিকার সাথে মেলে। --}}
            <x-ui.toolbar :title="__('system_admin::menu.companies')"
                          :count="trans_choice('core.count.records', $companies->count(), ['count' => $companies->count()])"
                          :search-placeholder="__('system_admin::message.company_search')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('system_admin.company.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="request('q') ? __('core.empty.no_results') : __('system_admin::message.no_companies')"
            :rows="$companies"
            :compact="request()->boolean('compact')"
            :columns="$columns" />
    </div>
</x-layouts.app>
