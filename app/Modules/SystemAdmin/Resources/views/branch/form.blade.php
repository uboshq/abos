{{--
    নতুন শাখা, বা একটা শাখার সম্পাদনা।

    ⓘ নিয়ম [[BranchDesk]]-এর — কোম্পানির পাতার শাখার ফর্ম একই সেবা ডাকে।
    ⚠️ সম্পাদনায় কোম্পানি বদলানো যায় না: শাখার প্রতিটা কাগজ ঐ কোম্পানির খাতায়,
    তাই ঘরটা কেবল দেখানো হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $branch->exists ? $branch->code : __('system_admin::menu.branches') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$branch->exists ? $branch->code.' · '.$branch->name() : __('system_admin::menu.branches')" />
    </x-slot:header>

    <x-ui.errors />

    <form method="POST"
          action="{{ $branch->exists ? route('system_admin.branch.update', $branch->id) : route('system_admin.branch.store') }}"
          data-boxed
          class="grid gap-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 sm:grid-cols-2">
        @csrf
        @if ($branch->exists)
            @method('PUT')
        @endif

        @if ($branch->exists)
            <x-ui.field name="company_label" :label="__('system_admin::menu.companies')"
                        :value="$companies->firstWhere('id', $branch->company_id)?->name()" readonly />
        @else
            <x-ui.select name="company_id" :label="__('system_admin::menu.companies')"
                         :options="$companies->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                         :selected="old('company_id', request('company'))" required />
        @endif

        <x-ui.field name="code" :label="__('master_data::field.code')"
                    :value="old('code', $branch->code)" required />
        <x-ui.field name="name_en" :label="__('system_admin::field.branch_name_en')"
                    :value="old('name_en', $branch->name_en)" required />
        <x-ui.field name="name_bn" :label="__('system_admin::field.branch_name_bn')"
                    :value="old('name_bn', $branch->name_bn)" />
        <x-ui.field name="phone" :label="__('core.print.phone')"
                    :value="old('phone', $branch->phone)" />
        {{-- ⭐ শাখার ক্রম — মালিক, ৫ অক্টোবর ২০২৬ --}}
        <x-ui.field name="sort_order" type="number" min="0" max="999" :label="__('core.company.branch_order')"
                    :hint="__('core.company.branch_order_hint')"
                    :value="old('sort_order', $branch->sort_order ?: '')" />
        <x-ui.field name="address_en" :label="__('system_admin::field.address_en')"
                    :value="old('address_en', $branch->address_en)" />

        {{-- ⭐ বাতিল · সংরক্ষণ স্থির পট্টিতে, ডানে — সিস্টেম পর্দার নকশা §৪, ১০ অক্টোবর ২০২৬ --}}
        <x-ui.form-actions class="sm:col-span-2" :cancel="route('system_admin.branch.index')" />
    </form>
</x-layouts.app>
