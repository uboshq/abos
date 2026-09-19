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
            'render' => fn ($r) => \App\Core\Support\RoleLabel::for($r->name),
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

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⭐ শিরোনাম, বর্ণনা আর "নতুন" বোতাম এখন টুলবারে — আগে ছিল
                 page-header-এ, তালিকার বাক্সের বাইরে। মালিকের নির্দেশ,
                 ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

                 ⓘ খোঁজা রোলের নামে — কাঁচা নাম আর পর্দার অনুবাদ দুইটাতেই
                 (RoleController::index)। কলাম আর মালিকের সারির তালা যেমন
                 ছিল তেমনই। --}}
            <x-ui.toolbar :title="__('system_admin::menu.roles')"
                          :subtitle="__('system_admin::message.roles_note')"
                          :search-placeholder="__('system_admin::message.role_search')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('system_admin.role.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$roles"
                    :columns="$columns"
                    :compact="request()->boolean('compact')"
                    :empty="__('core.empty.no_results')" />
    </div>

</x-layouts.app>
