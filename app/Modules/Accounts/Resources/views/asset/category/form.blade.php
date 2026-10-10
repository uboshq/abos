{{--
    ⭐ সম্পদের শ্রেণি — নতুন বা বদল (স্থায়ী সম্পদ ধাপ ১, মালিক, ১০ অক্টোবর ২০২৬)।

    ⓘ প্রতিটা খাতের তালিকায় কেবল ঠিক ধরনের পোস্টযোগ্য খাত — সম্পদের ঘরে সম্পদ, খরচের ঘরে খরচ। সেবার স্তরও আবার মেলায়
    ([[AssetCategoryService]]), কারণ পর্দা এড়িয়ে কেউ ভুল খাত পাঠালে স্থিতিপত্র আর লাভ-ক্ষতি দুইটাই ভুল হত।
    ⚠️ শ্রেণি বদলালে পুরনো সম্পদের খাত নড়ে না — কেবল নতুন সম্পদে খাটে।
--}}
@php
    $isNew = ! $category->exists;
    $pick = fn (string $type) => $accounts[$type]->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('accounts::asset.category_new') : $category->label() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('accounts::asset.category_new') : $category->label()"
                          :subtitle="__('accounts::asset.category_subtitle')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('accounts.asset.category.store') : route('accounts.asset.category.update', $category) }}"
          x-data="{ method: @js(old('method', $category->method)) }"
          class="max-w-screen-2xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <x-ui.errors />

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="code" :label="__('accounts::field.code')" :value="old('code', $category->code)" required />
                <x-ui.field name="name_bn" :label="__('accounts::asset.category_name_bn')" :value="old('name_bn', $category->name_bn)" />
                <x-ui.field name="name_en" :label="__('accounts::asset.category_name_en')" :value="old('name_en', $category->name_en)" required />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('accounts::asset.category_accounts') }}</h2>
            <p class="mt-0.5 mb-3 text-sm text-(--color-ink-muted)">{{ __('accounts::asset.category_accounts_note') }}</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.select name="asset_account_id" :label="__('accounts::asset.account_cost')" :options="$pick('asset')"
                             :selected="old('asset_account_id', $category->asset_account_id)" placeholder="—" required />
                <x-ui.select name="accumulated_account_id" :label="__('accounts::asset.account_accumulated')" :options="$pick('asset')"
                             :selected="old('accumulated_account_id', $category->accumulated_account_id)" placeholder="—" required />
                <x-ui.select name="expense_account_id" :label="__('accounts::asset.account_expense')" :options="$pick('expense')"
                             :selected="old('expense_account_id', $category->expense_account_id)" placeholder="—" required />
                <x-ui.select name="gain_account_id" :label="__('accounts::asset.account_gain')" :options="$pick('income')"
                             :selected="old('gain_account_id', $category->gain_account_id)" placeholder="—" />
                <x-ui.select name="loss_account_id" :label="__('accounts::asset.account_loss')" :options="$pick('expense')"
                             :selected="old('loss_account_id', $category->loss_account_id)" placeholder="—" />
                <x-ui.select name="impairment_account_id" :label="__('accounts::asset.account_impairment')" :options="$pick('expense')"
                             :selected="old('impairment_account_id', $category->impairment_account_id)" placeholder="—" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::asset.category_defaults') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.method') }}</span>
                    <select name="method" x-model="method"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
                        @foreach (\App\Modules\Accounts\Models\FixedAsset::METHODS as $method)
                            <option value="{{ $method }}">{{ __('accounts::asset.'.$method) }}</option>
                        @endforeach
                    </select>
                </label>

                <div x-show="method === 'straight'">
                    <x-ui.field name="life_months" type="number" numeric :label="__('accounts::asset.life_months')"
                                :value="old('life_months', $category->life_months)" />
                </div>
                <div x-show="method === 'reducing'" x-cloak>
                    <x-ui.field name="rate" type="number" numeric :label="__('accounts::asset.rate')"
                                :value="old('rate', $category->rate)" />
                </div>

                <x-ui.field name="residual_percent" type="number" numeric :label="__('accounts::asset.residual_percent')"
                            :value="old('residual_percent', $category->residual_percent)"
                            :hint="__('accounts::asset.residual_percent_hint')" />

                <x-ui.field name="capitalisation_threshold" type="number" numeric :label="__('accounts::asset.category_threshold')"
                            :value="old('capitalisation_threshold', $category->capitalisation_threshold)"
                            :hint="__('accounts::asset.category_threshold_hint')" />
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="size-4" @checked(old('is_active', $category->is_active ?? true))>
                {{ __('accounts::asset.category_on') }}
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('accounts.asset.category.index')">{{ __('core.action.cancel') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
