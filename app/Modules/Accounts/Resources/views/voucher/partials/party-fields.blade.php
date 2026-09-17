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
@endphp

<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         x-data="partyVoucher({
             partyType: @js((string) ($was('party_type') ?? '')),
             partyId: @js((string) ($was('party_id') ?? '')),
             parties: @js(collect($parties)->flatMap(fn (array $g) => collect($g['options'])
                 ->map(fn (array $o) => ['type' => $g['type'], 'id' => (int) $o['id'], 'label' => $o['label']]))
                 ->values()),
             dueUrl: @js(route('accounts.voucher.due')),
             picked: @js(old('bill_allocs', [])),
             texts: @js([
                 'owed' => __('accounts::field.owed'),
                 'allocated' => __('accounts::field.allocated'),
                 'overAllocated' => __('accounts::message.over_allocated'),
                 'noBills' => __('accounts::message.no_open_bill'),
             ]),
         })">

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
    <div class="grid gap-3 sm:grid-cols-4">
        <x-ui.field name="trx_date" type="date"
                    :label="__('accounts::field.trx_date_long')"
                    :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                    required />

        <x-ui.field name="moved_at" type="time"
                    :label="__('accounts::field.moved_at')"
                    :value="$was('moved_at')" />


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
        <x-ui.select name="party_type"
                     :label="$isReceipt ? __('accounts::field.depositor_type') : __('accounts::field.payee_type')"
                     :options="collect($parties)->pluck('label', 'type')->all()"
                     :selected="$was('party_type')"
                     x-model="partyType" x-on:change="resetParty()" />

        <div>
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium">
                    {{ $isReceipt ? __('accounts::field.depositor_name') : __('accounts::field.payee_name_party') }}
                </span>

                {{-- পাওনা — নামের পাশে, নমুনার মতো --}}
                <span class="text-xs text-(--color-ink-muted)" x-show="due !== null" x-cloak>
                    <span x-text="texts.owed"></span>
                    <b class="num ms-1" x-text="due"></b>
                </span>
            </div>

            <select name="party_id" x-model="partyId" x-on:change="loadDue()"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-card) px-3">
                <option value="">—</option>
                <template x-for="p in partyOptions" :key="p.id">
                    <option :value="p.id" x-text="p.label" :selected="String(p.id) === partyId"></option>
                </template>
            </select>

            @error('party_id')
                <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
            @enderror

            {{-- ⭐ তালিকায় নেই? নাম লিখুন — ১৮ সেপ্টেম্বর ২০২৬।

                 ── ⛔ কেন দরকার ────────────────────────────────────────
                 মালিকের কথা: *"ডিপোজিটরের নাম / প্রাপকের নাম হাতে লিখতে
                 পারতে হবে।"* ⚠️ এতদিন ঘরটা কেবল ড্রপডাউন ছিল, তাই
                 কাউন্টারে একজন অচেনা লোক টাকা দিয়ে গেলে **রসিদই কাটা
                 যেত না** — আগে মাস্টার ডেটায় গিয়ে তাঁকে বসিয়ে আসতে হত।

                 ── ⚠️ কেন পঞ্চম একটা "অন্যান্য" ধরন বানানো হয়নি ────────
                 ⛔ তাহলে ঐ নামগুলো কোনো তালিকায় থাকত না, পরের বার আবার
                 টাইপ করতে হত, আর একই মানুষ তিন বানানে তিনজন হয়ে যেতেন।

                 ⭐ বদলে নামটা **ব্যক্তি** হিসেবে বসে (`mdm_people`) —
                 মালিকের নিজের নির্দেশে ওখানেই ঋণদাতা, বাড়িওয়ালা, বাহক
                 সবাই থাকেন। ⓘ তাই পরের বার নামটা তালিকাতেই পাওয়া যায়।

                 ⓘ ছাঁচটা Finance-এর [[finance::components.person-picker]]
                 থেকে নেওয়া, আর পিছনে একই [[PersonResolver]]। --}}
            <details class="mt-2 text-sm"
                     @if ($errors->has('party_new')) open @endif>
                <summary class="cursor-pointer text-(--color-brand-500) underline-offset-2 hover:underline">
                    {{ __('accounts::field.party_not_listed') }}
                </summary>

                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <x-ui.field name="party_new"
                                :label="__('accounts::field.party_new_name')"
                                :value="old('party_new')"
                                :hint="__('accounts::field.party_new_hint')" />

                    <x-ui.field name="party_mobile"
                                :label="__('master_data::field.mobile')"
                                :value="old('party_mobile')" />
                </div>
            </details>
        </div>
    </div>

    {{--
        ── কোন বিলের বিপরীতে ──────────────────────────────────────────
        ⭐ রসিদের সবচেয়ে বড় প্রশ্ন, আর এতদিন পর্দায় ছিলই না।

        ⚠️ মোট বকেয়া জানা আর কোন বিলের বিপরীতে জানা এক নয়। ⓘ না জানলে
        পুরনো বিলটা চিরকাল খোলা থাকত আর নতুনটা শোধ দেখাত — টাকাটা একই,
        অথচ বয়স ধরে বকেয়ার তালিকা মিথ্যা বলত।

        ⓘ ভাঁজটা নিজে থেকেই খোলে যখন পক্ষ বাছা হয় আর বিল আছে।
    --}}
    <details class="mt-4 rounded-(--radius-card) border border-(--color-border) p-3"
             x-bind:open="bills.length > 0">
        <summary class="cursor-pointer text-sm font-semibold">
            {{ __('accounts::field.against_which_invoice') }}
            <span class="ms-2 text-xs font-normal text-(--color-ink-muted)"
                  x-show="bills.length > 0" x-cloak
                  x-text="texts.allocated + ' ' + allocatedTotal.toFixed(2)"></span>
        </summary>

        <template x-if="bills.length === 0">
            <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) p-3 text-sm text-(--color-ink-muted)"
               x-text="texts.noBills"></p>
        </template>

        <template x-if="bills.length > 0">
            <div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-(--color-ink-muted)">
                            <tr class="border-b border-(--color-border)">
                                <th class="w-8"></th>
                                <th class="p-2 text-start">{{ __('accounts::field.invoice') }}</th>
                                <th class="p-2 text-start">{{ __('accounts::field.date') }}</th>
                                <th class="p-2 text-end">{{ __('accounts::field.age') }}</th>
                                <th class="p-2 text-end">{{ __('accounts::field.outstanding') }}</th>
                                <th class="p-2 text-end">{{ __('accounts::field.on_this_receipt') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(b, i) in bills" :key="b.id">
                                <tr class="border-b border-(--color-border)">
                                    <td class="p-2">
                                        <input type="checkbox" :value="b.id"
                                               :name="'bill_allocs[' + i + '][invoice_id]'"
                                               :checked="alloc[b.id] !== undefined"
                                               x-on:change="toggle(b, $event.target.checked)">
                                    </td>
                                    <td class="p-2 font-medium" x-text="b.no"></td>
                                    <td class="p-2" x-text="b.date"></td>
                                    <td class="num p-2 text-end" x-text="b.age"></td>
                                    <td class="num p-2 text-end" x-text="Number(b.outstanding).toFixed(2)"></td>
                                    <td class="p-2 text-end">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               class="num w-28 rounded-(--radius-field) border border-(--color-border) p-1 text-end"
                                               :name="'bill_allocs[' + i + '][amount]'"
                                               x-model.number="alloc[b.id]">
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                {{--
                    ⭐ পুরনো বিল আগে — মালিকের নিজের চাওয়া নিয়ম।

                    ⓘ টাকাটা বয়সের ক্রমে বসে, আর যতটুকু বাকি ততটুকুই।
                    ⚠️ নিজে থেকে হয় না, বোতাম চাপলে হয় — কারণ কখনো
                    গ্রাহক নির্দিষ্ট একটা বিলের জন্যই টাকা দেন।
                --}}
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button"
                            class="rounded-full border border-(--color-border) px-3 py-1 text-xs"
                            x-on:click="fifo()">
                        {{ __('accounts::field.oldest_first') }}
                    </button>
                    <button type="button"
                            class="rounded-full border border-(--color-border) px-3 py-1 text-xs"
                            x-on:click="clearAlloc()">
                        {{ __('accounts::field.clear_all') }}
                    </button>
                </div>

                <p class="mt-3 flex items-center justify-between text-sm">
                    <span class="text-(--color-ink-muted)" x-text="texts.allocated"></span>
                    <span class="num font-semibold" x-text="allocatedTotal.toFixed(2)"></span>
                </p>

                {{-- ⚠️ ভাগ করা টাকা গৃহীত টাকার চেয়ে বেশি — অঙ্ক দুইটা সমান হতেই হবে --}}
                <p class="mt-1 text-xs text-(--color-danger)" x-show="overAllocated" x-cloak
                   x-text="texts.overAllocated"></p>
            </div>
        </template>
    </details>
</section>
