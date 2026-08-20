{{--
    স্থায়ী সম্পদের খাতা।

    উপরে মাস শেষের দৌড়, নিচে তালিকা — ক্রমটা ইচ্ছাকৃত। এই পাতাটার
    সাথে মানুষের দেখা হয় মাসে একবার, আর তখন কাজটা একটাই: গত মাসের
    অবচয় বসানো। তালিকাটা তার পরের প্রশ্ন।
--}}
@php
    /*
        কলামগুলো এখানে, স্লটে নয়।

        `x-ui.table` স্লট পড়ে না — সে `:rows` আর `:columns` থেকে নিজে
        সারি আঁকে, আর প্রতিটা কলামে `key` ও `label` দুইটাই চায়। প্রথম
        লেখায় ভেতরে হাতে `<tr>` বসানো ছিল, ফলে পর্দাটা খালি অবস্থায়
        ঠিক চলত আর প্রথম সম্পদ যোগ হওয়ামাত্র ৫০০ দিত।
    */
    $columns = [
        [
            'key' => 'name',
            'label' => __('accounts::asset.name'),
            'render' => fn ($a) => view('accounts::asset.partials.name', ['asset' => $a]),
        ],
        [
            'key' => 'account',
            'label' => __('accounts::asset.account'),
            'render' => fn ($a) => $a->assetAccount?->label(),
        ],
        [
            'key' => 'acquired_on',
            'label' => __('accounts::asset.acquired_on'),
            'width' => '9rem',
            'render' => fn ($a) => $a->acquired_on?->format('d M Y'),
        ],
        [
            'key' => 'cost',
            'label' => __('accounts::asset.cost'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->cost]),
        ],
        [
            'key' => 'accumulated',
            'label' => __('accounts::asset.accumulated'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->accumulated()]),
        ],
        [
            'key' => 'book_value',
            'label' => __('accounts::asset.book_value'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($a) => view('accounts::asset.partials.amount', ['value' => $a->bookValue()]),
        ],
        [
            'key' => 'status',
            'label' => __('accounts::asset.status'),
            'width' => '8rem',
            'render' => fn ($a) => view('accounts::asset.partials.status', ['asset' => $a]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.title')"
                          :subtitle="__('accounts::asset.subtitle')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

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

    @can('accounts.asset.manage')
        {{--
            মাসটা ডিফল্টে গত মাস, চলতি মাস নয়।

            অবচয় বসে মাস শেষ হওয়ার পরে। ডিফল্টে চলতি মাস দিলে প্রতি
            মাসে কেউ না কেউ অর্ধেক মাসের ক্ষয় পুরো মাস হিসেবে বসিয়ে
            ফেলতেন, আর সংখ্যাটা দেখতে বৈধই লাগত।
        --}}
        <form method="POST" action="{{ route('accounts.asset.depreciate') }}"
              class="mb-5 flex flex-wrap items-end gap-3 rounded-(--radius-card) border
                     border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.run_month') }}</span>
                <input type="month" name="month" required value="{{ old('month', $defaultMonth) }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <x-ui.button type="submit" tone="primary">
                {{ __('accounts::asset.run_action') }}
            </x-ui.button>
        </form>

        <form method="POST" action="{{ route('accounts.asset.store') }}"
              x-data="{ method: '{{ old('method', \App\Modules\Accounts\Models\FixedAsset::STRAIGHT_LINE) }}' }"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
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

            <div class="flex items-end lg:col-start-4">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('accounts::asset.register_action') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    <x-ui.table :rows="$assets"
                :columns="$columns"
                :empty="__('accounts::asset.empty')" />

    {{ $assets->links() }}
</x-layouts.app>
