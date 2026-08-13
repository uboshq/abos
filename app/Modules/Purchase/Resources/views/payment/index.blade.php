{{--
    পরিশোধের তালিকা — সবচেয়ে নতুনটা উপরে।

    এখানে লোকে আসে "আজ কাকে কত দিলাম" দেখতে, তাই তারিখই প্রথম কলাম আর
    সাম্প্রতিকটাই ডিফল্ট বাছাই।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('purchase::field.date'), 'width' => '7rem',
         'render' => fn ($p) => \App\Core\Support\DateFormat::format($p->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($p) => view('purchase::components.doc-link', [
             'document' => $p, 'route' => 'purchase.payment.show'])],
        ['key' => 'supplier', 'label' => __('purchase::field.supplier'),
         'render' => fn ($p) => $p->supplier?->name()],
        ['key' => 'account', 'label' => __('purchase::field.account'), 'width' => '11rem',
         'render' => fn ($p) => $p->account?->name()],
        ['key' => 'amount', 'label' => __('purchase::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($p) => \App\Core\Support\Money::format($p->amount)],
        ['key' => 'status', 'label' => __('purchase::field.state'), 'width' => '8rem',
         'render' => fn ($p) => view('purchase::components.status-badge', ['document' => $p])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.payments') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('purchase::menu.payments')"
            :subtitle="trans_choice('core.count.records', $payments->total(), ['count' => $payments->total()])">
            <x-slot:actions>
                @can('purchase.payment.create')
                    <x-ui.button tone="primary" icon="+" :href="route('purchase.payment.create')">
                        {{ __('purchase::action.new_payment') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :columns="$columns" :search-placeholder="__('purchase::message.payment_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_payments')"
            :rows="$payments"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        @if ($payments->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $payments->links() }}</div>
        @endif
    </div>
</x-layouts.app>
