{{--
    আর্থিক প্রতিষ্ঠান বসানো ও বদলানো — একটাই ফর্ম, দুই কাজে (One Form Standard)।
    ⓘ মূলধনের পাতার নিয়মে ফর্ম আলাদা পাতায়, তালিকার মাঝে নয়।

    ⚠️ নকলের পাহারা সার্ভারে ([[InstitutionService::assertNameIsFree]]):
    "Islami Bank Bangladesh Ltd." থাকা অবস্থায় "islami bank bangladesh ltd"
    লিখলে থামে। "IBBL" লিখলে থামে না — ওটার জন্যই সংক্ষেপের ঘর।
--}}
@php
    use App\Modules\Finance\Models\Institution;

    $isNew = ! $institution->exists;

    $kinds = collect(Institution::KINDS)
        ->mapWithKeys(fn ($k) => [$k => __('finance::institution.kind_'.$k)])
        ->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>
        {{ $isNew ? __('finance::institution.new') : __('finance::institution.edit') }}
    </x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('finance::institution.new') : __('finance::institution.edit')"
                          :subtitle="__('finance::institution.subtitle')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 max-w-4xl rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed
             class="max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST"
              action="{{ $isNew ? route('finance.institution.store') : route('finance.institution.update', $institution) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <x-ui.select name="kind" :label="__('finance::institution.kind')" required
                         :options="$kinds" :selected="old('kind', $institution->kind)" />

            <x-ui.field name="name_en" :label="__('finance::institution.name_en')" required
                        :value="old('name_en', $institution->name_en)" />

            <x-ui.field name="name_bn" :label="__('finance::institution.name_bn')"
                        :value="old('name_bn', $institution->name_bn)" />

            <x-ui.field name="short_code" :label="__('finance::institution.short_code')"
                        :hint="__('finance::institution.short_code_hint')"
                        :value="old('short_code', $institution->short_code)" />

            <x-ui.field name="branch_name" :label="__('finance::institution.branch_name')"
                        :value="old('branch_name', $institution->branch_name)" />

            <x-ui.field name="contact_person" :label="__('finance::institution.contact_person')"
                        :value="old('contact_person', $institution->contact_person)" />

            <x-ui.field name="phone" type="tel" :label="__('finance::institution.phone')"
                        :value="old('phone', $institution->phone)" />

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">
                    {{ __('core.action.save') }}
                </x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.institution.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
