{{--
    ব্যবহারকারীর তালিকা।

    ── কেন মোছার বোতাম নেই ────────────────────────────────────────────
    একজন ব্যবহারকারীর নাম প্রতিটা বিলে, প্রতিটা অডিটের সারিতে আর
    লগইনের খাতায় বসে আছে। মুছে ফেললে ওই সব কাগজে "কে করেছিল" প্রশ্নের
    উত্তর হারায়। নিষ্ক্রিয় করা যায় — তখন আর ঢোকা যায় না, ইতিহাস থাকে।
--}}
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="w-full text-sm">
            <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                <tr>
                    <th class="p-2 text-start font-medium">{{ __('system_admin::field.user_name') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('core.profile.email') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('system_admin::field.roles') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('core.company.company') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('system_admin::field.last_login') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('core.table.actions') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b border-(--color-border) last:border-b-0">
                        <td class="p-2">
                            {{ $user->name }}
                            @unless ($user->is_active)
                                <span class="ms-1 rounded-full bg-(--color-badge-warning-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-warning-ink)">
                                    {{ __('core.state.inactive') }}
                                </span>
                            @endunless
                        </td>
                        <td class="p-2">{{ $user->email }}</td>
                        <td class="p-2">{{ $user->roles->pluck('name')->implode(', ') ?: '-' }}</td>
                        <td class="p-2">{{ $user->companies->map(fn ($c) => $c->code)->implode(', ') ?: '-' }}</td>
                        <td class="p-2 text-(--color-ink-muted)">
                            {{ $user->last_login_at ? \App\Core\Support\DateFormat::format($user->last_login_at) : '-' }}
                        </td>
                        <td class="p-2">
                            <a href="{{ route('system_admin.user.edit', $user) }}"
                               class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                {{ __('core.action.edit') }}
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($users->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $users->links() }}</div>
        @endif
    </div>
</x-layouts.app>
