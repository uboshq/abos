{{--
    রসিদ ও পরিশোধের পর্দা — নমুনার s-party অংশের হুবহু ক্রমে।

    ── ⛔ কেন নতুন করে লেখা হলো, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────
    মালিক নমুনা আর পর্দা পাশাপাশি রেখে দেখালেন। ঘরগুলো নামে মিলছিল,
    কিন্তু পর্দাটা নমুনার মতো দেখাচ্ছিল না — ক্রম আলাদা, লেবেল আলাদা,
    আর সবচেয়ে বড় অংশটা (কোন বিলের বিপরীতে) একেবারেই ছিল না।

    ⚠️ আগের মাপটা ছিল name="..." গুনে, আর সেটা ভুল জিনিস মাপা: ঘরটা
    পর্দার যেখানেই থাকুক, যে লেবেলেই থাকুক, গোনাটা সবুজ বলত।

    ⭐ মালিকের নিয়ম: ১০০% মানে ১০০%, ৯৯.৯৯%-ও নয়। আর "নমুনাই চূড়ান্ত" —
    নমুনায় নেই এমন ঘর (টাকার শ্রেণি, উপশ্রেণি, কাগজের তারিখ) তুলে
    দেওয়া হয়েছে, তাঁর নিজের সিদ্ধান্তে।

    ── নমুনার ক্রম ─────────────────────────────────────────────────────
        সারি ১   লেনদেনের তারিখ
                 (নমুনায় "কার মাধ্যমে" ও "কখন"-ও ছিল — মালিকের
                 সিদ্ধান্তে বাদ, কারণ কেউ ওগুলো পড়ত না)
        সারি ২   ডিপোজিটরের ধরন · ডিপোজিটরের নাম (পাশে পাওনা)
        ভাঁজ     কোন বিলের বিপরীতে
        চিপ      পেমেন্ট মেথড
        ব্লক     নোটের হিসাব · মোবাইল ব্যাংকিং · ব্যাংক ট্রান্সফার · চেক
        নিচে     যে খাতে জমা · গৃহীত টাকা · বিবরণ
--}}
@php
    $isReceipt = $voucher->type === \App\Modules\Accounts\Models\Voucher::RECEIPT;

    $was = fn (string $field, $fallback = null) => old($field, $voucher->{$field} ?? $fallback);

    /*
     * ⭐ ধরনটা আগে থেকেই বসানো — ২০ সেপ্টেম্বর ২০২৬, মালিক: *"ডিপোজিটরের
     * ধরন আদায় ভাউচার e grahok, payment e supplyer, defolt koro"*।
     *
     * ⓘ টাকা আসে প্রায় সবসময় গ্রাহকের কাছ থেকে, আর যায় সরবরাহকারীর কাছে —
     * তাই ঐ দুইটাই ধরা থাকে, আর অন্য কিছু হলে বদলানো যায়। ⛔ ফাঁকা রাখলে
     * প্রতিটা আদায়ে একটা বাড়তি ক্লিক, আর নামের তালিকাটা ততক্ষণ খালি।
     *
     * ⚠️ কেবল নতুন কাগজে: পুরনো ভাউচার খুললে তার নিজের ধরনই থাকে, আর
     * ভুল জমার পরে old() যা ছিল তাই ফেরে।
     */
    $partyDefault = $isReceipt ? 'customer' : 'supplier';

    $knownTypes = collect($parties)->pluck('type')->all();

    $partyType = (string) ($was('party_type') ?? '');

    if ($partyType === '' && in_array($partyDefault, $knownTypes, true)) {
        $partyType = $partyDefault;
    }
@endphp

