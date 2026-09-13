{{--
    নতুন সম্পদ — নিজের পাতায়।

    আগে ফর্মটা তালিকার পাতায় গোঁজা ছিল, মাস শেষের দৌড়ের ঠিক নিচে।
    দুইটা আলাদা কাজ পাশাপাশি থাকায় পর্দাটা পড়তে হত আগে, বোঝা যেত পরে।

    ⓘ মাস শেষের দৌড়টা (এক ঘরের `month` ফর্ম) তালিকার পাতাতেই আছে, আর
    ইচ্ছাকৃতভাবে — ওটা একটা বোতাম, একটা পাতা নয়। কারণটা রুট ফাইলেও লেখা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.register_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.register_title')"
                          :subtitle="__('accounts::asset.subtitle')" />
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

    <form method="POST" action="{{ route('accounts.asset.store') }}"
          x-data="{ method: '{{ old('method', \App\Modules\Accounts\Models\FixedAsset::STRAIGHT_LINE) }}' }"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
        @csrf

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.name') }}</span>
            <input type="text" name="name" required value="{{ old('name') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.account') }}</span>
            <select name="asset_account_id" required
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                @foreach ($assetAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.cost') }}</span>
            <input type="number" step="0.01" min="0" name="cost" required value="{{ old('cost') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.salvage') }}</span>
            <input type="number" step="0.01" min="0" name="salvage" value="{{ old('salvage', 0) }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.acquired_on') }}</span>
            <x-ui.date name="acquired_on" :required="true"
                       :value="old('acquired_on', now()->toDateString())" />
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.method') }}</span>
            <select name="method" x-model="method"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="{{ \App\Modules\Accounts\Models\FixedAsset::STRAIGHT_LINE }}">
                    {{ __('accounts::asset.straight') }}
                </option>
                <option value="{{ \App\Modules\Accounts\Models\FixedAsset::REDUCING }}">
                    {{ __('accounts::asset.reducing') }}
                </option>
            </select>
        </label>

        {{-- একটা পদ্ধতিতে আয়ু লাগে, অন্যটায় হার — দুইটা একসাথে নয়। --}}
        <label class="flex flex-col gap-1" x-show="method === 'straight'">
            <span class="text-sm font-medium">{{ __('accounts::asset.life_months') }}</span>
            <input type="number" step="1" min="1" name="life_months" value="{{ old('life_months') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1" x-show="method === 'reducing'" x-cloak>
            <span class="text-sm font-medium">{{ __('accounts::asset.rate') }}</span>
            <input type="number" step="0.01" min="0" max="100" name="rate" value="{{ old('rate') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <div class="flex flex-wrap items-end gap-2 md:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" tone="primary">
                {{ __('accounts::asset.register_action') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.asset.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
