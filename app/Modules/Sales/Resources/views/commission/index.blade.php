{{--
    ডিলারের কমিশন — দেওয়া, আর কোম্পানির সিদ্ধান্ত বসানো।

    উপরে বাঁ কোণে "+ নতুন কমিশন" (আলাদা পাতা), নিচে তালিকা। মাস শেষে
    কোম্পানির লোক বসে সারি ধরে ধরে বলেন কোনটা মানা হলো — তাই
    সিদ্ধান্তের বোতাম দুইটা সারিতেই, আলাদা পাতায় নয়।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'document_no', 'label' => __('core.print.document_no')],
        ['key' => 'trx_date', 'label' => __('accounts::field.date'), 'width' => '9rem',
         'render' => fn ($c) => $c->trx_date?->format('d M Y')],
        ['key' => 'customer', 'label' => __('customer::menu.party'),
         'render' => fn ($c) => $c->customer?->name()],
        ['key' => 'supplier', 'label' => __('supplier::menu.party'),
         'render' => fn ($c) => $c->supplier?->name()],
        ['key' => 'rate', 'label' => __('sales::field.commission_rate'),
         'numeric' => true, 'width' => '9rem',
         'render' => fn ($c) => $c->describeRate()],
        ['key' => 'amount', 'label' => __('accounts::field.amount'),
         'numeric' => true, 'width' => '11rem',
         'render' => fn ($c) => \App\Core\Support\Money::format($c->amount)],
        ['key' => 'state', 'label' => __('accounts::field.state'), 'width' => '11rem',
         'render' => fn ($c) => view('sales::commission.partials.state', ['claim' => $c])],
        ['key' => 'actions', 'label' => __('core.table.actions'),
         'render' => fn ($c) => view('sales::commission.partials.actions', ['claim' => $c])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.commission') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.commission')"
                          :subtitle="__('sales::message.commission_note')">
            {{-- শর্তটা হুবহু সেটাই যেটায় আগে উপরের ফর্মটা দেখা যেত। --}}
            <x-slot:actions>
                @can('sales.commission.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.commission.create')">
                        {{ __('sales::action.new_commission') }}
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

    {{--
        এখনো কত কোম্পানির কাছে আটকে — পাতাটার একমাত্র যোগফল।

        মাস শেষে কোম্পানির লোককে বলা প্রথম সংখ্যাটা এটাই, তাই উপরে,
        আর বড় করে।
    --}}
    <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-3">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ __('sales::field.commission_pending_total') }}
        </p>
        <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($pendingTotal) }}</p>
    </div>

    @if ($claims->isEmpty())
        <x-ui.empty-state :message="__('sales::message.no_commissions')" />
    @else
        <div data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$claims"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />
        </div>

        <div class="mt-3">{{ $claims->links() }}</div>
    @endif
</x-layouts.app>