{{--
    ⭐ নকশার কার্ড — "সাধারণ তথ্য" (পক্ষের ধরন · পক্ষ · তারিখ · টাকা, তারপর পদ্ধতি → খাত → পদ্ধতির ঘর, উৎসে কাটা), তার নিচে
    "খোলা বিল" নিজের কার্ডে (নকশা পাতা ২ আর ৪; fe, ২৫ অক্টোবর ২০২৬)। ⓘ পক্ষের বাক্স (`partyVoucher`) দুই কার্ড জুড়ে — বিলের টেবিল
    আর পক্ষের ঘর একই অবস্থা দেখে।
--}}
<div class="space-y-4" data-party-box
         @money-gross.window="heardGross($event.detail)"
         x-on:change="heardChange($event)"
         x-data="partyVoucher({
             partyType: @js($partyType),
             partyId: @js((string) ($was('party_id') ?? '')),
             dueUrl: @js(route('accounts.voucher.due')),
             // ⓘ কোন বিলের বিপরীতে — একটাই (অডিট ম১); অন্য মডিউলের আগাম-ভরা “বিপরীতে”-ও এখান দিয়েই যায়
             pickedType: @js((string) ($was('against_type') ?? '')),
             pickedId: @js((string) ($was('against_id') ?? '')),
             out: @js(! $isReceipt),
             texts: @js([
                 'owed' => __('accounts::field.owed'),
                 'dueInOwed' => __('accounts::field.due_in_owed'),
                 'dueInAdvance' => __('accounts::field.due_in_advance'),
                 'dueInNone' => __('accounts::field.due_in_none'),
                 'dueOutOwed' => __('accounts::field.due_out_owed'),
                 'dueOutAdvance' => __('accounts::field.due_out_advance'),
                 'dueOutNone' => __('accounts::field.due_out_none'),
                 'overDue' => __('accounts::message.more_than_the_bill_owes'),
                 'noBills' => __('accounts::message.no_open_bill'),
                 // ⭐ পক্ষের ঘরের নাম ধরনে — "গ্রাহক", "সরবরাহকারী" (নকশা); "অন্য"-তে ঘরটাই নেই
                 'typeLabels' => collect($parties)->pluck('label', 'type')->all(),
                 'partyWord' => $isReceipt ? __('accounts::field.depositor_name') : __('accounts::field.payee_name_party'),
                 'oldestDue' => __('money.screen.oldest_due'),
                 'lateDays' => __('money.screen.late_days'),
                 'unapplied' => __('money.screen.bills_unapplied'),
                 'partyOf' => __('money.screen.party_of'),
                 'over' => __('money.screen.bills_over'),
                 'creditLimit' => __('money.screen.credit_limit'),
                 'creditNone' => __('money.screen.credit_none'),
                 'bankNone' => __('money.screen.payee_bank_none'),
                 // ⭐ বিলে না-বাঁধা নোটের সারি — ধরনের নাম (a5-এর চুক্তি `kind`)
                 'kinds' => ['credit_note' => __('money.screen.kind.credit_note'), 'debit_note' => __('money.screen.kind.debit_note')],
             ]),
         })">
