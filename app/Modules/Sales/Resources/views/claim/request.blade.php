{{--
    স্লিপসহ জমার অনুরোধ — কর্মীর হাতে (মালিক, ১ অক্টোবর ২০২৬, [[DepositRequestController]])।

    ⓘ এটা অনুরোধ, টাকা নয়: হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই আদায় — উপরের বাক্যটা সেটাই বলে,
    নাহলে SR ধরে নিতেন পাঠালেই বকেয়া কমে গেছে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::slip.title') }}</x-slot:title>

    <form method="POST" action="{{ route('sales.claim.request.store') }}" enctype="multipart/form-data"
          x-data="{ method: @js(old('method', 'bank')) }"
          class="mx-auto grid max-w-xl gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4">
        @csrf

        <h1 class="text-lg font-semibold">{{ __('sales::slip.title') }}</h1>
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::slip.hint') }}</p>

        <x-ui.errors />

        <x-ui.select name="customer" :label="__('sales::slip.customer')"
                     :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                     :selected="old('customer')" placeholder="-" required />

        <x-ui.field name="claimed_on" type="date" :label="__('sales::portal.claimed_on')"
                    :value="old('claimed_on', now()->toDateString())" required />

        <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal" numeric required
                    :label="__('sales::portal.amount')" :value="old('amount')" />

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.method') }}</span>
            <select name="method" x-model="method"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-app) px-3">
                <option value="bank">{{ __('sales::portal.bank') }}</option>
                <option value="mfs">{{ __('sales::portal.mfs') }}</option>
                <option value="cash">{{ __('sales::portal.cash') }}</option>
            </select>
        </label>

        <label class="block" x-show="method === 'bank'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.bank_account') }}</span>
            <select name="bank_account_id"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-app) px-3">
                <option value="">—</option>
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}" @selected((string) old('bank_account_id') === (string) $bank->id)>{{ $bank->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block" x-show="method !== 'cash'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.reference') }}</span>
            <input type="text" name="reference" value="{{ old('reference') }}" maxlength="64"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3">
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::slip.slip') }}</span>
            {{-- ⓘ ফোনে খুললে সরাসরি ক্যামেরা — capture --}}
            <input type="file" name="slip" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"
                   data-slip-input
                   class="block w-full text-sm">
        </label>

        <x-ui.field name="note" :label="__('sales::portal.note')" :value="old('note')" />

        <x-ui.button type="submit" tone="primary">{{ __('sales::portal.send') }}</x-ui.button>
    </form>
</x-layouts.app>
