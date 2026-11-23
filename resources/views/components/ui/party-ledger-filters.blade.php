@props(['party' => 'customer'])

{{--
    পার্টির "লেনদেন"-এর ছাঁকনি — টুলবারের ছাঁকনির প্যানেলে বসে ([[PartyLedger]], মালিক, ৩ অক্টোবর ২০২৬)।
    ⓘ গ্রাহক, সরবরাহকারী আর সেবাদাতা তিন পাতায় হুবহু একই ঘর; কেবল বিল আর টাকার নাম পার্টি ধরে।
    ⓘ ঘরের নামগুলো [[PartyLedger::filter()]] যা পড়ে ঠিক তাই — from, to, kind, side।
--}}
@php
    $kinds = collect(array_keys(\App\Core\Support\PartyLedger::KINDS))->mapWithKeys(fn ($k) => [
        $k => in_array($k, ['bill', 'money'], true)
            ? __('party_ledger.kind_'.($party === 'customer' ? 'customer' : 'supplier').'.'.$k)
            : __('party_ledger.kind_'.$k),
    ]);
@endphp

{{-- ⭐ রিপোর্ট: তারিখ বসালেই চলে না — দুই তারিখ আর ছাঁকনি বেছে "দেখান" বা Enter (মালিক, ২৩ নভেম্বর ২০২৬)।
     ⓘ তাই `x-ui.date-range` নয় (ওটা তারিখ বসলেই জমা দেয়, তালিকার জন্য) — একই দুই ঘর, জমা ছাড়া। --}}
<x-ui.date name="from"
           value="{{ request('from') ?? '' }}"
           aria-label="{{ __('core.table.from_date') }}"
           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />

<x-ui.date name="to"
           value="{{ request('to') ?? '' }}"
           aria-label="{{ __('core.table.to_date') }}"
           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />

<x-ui.select name="kind" :label="__('party_ledger.kind')" :options="$kinds"
             :selected="request('kind')" :placeholder="__('party_ledger.any_kind')" />

<x-ui.select name="side" :label="__('party_ledger.side')"
             :options="['debit' => __('party_ledger.debit_only'), 'credit' => __('party_ledger.credit_only')]"
             :selected="request('side')" :placeholder="__('party_ledger.any_side')" />