<section data-boxed data-voucher-general class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="mb-3 font-semibold">{{ __('money.screen.general') }}</h2>

    {{--
        ── সারি ১ — তারিখ · কখন · ডিপোজিটরের ধরন · ডিপোজিটরের নাম ───────

        ⭐ চারটা ঘর এক লাইনে — মালিকের নির্দেশ, ১৮ সেপ্টেম্বর ২০২৬।
        তিনি পর্দার ছবিতে লিখে দিলেন: *"লেনদেনের তারিখ, কখন,
        ডিপোজিটরের ধরন, ডিপোজিটরের নাম egulo ek line daw"*।

        ⓘ আগে দুই সারি ছিল (তিন ঘর + দুই ঘর), আর মাঝখানে "কার মাধ্যমে"।
        ⚠️ সেটা এখন নিচের সারিতে, বিবরণের ঠিক আগে — মালিকের নির্দেশেই:
        *"কার মাধ্যমে ei boxta বিবরণ er age niye aso"*।

        ⛔ ঘরটা এখান থেকে **মুছে** ফেলতে হয়েছে, কেবল সরানো নয় — একই
        নামের দুইটা সক্রিয় ঘর থাকলে ব্রাউজার শেষেরটার মান পাঠাত, আর
        উপরের বাছাইটা নীরবে হারাত।
    --}}
    {{--
        ⭐ ২৩ অক্টোবর ২০২৬ — চূড়ান্ত নকশার ক্রম: পক্ষের ধরন · পক্ষ · তারিখ · টাকা (fe; মালিক: *"নকশা অনুযায়ী, লাইভের আগে
        মিলিয়ে নাও"*)। ⓘ আগে তারিখ প্রথমে আর টাকা একদম নিচের সারিতে ছিল; নকশায় টাকা পক্ষের পাশেই, কারণ "কত" আর "কার কাছ
        থেকে" এক নজরে মেলানোর জিনিস। ঘরের নাম বদলায়নি, তাই জমা, ফোন আর পুরনো কাগজ অক্ষত।
    --}}
    {{-- ⓘ পক্ষের কলাম দ্বিগুণ — ১৩৬৬-এ সমান চার কলামে "আকিজ ফুড অ্যা…" কাটা পড়ত (মাপা, ২৩ অক্টোবর ২০২৬); নকশাতেও নামের ঘর সবচেয়ে চওড়া --}}
    {{--
        ⭐ ২৪ অক্টোবর ২০২৬ — ডানে সারাংশের কলাম আসায় এই বাক্স সরু (১৩৬৬, মেনু খোলা: ~৬০০px); চার কলামে তারিখ "24-10-" কাটা পড়ত
        (মাপা)। ⓘ টাকা দেওয়ার পর্দার একই গড়ন, নকশা পাতা ২ আর ৪: পক্ষের ধরন নিজের সারিতে, তার নিচে পক্ষ · তারিখ · টাকা তিন কলামে
        (`data-pay-general-row`-এর মতো; পক্ষের কলাম বেশি — নামের পাশে "অগ্রিম ৳…" বসে, আর ৯.৫rem-এর তারিখ-টাকায় নাম "আল…" হয়ে কাটত)।
    --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)_minmax(8rem,1fr)]" data-voucher-head>

        {{-- ⓘ "কখন" (moved_at) আর চাওয়া হয় না — মালিকের ২১ অক্টোবরের রায় ([[x-money.method]]); কলাম থাকে, পুরনো কাগজে দেখায় --}}


        {{--
            ⛔ "কার মাধ্যমে" ও "কখন" — মালিকের সিদ্ধান্তে বাদ, ১৫ সেপ্টেম্বর ২০২৬।

            ── কেন নমুনায় থাকা সত্ত্বেও ─────────────────────────────────
            গুনে দেখা গেল ঘর দুইটা **কেউ পড়ে না**: মাইগ্রেশন, fillable,
            ভ্যালিডেশন আর ফর্ম ছাড়া একটাও পর্দা, রিপোর্ট বা কোয়েরি
            ওগুলো ছোঁয় না।

            ⚠️ অর্থাৎ ওগুলো কেবল টাইপ করার খরচ ছিল, কোনো প্রশ্নের উত্তর
            দিত না। ⓘ মালিককে তিনটা পথ দেখানো হয় — রেখে পড়ার ব্যবস্থা
            করা, কেবল বাহকটা রাখা, বা দুইটাই বাদ — আর তিনি বাদ বেছেছেন।

            ⭐ কলাম দুইটা থেকে যাচ্ছে, মুছছে না: কন্ট্রা ভাউচারে
            "কে হাতে নিয়ে গেল" ঘরটা ঐ একই `carried_by` ব্যবহার করে, আর
            নমুনায় সেটা আছে।
        --}}

        {{--
            ⛔ `<x-ui.select>` ভিতরের `<option>` পড়ে না — ১৫ সেপ্টেম্বর ২০২৬।

            কম্পোনেন্টটা তালিকাটা `:options` প্রপ থেকে নেয়, আর স্লটে লেখা
            `<option>`গুলো **চুপচাপ ফেলে দেয়**।

            ⚠️ ফল: ড্রপডাউনটা পর্দায় থাকত, লেবেলও থাকত, কিন্তু **ভিতরে
            একটাও অপশন নেই** — ব্যবহারকারী ধরনটাই বাছতে পারতেন না, আর
            নামের ঘরটাও ভরত না (সে ধরনের উপর দাঁড়িয়ে)।

            ⛔ আমার গোনার পাহারা এটা ধরেনি: সে `name="party_type"` খুঁজত,
            আর ঘরটা সত্যিই ছিল। ⓘ **অপশন গোনা হয়নি।** মালিক পর্দা দেখে
            ধরিয়ে দেন।
        --}}
        {{--
            ⭐ পক্ষের ধরন চিপে, ড্রপডাউন নয় — নকশা পাতা ২ আর ৪ ("গ্রাহক · সরবরাহকারী · কর্মী · অন্য (সরাসরি খাত)"; fe, ২৪ অক্টোবর ২০২৬)।
            ⓘ নাম আগের `party_type`-ই, তাই জমা, ফোন আর পুরনো কাগজ অক্ষত। ⭐ "অন্য (সরাসরি খাত)" = ফাঁকা ধরন — পক্ষ ছাড়া কাগজ (যেমন
            ভাড়ার আয়); সার্ভার আগে থেকেই পক্ষ ছাড়া চলে ([[VoucherRequest::fillAccountFromParty()]] তখন কিছু বসায় না, খাত বাছতে হয়)।
        --}}
        <fieldset class="sm:col-span-2 lg:col-span-full" data-party-type-row>
            <legend class="mb-1 block text-sm font-medium">{{ $isReceipt ? __('accounts::field.depositor_type') : __('accounts::field.payee_type') }}</legend>
            <div class="inline-flex max-w-full flex-wrap overflow-hidden rounded-(--radius-field) border border-(--color-border)" role="radiogroup">
                @foreach ([...collect($parties)->pluck('label', 'type')->all(), '' => __('accounts::field.party_other')] as $value => $text)
                    <label class="cursor-pointer border-e border-(--color-border) last:border-e-0">
                        <input type="radio" name="party_type" value="{{ $value }}" class="peer sr-only"
                               data-party-type-chip="{{ $value === '' ? 'other' : $value }}"
                               x-model="partyType" x-on:change="resetParty()" @checked($partyType === (string) $value)>
                        <span class="block whitespace-nowrap px-2.5 py-2 text-sm text-(--color-ink-body) transition-colors
                                     hover:bg-(--color-surface-hover)
                                     peer-checked:bg-(--color-brand-700) peer-checked:font-medium peer-checked:text-(--color-brand-ink)
                                     peer-checked:hover:bg-(--color-brand-700)
                                     peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-(--color-brand-500)">
                            {{ $text }}
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- ⓘ `adding` এখন [[party-voucher.js]]-এ, আলাদা ছোট x-data-য় নয় — খোঁজার
             তালিকা থেকে "নতুন নাম হিসেবে যোগ করুন" চাপলে "+" ঘরটা খুলতে হয়, আর
             দুইটা আলাদা x-data একে অন্যের অবস্থা দেখে না। --}}
        {{-- ⓘ "অন্য (সরাসরি খাত)"-এ পক্ষের ঘর নেই — নিচে এক লাইনে কারণ --}}
        <div x-show="! noParty" data-party-name-cell>
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium" data-party-label>
                    <span x-text="partyLabel">{{ collect($parties)->firstWhere('type', $partyType)['label'] ?? ($isReceipt ? __('accounts::field.depositor_name') : __('accounts::field.payee_name_party')) }}</span>
                    <span class="text-(--color-danger)" aria-hidden="true">*</span>
                </span>

                {{-- পাওনা — নামের পাশে, রঙিন পটভূমিতে।

                     ⭐ মালিক, ২০ সেপ্টেম্বর ২০২৬: *"পাওনা -280.56 brafground e
                     ekta color daw"*। ⓘ ধূসর ছোট লেখায় সংখ্যাটা চোখ এড়িয়ে যেত,
                     অথচ টাকা নেওয়ার আগে এটাই দেখার জিনিস।

                     ⚠️ রং দুই রকম, আর অর্থও দুই রকম: ধনাত্মক মানে তিনি দেবেন
                     (লাল), ঋণাত্মক মানে তাঁর টাকা আমাদের কাছে জমা আছে (সবুজ) —
                     ⛔ ঋণাত্মক পাওনা দেখে আরেকবার টাকা চাইলে সেটা ভুল আদায়।
                     ⓘ রং একা কিছু বলে না, তাই লেখাটাও সাথে থাকে। --}}
                {{-- ⭐ ২৩ অক্টোবর ২০২৬ (fe): চিহ্নটা শব্দে — "-45,000.00" সবুজে দেখে কেউ বুঝত না এটা অগ্রিম। রসিদে "বকেয়া / অগ্রিম /
                     বকেয়া নেই", পরিশোধে "দেনা / অগ্রিম দেওয়া / দেনা নেই"; অঙ্ক কখনো ঋণাত্মক চিহ্নে নয় ([[party-voucher.js]]) --}}
                <span class="rounded-(--radius-pill) px-2 py-0.5 text-xs" x-show="due !== null" x-cloak data-party-due
                      x-bind:class="dueClass">
                    <span x-text="dueWord"></span>
                    <b class="num ms-1" x-show="dueAmount !== ''" x-text="dueAmount"></b>
                </span>
            </div>

            {{--
                ⭐ নাম বাছা ভাগ করা পিকারে — [[x-ui.party-search]] `stacked addable` (a6 d8005fa00; fe, ২৪ অক্টোবর ২০২৬): খোঁজা, ↑ ↓ Enter,
                নামের নিচে কোড · পয়েন্ট · শাখা (দুই লাইনে, সরু ঘরেও নাম কাটে না), আর তালিকায় না থাকলে "+ নতুন নাম" — মালিকের ৩ অক্টোবরের
                আচরণ হুবহু। ⓘ ঘরের নাম আগের মতোই: `party_id`, আর নতুন নামে `party_new` / `party_mobile` — সার্ভার সেখান থেকেই তাঁকে
                ব্যক্তি বানায় ([[VoucherRequest]] · [[TurnsATypedNameIntoAParty]]), কিছুই বদলায়নি।
                ⓘ তালিকা ধরন ধরে — প্রতিটা ধরনের নিজের পিকার, `x-if`-এ: একসাথে একটাই পাতায় থাকে, তাই একই নামের দুই ঘর জমা যায় না।
                ধরন বদলালে নতুন পিকার খালি শুরু করে ([[party-voucher.js]] `resetParty`)। পিকার লুকানো ঘরে `change` ছাড়ে — বাছলে id,
                নতুন নাম লিখলে ফাঁকা — আর এই বাক্স তা শুনে বকেয়া, বিল আর মাথার তিন অঙ্ক আনে বা মোছে (`partyPicked`)।
            --}}
            {{--
                ⛔ `party_id` পাতায় নিজে আঁকা, পিকারের ভিতরে নয় — cb-ma-2 (৪৯dae790c, ২৪ অক্টোবর ২০২৬): পিকারগুলো `<template x-if>`-এ,
                আর টেমপ্লেটের ভিতরের ঘর সার্ভারের আঁকা ফর্মে নেই (TheVoucherFormSendsWhatItShowsTest: "party_id ঘরটাই পাঠানো হয় না")।
                ⭐ এখন একটাই `party_id`, সবসময় পাতায়, মান বাক্সের `partyId` থেকে; পিকারের নিজের ঘর `party_pick` — সার্ভার সেটা পড়ে না,
                কেবল তার `change` এই বাক্সকে খবর দেয়। ⓘ একই নামের দুই ঘর তাই কখনো জমা যায় না।
            --}}
            <input type="hidden" name="party_id" value="{{ (string) ($was('party_id') ?? '') }}" x-bind:value="partyId" data-party-id>
            <div x-on:change="partyPicked($event)" data-party-picker-cell>
                @foreach ($parties as $group)
                    <template x-if="partyType === @js((string) $group['type'])">
                        <x-ui.party-search name="party_pick" :options="$group['options']"
                                           :selected="$partyType === (string) $group['type'] ? ($was('party_id') ?? null) : null"
                                           :aria-label="__('accounts::field.party')" stacked addable />
                    </template>
                @endforeach
            </div>
            @error('party_id')
                <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
            @enderror
            {{--
                ⭐ নকশার নিচের লাইন — "বাকির সীমা ৳1,00,000 · সবচেয়ে পুরনো বাকি 19 দিন" (রসিদ) / "BIN … · TIN …" (পরিশোধ)। বাকির সীমা আর
                BIN/TIN পক্ষের মালিক মডিউলের, Core চুক্তি দিয়ে বকেয়ার একই উত্তরে আসে (হিসাব নিজে গ্রাহক বা সরবরাহকারী পড়ে না; cb-ma-4);
                পুরনো বাকি খোলা বিলের বয়স থেকে ([[party-voucher.js]])।
            --}}
            <p class="mt-1 text-2xs text-(--color-ink-muted)" data-party-hint x-show="creditText !== '' || oldestDueText !== '' || taxText !== ''" x-cloak>
                <span data-party-credit x-text="creditText"></span><span x-show="creditText !== '' && oldestDueText !== ''"> · </span><span data-party-oldest x-text="oldestDueText"></span><span x-show="taxText !== '' && (creditText !== '' || oldestDueText !== '')"> · </span><span data-party-tax x-text="taxText"></span>
            </p>
        </div>

        <x-ui.field name="trx_date" type="date"
                    :label="__('money.screen.date')"
                    :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                    required />

        {{-- ⭐ টাকা লাখ-কমায় আর দুই দশমিকে, ডানে ("45,000.00", নকশার মতো) — দেখানোর ঘর আর পাঠানোর ঘর আলাদা, পাঠানো মান কাঁচা
             অঙ্ক (type="money", fe, ২৪ অক্টোবর ২০২৬); সার্ভার তবু কমা ছেঁটে পড়ে ([[VoucherRequest::prepareForValidation()]])।
             ⓘ `?? $voucher->amount` — অর্থের খাতার "টাকা নিন" বোতাম অঙ্কসহ আসে ([[VoucherController::prefill()]], ১৮ সেপ্টেম্বর ২০২৬)। --}}
        <div>
            {{-- ⭐ পরিশোধে নকশার "মোট টাকা" — `gross_amount`, পক্ষের খাতা যতটা কমে; নিট (মোট − AIT − VDS) সার্ভার গোনে (পরিশোধে-মোট-টাকা-চুক্তি,
                 fe-র সিদ্ধান্ত ক, ২৫ অক্টোবর ২০২৬)। ⓘ সম্পাদনায় পক্ষের ডেবিট সারিই মোট; আগাম-ভরা অঙ্কে কাটা নেই, তাই মোট = অঙ্ক --}}
            <x-ui.field :name="$isReceipt ? 'amount' : 'gross_amount'" type="money" model="amount" required data-voucher-amount
                        :label="$isReceipt ? __('money.screen.amount_in') : __('money.screen.gross')"
                        :value="$isReceipt ? old('amount', $typedAmount ?? $voucher->amount) : old('gross_amount', $typedAmount ?? $voucher->amount)" />
            {{-- ⭐ নকশার "পুরো বকেয়া · নির্বাচিত বিল" — এক চাপে অঙ্ক বসে (বকেয়া থাকলে, বিল বাছা থাকলে); ঘটনায় ফর্মের টাকায় যায় ([[voucherMoney]] `heardFill`) --}}
            @if ($isReceipt)
                <p class="mt-1 flex flex-wrap gap-x-2 text-2xs" data-amount-fill>
                    <button type="button" class="text-(--color-brand-700) hover:underline" data-fill-due
                            x-show="canFillDue" x-cloak x-on:click="fillWholeDue()">{{ __('money.screen.fill.whole_due') }}</button>
                    <button type="button" class="text-(--color-brand-700) hover:underline" data-fill-bill
                            x-show="hasTicked" x-cloak x-on:click="fillPickedBill()">{{ __('money.screen.fill.picked_bill') }}</button>
                </p>
            @endif
        </div>
    </div>

    @unless ($isReceipt)
        {{--
            ⭐ প্রাপকের ব্যাংক — সরবরাহকারীর রেকর্ড থেকে, কেবল পড়ার জন্য (নকশা পাতা ৪; Core চুক্তি PayeeBankView, cb-ma-4)। ⓘ দুজনের সই পেলে সবুজ
            দাগ; না পেলে আর পদ্ধতি ট্রান্সফার বা নগদ জমা হলে লালে কারণ — পোস্টের পাহারার একই কথা ([[PayeeBankGuard]]), আগে থেকে।
        --}}
        <div class="mt-3" data-payee-bank-row x-show="payeeBank !== null" x-cloak>
            <span class="mb-1 block text-xs text-(--color-ink-muted)">{{ __('money.screen.payee_bank') }}</span>
            <p class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-sunken) px-3 py-2 text-sm" data-payee-bank-line>
                <span x-text="bankLineText"></span>
                <b class="ms-1 font-medium text-(--color-badge-success-ink)" data-payee-bank-signed x-show="bankSigned" x-cloak>{{ __('money.screen.payee_bank_signed') }}</b>
            </p>
            <p class="mt-1 text-xs text-(--color-danger)" data-payee-bank-blocks x-show="bankBlocks" x-cloak>{{ __('money.screen.payee_bank_unsigned') }}</p>
        </div>
    @endunless

    {{-- ⭐ পক্ষ ছাড়া কাগজে এক লাইনে কারণ — বিলের তালিকা আর পাওনার অঙ্ক কেন নেই (fe, ২৪ অক্টোবর ২০২৬) --}}
    <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-xs text-(--color-ink-muted)"
       data-no-party-note x-show="noParty" x-cloak>{{ $isReceipt ? __('accounts::message.no_party_note_in') : __('accounts::message.no_party_note_out') }}</p>

    {{--
        ── কোন খাতের টাকা — গ্রাহক ছাড়া অন্য কারও কাছ থেকে রসিদ ────────
        ⛔ কী ঘটছিল, ১৯ সেপ্টেম্বর ২০২৬: মালিক একজন ব্যক্তির কাছ থেকে
        মূলধনের রসিদ কাটতে গেলেন, আর পর্দা বলল *"যে খাত থেকে দিতেই
        হবে"* — অথচ ঐ ঘরটা পর্দায় কোথাও নেই। তিনি জিজ্ঞেস করলেন:
        *"eta capital entry nicchena keno"*।

        ⓘ দুইটা ঠিক সিদ্ধান্ত একসাথে ভুল ফল দিচ্ছিল:
        ১. নমুনা মেনে রসিদে "কার কাছ থেকে" খাতের ঘরটা লুকানো ও নিষ্ক্রিয়
           (উপরে ডিপোজিটরই সেটা বলে)।
        ২. 5e7d508c: পক্ষ থেকে খাত আন্দাজ কেবল গ্রাহকের বেলায় (→ AR),
           কারণ আগে ব্যক্তির টাকাও AR-এ বসত — ২৫ লাখের মূলধনটা ঠিক তাই।
        ⛔ ফল: গ্রাহক ছাড়া কারও কাছ থেকে **কোনো রসিদই** কাটা যেত না।

        ⭐ তাই ডিপোজিটর গ্রাহক না হলে ঘরটা এখানে দেখা দেয়। গ্রাহক হলে
        লুকানো ও নিষ্ক্রিয়, তাই আগের মতোই AR নিজে বসে, আর আগে বাছা
        কোনো খাত ভুল করে জমা পড়ে না।
    --}}
    @if ($isReceipt)
        {{-- ⓘ "অন্য (সরাসরি খাত)"-এও (ফাঁকা ধরন) — পক্ষ ছাড়া রসিদে খাতটা বাছতেই হয়; ঘরের নাম আর বাধ্যতা মালিকের ১৯ সেপ্টেম্বরের কথামতো --}}
        <div class="mt-3 max-w-xl" x-show="partyType !== 'customer'" x-cloak>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">
                    {{ __('accounts::field.received_on_account') }}
                    <span class="text-(--color-danger)" aria-hidden="true">*</span>
                </span>
                @php
                    /*
                        যাঁরা আগে মূলধন দিয়েছেন — তাঁদের বাছলে ঘরটায় মূলধন (3100) নিজে বসে।

                        ⚠️ ২১ সেপ্টেম্বর ২০২৬: আগে এখানে সরাসরি Finance-এর মডেল ডাকা হত,
                        আর তাতে নির্ভরতার তীরটা উল্টো ছিল — Finance accounts চেনে, উল্টোটা
                        নয়। ⓘ এখন চুক্তিটা জিজ্ঞেস করা হয়; কে উত্তর দিচ্ছে, দিচ্ছে কি না,
                        এই পর্দা কিছুই জানে না ([[KnowsWhereAPersonsMoneyBelongs]])।
                    */
                    $contributors = app(\App\Core\Contracts\KnowsWhereAPersonsMoneyBelongs::class)->peopleItKnowsAbout();
                    $capitalAccount = (string) \App\Modules\Accounts\Models\Account::query()
                        ->where('code', \App\Modules\Accounts\Services\StandardChart::OWNER_CAPITAL)->value('id');

                    /*
                     * ⛔ "কী বাবদ" প্রশ্নে পুরো হিসাবের তালিকা নয় — ১৯ সেপ্টেম্বর ২০২৬,
                     * মালিক: *"'টাকাটা কী বাবদ' zodi hoy tahole full hisab talika
                     * dewa keno"*। ⓘ নগদ, ব্যাংক, মজুদ হলো টাকা **রাখার** জায়গা, আসার
                     * কারণ নয়; গ্রাহক-সরবরাহকারীর বাকি তাঁদের নিজের পথে আসে; খরচের
                     * খাতে টাকা ঢোকে না। ⭐ থাকে চার দল, মূলধন আগে:
                     *   মূলধন · ঋণ ও দায় (সরবরাহকারীর বাকি বাদে) · আয় ·
                     *   ফেরত পাওয়া (অগ্রিম, জামানত, দাবি, বিনিয়োগ, দেওয়া হাতধার — ১১৩০–১১৭০)
                     */
                    $returnable = ['113', '114', '115', '116', '117'];
                    $pool = collect($optionsFor('party_or_income'))
                        ->filter(fn ($a) => $a->money_kind === null);
                    $sourceGroups = array_filter([
                        __('accounts::field.source_equity') => $pool->where('type', \App\Modules\Accounts\Models\Account::EQUITY),
                        __('accounts::field.source_liability') => $pool->where('type', \App\Modules\Accounts\Models\Account::LIABILITY)
                            ->reject(fn ($a) => str_starts_with((string) $a->code, \App\Modules\Accounts\Services\StandardChart::PAYABLE_GROUP)),
                        __('accounts::field.source_income') => $pool->where('type', \App\Modules\Accounts\Models\Account::INCOME),
                        __('accounts::field.source_returned') => $pool->where('type', \App\Modules\Accounts\Models\Account::ASSET)
                            ->filter(fn ($a) => in_array(substr((string) $a->code, 0, 3), $returnable, true)),
                    ], fn ($group) => $group->isNotEmpty());
                @endphp
                <select name="from_account_id" x-bind:disabled="partyType === 'customer'"
                        x-bind:value="partyType === 'person' && @js($contributors).includes(partyId) ? @js($capitalAccount) : $el.value"
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                               border-(--color-border) bg-(--color-surface-card) px-3">
                    <option value="">&mdash;</option>
                    @foreach ($sourceGroups as $groupLabel => $accounts)
                        <optgroup label="{{ $groupLabel }}">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>
                                    {{ $account->label() }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('accounts::message.received_on_account_hint') }}</p>
        </div>
    @endif

    @include('accounts::voucher.partials.money-fields')
