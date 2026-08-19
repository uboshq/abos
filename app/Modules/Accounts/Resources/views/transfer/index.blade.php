{{--
    টাকা হস্তান্তরের তালিকা।

    "আপনার গ্রহণের অপেক্ষায়" আলাদা করে উপরে: একজন ডেলিভারি ম্যান দিনে
    একবারই এই পর্দায় আসে, আর তার একটাই কাজ। সেটা তালিকার মাঝখানে
    খুঁজতে হলে অর্ধেক দিন কেউ গ্রহণ করে না, আর টাকা কার হিসাবে তা
    অস্পষ্ট থাকে — যা এই পুরো ব্যবস্থার উদ্দেশ্যের বিপরীত।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($t) => \App\Core\Support\DateFormat::format($t->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($t) => view('accounts::transfer.partials.number', ['transfer' => $t])],
        ['key' => 'from_till_id', 'label' => __('accounts::field.moved_from'),
         'render' => fn ($t) => $t->fromTill?->name() . ($t->giver ? ' — ' . $t->giver->name : '')],
        ['key' => 'to_till_id', 'label' => __('accounts::field.moved_to'),
         'render' => fn ($t) => $t->destinationName() . ($t->receiver ? ' — ' . $t->receiver->name : '')],
        ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($t) => \App\Core\Support\Money::format($t->amount)],
        ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '9rem',
         'render' => fn ($t) => view('accounts::transfer.partials.status', ['transfer' => $t])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.money_transfer') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($awaiting->isNotEmpty())
        <section class="mb-4 rounded-(--radius-card) border border-(--color-brand-500)
                        bg-(--color-surface-selected) p-4">
            <h2 class="font-semibold">{{ __('accounts::message.awaiting_you') }}</h2>

            <ul class="mt-3 space-y-2">
                @foreach ($awaiting as $item)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-field)
                               bg-(--color-surface-card) px-3 py-2">
                        <span class="min-w-0">
                            <span class="block text-sm">
                                <a href="{{ route('accounts.transfer.show', $item) }}"
                                   class="num text-(--color-brand-500) underline-offset-2 hover:underline">
                                    {{ $item->document_no }}
                                </a>
                                — {{ $item->fromTill?->name() }}
                            </span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ $item->giver?->name }} · {{ \App\Core\Support\DateFormat::format($item->trx_date) }}
                            </span>
                        </span>

                        <span class="flex items-center gap-3">
                            <span class="num font-semibold">{{ \App\Core\Support\Money::format($item->amount) }}</span>

                            <form method="POST" action="{{ route('accounts.transfer.confirm', $item) }}">
                                @csrf
                                <x-ui.button type="submit" tone="primary">
                                    {{ __('accounts::action.receive') }}
                                </x-ui.button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::menu.money_transfer')"
                :sort="$sortOptions"
                :columns="$columns">
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" :href="route('accounts.transfer.create')">
                    {{ __('accounts::action.new_transfer') }}
                </x-ui.button>
        </x-slot:actions>
        </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_transfers')"
            :rows="$transfers"
            :columns="$columns" />

        @if ($transfers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $transfers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
