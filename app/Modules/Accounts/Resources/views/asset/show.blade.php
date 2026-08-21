{{--
    একটা সম্পদের পাতা।

    উপরে চারটা সংখ্যা, নিচে মাসে মাসে ক্ষয়ের ইতিহাস। ইতিহাসটাই এই
    পাতার আসল কাজ: "গত জুনেরটা কি বসানো হয়েছিল" প্রশ্নের উত্তর আর
    কোথাও নেই।
--}}
@php
    /* কলাম ধরে, স্লটে নয় — কম্পোনেন্ট স্লট পড়ে না। */
    $columns = [
        [
            'key' => 'period_end',
            'label' => __('accounts::asset.period'),
            'render' => fn ($e) => $e->period_end?->format('M Y'),
        ],
        [
            'key' => 'amount',
            'label' => __('accounts::asset.amount'),
            'numeric' => true,
            'render' => fn ($e) => view('accounts::asset.partials.amount', ['value' => $e->amount]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $asset->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$asset->name"
                          :subtitle="$asset->document_no" />
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

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['accounts::asset.cost', (string) $asset->cost],
            ['accounts::asset.accumulated', $asset->accumulated()],
            ['accounts::asset.book_value', $asset->bookValue()],
            ['accounts::asset.next_month', $nextAmount],
        ] as [$label, $value])
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($value) }}</p>
            </div>
        @endforeach
    </div>

    @if ($asset->isActive())
        @can('accounts.asset.manage')
            <form method="POST" action="{{ route('accounts.asset.dispose', $asset) }}"
                  class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
                @csrf

                <p class="text-sm font-semibold lg:col-span-4">{{ __('accounts::asset.dispose_title') }}</p>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.disposal_amount') }}</span>
                    <input type="number" step="0.01" min="0" name="disposal_amount" required value="0"
                           class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-2 text-end">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.into_account') }}</span>
                    <select name="into_account_id" required
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.disposed_on') }}</span>
                    <x-ui.date name="disposed_on" :required="true" :value="now()->toDateString()" />
                </label>

                <div class="flex items-end">
                    <x-ui.button type="submit" class="w-full">
                        {{ __('accounts::asset.dispose_action') }}
                    </x-ui.button>
                </div>
            </form>
        @endcan
    @endif

    <x-ui.table :rows="$asset->depreciation"
                :columns="$columns"
                :empty="__('accounts::asset.empty_entries')" />

</x-layouts.app>
