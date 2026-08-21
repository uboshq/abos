{{-- বেতনের একটা খাতের ফর্ম। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $head->exists ? $head->name() : __('hr::action.new_head') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$head->exists ? $head->name() : __('hr::action.new_head')"
            :subtitle="$head->exists ? $head->code : null" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $head->exists ? route('hr.salary_head.update', $head->id) : route('hr.salary_head.store') }}"
          class="space-y-4">
        @csrf
        @if ($head->exists) @method('PUT') @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 md:grid-cols-2">
                <x-ui.field name="code" :label="__('hr::field.code')"
                            :value="old('code', $head->code)"
                            :placeholder="__('core.create.code_auto')"
                            :hint="$head->exists ? null : __('core.create.code_auto_hint')" />
                <x-ui.field name="name_en" :label="__('hr::field.name_en')"
                            :value="old('name_en', $head->name_en)" required />
                <x-ui.field name="name_bn" :label="__('hr::field.name_bn')"
                            :value="old('name_bn', $head->name_bn)" />

                <x-ui.select name="kind" :label="__('hr::field.kind')"
                             :options="collect($kinds)->mapWithKeys(fn ($k) => [$k => __('hr::kind.' . $k)])"
                             :selected="old('kind', $head->kind)" required />

                <x-ui.select name="calculation" :label="__('hr::field.calculation')"
                             :options="collect($calculations)->mapWithKeys(fn ($c) => [$c => __('hr::kind.' . $c)])"
                             :selected="old('calculation', $head->calculation)" required />

                {{-- হিসাবের খাত — এখানেই বেতনের খরচ বইয়ে বসবে। খালি
                     রাখলে বেতন পোস্ট করার দিনে সেটা ধরা পড়ত, তাই
                     ফর্মেই বসানোর জায়গা। --}}
                <x-ui.select name="account_id" :label="__('hr::field.account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->label()])"
                             :selected="old('account_id', $head->account_id)" placeholder="-" />

                <x-ui.field name="sort_order" type="number" :label="__('hr::field.sort_order')"
                            :value="old('sort_order', $head->sort_order ?? 0)" />
            </div>

            <div class="mt-3 space-y-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_basic" value="1"
                           @checked(old('is_basic', $head->is_basic)) class="size-4">
                    {{ __('hr::field.is_basic') }}
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="prorated_by_attendance" value="1"
                           @checked(old('prorated_by_attendance', $head->prorated_by_attendance)) class="size-4">
                    {{ __('hr::field.prorated_by_attendance') }}
                </label>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('hr.salary_head.index')">{{ __('core.action.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary">{{ __('hr::action.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
