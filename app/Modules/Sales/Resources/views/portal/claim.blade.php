{{--
    জমার কথা জানানোর ফর্ম।

    উপরে একটা বাক্য যা সবচেয়ে জরুরি: এতে বকেয়া নিজে থেকে কমে না।
    ওটা না বললে গ্রাহক পাঠিয়ে দিয়ে ধরে নিতেন কাজ শেষ, আর পরের বিলে
    বকেয়া দেখে তর্ক শুরু হত।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="mb-1 text-lg font-semibold">{{ __('sales::portal.claim_title') }}</h1>
    <p class="mb-4 text-sm text-(--color-ink-muted)">{{ __('sales::portal.claim_hint') }}</p>

    <form method="POST" action="{{ route('sales.portal.claim.store') }}" enctype="multipart/form-data"
          x-data="{ method: @js(old('method', 'bank')) }"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4">
        @csrf

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.claimed_on') }}</span>
            <x-ui.date name="claimed_on" :required="true"
                       :value="old('claimed_on', now()->toDateString())" />
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.amount') }}</span>
            <input type="number" step="0.01" min="0" name="amount" required value="{{ old('amount') }}"
                   class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3 text-end">
        </label>

        {{-- ⭐ কোন বিলের বিপরীতে — ঐচ্ছিক (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬; [[DepositClaimService::raise()]]) --}}
        <fieldset class="block" data-claim-bills>
            <legend class="mb-1 block text-sm font-medium">{{ __('sales::slip.bills') }}</legend>
            <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('sales::slip.bills_hint') }}</p>
            @forelse ($openBills as $n => $bill)
                <div class="mb-1 flex items-center gap-2 text-sm">
                    <span class="flex-1">{{ $bill->document_no }} <span class="text-2xs text-(--color-ink-muted)">{{ $bill->trx_date?->format('d M Y') }}</span></span>
                    <span class="num text-2xs text-(--color-ink-muted)">{{ __('sales::slip.bill_due') }} {{ \App\Core\Support\Money::format($bill->dueAmount()) }}</span>
                    <input type="hidden" name="bills[{{ $n }}][invoice]" value="{{ $bill->public_id }}">
                    <input type="number" step="0.01" min="0" max="{{ bcadd($bill->dueAmount(), '0', 2) }}"
                           name="bills[{{ $n }}][amount]" value="{{ old('bills.'.$n.'.amount') }}"
                           aria-label="{{ __('sales::slip.bill_pay') }} {{ $bill->document_no }}"
                           class="num h-(--spacing-field) w-32 shrink-0 rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                </div>
            @empty
                <p class="text-sm text-(--color-ink-muted)">{{ __('sales::slip.bills_none') }}</p>
            @endforelse
        </fieldset>

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

        {{-- নগদে কোনো রেফারেন্স থাকে না, তাই ঘরটাও থাকে না। --}}
        <label class="block" x-show="method !== 'cash'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.reference') }}</span>
            <input type="text" name="reference" value="{{ old('reference') }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3">
            <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                {{ __('sales::portal.reference_hint') }}
            </span>
        </label>

        <label class="block" x-show="method === 'bank'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.bank_account') }}</span>
            <select name="bank_account_id"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-app) px-3">
                <option value="">—</option>
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                @endforeach
            </select>
        </label>

        {{-- ⭐ স্লিপের ছবি — ১ অক্টোবর ২০২৬ ([[DepositSlip]]); ফোনে খুললে সরাসরি ক্যামেরা --}}
        <label class="block" x-show="method !== 'cash'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::slip.slip') }}</span>
            <input type="file" name="slip" accept="image/jpeg,image/png,image/webp,application/pdf"
                   capture="environment" data-slip-input class="block w-full text-sm">
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.note') }}</span>
            <input type="text" name="note" value="{{ old('note') }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3">
        </label>

        <button type="submit"
                class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500)
                       font-medium text-white">
            {{ __('sales::portal.send') }}
        </button>
    </form>
</x-sales::portal.layout>
