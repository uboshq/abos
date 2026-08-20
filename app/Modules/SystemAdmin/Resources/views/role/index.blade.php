{{--
    রোলের তালিকা।

    ── কেন মালিকের সারিতে সম্পাদনার লিংক নেই ───────────────────────────
    মালিকের রোল সংজ্ঞা অনুযায়ীই সব পারে, আর প্রতিটা ডিপ্লয়ে
    `abos:sync-permissions` নতুন অনুমতিগুলো ওখানে বসিয়ে দেয়। এখানে
    কেটে দিলে পরের ডিপ্লয়েই ফিরে আসত — অর্থাৎ বোতামটা একটা মিথ্যা
    প্রতিশ্রুতি দিত।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        [
            'key' => 'name',
            'label' => __('system_admin::field.role_name'),
            'render' => fn ($r) => $r->name,
        ],
        [
            'key' => 'permissions_count',
            'label' => __('system_admin::field.permission_count'),
            'numeric' => true,
            'width' => '10rem',
        ],
        [
            'key' => 'users_count',
            'label' => __('system_admin::field.user_count'),
            'numeric' => true,
            'width' => '9rem',
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'render' => fn ($r) => view('system_admin::role.partials.actions',
                ['role' => $r, 'ownerRole' => $ownerRole]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.roles') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('system_admin::menu.roles')"
            :subtitle="__('system_admin::message.roles_note')">
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" :href="route('system_admin.role.create')">
                    {{ __('core.action.create') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

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

    <x-ui.table :rows="$roles"
                :columns="$columns"
                :empty="__('core.empty.no_results')" />

</x-layouts.app>
