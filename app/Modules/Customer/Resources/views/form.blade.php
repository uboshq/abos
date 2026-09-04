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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('customer::field.code')"
                                   :value="old('code', $customer->code)"
                                   :hint="$isNew ? __('customer::message.code_auto') : null" />

                <x-ui.field name="name_en" :label="__('customer::field.name_en')"
                                   :value="old('name_en', $customer->name_en)" required />

                <x-ui.field name="name_bn" :label="__('customer::field.name_bn')"
                                   :value="old('name_bn', $customer->name_bn)"
                                   :required="$requireBangla"
                                   :hint="__('customer::message.bn_name_hint')" />

                {{-- দোকানের নাম আর মালিকের নাম এক নয়।

                     "মায়ের দোয়া স্টোর" কাগজে ছাপা হয়, আর ফোনে ধরতে হয়
                     "রফিকুল ইসলাম"-কে। এক ঘরে দুইটা লিখলে চালানে ভুল
                     নাম যেত। --}}
                <x-ui.field name="owner_name" :label="__('customer::field.owner_name')"
                            :value="old('owner_name', $customer->owner_name)" />

                <x-ui.select name="location_id" :label="__('customer::field.point')"
                             :options="$locations->mapWithKeys(fn ($l) => [$l->id => $l->name()])"
                             :selected="old('location_id', $customer->location_id)"
                             placeholder="-"
                             :hint="__('customer::message.point_hint')" />

                {{-- মোবাইলে সঠিক কী-বোর্ড আসার জন্য type ঠিক দিতে হয়
                     (সেকশন ২০.৫) — ফোনের ঘরে অক্ষরের কী-বোর্ড এলে ফিল্ড
                     সেলসম্যানের প্রতিটা এন্ট্রি ধীর হয়। --}}
                <x-ui.field name="phone" type="tel" :label="__('customer::field.phone')"
                                   :value="old('phone', $customer->phone)" />

                <x-ui.field name="email" type="email" :label="__('customer::field.email')"
                                   :value="old('email', $customer->email)" />

                {{-- ধরন মাস্টার তালিকা থেকে, মুক্ত লেখা নয় — প্রতিষ্ঠান
                     নিজেই নতুন ধরন যোগ করতে পারে, তাই এখানে কোনো স্থির
                     তালিকাও নেই। আগে এটা খোলা ইনপুট ছিল, আর তাতে একই
                     ধরন "পাইকারি", "পাইকারী", "wholesale" তিন বানানে
                     জমা হত — তারপর ধরন ধরে রিপোর্ট করা যেত না। --}}
                {{-- নতুন গ্রাহকে ঘরটা খালি খোলে না — ডিফল্ট ধরনটা (পরিবেশক)
                     আগে থেকে বাছা থাকে; নাহলে কেউ ভুলে খালি রেখে সেভ করতেন
                     আর গ্রাহক ভুল শ্রেণিতে বসতেন। সম্পাদনায় নিজের ধরনটাই থাকে। --}}
                <x-ui.select name="party_type_id" :label="__('customer::field.type')"
                             :options="$partyTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$customer->party_type_id ?? $partyTypes->firstWhere('is_default', true)?->id"
                             :hint="__('customer::message.type_hint')"
                             placeholder="—" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.address') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('customer::field.address_en')"
                                   :value="old('address_en', $customer->address_en)" />
                <x-ui.field name="address_bn" :label="__('customer::field.address_bn')"
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
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('customer::section.credit') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                    <x-ui.field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                                       :label="__('customer::field.credit_limit')"
                                       :value="old('credit_limit', $customer->credit_limit)"
                                       :hint="__('customer::message.zero_means_unlimited')" numeric />

                    <x-ui.field name="credit_days" type="number" inputmode="numeric"
                                       :label="__('customer::field.credit_days')"
                                       :value="old('credit_days', $customer->credit_days)" numeric />
                </div>
            </section>
        @endif

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('customer::section.opening') }}</h2>
                {{-- খোলা ব্যালেন্স শুধু তৈরির সময়: পরে বদলালে লেজার ও
                     তালিকা দুই রকম বলত। বদলাতে হলে জাবেদা ভাউচার। --}}
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('customer::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                       :label="__('customer::field.opening_balance')"
                                       :value="old('opening_balance', 0)" numeric />
                    <x-ui.field name="opening_date" type="date"
                                       :label="__('customer::field.opening_date')"
                                       :value="old('opening_date')" />
                </div>
            </section>
        @endif

        {{-- কোম্পানির নিজের যোগ করা ঘরগুলো — কিছু না বানালে কিছুই আঁকা হয় না --}}
        <x-ui.custom-fields :record="$customer" />

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
