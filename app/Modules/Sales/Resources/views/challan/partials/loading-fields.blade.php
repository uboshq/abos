{{--
    ⭐ লোডিং খরচের ঘর — অফিসের চালান, পাকা চালানের পরিবহন পাতা আর কাউন্টার, তিন জায়গায় একই ঘর (মালিক, ১৮ নভেম্বর ২০২৬:
    "ওকে"; [[ChallanLoading]], [[LabourCost]])। ⓘ তিন পথ — এখনই নগদে (নিজের টিল) · ঠিকাদারের কাছে বাকি · লাগেনি (কারণ)।
    ⓘ সুইচ `sales.loading.mandatory` বন্ধ থাকলে চতুর্থ বোতাম "এখনো বাছা হয়নি" — খালি পাঠালে কিছুই বসে না; চালু থাকলে
      বোতামটা নেই, পাশে "বাধ্যতামূলক"। আসল পাহারা সেবায় ([[ChallanLoading::assertSet()]]) — পর্দার চিহ্ন কেবল ভদ্রতা।
    ⓘ লুকানো ঘরগুলোও যায় — সেবা পথ ধরে কেবল দরকারি ঘর পড়ে ([[LabourCost::read()]]), তাই বন্ধ করার দরকার নেই।

    চাই (সবই ঐচ্ছিক):
      $loadingValues  — ['mode', 'amount', 'account_id', 'contractor_id', 'reason'] — old() না থাকলে এগুলো
      $compact        — কাউন্টারের ছোট ঘর
      $modeRequired   — পথ বাছাই লাগবেই (পাকা চালানে লেখার ফর্ম)
--}}
@php
    $lv = $loadingValues ?? [];
    $lc = (bool) ($compact ?? false);
    $lForced = (bool) ($modeRequired ?? false);
    $lMandatory = $lForced || app(\App\Modules\Sales\Services\ChallanLoading::class)->mandatory();
    $lLabour = app(\App\Modules\Supplier\Services\LabourCost::class);
    $lTills = $lLabour->cashTills();
    $lContractors = $lLabour->contractors();
    $lMode = (string) old('loading_mode', $lv['mode'] ?? '');
    $lAccount = (string) old('loading_account_id', $lv['account_id'] ?? '');
    $lContractor = (string) old('loading_contractor_id', $lv['contractor_id'] ?? '');
    $lAmount = old('loading_amount', $lv['amount'] ?? '');
    // ⓘ খাতার "0" মানে অঙ্ক নেই — ঘরে শূন্য দেখালে মানুষ ভাবতেন শূন্য টাকা লেখা আছে
    $lAmount = is_numeric($lAmount) && bccomp((string) $lAmount, '0', 4) === 0 ? '' : (string) $lAmount;
    $lInput = $lc
        ? 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-2xs'
        : 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
    $lLabel = 'mb-1 block text-2xs text-(--color-ink-muted)';
@endphp

<div class="grid gap-2" data-loading-fields x-data="{ lm: @js($lMode) }">
    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
        {{ __('supplier::labour.loading') }}
        @if ($lMandatory)
            <span class="text-2xs text-(--color-badge-danger-ink)" data-loading-required>* {{ __('sales::loading_cost.required') }}</span>
        @else
            <span class="text-2xs font-normal text-(--color-ink-muted)">{{ __('sales::loading_cost.optional') }}</span>
        @endif
    </p>

    <span class="text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.how') }}</span>
    <div class="ds-seg" role="radiogroup" data-loading-mode>
        @foreach (\App\Modules\Supplier\Services\LabourCost::LOADING as $choice)
            <button type="button" @click="lm = '{{ $choice }}'" :class="lm === '{{ $choice }}' ? 'is-on' : ''">{{ __('supplier::labour.mode_'.$choice) }}</button>
        @endforeach
        @unless ($lMandatory)
            <button type="button" @click="lm = ''" :class="lm === '' ? 'is-on' : ''">{{ __('supplier::labour.not_chosen') }}</button>
        @endunless
    </div>
    <input type="hidden" name="loading_mode" :value="lm" value="{{ $lMode }}">

    <div class="grid gap-2 sm:grid-cols-2" x-show="lm === 'cash' || lm === 'due'">
        <label class="block">
            <span class="{{ $lLabel }}">{{ __('supplier::labour.amount') }}</span>
            <input type="number" step="0.01" min="0" inputmode="decimal" name="loading_amount" value="{{ $lAmount }}"
                   class="num text-end {{ $lInput }}" data-loading-amount>
        </label>

        <label class="block" x-show="lm === 'cash'">
            <span class="{{ $lLabel }}">{{ __('supplier::labour.till') }}</span>
            <select name="loading_account_id" class="{{ $lInput }}">
                <option value="">{{ __('sales::loading_cost.pick_till') }}</option>
                @foreach ($lTills as $till)
                    <option value="{{ $till['id'] }}" @selected($lAccount === $till['id'])>{{ $till['label'] }}</option>
                @endforeach
            </select>
        </label>

        <div class="block" x-show="lm === 'due'">
            @if ($lContractors->isEmpty())
                <p class="text-2xs text-(--color-badge-danger-ink)" data-loading-no-contractor>{{ __('supplier::labour.no_contractor_party') }}</p>
                <input type="hidden" name="loading_contractor_id" value="">
            @else
                <label class="block">
                    <span class="{{ $lLabel }}">{{ __('supplier::labour.contractor') }}</span>
                    <select name="loading_contractor_id" class="{{ $lInput }}">
                        <option value="">{{ __('sales::loading_cost.pick_contractor') }}</option>
                        @foreach ($lContractors as $who)
                            <option value="{{ $who['id'] }}" @selected($lContractor === $who['id'])>{{ $who['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
    </div>

    <label class="block" x-show="lm === 'none'">
        <span class="{{ $lLabel }}">{{ __('supplier::labour.reason') }}</span>
        <input type="text" name="loading_reason" maxlength="255" value="{{ old('loading_reason', $lv['reason'] ?? '') }}"
               class="{{ $lInput }}">
    </label>

    @unless ($lForced)
        <p class="text-2xs text-(--color-ink-muted)">{{ $lMandatory ? __('sales::loading_cost.required_hint') : __('sales::loading_cost.optional_hint') }}</p>
    @endunless

    @foreach (\App\Modules\Supplier\Services\LabourCost::fields('loading_') as $lField)
        @error($lField)
            <p class="text-sm text-(--color-danger)" data-loading-error>{{ $message }}</p>
        @enderror
    @endforeach
</div>