</section>

    {{--
        ── কোন বিলের বিপরীতে ──────────────────────────────────────────
        ⭐ রসিদের সবচেয়ে বড় প্রশ্ন, আর এতদিন পর্দায় ছিলই না।

        ⚠️ মোট বকেয়া জানা আর কোন বিলের বিপরীতে জানা এক নয়। ⓘ না জানলে
        পুরনো বিলটা চিরকাল খোলা থাকত আর নতুনটা শোধ দেখাত — টাকাটা একই,
        অথচ বয়স ধরে বকেয়ার তালিকা মিথ্যা বলত।

        ⓘ ভাঁজটা নিজে থেকেই খোলে যখন পক্ষ বাছা হয় আর বিল আছে।
    --}}
    {{--
        ⓘ একক "বিপরীতে" — কেবল অন্য মডিউল আগে থেকে ভরে দিলে (অর্থের খাতার "টাকা নিন", ঋণ, দাবি); সেটা খোলা বিলের তালিকায় থাকলে বাক্সটা
        তাকে বিলের সারিতে টিক দিয়ে এই ঘর খালি করে ([[party-voucher.js]] `adoptPicked`)। নইলে ঘর দুটো যায়ই না।
    --}}
    <input type="hidden" name="against_type" x-bind:value="pickedType" x-bind:disabled="pickedId === ''">
    <input type="hidden" name="against_id" x-bind:value="pickedId" x-bind:disabled="pickedId === ''">

    {{--
        ⭐ এক টাকা, বহু বিল — নকশার "খোলা বিল" টেবিল (পাতা ২ আর ৪; cb-ma-4, ২৫ অক্টোবর ২০২৬; পরিশোধে-মোট-টাকা-চুক্তি §৩)।
        কলাম: কাগজ · তারিখ · শেষ দিন · দেরি (শেষ দিন পেরোলে লালে "N দিন", নইলে —) · মূল টাকা · বাকি · এই রসিদে কত।
        ⓘ টিক দিলে সারিতে পুরো বকেয়া বসে আর টাকার ঘরে বসে টিক-দেওয়া বিলগুলোর যোগফল (লেখক বদলাতে পারেন; নিজে বদলালে আর ছোঁয়া হয় না)।
        যোগফল টাকার কম হলে বাকিটা বিলে প্রয়োগ হয় না — পক্ষের নিজের খাতায় থাকে (পরিশোধে Dr, রসিদে Cr), কথায় বলা; বেশি হলে লালে (সার্ভারও থামায়)। পাঠানো হয় `bills[i][id]` (বিলের public_id) আর
        `bills[i][amount]`; প্রতিটা সারি সার্ভারে পক্ষের নিজের খোলা বিল থেকে মেলে আর পোস্টে তালাসহ আবার মাপা হয় ([[VoucherAllocations]])।
        ⛔ আগের এক-বিলের রেডিও (অডিট ম১-এর সময়ে সার্ভার ভাগ রাখত না) এখন নেই — ভাগ রাখার টেবিল আছে, আর ম১-এর পাঁচ প্রশ্ন প্রতিটা সারিতে।
    --}}
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" data-open-bills
             x-show="! noParty && bills.length > 0" x-cloak>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">
                {{ $isReceipt ? __('money.screen.open_bills_in') : __('money.screen.open_bills') }}
            </h3>
            <span class="text-2xs text-(--color-ink-muted)" data-bills-hint>{{ __('money.screen.bills_hint') }}</span>
        </div>

        <template x-if="bills.length > 0">
            <div>
                <div class="mt-3 overflow-x-auto">
                    <table class="ui-grid is-sheet w-full whitespace-nowrap text-xs" data-bills-table>
                        <thead class="text-xs text-(--color-ink-muted)">
                            {{-- ⓘ মাথার লেখা দুই লাইনে যেতে পারে, ঘরের অঙ্ক-তারিখ নয় — ১৩৬৬-এ মেনু খোলা অবস্থায় সাত কলাম কার্ডের ভিতরে (cb-ma-5-এর মাপ) --}}
                            <tr class="whitespace-normal border-b border-(--color-border)">
                                <th class="w-8"><span class="sr-only">{{ __('money.screen.col.apply') }}</span></th>
                                <th class="text-start">{{ __('money.screen.col.paper') }}</th>
                                <th class="text-start">{{ __('money.screen.col.date') }}</th>
                                <th class="text-start">{{ __('money.screen.col.due') }}</th>
                                <th class="num">{{ __('money.screen.col.late') }}</th>
                                <th class="num">{{ __('money.screen.col.total') }}</th>
                                <th class="num">{{ __('money.screen.col.remaining') }}</th>
                                <th class="num" data-bill-amount-col>{{ $isReceipt ? __('money.screen.col.this_in') : __('money.screen.col.this_out') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="b in bills" :key="billKey(b)">
                                <tr class="border-b border-(--color-border)" data-bill-row>
                                    {{-- ⓘ নোটের সারিতে টিক আর অঙ্ক নেই — নোট এই কাগজে প্রয়োগ হয় না (আলাদা কাজ); কেবল দেখায় পক্ষের বকেয়া কত কমেছে/বেড়েছে --}}
                                    <td>
                                        <template x-if="isBill(b)">
                                            <input type="checkbox" data-bill-tick :aria-label="b.no"
                                                   :checked="isTicked(b)" x-on:change="toggle(b)">
                                        </template>
                                    </td>
                                    <td class="whitespace-normal font-medium">
                                        <span x-text="b.no"></span>
                                        <span class="mt-0.5 block w-fit whitespace-nowrap rounded bg-(--color-badge-info-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)"
                                              data-bill-kind x-show="! isBill(b)" x-text="kindText(b)"></span>
                                    </td>
                                    <td class="num" x-text="dayMonth(b.date)"></td>
                                    <td class="num" data-bill-due-date x-text="dayMonth(b.due_date)"></td>
                                    <td class="num" data-bill-late x-bind:class="lateClass(b)" x-text="lateText(b)"></td>
                                    <td class="num" data-bill-total x-text="lakh(b.total)"></td>
                                    <td class="num" data-bill-outstanding x-text="lakh(b.outstanding)"></td>
                                    <td class="num">
                                        <template x-if="isBill(b)">
                                            <input type="text" inputmode="decimal" data-bill-amount :aria-label="b.no"
                                                   class="h-(--spacing-field-dense) w-16 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm disabled:opacity-50"
                                                   :value="rowAmount(b)" :disabled="! isTicked(b)" x-on:change="setRow(b, $event.target.value)">
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td colspan="7">{{ __('money.screen.col.applied_total') }}</td>
                                <td class="num" data-bills-sum x-text="allocSumText"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- ⓘ টিক-দেওয়া সারিগুলোই যায় — ক্রমিক নম্বরে (`bills[0]`, `bills[1]` …) --}}
                <template x-for="(row, i) in pickedRows" :key="row.id">
                    <span>
                        <input type="hidden" x-bind:name="billField(i, 'id')" x-bind:value="row.id">
                        <input type="hidden" x-bind:name="billField(i, 'amount')" x-bind:value="row.amount">
                    </span>
                </template>

                {{-- ⓘ বিলের বাইরের টাকা পক্ষের নিজের খাতায় — পরিশোধে Dr, রসিদে Cr; "অগ্রিম" নয় (আলাদা অগ্রিম খাত নেই; চুক্তি §৭.৬) --}}
                <p class="mt-2 text-xs text-(--color-ink-muted)" data-bills-notes x-show="hasNotes" x-cloak>{{ __('money.screen.notes_line') }}</p>
                <p class="mt-2 text-xs text-(--color-ink-muted)" data-bills-unapplied x-show="unappliedText !== ''" x-cloak x-text="unappliedText"></p>
                <p class="mt-2 text-xs text-(--color-danger)" data-bills-over x-show="overText !== ''" x-cloak x-text="overText"></p>
            </div>
        </template>
    </section>
</div>
