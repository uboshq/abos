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
        default => $allAccounts,
    };

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

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                            required />

                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('accounts::field.amount')"
                            :value="old('amount', $debitLine?->debit)" required numeric />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                {{-- from — টাকা যেখান থেকে এল --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __($sides['from']['label']) }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="from_account_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($optionsFor($sides['from']['source']) as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('from_account_id', $creditLine?->account_id) == $account->id)>
                                {{ $account->label() }}
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
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
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
