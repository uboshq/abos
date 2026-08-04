{{-- গুদাম তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে। --}}
@php
    $isNew = ! $warehouse->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('inventory::action.new_warehouse') : $warehouse->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('inventory::action.new_warehouse') : __('inventory::action.edit')"
            :subtitle="$isNew ? null : $warehouse->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('inventory.warehouse.store') : route('inventory.warehouse.update', $warehouse) }}"
          class="max-w-2xl space-y-4">
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

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('inventory::field.code')"
                            :value="old('code', $warehouse->code)" required />

                <x-ui.field name="name_en" :label="__('inventory::field.name_en')"
                            :value="old('name_en', $warehouse->name_en)" required />

                <x-ui.field name="name_bn" :label="__('inventory::field.name_bn')"
                            :value="old('name_bn', $warehouse->name_bn)" />

                {{-- শাখা — গুদাম শাখার নিচে, নাহলে এক শাখার মাল অন্য
                     শাখার তালিকায় দেখাত --}}
                <x-ui.select name="branch_id" :label="__('inventory::field.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="$warehouse->branch_id"
                             placeholder="-" />

                <x-ui.field name="address_en" :label="__('inventory::field.address_en')"
                            :value="old('address_en', $warehouse->address_en)" />

                <x-ui.field name="address_bn" :label="__('inventory::field.address_bn')"
                            :value="old('address_bn', $warehouse->address_bn)" />
            </div>

            <label class="mt-3 flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                <input type="checkbox" name="is_default" value="1"
                       @checked(old('is_default', $warehouse->is_default)) class="size-4">
                {{ __('inventory::field.is_default') }}
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('inventory.warehouse.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
