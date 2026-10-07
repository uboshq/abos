{{-- ⓘ ভাড়ার টাকা — কোন খাত থেকে, TrxID, কে দিলেন ([[FarePayment::moneyFrom()]]-এর ঘর)। ⓘ নগদে "কে দিলেন" সার্ভার নিজেই
     লগইন করা মানুষ বসায় (বাছাটা উপেক্ষা হয়); TrxID কেবল ব্যাংক বা MFS-এ লাগে — নগদে খালি রাখলেই চলে। --}}
<div class="grid gap-2 sm:grid-cols-3">
    <label class="block">
        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::fare.account') }}</span>
        <select name="fare_account_id" class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            <option value="">{{ __('sales::fare.pick_account') }}</option>
            @foreach ($options->moneyAccounts() as $account)
                <option value="{{ $account['id'] }}" @selected((string) old('fare_account_id') === $account['id'])>{{ $account['label'] }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::fare.reference') }}</span>
        <input type="text" name="fare_reference" maxlength="64" value="{{ old('fare_reference') }}"
               class="num h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
    </label>
    <label class="block">
        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::fare.payer') }}</span>
        <select name="fare_payer_id" class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            @foreach ($options->farePayers() as $payer)
                <option value="{{ $payer['id'] }}" @selected((string) old('fare_payer_id', (string) auth()->id()) === $payer['id'])>{{ $payer['label'] }}</option>
            @endforeach
        </select>
    </label>
</div>
