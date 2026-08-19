{{--
    এক ধরনের ভাউচারের তালিকা।

    সবচেয়ে নতুনটা উপরে — ভাউচারের তালিকায় লোকে আজকের কাজ দেখতে আসে।
    অবস্থাটা কলাম হিসেবে আছে, কারণ খসড়া আর পোস্ট করা ভাউচারের মধ্যে
    পার্থক্যটা মৌলিক: একটা হিসাবে আছে, অন্যটা নেই।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($v) => view('accounts::voucher.partials.number', ['voucher' => $v])],
        ['key' => 'narration', 'label' => __('core.table.narration')],
        ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)],
        ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
         'render' => fn ($v) => view('accounts::voucher.partials.status', ['voucher' => $v])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.' . $type) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::voucher.' . $type)"
            :subtitle="trans_choice('accounts::message.voucher_count', $vouchers->total(), ['count' => $vouchers->total()])">
            <x-slot:actions>
                @can('accounts.voucher.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.voucher.create', $type)">
                        {{ __('accounts::action.new_voucher') }}
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
                :sort="$sortOptions"
                :columns="$columns">
                {{-- তারিখের পরিসর — ভাউচারের তালিকায় এটাই সবচেয়ে বেশি
                     ব্যবহৃত ফিল্টার, তাই লুকানো নয় --}}
                <x-ui.date name="from"
                           value="{{ request('from') }}"
                           aria-label="{{ __('accounts::field.from_date') }}"
                           :submit-on-change="true"
                           class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                           <x-ui.date name="to"
                           value="{{ request('to') }}"
                           aria-label="{{ __('accounts::field.to_date') }}"
                           :submit-on-change="true"
                           class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                           </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_vouchers')"
            :rows="$vouchers"
            :columns="$columns" />

        @if ($vouchers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $vouchers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
