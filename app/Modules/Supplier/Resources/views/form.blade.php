{{--
    সরবরাহকারী তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard,
    সেকশন ১৫.২৪)।

    গ্রাহকের ফর্মের সাথে দুইটা আসল পার্থক্য: ধরনটা এখানে ড্রপডাউন (মাস্টার
    তালিকা থেকে, মুক্ত লেখা নয়), আর ক্রেডিট সীমার ঘরটা কখনো লুকায় না —
    সেটা তথ্য, নিয়ম নয়, তাই বন্ধ করার সুইচও নেই।
--}}
@php
    $isNew = ! $supplier->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('supplier::action.new') : $supplier->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('supplier::action.new') : __('supplier::action.edit')"
            :subtitle="$isNew ? __('supplier::message.code_auto') : $supplier->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('supplier.store') : route('supplier.update', $supplier) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
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
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('supplier::field.code')"
                            :value="old('code', $supplier->code)"
                            :hint="$isNew ? __('supplier::message.code_auto') : null" />

                <x-ui.field name="name_en" :label="__('supplier::field.name_en')"
                            :value="old('name_en', $supplier->name_en)" required />

                <x-ui.field name="name_bn" :label="__('supplier::field.name_bn')"
                            :value="old('name_bn', $supplier->name_bn)"
                            :required="$requireBangla"
                            :hint="__('supplier::message.bn_name_hint')" />

                {{-- ধরন মাস্টার তালিকা থেকে — প্রতিষ্ঠান নিজেই নতুন ধরন
                     যোগ করতে পারে, তাই এখানে কোনো স্থির তালিকা নেই। --}}
                <x-ui.select name="party_type_id" :label="__('supplier::field.party_type')"
                             :options="$partyTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$supplier->party_type_id"
                             placeholder="—" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('supplier::section.contact') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('supplier::message.contact_hint') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- মোবাইলে সঠিক কী-বোর্ড আসার জন্য type ঠিক দিতে হয়
                     (সেকশন ২০.৫)। --}}
                <x-ui.field name="phone" type="tel" :label="__('supplier::field.phone')"
                            :value="old('phone', $supplier->phone)" />

                <x-ui.field name="email" type="email" :label="__('supplier::field.email')"
                            :value="old('email', $supplier->email)" />

                <x-ui.field name="contact_person" :label="__('supplier::field.contact_person')"
                            :value="old('contact_person', $supplier->contact_person)" />

                <x-ui.field name="contact_phone" type="tel" :label="__('supplier::field.contact_phone')"
                            :value="old('contact_phone', $supplier->contact_phone)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.address') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('supplier::field.address_en')"
                            :value="old('address_en', $supplier->address_en)" />
                <x-ui.field name="address_bn" :label="__('supplier::field.address_bn')"
                            :value="old('address_bn', $supplier->address_bn)" />

                <x-ui.select name="branch_id" :label="__('supplier::field.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="$supplier->branch_id"
                             placeholder="—" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('supplier::section.tax') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('supplier::message.bin_hint') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="bin" :label="__('supplier::field.bin')"
                            :value="old('bin', $supplier->bin)"
                            :required="$requireBin" />

                <x-ui.field name="tin" :label="__('supplier::field.tin')"
                            :value="old('tin', $supplier->tin)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.credit') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- শর্ত থাকলে সেটাই শেষ তারিখ ঠিক করে; না থাকলে
                     ক্রেডিট দিন। দুইটাই রাখা আছে, কারণ ছোট সরবরাহকারীর
                     জন্য আলাদা শর্ত খোলার মানে হয় না। --}}
                <x-ui.select name="payment_term_id" :label="__('supplier::field.payment_term')"
                             :options="$paymentTerms->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$supplier->payment_term_id"
                             placeholder="—" />

                <x-ui.field name="credit_days" type="number" inputmode="numeric"
                            :label="__('supplier::field.credit_days')"
                            :value="old('credit_days', $supplier->credit_days)" numeric />

                {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                <x-ui.field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                            :label="__('supplier::field.credit_limit')"
                            :value="old('credit_limit', $supplier->credit_limit)"
                            :hint="__('supplier::message.credit_limit_hint')" numeric />
            </div>
        </section>

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('supplier::section.opening') }}</h2>
                {{-- খোলা ব্যালেন্স শুধু তৈরির সময়: পরে বদলালে লেজার ও
                     তালিকা দুই রকম বলত। বদলাতে হলে জাবেদা ভাউচার। --}}
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('supplier::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                :label="__('supplier::field.opening_balance')"
                                :value="old('opening_balance', 0)" numeric />
                    <x-ui.field name="opening_date" type="date"
                                :label="__('supplier::field.opening_date')"
                                :value="old('opening_date')" />
                </div>
            </section>
        @endif

        <x-ui.custom-fields :record="$supplier" />

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('supplier.index') : route('supplier.show', $supplier)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
