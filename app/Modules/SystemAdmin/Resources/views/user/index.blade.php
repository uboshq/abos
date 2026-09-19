{{--
    ব্যবহারকারীর তালিকা।

    ── কেন মোছার বোতাম নেই ────────────────────────────────────────────
    একজন ব্যবহারকারীর নাম প্রতিটা বিলে, প্রতিটা অডিটের সারিতে আর
    লগইনের খাতায় বসে আছে। মুছে ফেললে ওই সব কাগজে "কে করেছিল" প্রশ্নের
    উত্তর হারায়। নিষ্ক্রিয় করা যায় — তখন আর ঢোকা যায় না, ইতিহাস থাকে।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        [
            'key' => 'name',
            'label' => __('system_admin::field.user_name'),
            'render' => fn ($u) => view('system_admin::user.partials.name', ['user' => $u]),
        ],
        [
            'key' => 'email',
            'label' => __('core.profile.email'),
        ],
        [
            'key' => 'roles',
            'label' => __('system_admin::field.roles'),
            'render' => fn ($u) => $u->roles->pluck('name')
                ->map(fn (string $n) => \App\Core\Support\RoleLabel::for($n))
                ->implode(', ') ?: '-',
        ],
        [
            'key' => 'companies',
            'label' => __('core.company.company'),
            'render' => fn ($u) => $u->companies->map(fn ($c) => $c->code)->implode(', ') ?: '-',
        ],
        [
            'key' => 'last_login_at',
            'label' => __('system_admin::field.last_login'),
            'render' => fn ($u) => $u->last_login_at
                ? \App\Core\Support\DateFormat::format($u->last_login_at)
                : '-',
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'render' => fn ($u) => view('system_admin::user.partials.edit', ['user' => $u]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.users') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⭐ শিরোনাম, বর্ণনা আর "নতুন" বোতাম এখন টুলবারে — আগে ছিল
                 page-header-এ, তালিকার বাক্সের বাইরে। মালিকের নির্দেশ,
                 ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

                 ⓘ খোঁজা নাম ও ইমেইলে (UserController::index)। সাজানো নেই —
                 তালিকা সবসময় নামের ক্রমে, আর অন্য কোনো ক্রম কন্ট্রোলার
                 জানে না। --}}
            <x-ui.toolbar :title="__('system_admin::menu.users')"
                          :subtitle="__('system_admin::message.users_note')"
                          :search-placeholder="__('system_admin::message.user_search')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('system_admin.user.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$users"
                    :columns="$columns"
                    :compact="request()->boolean('compact')"
                    :empty="__('core.empty.no_results')" />
    </div>

    {{ $users->links() }}

</x-layouts.app>
