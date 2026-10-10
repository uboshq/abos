{{--
    ⭐ সরেজমিন গোনার অভিযান — তালিকা আর নতুন অভিযান (স্থায়ী সম্পদ ধাপ ৪)।
    ⓘ শাখা ধরে একটা করে; খোলার মুহূর্তে শাখার সব চালু সম্পদ তালিকায় ওঠে।
--}}
@php
    $columns = [
        ['key' => 'document_no', 'label' => __('accounts::asset.verify_no'), 'width' => '10rem',
            'render' => fn ($c) => view('accounts::asset.verify.partials.link', ['campaign' => $c])],
        ['key' => 'branch', 'label' => __('accounts::asset.branch'), 'render' => fn ($c) => $c->branch?->name()],
        ['key' => 'title', 'label' => __('accounts::asset.verify_title_field'), 'render' => fn ($c) => $c->title ?? '—'],
        ['key' => 'started_on', 'label' => __('accounts::asset.verify_started'), 'width' => '8rem',
            'render' => fn ($c) => $c->started_on?->format('d M Y')],
        ['key' => 'lines_count', 'label' => __('accounts::asset.verify_total'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($c) => $c->lines_count],
        ['key' => 'checked_count', 'label' => __('accounts::asset.verify_checked'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($c) => $c->checked_count],
        ['key' => 'exception_count', 'label' => __('accounts::asset.verify_exceptions'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($c) => $c->exception_count],
        ['key' => 'status', 'label' => __('accounts::asset.status'), 'width' => '7rem',
            'render' => fn ($c) => __('accounts::asset.verify_status_'.$c->status)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.asset_verifications') }}</x-slot:title>

    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif
    <x-ui.errors />

    @can('accounts.asset.verify')
        <form method="POST" action="{{ route('accounts.asset.verify.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 md:grid-cols-4">
            @csrf
            <div class="md:col-span-4">
                <p class="text-sm font-semibold">{{ __('accounts::asset.verify_new') }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.verify_new_hint') }}</p>
            </div>
            <x-ui.select name="branch_id" :label="__('accounts::asset.branch')" required placeholder="—"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])->all()" />
            <x-ui.field name="title" :label="__('accounts::asset.verify_title_field')" />
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.verify_started') }}</span>
                <x-ui.date name="started_on" :required="true" :value="now()->toDateString()" />
            </label>
            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary">{{ __('accounts::asset.verify_open_action') }}</x-ui.button>
            </div>
        </form>
    @endcan

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::menu.asset_verifications')"
                          :subtitle="__('accounts::asset.verify_subtitle')"
                          :columns="$columns" />
        </form>

        <x-ui.table :rows="$campaigns" :columns="$columns" :empty="__('accounts::asset.verify_empty')" />
    </div>

    <div class="mt-3">{{ $campaigns->links() }}</div>
    <x-ui.list-totals :rows="$campaigns" :columns="$columns" />
</x-layouts.app>
