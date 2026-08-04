{{--
    গ্রাহক তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard,
    সেকশন ১৫.২৪)। আলাদা create ও edit ফর্ম রাখলে একটায় ফিল্ড যোগ করে
    অন্যটায় ভুলে যাওয়া নিশ্চিত।

    ঐচ্ছিক ফিল্ডগুলো Control Panel-এর সুইচ মানে (নিয়ম ৭): ক্রেডিট লিমিট
    বন্ধ থাকলে ঘরটা কোথাও নেই — এখানেও না, প্রিন্টেও না।
--}}
@php
    $isNew = ! $customer->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('customer::action.new') : $customer->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('customer::action.new') : __('customer::action.edit')"
            :subtitle="$isNew ? __('customer::message.code_auto') : $customer->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('customer.store') : route('customer.update', $customer) }}"
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

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-customer::field name="code" :label="__('customer::field.code')"
                                   :value="old('code', $customer->code)"
                                   :hint="$isNew ? __('customer::message.code_auto') : null" />

                <x-customer::field name="name_en" :label="__('customer::field.name_en')"
                                   :value="old('name_en', $customer->name_en)" required />

                <x-customer::field name="name_bn" :label="__('customer::field.name_bn')"
                                   :value="old('name_bn', $customer->name_bn)"
                                   :required="$requireBangla"
                                   :hint="__('customer::message.bn_name_hint')" />

                {{-- মোবাইলে সঠিক কী-বোর্ড আসার জন্য type ঠিক দিতে হয়
                     (সেকশন ২০.৫) — ফোনের ঘরে অক্ষরের কী-বোর্ড এলে ফিল্ড
                     সেলসম্যানের প্রতিটা এন্ট্রি ধীর হয়। --}}
                <x-customer::field name="phone" type="tel" :label="__('customer::field.phone')"
                                   :value="old('phone', $customer->phone)" />

                <x-customer::field name="email" type="email" :label="__('customer::field.email')"
                                   :value="old('email', $customer->email)" />

                <x-customer::field name="customer_type" :label="__('customer::field.type')"
                                   :value="old('customer_type', $customer->customer_type)"
                                   :hint="__('customer::message.type_hint')" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.address') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-customer::field name="address_en" :label="__('customer::field.address_en')"
                                   :value="old('address_en', $customer->address_en)" />
                <x-customer::field name="address_bn" :label="__('customer::field.address_bn')"
                                   :value="old('address_bn', $customer->address_bn)" />

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('core.company.branch') }}</span>
                    <select name="branch_id"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected(old('branch_id', $customer->branch_id) == $branch->id)>
                                {{ $branch->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        @if ($creditLimitOn)
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('customer::section.credit') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                    <x-customer::field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                                       :label="__('customer::field.credit_limit')"
                                       :value="old('credit_limit', $customer->credit_limit)"
                                       :hint="__('customer::message.zero_means_unlimited')" numeric />

                    <x-customer::field name="credit_days" type="number" inputmode="numeric"
                                       :label="__('customer::field.credit_days')"
                                       :value="old('credit_days', $customer->credit_days)" numeric />
                </div>
            </section>
        @endif

        @if ($isNew)
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('customer::section.opening') }}</h2>
                {{-- খোলা ব্যালেন্স শুধু তৈরির সময়: পরে বদলালে লেজার ও
                     তালিকা দুই রকম বলত। বদলাতে হলে জাবেদা ভাউচার। --}}
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('customer::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-customer::field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                       :label="__('customer::field.opening_balance')"
                                       :value="old('opening_balance', 0)" numeric />
                    <x-customer::field name="opening_date" type="date"
                                       :label="__('customer::field.opening_date')"
                                       :value="old('opening_date')" />
                </div>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('customer.index') : route('customer.show', $customer)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
