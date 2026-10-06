{{--
    ⭐ নতুন ভাড়াটের চুক্তি — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ ([[TenancyService::open()]])।
    ⓘ ভাড়াটে তালিকা থেকেই (ব্যক্তি বা গ্রাহক) — ১১২৫ আর ২১৫৫-এর প্রতিটা সারি তাঁর নামে বসে; নামের ঘর কেবল ছাপার নাম বদলাতে।
    জামানত তখনই হাতে এলে টাকার খাত বাছুন; পরে এলে চুক্তির পাতা থেকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::tenancy.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::tenancy.new')" :subtitle="__('finance::tenancy.title')" />
    </x-slot:header>

    <x-ui.errors />

    <section data-boxed data-tenancy-form
             class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <form method="POST" action="{{ route('finance.tenancy.store') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf

            <x-ui.select name="party" :label="__('finance::tenancy.party')" :options="$parties"
                         :placeholder="__('finance::tenancy.party_pick')" :hint="__('finance::tenancy.party_hint')"
                         :selected="old('party')" required />

            <x-ui.field name="tenant" :label="__('finance::tenancy.tenant_name')" :hint="__('finance::tenancy.tenant_name_hint')" />
            <x-ui.field name="tenant_phone" :label="__('finance::tenancy.phone')" />
            <x-ui.field name="premises" :label="__('finance::tenancy.premises')" />

            <x-ui.field name="monthly_rent" type="number" step="0.01" min="0" :label="__('finance::tenancy.monthly_rent')" required />
            <x-ui.field name="deposit_amount" type="number" step="0.01" min="0" :label="__('finance::tenancy.deposit_amount')"
                        :value="old('deposit_amount', 0)" />

            <label class="grid gap-1">
                <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.starts_on') }}</span>
                <x-ui.date name="starts_on" :value="old('starts_on', now()->toDateString())" required />
            </label>

            <x-ui.field name="term_months" type="number" min="1" max="600" :label="__('finance::tenancy.term_months')" required />
            <x-ui.field name="rent_day" type="number" min="1" max="28" :label="__('finance::tenancy.rent_day')" :value="old('rent_day', 5)" />

            <x-ui.select name="income_account_id" :label="__('finance::tenancy.income_account')"
                         :options="$incomes->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name()])->all()"
                         :placeholder="__('finance::tenancy.income_default')" :selected="old('income_account_id')" />

            @include('finance::rental._money', [
                'money' => $money,
                'label' => __('finance::tenancy.deposit_money_account'),
                'blank' => __('finance::tenancy.deposit_later'),
            ])

            <x-ui.field name="note" :label="__('finance::tenancy.note')" />

            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('finance::tenancy.open') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.tenancy.index')">{{ __('core.action.cancel') }}</x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
