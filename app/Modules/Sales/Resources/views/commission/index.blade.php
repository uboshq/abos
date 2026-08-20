{{--
    ডিলারের কমিশন — দেওয়া, আর কোম্পানির সিদ্ধান্ত বসানো।

    উপরে বসানোর ফর্ম, নিচে তালিকা। মাস শেষে কোম্পানির লোক বসে সারি
    ধরে ধরে বলেন কোনটা মানা হলো — তাই সিদ্ধান্তের বোতাম দুইটা সারিতেই,
    আলাদা পাতায় নয়।
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
                          :subtitle="__('sales::message.commission_note')" />
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
    <div class="mb-4 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-3">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ __('sales::field.commission_pending_total') }}
        </p>
        <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($pendingTotal) }}</p>
    </div>

    @can('sales.commission.manage')
        <form method="POST" action="{{ route('sales.commission.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.date') }}</span>
                <x-ui.date name="trx_date"
                           :value="old('trx_date', now()->toDateString())" />
                           </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('customer::menu.party') }}</span>
                <select name="customer_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                            {{ $customer->name() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('supplier::menu.party') }}</span>
                <select name="supplier_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                            {{ $supplier->name() }}
                        </option>
                    @endforeach
                </select>
            </label>

            {{--
                ভিত্তি — শতাংশ কিসের উপর বসবে।

                বিলের সাথে জোড়া দিলে বিলের অঙ্কই ভিত্তি হয়ে যায়, তাই
                ঘরটা কেবল নগদে দেওয়া কমিশনের জন্য।
            --}}
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_base') }}</span>
                <input type="number" step="0.01" min="0" name="base_amount" value="{{ old('base_amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_percent') }}</span>
                <input type="number" step="0.01" min="0" max="100" name="rate_percent" value="{{ old('rate_percent') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_flat') }}</span>
                <input type="number" step="0.01" min="0" name="rate_amount" value="{{ old('rate_amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1 md:col-span-2 lg:col-span-5">
                <span class="text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    @if ($claims->isEmpty())
        <x-ui.empty-state :message="__('sales::message.no_commissions')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$claims"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />
        </div>

        <div class="mt-3">{{ $claims->links() }}</div>
    @endif
</x-layouts.app>
