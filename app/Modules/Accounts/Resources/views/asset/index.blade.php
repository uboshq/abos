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
                          :subtitle="__('accounts::asset.subtitle')">
            {{-- শর্তটা হুবহু সেটাই যেটায় আগে নিচের ফর্মটা দেখা যেত। --}}
            <x-slot:actions>
                @can('accounts.asset.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.asset.create')">
                        {{ __('accounts::action.new_asset') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
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
    @endcan

    <x-ui.table :rows="$assets"
                :columns="$columns"
                :empty="__('accounts::asset.empty')" />

    {{ $assets->links() }}
</x-layouts.app>
