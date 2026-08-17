{{--
    রোলের তালিকা।

    ── কেন মালিকের সারিতে সম্পাদনার লিংক নেই ───────────────────────────
    মালিকের রোল সংজ্ঞা অনুযায়ীই সব পারে, আর প্রতিটা ডিপ্লয়ে
    `abos:sync-permissions` নতুন অনুমতিগুলো ওখানে বসিয়ে দেয়। এখানে
    কেটে দিলে পরের ডিপ্লয়েই ফিরে আসত — অর্থাৎ বোতামটা একটা মিথ্যা
    প্রতিশ্রুতি দিত।
--}}
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="w-full text-sm">
            <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                <tr>
                    <th class="p-2 text-start font-medium">{{ __('system_admin::field.role_name') }}</th>
                    <th class="p-2 text-end font-medium">{{ __('system_admin::field.permission_count') }}</th>
                    <th class="p-2 text-end font-medium">{{ __('system_admin::field.user_count') }}</th>
                    <th class="p-2 text-start font-medium">{{ __('core.table.actions') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($roles as $role)
                    <tr class="border-b border-(--color-border) last:border-b-0">
                        <td class="p-2 font-medium">{{ $role->name }}</td>
                        <td class="tabular p-2 text-end">{{ $role->permissions_count }}</td>
                        <td class="tabular p-2 text-end">{{ $role->users_count }}</td>
                        <td class="p-2">
                            @if ($role->name === $ownerRole)
                                <span class="text-(--color-ink-muted)">
                                    {{ __('system_admin::message.owner_role_fixed') }}
                                </span>
                            @else
                                <a href="{{ route('system_admin.role.edit', $role) }}"
                                   class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                    {{ __('core.action.edit') }}
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
