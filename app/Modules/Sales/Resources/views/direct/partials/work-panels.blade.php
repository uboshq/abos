{{--
    কাজের প্যানেল — কার্টের নিচে, পুরো প্রস্থে (পরিবহন, জমা, ছাড়…)।
    ⓘ direct/index.blade.php থেকে হুবহু সরানো (পাতা সাজানো, ১০ অক্টোবর ২০২৬; NoScreenGrowsPastAThousandLinesTest)।
    partial মূল পাতার সব চলক পায়, Alpine-এর স্কোপও একই — আচরণ বদলায়নি।
--}}
{{--
    ── কাজের প্যানেল — কার্টের ঠিক নিচে, পুরো প্রস্থে ──────────

    ── মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬) ────────────────────
    তিনি জিজ্ঞেস করেছিলেন পপআপ ভালো নাকি বাঁয়ে নিচে খোলা, আর
    যুক্তি শুনে **নিচেরটাই** বেছেছেন।

    ── কেন পপআপ নয় ───────────────────────────────────────────
    ⚠️ এই চারটার তিনটাই **টাকার অঙ্ক বদলায়** — খরচ লিখলে "নিট
    পরিশোধযোগ্য", জমা লিখলে "বিলের বকেয়া"। ওই দুইটা সংখ্যা ডান
    প্যানেলে। **পপআপ ঠিক সেগুলোকেই ঢেকে দিত** — টাইপ এক জায়গায়,
    ফল ঢাকা পড়া জায়গায়।

    ── আর জায়গার হিসাব ────────────────────────────────────────
    ডান প্যানেল ৩৪০px, এখানে ১২৪৫px। পরিবহনের তিনটা ঘর (গাড়ি ·
    চালক · ভাড়া) সরু কলামে একটার নিচে আরেকটা নামত।

    ⓘ উপহারের ঘরটাও আজ এভাবেই ইনলাইনে বসেছে — এক পর্দায় দুই
    রকম নিয়ম (কিছু ইনলাইন, কিছু পপআপ) শেখার বোঝা বাড়ায়।
--}}
<div x-ref="actionPanel">
    @include('sales::direct.partials.panels')
</div>

{{-- ⭐ যা যোগ হলো — চারটা ছোট কার্ড, আগের নকশার মতো (মালিক, ৪ অক্টোবর ২০২৬); চাপলে নিজের পপ-আপ।
     ⓘ গাড়ি ও ভাড়ার লাইনটা এখানে, "ফেরত"-এর জায়গায় (মালিকের ছবি)। --}}
<div class="ds-cards" data-counter-cards>
    @if ($show['deposit'])
        <button type="button" @click="openPanel('deposit')" class="ds-card ds-b8-success">
            <span class="ds-card-h"><span>{{ __('sales::field.card_money') }}</span><span class="num" x-text="deposits.length"></span></span>
            <span class="ds-card-r">
                <span x-text="deposits.length ? depositMethodName(deposits[deposits.length - 1].methodId) : @js(__('sales::field.card_nothing'))"></span>
                <b class="num" x-show="deposits.length > 0" x-text="'৳' + money(deposit)"></b>
            </span>
        </button>
    @endif
    <button type="button" @click="openPanel('delivery')" class="ds-card ds-b8-inventory">
        <span class="ds-card-h"><span>{{ __('sales::field.btn_delivery') }}</span><span>✓</span></span>
        <span class="ds-card-r"><span x-text="deliveryModeLabel"></span></span>
    </button>
    <button type="button" @click="openPanel('note')" class="ds-card ds-b8-info">
        <span class="ds-card-h"><span>{{ __('sales::field.note') }}</span><span x-show="noteText !== ''" x-cloak>✓</span></span>
        <span class="ds-card-r"><span x-text="noteText || @js(__('sales::field.card_nothing'))"></span></span>
    </button>
    @if ($show['transport'])
        <button type="button" @click="openPanel('transport')" class="ds-card ds-b8-pending" data-transport-line>
            <span class="ds-card-h"><span>{{ __('sales::field.btn_transport') }}</span><span x-show="transportLine !== ''" x-cloak>✓</span></span>
            <span class="ds-card-r"><span x-text="transportLine || @js(__('sales::field.card_nothing'))"></span></span>
        </button>
    @endif
