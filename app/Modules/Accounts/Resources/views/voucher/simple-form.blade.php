{{--
    সহজ ভাউচার ফর্ম — আদায়, পরিশোধ, খরচ, কন্ট্রা।

    দুইটা ঘর আর একটা অঙ্ক। "ডেবিট" ও "ক্রেডিট" শব্দ দুটো এই পর্দায়
    কোথাও নেই, ইচ্ছাকৃতভাবে: যিনি কাউন্টারে বসে আদায় লিখছেন তিনি
    "কার কাছ থেকে" ও "কোথায় রাখলাম" বোঝেন। দিকটা VoucherService
    একবারের জন্য ঠিক করে — এই পর্দা সেটা জানেও না।

    DMS-এ প্রতিটা ভাউচারের পর্দা নিজে দিক ঠিক করত, আর একটায় উল্টো
    লেখা ছিল। সেই ভুলটা এখানে অসম্ভব, কারণ ভুল করার জায়গাটাই নেই।
--}}
@php
    $isNew = ! $voucher->exists;

    $optionsFor = fn (string $source) => match ($source) {
        'money' => $moneyAccounts,
        'expense' => $expenseAccounts,
        'party_or_income' => $allAccounts,

        /*
         * খরচ: নগদে **বা** বাকিতে।
         *
         * ⚠️ দুইটা দল আলাদা রাখা হয়, এক তালিকায় মিশিয়ে নয় — কারণ
         * দুইটার ফল **সম্পূর্ণ আলাদা**: একটায় টাকা এখনই যায়, অন্যটায়
         * দেনা তৈরি হয়। মিশিয়ে দিলে ব্যবহারকারী তফাতটা দেখতেন না।
         */
        'money_or_credit' => $moneyAccounts->concat($creditAccounts ?? collect()),
        default => $allAccounts,
    };

    /*
     * বাকিতে খরচের খাতগুলোর id — ফর্মে দুইটা কাজে লাগে:
     * দল আলাদা দেখানো, আর "বাকিতে বাছলে পক্ষ বাধ্যতামূলক" নিয়মটা।
     */
    $creditIds = collect($creditAccounts ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();

    // সম্পাদনার সময় দুইটা সারি থেকে দিক ফিরে পাওয়া — ডেবিটেরটা "to",
    // ক্রেডিটেরটা "from", ঠিক যেভাবে সেভ হয়েছিল
    $debitLine = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
    $creditLine = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.' . $type) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::voucher.' . $type)"
            :subtitle="$isNew ? __('accounts::message.number_on_save') : $voucher->document_no" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('accounts.voucher.store', $type) : route('accounts.voucher.update', $voucher) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="type" value="{{ $type }}">

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                            required />

                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('accounts::field.amount')"
                            :value="old('amount', $debitLine?->debit)" required numeric />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                 x-data="{
                     from: '{{ old('from_account_id', $creditLine?->account_id) }}',
                     credit: @js($creditIds),
                     get onCredit() { return this.credit.includes(Number(this.from)); },
                 }">
            <div class="grid gap-3 sm:grid-cols-2">
                {{-- from — টাকা যেখান থেকে এল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['from']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="from_account_id" required x-model="from"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($optionsFor($sides['from']['source']) as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('from_account_id', $creditLine?->account_id) == $account->id)>
                                {{ in_array((int) $account->id, $creditIds, true)
                                    ? __('accounts::field.on_credit_option', ['account' => $account->label()])
                                    : $account->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('from_account_id')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{-- to — টাকা যেখানে গেল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['to']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="to_account_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($optionsFor($sides['to']['source']) as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('to_account_id', $debitLine?->account_id) == $account->id)>
                                {{ $account->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_account_id')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{--
                    কাকে — ঐচ্ছিক, কিন্তু বাকিতে হলে বাধ্যতামূলক।
                
                    ── কেন ঘরটা লাগল ─────────────────────────────────────────
                    পক্ষ বসালে টাকাটা **তাঁর খতিয়ানে** যায়, আর তখন পরিবহনকারী বা
                    হাম্মালি ঠিকাদারের হিসাব নিজে থেকেই ভরে ওঠে। না বসালে খরচটা
                    সরাসরি খাতে বসে — চা-নাস্তা বা রিকশাভাড়ায় "কাকে দিলাম" লেখার
                    দরকার নেই।
                
                    ⛔ **কিন্তু বাকিতে হলে পক্ষ ছাড়া চলে না** — কারো কাছে দেনা হতে
                    হলে "কার কাছে" জানতেই হবে। ⚠️ নাহলে প্রদেয়ের ঘরে একটা টাকা বসে
                    থাকত **যার কোনো মালিক নেই**, আর "কাকে কত দিতে হবে" তালিকার
                    যোগফল স্থিতিপত্রের সাথে মিলত না।
                
                    ⚠️ এখানে আগে লেখা ছিল *"ইঞ্জিনে কিছু যোগ করতে হয়নি — সারি
                    হেডার থেকে উত্তরাধিকার পায়"*। **কথাটা ভুল ছিল, আর আমি
                    যাচাই না করেই লিখেছিলাম।**

                    মেপে দেখা গেছে [[VoucherService::replaceLines]] সারির পক্ষ
                    নেয় কেবল `$line['party_type'] ?? null` থেকে — **হেডার থেকে
                    কিছুই নামে না।** ⛔ ফলে বাকিতে খরচ লিখলে হেডারে পক্ষ বসত,
                    কিন্তু প্রদেয়ের **সারিটা মালিকহীনই থাকত** — ঠিক যেটা উপরের
                    বার্তাটা ঠেকানোর প্রতিশ্রুতি দেয়। ⚠️ আর বকেয়ার বয়স-রিপোর্ট
                    ও "কাকে কত দিতে হবে" পড়ে **সারি থেকে**, হেডার থেকে নয়।

                    ⓘ ইতিহাসটা রেখে দেওয়া হলো ইচ্ছে করেই: পরের জন যেন মন্তব্য
                    বিশ্বাস করার আগে মেপে নেন।
                --}}
                <label class="mt-3 block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.party') }}
                        <span class="text-(--color-danger)" x-cloak x-show="onCredit" aria-hidden="true">*</span>
                    </span>
                    <select name="party"
                            :required="onCredit"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($parties as $group)
                            <optgroup label="{{ $group['label'] }}">
                                @foreach ($group['options'] as $party)
                                    <option value="{{ $group['type'] }}:{{ $party['id'] }}"
                                            @selected(old('party') === $group['type'].':'.$party['id']
                                                || ($voucher->party_type === $group['type']
                                                    && $voucher->party_id == $party['id']))>
                                        {{ $party['label'] }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('party')
                        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
                    @enderror
                </label>

                {{--
                    ⭐ বাছার পর পর্দা বলে **কী ঘটতে যাচ্ছে**।
                
                    ⚠️ "কীভাবে" লেখা একটা তালিকা যথেষ্ট নয় — নগদে আর বাকিতে দুইটার
                    ফল সম্পূর্ণ আলাদা, আর ব্যবহারকারী যেন বুঝে বাছেন।
                --}}
                <p x-cloak x-show="onCredit"
                   class="mt-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                          text-2xs text-(--color-badge-pending-ink)">
                    {{ __('accounts::message.expense_on_credit') }}
                </p>
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.details') }}</h2>

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.instrument') }}</span>
                    <select name="instrument"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach (['cash', 'cheque', 'mfs', 'transfer', 'card'] as $mode)
                            <option value="{{ $mode }}" @selected(old('instrument', $voucher->instrument) === $mode)>
                                {{ __('accounts::instrument.' . $mode) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                {{-- চেকের নম্বর ও তারিখ ছাড়া ব্যাংকের বিবরণীর সাথে মেলানো
                     যায় না — হিসাবের দিক থেকে অপ্রয়োজনীয়, বাস্তবে অপরিহার্য --}}
                <x-ui.field name="instrument_no" :label="__('accounts::field.instrument_no')"
                            :value="old('instrument_no', $voucher->instrument_no)" />

                <x-ui.field name="instrument_date" type="date" :label="__('accounts::field.instrument_date')"
                            :value="old('instrument_date', $voucher->instrument_date?->format('Y-m-d'))" />
            </div>

            <label class="mt-3 block">
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <textarea name="narration" rows="2"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-card) px-3 py-2">{{ old('narration', $voucher->narration) }}</textarea>
            </label>
        </section>

        @include('accounts::voucher.partials.save-buttons', ['voucher' => $voucher, 'type' => $type])
    </form>
</x-layouts.app>
