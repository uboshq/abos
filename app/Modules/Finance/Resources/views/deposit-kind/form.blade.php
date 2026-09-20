{{--
    জমার ধরন — নতুন বা সম্পাদনা।

    ⓘ ছাঁদটাই ঠিক করে জমার ফর্মে কোন ঘর খোলে: কিস্তির ঘর, না মুনাফা
    তোলার খাত ([[DepositKind::takesInstalments()]], [[paysOut()]])।
    ⚠️ তাই ছাঁদটা ভুল বসালে ফর্মে দরকারি ঘরটাই আসত না।
--}}
@php($isNew = ! $kind->exists)

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('finance::action.new_kind') : $kind->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('finance::action.new_kind') : $kind->name()"
                          :subtitle="__('finance::menu.deposit_kinds')" />
    </x-slot:header>

    <x-ui.errors />

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST"
              action="{{ $isNew ? route('finance.deposit_kind.store') : route('finance.deposit_kind.update', $kind) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            {{-- ⚠️ কোডটা কোম্পানির ভিতরে অনন্য — একই কোডে দুইটা ধরন থাকলে
                 পুরনো কাগজের জোড়া ঘোলাটে হত। --}}
            <x-ui.field name="code" :label="__('finance::field.kind_code')" required
                        :value="old('code', $kind->code)" />

            <x-ui.field name="name_en" :label="__('finance::field.kind_name_en')" required
                        :value="old('name_en', $kind->name_en)" />

            <x-ui.field name="name_bn" :label="__('finance::field.kind_name_bn')"
                        :value="old('name_bn', $kind->name_bn)" />

            <x-ui.select name="issuer" :label="__('finance::field.kind_issuer')"
                         :options="collect(\App\Modules\Finance\Models\DepositKind::ISSUERS)
                             ->mapWithKeys(fn (string $i) => [
                                 $i => __('finance::menu.deposit_'.($i === 'national_savings' ? 'savings' : $i)),
                             ])"
                         :selected="old('issuer', $kind->issuer)" />

            <x-ui.select name="shape" :label="__('finance::field.kind_shape')"
                         :hint="__('finance::message.kind_shape_hint')"
                         :options="collect(\App\Modules\Finance\Models\DepositKind::SHAPES)
                             ->mapWithKeys(fn (string $s) => [$s => __('finance::field.shape_'.$s)])"
                         :selected="old('shape', $kind->shape)" />

            <x-ui.field name="sort" type="number" inputmode="numeric" numeric
                        :label="__('finance::field.kind_sort')"
                        :value="old('sort', $kind->sort ?? 0)" />

            {{-- ⓘ কিছু স্কিম কেবল ব্যক্তির নামে হয় (যেমন পরিবার সঞ্চয়পত্র);
                 প্রতিষ্ঠানের নামে ওগুলো খোলাই যায় না। --}}
            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="personal_only" value="0">
                <input type="checkbox" name="personal_only" value="1" class="size-4"
                       @checked(old('personal_only', $kind->personal_only)) >
                {{ __('finance::field.kind_personal_only') }}
            </label>

            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4"
                       @checked(old('is_active', $kind->is_active ?? true)) >
                {{ __('finance::field.kind_is_active') }}
            </label>

            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.deposit_kind.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