</div>

{{--
    ── নেওয়া জমাগুলোর তালিকা — চার্টের নিচে, বাম পাশে ──────────

    মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"এই list Item Chart-এর
    নিচে বাম পাশে থাকবে, একাধিক payment add করতে পারবে"*।

    ── কেন এখানে, ডান প্যানেলে নয় ─────────────────────────────
    ডান প্যানেলে জমার **যোগফল** থাকে ("জমা নেওয়া হলো ৳১৫,০০০")।
    ⚠️ সারিগুলোও ওখানে বসালে সরু কলামে পাঁচটা ঘর একটার নিচে
    আরেকটা নামত, আর যোগফলটা নিচে ঠেলে যেত — যে সংখ্যাটা সবচেয়ে
    বেশি দেখা হয়।

    ⭐ কার্টের নিচে বসার আসল কারণটা আলাদা: **এটা কার্টেরই মতো
    একটা জিনিস** — যা যা নেওয়া হলো তার তালিকা। মালের তালিকার
    ঠিক নিচে টাকার তালিকা, একই চোখের পথে।

    ⓘ খালি থাকলে দেখাই যায় না — বেশিরভাগ বাকির চালানে কোনো জমা
    নেই, আর একটা স্থায়ী খালি বাক্স রোজ চোখের সামনে থাকত।
--}}
@if ($show['deposit'])
    {{-- ⓘ ছকটা লুকানো — জমার আসল ঘরগুলো এখানেই থাকে বলে সার্ভারে যায়; চোখে পড়ে নিচের কার্ড আর
         "টাকা নিন" পপ-আপের তালিকা (মালিক, ৪ অক্টোবর ২০২৬) --}}
    <div hidden data-deposit-fields
         class="ds-gold rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-3">
        <div class="mb-2 flex items-baseline justify-between gap-2">
            <span class="text-2xs font-semibold text-(--color-ink)">
                {{ __('sales::field.received_deposit') }}
            </span>

            <span class="num text-sm font-bold text-(--color-success)"
                  x-text="'৳' + money(deposit)"></span>
        </div>

        <div class="overflow-x-auto">
            {{-- ⓘ `ui-list` — তিনটা নামের একটা।

                 নাম না থাকলে ছকের মাপ ও ধার **কোনো থিম বদলাতে
                 পারবে না**, আর থিম বদলালে এই ছকটা একা আগের
                 চেহারায় বসে থাকত। ⚠️ ঘরের প্যাডিংও তাই হাতে
                 লেখা হয়নি — ওটা টোকেন থেকে আসে। --}}
            <table class="ui-list w-full text-2xs">
                <thead class="text-(--color-ink-muted)">
                    <tr class="border-b border-(--color-border)">
                        <th class="text-start font-medium">{{ __('sales::field.ref_date') }}</th>
                        <th class="text-start font-medium">{{ __('sales::field.deposit_method') }}</th>
                        <th class="text-start font-medium">{{ __('sales::field.account') }}</th>
                        <th class="text-start font-medium">{{ __('sales::field.deposit_ref') }}</th>
                        {{-- ⚠️ "বিবরণ", "মন্তব্য" নয় — মালিকের সিদ্ধান্ত
                             (৪ সেপ্টেম্বর ২০২৬)। লেখাটা
                             `collections.narration`-এ বসে, আদায়ের
                             কাগজে ছাপা হয়, আর খতিয়ানের সারিতে যায় —
                             অর্থাৎ ওটা হিসাবের বক্তব্য, পাশের নোট নয়।
                             ⓘ এক কলামের দুইটা নাম হলে রিপোর্টে ওরা
                             মিলত না। --}}
                        <th class="text-start font-medium">{{ __('sales::field.narration') }}</th>
                        <th class="text-end font-medium">{{ __('sales::field.amount') }}</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <template x-for="(row, i) in deposits" :key="i">
                        <tr class="border-b border-(--color-border)/50">
                            <td class="num" x-text="row.refDate || '—'"></td>
                            <td x-text="depositMethodName(row.methodId)"></td>
                            <td x-text="depositAccountName(row.accountId)"></td>
                            {{-- ⓘ ব্যাংকের জমায় ট্রান্সফার মোডও — "NPSB · TRX123" --}}
                            <td class="num" x-text="depositRefText(row)"></td>
                            <td x-text="row.narration || '—'"></td>
                            <td class="num text-end font-semibold"
                                x-text="money(row.amount)"></td>

                            <td class="text-end">
                                <button type="button" @click="dropDeposit(i)"
                                        class="rounded px-1.5 text-(--color-danger)"
                                        aria-label="{{ __('sales::action.remove_line') }}">&times;</button>
                            </td>

                            {{-- ⚠️ সার্ভারে যা যায় — সারির ভেতরেই।

                                 উপহারের মতো আলাদা করে সমতল করার
                                 দরকার নেই: তালিকাটা এমনিতেই এক
                                 স্তরের, তাই সূচকটা এখানেই বসে।

                                 ⓘ উপায়ের **কোড আর খাত সার্ভার
                                 নিজে বের করে** — পর্দার পাঠানো
                                 নাম বিশ্বাস করা হয় না। --}}
                            <td class="hidden">
                                <x-counter.deposit-fields />

                                {{-- ⭐ ব্যাংক/মোবাইল ব্যাংকিংয়ের তথ্য — মালিকের
                                     নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬। ⓘ কেবল এই
                                     পর্দার, তাই ক্রয়ের সাথে ভাগ করা
                                     কম্পোনেন্টে নয়; কেবল ভরা ঘর, আর সারির
                                     উপায়ের ধরন ধরে ([[depositDetailsOf()]])। --}}
                                <template x-for="d in depositDetailsOf(row)" :key="d.key">
                                    <input type="hidden" :name="'deposits[' + i + '][' + d.key + ']'"
                                           :value="d.value">
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
@endif

