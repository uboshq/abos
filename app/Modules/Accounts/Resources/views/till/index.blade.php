{{--
    নগদ কাউন্টার — কার কাছে কত।

    এই পর্দাটার একটাই কাজ, আর সেটা একটা প্রশ্নের উত্তর: এই মুহূর্তে
    প্রতিষ্ঠানের নগদ টাকা কার কার হাতে আছে। তাই মোট টাকাটা উপরে, আর
    সীমা ছাড়ানো কাউন্টার চোখে পড়ার মতো করে।

    ব্যালেন্স আসে লেজার থেকে, কাউন্টারের নিজের কোনো কলাম থেকে নয় —
    দুই জায়গায় একই সংখ্যা রাখলে একদিন সেগুলো আলাদা হত।
--}}
@php
    $columns = [
        [
            'key' => 'code',
            'label' => __('accounts::field.code'),
            'width' => '9rem',
            'render' => fn ($t) => view('accounts::till.partials.code', ['till' => $t]),
        ],
        [
            'key' => 'name_en',
            'label' => __('accounts::field.name'),
            'render' => fn ($t) => view('accounts::till.partials.name', ['till' => $t]),
        ],
        [
            'key' => 'holder_id',
            'label' => __('accounts::field.holder'),
            'width' => '12rem',
            'render' => fn ($t) => $t->holder?->name ?? '—',
        ],
        [
            'key' => 'balance',
            'label' => __('accounts::field.in_hand'),
            'numeric' => true,
            'width' => '11rem',
            'render' => fn ($t) => view('accounts::till.partials.balance', [
                'till' => $t,
                'balance' => $balances[$t->id] ?? '0',
            ]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cash_tills') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::menu.cash_tills')" :count="__('accounts::message.till_total', ['amount' => \App\Core\Support\Money::format($total)])"
                :sort="$sortOptions"
                :columns="$columns">
        <x-slot:actions>
            @can('create', \App\Modules\Accounts\Models\CashTill::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.till.create')">
                        {{ __('accounts::action.new_till') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('accounts::action.show_closed') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_tills')"
            :rows="$tills"
            :columns="$columns" />
    </div>
</x-layouts.app>
