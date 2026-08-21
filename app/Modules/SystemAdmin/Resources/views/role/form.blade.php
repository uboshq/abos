{{--
    রোল — তৈরি ও সম্পাদনা।

    ── কেন অনুমতিগুলো মডিউল ধরে সাজানো ─────────────────────────────────
    একশোর বেশি অনুমতি এক লম্বা তালিকায় দিলে কেউ পড়ে না, আর না পড়ে টিক
    দেওয়া মানে ভুল অধিকার দেওয়া। মডিউলের নামের নিচে থাকলে "বিক্রয়ের কী
    কী পারবেন" এক নজরে দেখা যায় — আর ওটাই আসল প্রশ্ন।
--}}
@php
    $chosen = collect(old('permissions', $held))->all();
    $isNew = ! $role->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('system_admin::action.new_role') : $role->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('system_admin::action.new_role') : $role->name"
            :subtitle="__('system_admin::message.roles_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('system_admin.role.store') : route('system_admin.role.update', $role) }}"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="max-w-md">
                <x-ui.field name="name" :label="__('system_admin::field.role_name')"
                            :value="old('name', $role->name)"
                            :hint="__('system_admin::field.role_name_hint')" required />
            </div>
        </section>

        @foreach ($grouped as $module => $permissions)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ $moduleNames[$module] ?? $module }}</h2>

                <div class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permissions as $permission)
                        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                                   class="size-4"
                                   @checked(in_array($permission->name, $chosen, true))>
                            <span class="min-w-0 truncate">{{ $permission->name }}</span>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('system_admin.role.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
