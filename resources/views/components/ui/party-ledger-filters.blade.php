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

<x-ui.date-range :dates="['from' => request('from'), 'to' => request('to')]" />

<x-ui.select name="kind" :label="__('party_ledger.kind')" :options="$kinds"
             :selected="request('kind')" :placeholder="__('party_ledger.any_kind')" />

<x-ui.select name="side" :label="__('party_ledger.side')"
             :options="['debit' => __('party_ledger.debit_only'), 'credit' => __('party_ledger.credit_only')]"
             :selected="request('side')" :placeholder="__('party_ledger.any_side')" />
