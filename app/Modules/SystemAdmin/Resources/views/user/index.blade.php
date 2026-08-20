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
            'render' => fn ($u) => $u->roles->pluck('name')->implode(', ') ?: '-',
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

    <x-slot:header>
        <x-ui.page-header
            :title="__('system_admin::menu.users')"
            :subtitle="__('system_admin::message.users_note')">
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" :href="route('system_admin.user.create')">
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

    <x-ui.table :rows="$users"
                :columns="$columns"
                :empty="__('core.empty.no_results')" />

    {{ $users->links() }}

</x-layouts.app>