{{--
    ── উপহারগুলো সার্ভারে যায় এখান থেকে ─────────────────────

    কার্টের সারিগুলো নিজেরাই লুকানো ঘর বহন করে, কিন্তু উপহার
    পারে না: সার্ভার একটা **সমতল** তালিকা চায়
    (`gifts[0][…]`, `gifts[1][…]`), অথচ পর্দায় ওগুলো লাইনের
    ভেতরে বাসা বেঁধে আছে।

    `payloadGifts` ওই দুইটাকে মেলায় — আর ঠিক ওখানেই
    `against_product_id` বসে **লাইনের পণ্য থেকে**, কোনো
    ড্রপডাউন থেকে নয়।

    ⚠️ খালি productId বা শূন্য পরিমাণের উপহার এখানে ছেঁকে
    ফেলা হয়, নাহলে সার্ভারে অর্ধেক লেখা সারি যেত।
--}}
<template x-for="(g, n) in payloadGifts" :key="n">
    <span>
        <input type="hidden" :name="'gifts[' + (n) + '][product_id]'" :value="g.productId">
        <input type="hidden" :name="'gifts[' + (n) + '][against_product_id]'" :value="g.againstProductId">
        <input type="hidden" :name="'gifts[' + (n) + '][qty]'" :value="g.qty">
        <input type="hidden" :name="'gifts[' + (n) + '][remarks]'" :value="g.remarks">
    </span>
</template>

