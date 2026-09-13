{{--
    নতুন কমিশনের দাবি — নিজের পাতায়।

    ⓘ সিদ্ধান্তের দুইটা বোতাম (মেনেছে · মানেনি) এখানে নেই, আর থাকার
    কথাও নয়: মাস শেষে কোম্পানির লোক বসে সারি ধরে ধরে বলেন কোনটা মানা
    হলো — ওটা তালিকার পাতার কাজ, প্রতিটার জন্য আলাদা পাতা নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::action.new_commission') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::action.new_commission')"
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

    <form method="POST" action="{{ route('sales.commission.store') }}"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
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

        <label class="flex flex-col gap-1 md:col-span-3 lg:col-span-6">
            <span class="text-sm font-medium">{{ __('core.table.narration') }}</span>
            <input type="text" name="narration" value="{{ old('narration') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <div class="flex flex-wrap items-end gap-2 md:col-span-3 lg:col-span-6">
            <x-ui.button type="submit" tone="primary">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('sales.commission.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
