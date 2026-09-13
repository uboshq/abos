@props([
    'name',
    'accounts',
    'label' => null,
    'selected' => null,
    'compact' => false,

    /* খালি বিকল্পের লেখা — কেবল তখন, যখন "না দেওয়া"-রও একটা অর্থ আছে। */
    'blank' => null,

    /* নামের আগে কোড — হিসাবরক্ষকের পর্দায় লাগে, কাউন্টারে নয়। */
    'codes' => false,

    /*
     * লেনদেন নম্বরের ঘরটার নাম — ভাউচারের কলামও এই নামেই।
     *
     * ⚠️ `false` দিলে ঘরটা আসে না, আর সেটা লাগে যখন একই ফর্মে দুইটা
     * খাত বাছা হয় কিন্তু **এখন** টাকা নড়ে একটাতেই। আমানতের ফর্মে
     * "কোথা থেকে টাকা গেল" আর "লাভ কোথায় আসবে" — দ্বিতীয়টা ভবিষ্যতের
     * কথা, আর দুইটাতেই ঘর দিলে একই নামের দুইটা ঘর জমা হত, আর পরেরটা
     * আগেরটাকে মুছে দিত।
     */
    'reference' => 'instrument_no',
])

{{--
    টাকা কোন খাতে — আর ব্যাংক বা MFS হলে তার লেনদেন নম্বরও।

    ── কেন দুইটা ঘর একসাথে, আলাদা নয় ───────────────────────────────────
    ⛔ ১৩ সেপ্টেম্বর ২০২৬-এ মালিক মূলধনের টাকা ব্যাংকে ঢোকাতে গিয়ে
    আটকে গেছেন। পর্দায় ছিল কেবল খাত বাছার একটা ঘর আর একটা বোতাম;
    ব্যাংক বাছলে সার্ভার বলত *"Money moving through 1109 — ibbl needs
    its bank or MFS transaction number"* — **অথচ সেই নম্বর লেখার কোনো
    ঘর পর্দায় ছিল না**। টাকাটা ঢোকানোই যেত না, আর কোনো পথও ছিল না।

    নিয়মটা নিজে ঠিক ([[VoucherService::assertBankReferenceIsFree()]]):
    একই ব্যাংক লেনদেন দুইবার খাতায় ওঠা আটকাতে নম্বরটা লাগে, আর
    ডাটাবেসে `vouchers_bank_reference_unique` সেটা পাহারা দেয়।
    ভুলটা ছিল **প্রশ্নটা আর নিয়মটা দুই জায়গায় থাকা** — নিয়ম Accounts-এ,
    ঘরটা প্রতিটা পর্দার নিজের হাতে। ছয়টা পর্দায় ঘরটা বসানোই হয়নি।

    ⭐ তাই এখন দুইটা একসাথে বসে, একটাই উপাদানে: যে পর্দা খাত বাছতে দেয়,
    সে কিছু না করেই নম্বরের ঘরটাও পায়। সপ্তম পর্দা এলে সে-ও পাবে।

    ── কেন নগদে চাওয়া হয় না ────────────────────────────────────────────
    নগদের কোনো TrxID নেই। চাইলে প্রতিটা নগদ ভাউচারে একটা বানানো নম্বর
    বসত, আর তখন নম্বরটা কিছুই প্রমাণ করত না।
--}}
@php
    $accounts = collect($accounts);
@endphp

<div x-data="{
        chosen: '{{ old($name, $selected) }}',

        /*
         * বাছা খাতটা কোন ধরনের — 'cash' হলে নম্বর লাগে না।
         *
         * ⚠️ ধরনটা প্রতিটা option-এ `data-kind`-এ বসে, তালিকার আলাদা
         * কোনো নকলে নয়। দুই জায়গায় রাখলে একটা তালিকা ছাঁকা হত আর
         * অন্যটা নয়, আর তখন নম্বরের ঘরটা ভুল খাতে ভেসে উঠত।
         */
        get kind() {
            const option = this.$refs.picker?.selectedOptions?.[0];
            return option?.dataset?.kind ?? '';
        },
        get needsReference() {
            return this.kind === 'bank' || this.kind === 'mfs';
        },
     }"
     class="grid min-w-0 gap-2 {{ $compact ? '' : 'sm:grid-cols-2' }}">

    <label class="block">
        @if ($label)
            <span class="mb-1 block text-sm font-medium">{{ $label }}</span>
        @endif

        <select name="{{ $name }}" x-ref="picker" x-model="chosen"
                {{ $attributes->class([
                    'w-full min-w-0 rounded-(--radius-field) border border-(--color-border)',
                    'bg-(--color-surface-card)',
                    'h-(--spacing-field-compact) px-2 text-2xs' => $compact,
                    'h-(--spacing-field) px-3' => ! $compact,
                ]) }}>
            @if ($blank !== null)
                <option value="">{{ $blank }}</option>
            @else
                <option value="" disabled selected>{{ __('accounts::field.money_account_pick') }}</option>
            @endif

            @foreach ($accounts as $account)
                <option value="{{ $account->id }}"
                        data-kind="{{ $account->money_kind }}"
                        @selected(old($name, $selected) == $account->id)>
                    @if ($codes){{ $account->code }} — @endif{{ $account->name() }}
                </option>
            @endforeach
        </select>
    </label>

    {{-- ⓘ `x-show` যথেষ্ট, `x-if` নয় — নামটা এই ফর্মে একবারই আছে, তাই
         লুকানো ঘরটা কিছু মুছে দেয় না। আর নগদ বেছে আবার ব্যাংক বাছলে
         লেখা নম্বরটা থেকে যায়, যেটা `x-if` হলে হারাত। --}}
    @if ($reference !== false)
    <label x-show="needsReference" x-cloak class="block">
        <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.bank_reference') }}</span>

        <input type="text" name="{{ $reference }}"
               value="{{ old($reference) }}"
               x-bind:required="needsReference"
               placeholder="{{ __('accounts::message.bank_reference_placeholder') }}"
               class="w-full min-w-0 rounded-(--radius-field) border border-(--color-border)
                      bg-(--color-surface-card)
                      {{ $compact ? 'h-(--spacing-field-compact) px-2 text-2xs' : 'h-(--spacing-field) px-3' }}">

        @unless ($compact)
            <span class="mt-1 block text-2xs text-(--color-ink-muted)"
                  x-text="kind === 'mfs'
                      ? '{{ __('accounts::message.reference_hint_mfs') }}'
                      : '{{ __('accounts::message.reference_hint_bank') }}'"></span>
        @endunless
    </label>

    @error($reference)
        <p class="text-2xs text-(--color-danger) sm:col-span-2">{{ $message }}</p>
    @enderror
    @endif
</div>
