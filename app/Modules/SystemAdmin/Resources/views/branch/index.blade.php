{{--
    শাখার তালিকা — "সিস্টেম প্রশাসন → শাখা" (মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬)।

    ⓘ কেবল আপনার কোম্পানিগুলোর শাখা ([[BranchController::index()]])। কোম্পানি
    দিয়ে ছাঁকা যায়। মোছার বোতাম নেই — নিষ্ক্রিয় করা যায়, কাগজ অক্ষত থাকে।
--}}
@php
    /* কলাম ধরে — টেবিল আর টুলবারের Columns মেনু দুইজনেই এই তালিকা পড়ে। */
    $columns = [
        ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '8rem',
         'render' => fn ($b) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('system_admin.branch.edit', $b->id) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($b->code) . '</a>')],
        ['key' => 'name', 'label' => __('master_data::field.name'),
         'render' => fn ($b) => $b->name()],
        ['key' => 'company', 'label' => __('system_admin::menu.companies'), 'width' => '14rem',
         'render' => fn ($b) => $b->company?->name() ?? '—'],
        ['key' => 'default', 'label' => __('system_admin::field.main_branch'), 'width' => '8rem',
         'render' => fn ($b) => $b->is_default ? '✓' : ''],
        ['key' => 'state', 'label' => __('inventory::field.state'), 'width' => '9rem',
         'render' => fn ($b) => view('system_admin::branch.partials.state', ['branch' => $b])],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'width' => '6rem',
         'render' => fn ($b) => view('system_admin::branch.partials.actions', ['branch' => $b])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.branches') }}</x-slot:title>

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
        {{ __('system_admin::message.branch_note') }}
    </p>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('system_admin::menu.branches')"
                          :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                          :search-placeholder="__('system_admin::message.branch_search')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('system_admin.branch.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </x-slot:actions>

                <x-slot:filters>
                    <x-ui.select name="company" :label="__('system_admin::menu.companies')"
                                 :options="$companies->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                                 :placeholder="__('system_admin::message.all_companies')"
                                 :selected="request('company')" />
                </x-slot:filters>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="request('q') ? __('core.empty.no_results') : __('system_admin::message.no_branches')"
            :rows="$rows"
            :compact="request()->boolean('compact')"
            :columns="$columns" />
    </div>

    <x-ui.pager :rows="$rows" />
</x-layouts.app>
