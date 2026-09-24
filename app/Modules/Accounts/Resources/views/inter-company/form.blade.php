{{--
    ভাই-কোম্পানিকে টাকা — দুই ধাপে, JS ছাড়া।

    ── ⚠️ কেন দুই ধাপ ──────────────────────────────────────────────────
    দ্বিতীয় খাতটা **অন্য কোম্পানির**, আর সেই তালিকা চলতি কোম্পানির
    স্কোপে পাওয়া যায় না। প্রথম নকশায় একটা fetch ছিল যা প্রসঙ্গ বদলে
    তালিকাটা আনত — কিন্তু এখানে CSP চালু আর Alpine সীমিত, তাই ঐ জোড়টা
    ভঙ্গুর। ⓘ আর জোড় ভাঙলে কিছুই লাল হয় না, শুধু ড্রপডাউনটা খালি থাকে।

    ⭐ তাই ধাপ এক কোম্পানি বাছে (সাধারণ GET), ধাপ দুই বাকিটা — দুই
    কোম্পানির খাতই সার্ভার থেকে বসানো। একটা ক্লিক বেশি, ভাঙার সুতো শূন্য।
--}}
@php
    $label = fn ($a) => $a->code.' — '.($a->name_bn ?: $a->name_en);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.inter_company_new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.inter_company_new')"
                          :subtitle="__('accounts::message.inter_company_moves_money_only')" />
    </x-slot:header>

    <x-ui.errors />

    @if ($companies->isEmpty())
        {{-- ⓘ ব্যবহারকারী একটাই কোম্পানিতে আছেন — তাঁর সাথে লেনদেনের
             মতো দ্বিতীয় কোনো কোম্পানিই নেই। ⚠️ খালি ড্রপডাউন দেখালে
             মনে হত কিছু ভেঙেছে। --}}
        {{-- ⓘ ঘরটা `message`, `title` নয় — কম্পোনেন্টের `@props` মিলিয়ে
             দেখা। ⚠️ `title` দিলে চুপচাপ উপেক্ষিত হত আর পর্দায় খালি
             একটা বাক্স বসত, কোনো ত্রুটি ছাড়াই। --}}
        <x-ui.empty-state :message="__('accounts::message.inter_company_no_sister')" icon="building" />
    @elseif ($chosen === null)
        {{-- ── ধাপ এক ───────────────────────────────────────────── --}}
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.select name="counter"
                         :label="__('accounts::field.inter_company_counter')"
                         :options="$companies->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                         :placeholder="__('accounts::field.inter_company_pick')"
                         required />

            {{-- ⓘ `core.action.apply` — `core.action.next` বলে কোনো চাবি
                 **নেই** (গোটা `action` দলটা গুনে দেখা)। ⚠️ আর
                 `lang/core.php` এখন অন্য সেশনের হাতে, তাই সেখানে নতুন
                 চাবি বসানো চলে না। --}}
            <x-ui.button type="submit">{{ __('core.action.apply') }}</x-ui.button>
        </form>
    @else
        {{-- ── ধাপ দুই ──────────────────────────────────────────── --}}
        <form method="POST" action="{{ route('accounts.inter_company.store') }}" class="grid gap-4 md:grid-cols-2">
            @csrf

            {{-- ⓘ বাছা কোম্পানিটা লুকানো ঘরে — ধাপ একে যাচাই হয়ে গেছে
                 যে সেটা ব্যবহারকারীরই, আর সেবা স্তর আবার যাচাই করে। --}}
            <input type="hidden" name="counter_company_id" value="{{ $chosen }}">

            <x-ui.field name="trx_date" type="date"
                        :label="__('accounts::field.date')"
                        :value="old('trx_date', now()->toDateString())" required />

            {{-- ⓘ `numeric` — কম্পোনেন্টের নিজের ঘর, যেটা ট্যাবুলার অঙ্ক
                 আর ডান-সারি দেয়। ⚠️ টাকার ঘরে ওটা ছাড়া অঙ্কগুলো
                 আনুপাতিক প্রস্থে বসে, আর দুইটা সংখ্যা পাশাপাশি রাখলে
                 কোনটা বড় তা চোখে ধরা পড়ে না।

                 ⓘ `step` কোনো prop নয়, কিন্তু কম্পোনেন্ট `{{ $attributes }}`
                 ইনপুটে ছড়িয়ে দেয় — মিলিয়ে দেখা। --}}
            <x-ui.field name="amount" type="number" step="0.0001" numeric
                        :label="__('accounts::field.amount')"
                        :value="old('amount')" required />

            <x-ui.select name="from_account_id"
                         :label="__('accounts::field.inter_company_from')"
                         :options="$money->mapWithKeys(fn ($a) => [$a->id => $label($a)])"
                         :selected="old('from_account_id')"
                         :placeholder="__('accounts::field.inter_company_pick')"
                         required />

            {{-- ⚠️ এই তালিকাটা **অন্য কোম্পানির** খাত।

                 ⓘ কন্ট্রোলার সেটা এনেছে CompanyContext::forCompany() দিয়ে,
                 যে finally-তে আগের প্রসঙ্গ ফিরিয়ে দেয় — নাহলে এই
                 অনুরোধের বাকি প্রতিটা কোয়েরি ভুল কোম্পানিতে চলত। --}}
            <x-ui.select name="to_account_id"
                         :label="__('accounts::field.inter_company_to')"
                         :options="$theirMoney->mapWithKeys(fn ($a) => [$a->id => $label($a)])"
                         :selected="old('to_account_id')"
                         :placeholder="__('accounts::field.inter_company_pick')"
                         required />

            <div class="md:col-span-2">
                <x-ui.field name="purpose"
                            :label="__('accounts::field.inter_company_purpose')"
                            :value="old('purpose')"
                            :hint="__('accounts::message.inter_company_purpose_hint')" required />
            </div>

            <div class="md:col-span-2 flex gap-3">
                <x-ui.button type="submit" icon="check">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button :href="route('accounts.inter_company.index')" tone="secondary">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>

        {{-- ── ⚠️ কী বসবে, আগেই বলা ────────────────────────────────────
             ⓘ ব্যবহারকারী সেভ করার আগেই জানুন দুই খাতায় কী লেখা হবে —
             পরে খতিয়ানে অচেনা দাখিলা দেখে অবাক হওয়ার চেয়ে ভালো। --}}
        <p class="mt-4 text-sm text-(--color-ink-muted)">
            {{ __('accounts::message.inter_company_what_posts') }}
        </p>
    @endif
</x-layouts.app>
