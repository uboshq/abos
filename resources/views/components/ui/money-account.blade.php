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

    /*
     * চার্জের ঘরের নাম — `false` দিলে ঘরটা আসে না।
     *
     * ⭐ ১৩ সেপ্টেম্বর ২০২৬: মালিক ধরিয়ে দিলেন যে ব্যাংক ও বিকাশ দুইটাই
     * চার্জ কাটে, আর সেই চার্জ কোথাও লেখা হত না। ⓘ ছকে খাত দুইটা
     * (`5210`, `5211`) আগে থেকেই ছিল, কেবল কেউ ব্যবহার করত না।
     *
     * ⚠️ কেবল ঐ পর্দাগুলোতেই দিতে হবে যেখানে সেবাটা চার্জ নিয়ে কিছু
     * করে — না দিলে ঘরটা আসবে, আর সংখ্যাটা নীরবে হারিয়ে যাবে।
     */
    'charge' => false,
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
     {{-- ⭐ সরু জায়গায় ঘর দুইটা **উপর-নিচে**, পাশাপাশি নয়।

          তালিকার ঘরে দুইটা ইনপুট পাশাপাশি বসালে দুইটাই এত সরু হয় যে
          কোনোটাই পড়া যায় না। ⓘ উপর-নিচে বসালে সারিটা দুই লাইন উঁচু
          হয় — কিন্তু ওটা তখনই, যখন ব্যাংক বাছা হয়েছে। --}}
     class="grid min-w-0 {{ $compact ? 'gap-1' : 'gap-2 sm:grid-cols-2' }}">

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
        {{-- ⛔ সরু জায়গায় লেবেলটা লেখা হয় না।

             ── কী ভেঙেছিল, ১৩ সেপ্টেম্বর ২০২৬ ────────────────────────
             উপাদানটা তালিকার একটা ঘরের ভিতরে বসানো হয়েছিল (মূলধনের
             "টাকা এসেছে" সারি)। ঘরটা দুইশো পিক্সেলের, আর লেবেলটা
             "ব্যাংক/MFS লেনদেন নম্বর" — ফলে লেখাটা **লম্বালম্বি ভেঙে**
             প্রতিটা সারিকে পাঁচ লাইন উঁচু করে দিয়েছিল, আর ইনপুটটা
             "Cheque n" পর্যন্ত দেখাত।

             ⭐ সরু জায়গায় placeholder-ই লেবেল। ⚠️ স্ক্রিন রিডারের জন্য
             `aria-label` থাকে, নাহলে ঘরটা চোখে বোঝা যেত আর কানে নয়। --}}
        @unless ($compact)
            <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.bank_reference') }}</span>
        @endunless

        <input type="text" name="{{ $reference }}"
               @if ($compact) aria-label="{{ __('accounts::field.bank_reference') }}" @endif
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

    {{-- চার্জ — ব্যাংক বা MFS-এ, নগদে নয়।

         ⓘ নগদে কেউ কিছু কাটে না, তাই ঘরটা আসেই না। ⚠️ আর ঘরটা ফাঁকা
         রাখা যায়: বেশিরভাগ জমায় চার্জ থাকে না, আর প্রতিবার শূন্য লিখতে
         বাধ্য করা মানে রোজকার কাজে একটা বাড়তি ধাপ। --}}
    @if ($charge !== false)
        <label x-show="needsReference" x-cloak class="block">
            @unless ($compact)
                <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.money_charge') }}</span>
            @endunless

            <input type="text" inputmode="decimal" name="{{ $charge }}"
                   value="{{ old($charge) }}"
                   @if ($compact) aria-label="{{ __('accounts::field.money_charge') }}" @endif
                   placeholder="{{ __('accounts::field.money_charge') }}"
                   class="num w-full min-w-0 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) text-end
                          {{ $compact ? 'h-(--spacing-field-compact) px-2 text-2xs' : 'h-(--spacing-field) px-3' }}">

            @unless ($compact)
                <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::message.charge_hint') }}
                </span>
            @endunless
        </label>
    @endif

    {{-- ⛔ সরু জায়গায় ভুলের বার্তা এখানে নয়।

         একই তালিকার প্রতিটা সারি এই উপাদানটা আঁকে, আর ভুলের ঝুড়ি
         সারিভিত্তিক নয় — পাতার। ফলে একটা সারিতে ভুল হলে বার্তাটা
         **প্রতিটা সারিতে** ছাপা হত, লাল রঙে, পাতার মাথার বার্তাটা
         ছাড়াও। ⓘ দুইটা সারির পর্দায় সেটা দুইবার; বিশটা সারিতে বিশবার।

         ⭐ পাতার মাথার বার্তাটাই যথেষ্ট — ওটা একবার, আর সেখানে কোন
         খাতের কথা বলা হচ্ছে তাও লেখা থাকে। --}}
    @unless ($compact)
        @error($reference)
            <p class="text-2xs text-(--color-danger) sm:col-span-2">{{ $message }}</p>
        @enderror
    @endunless
    @endif
</div>
