{{--
    ── ⛔ পাঁচটা পদ্ধতির ব্লক এখন `x-if`, `x-show` নয় — ১৮ সেপ্টেম্বর ২০২৬ ──

    ⚠️ এটা রূপের সিদ্ধান্ত নয়, **টাকার**। `x-show` ঘরটাকে কেবল চোখ থেকে
    লুকায় — ঘরটা DOM-এ থেকে যায়, আর জমা দেওয়ার সময় **যায়ও**।

    ⛔ পাঁচটা ব্লকেই একই নামের ঘর আছে (`instrument_no`, `charge_amount`,
    `from_bank`, `from_branch`, `from_account_name`, `from_account_no`) —
    অর্থাৎ ব্রাউজার ছয়টা নামের প্রতিটা **কয়েকবার** পাঠাত, আর সার্ভার
    শেষেরটা রাখত।

    ⓘ ফল, রোজকার ভাষায়: কেউ "মোবাইল ব্যাংকিং" বেছে লেনদেন নম্বর লিখলেন,
    আর লুকানো চেকের ব্লকের **খালি** `instrument_no` সেটা মুছে দিত। টাকা
    ঠিকই বসত, কিন্তু *কোন চেকে · কোন ব্যাংকে · কত কমিশন* — সব হারাত।

    ⭐ আর নীরবে: কোনো ভুলবার্তা নেই, পর্দায় সব ঠিক দেখাত, টের পাওয়া যেত
    মাস খানেক পরে মিলাতে বসে।

    ── ⓘ এই শিক্ষাটা এই রিপোতেই আগে লেখা আছে ─────────────────────────
    খাতের ফর্মে (`coa/form.blade.php`) হুবহু একই কারণে `x-if` ব্যবহার করা
    হয়েছে, আর সেখানে মন্তব্যে লেখা: *"লুকানো খালি ঘরটা ভরা ঘরটাকে মুছে
    দিত, আর ব্যবহারকারী সেভ করে দেখতেন নামটা উধাও।"*

    ⚠️ শিক্ষাটা লেখা ছিল, কিন্তু এই ফাইলে পৌঁছায়নি। ⭐ ধরা পড়েছে
    [[EveryFormScreenAnswersForItselfTest]]-এ — পাতাটা সত্যিই এঁকে,
    একই নামের সক্রিয় ঘর গুনে।
--}}
@props([
    /*
     * টাকা কোন দিকে — `in` (আমরা পাচ্ছি) বা `out` (আমরা দিচ্ছি)।
     *
     * ⛔ এটাই একমাত্র প্রপ যেটা ভুল দিলে ঘরগুলো **চুপচাপ ভুল অর্থ** বহন
     * করবে। `counterparty_phone` রসিদে প্রেরকের নম্বর আর পরিশোধে প্রাপকের —
     * একই কলাম, দুই অর্থ, আর অর্থটা আসে এখান থেকেই।
     */
    'direction' => 'in',

    /* মানুষ যাদের হাত দিয়ে টাকা যায় — বাহকের তালিকা। */
    'carriers' => [],

    /* BEFTN · RTGS · NPSB — `mdm_transfer_modes` থেকে। */
    'modes' => [],

    /*
     * বাহকের ঘর দুইটা এখানে আঁকা হবে কি না।
     *
     * ⓘ রসিদ ও পরিশোধে ওগুলো পর্দার **উপরে** বসে (নমুনার সারি ১), তাই
     * সেখানে `false`। ⚠️ দুই জায়গায় একই নামের ঘর থাকলে ব্রাউজার
     * শেষেরটার মান পাঠায়, আর উপরের বাছাইটা নীরবে হারায়।
     */
    'carrierHere' => true,

    /* ভাউচারের নিজের অঙ্ক, নোট গোনার সাথে মেলানোর জন্য। */
    'amountField' => 'amount',

    /*
     * নোট গোনার ঘরটা আসবে কি না।
     *
     * ⚠️ `false` দেওয়ার একটাই বৈধ কারণ: পর্দাটা নগদ নেয়ই না। ⓘ "লাগবে
     * না মনে হচ্ছে" কারণ নয় — নগদ নিলে গোনাটাও লাগে, নাহলে দিনশেষে
     * গরমিলটা কোন নোটে তা কেউ বলতে পারে না।
     */
    'counting' => true,

    /*
     * সম্পাদনার সময় যে ভাউচারটা খোলা — ঘরগুলো এর মান নিয়ে বসে।
     *
     * ⛔ এটা না থাকলে একটা পুরনো ভাউচার খুললে প্রতিটা ঘর **ফাঁকা** আসত,
     * আর সেভ করলে মানগুলো নীরবে মুছে যেত। ⚠️ কোনো ত্রুটি নয়: ফর্মটা
     * ফাঁকা ঘরই জমা দিত, আর ভ্যালিডেশন ওগুলোকে "দেওয়া হয়নি" ধরে নিত।
     *
     * ⓘ নতুন ভাউচারে `null` — তখন `old()`-ই একমাত্র উৎস, আর সেটাই ঠিক।
     */
    'record' => null,
])

{{--
    টাকাটা কীভাবে হাতবদল হলো — চারটা মাধ্যম, আর প্রতিটার নিজের প্রশ্ন।

    ══ ⭐ এই ফাইলটা কেন আছে, আর কেন একটাই ══════════════════════════════
    ১৪ সেপ্টেম্বর ২০২৬-এ দুইটা সেশন **আলাদাভাবে একই ব্লক** বানিয়ে ফেলেছিল —
    একটা হিসাবের ভাউচারে, একটা অর্থের খাতায়। ⓘ দুইটাই কাজ করত, দুইটাই
    দেখতে প্রায় এক ছিল।

    ⛔ কিন্তু দুইটা বাস্তবায়ন মানে একদিন দুইটা আলাদা উত্তর: ছয় মাস পর
    একটায় চেকের ব্রাঞ্চ চাওয়া হবে, অন্যটায় হবে না — আর তখন *"কোন ব্যাংক
    থেকে কত এল"* প্রশ্নের উত্তর **অর্ধেক লেনদেনে** থাকবে না।

    ⭐ তাই ব্লকটা একটাই, আর তার জায়গা হিসাবে: **প্রতিটা ভাউচারই টাকার
    নড়াচড়া, অর্থের কেবল কিছু সারি।** অর্থ এটা ডাকবে, নকল করবে না।

    ══ ⚠️ নতুন ঘর যোগ করার নিয়ম ═══════════════════════════════════════
    এখানে যোগ করুন, দুই জায়গায় নয়। আর যোগ করলে
    [[MoneyMovementHasEveryFieldTheOwnerAskedForTest]] সংখ্যাটা বাড়াতে
    হবে — ⓘ পরীক্ষাটা **গুনে** দেখে, কারণ মালিকের শর্ত ছিল
    *"স্যাম্পলের সাথে ১০০% মিল — ৯৯.৯৯% হলেও হবে না"*।
--}}

@php
    $inward = $direction === 'in';

    /*
     * পুরনো ইনপুট আগে, তারপর সারির মান — Laravel-এর নিজের ক্রম।
     *
     * ⚠️ উল্টো করলে ভ্যালিডেশন ব্যর্থ হয়ে ফেরার পর ব্যবহারকারীর টাইপ
     * করা লেখা হারিয়ে যেত, আর তিনি আবার সব লিখতেন — প্রতিবার।
     */
    $was = fn (string $field, $fallback = null) => old($field, $record?->{$field} ?? $fallback);

    /*
     * নোটের মান — বড় থেকে ছোট।
     *
     * ⓘ [[CashCount]] হুবহু এই তালিকাই ব্যবহার করে। ⚠️ দুই জায়গায় দুই
     * তালিকা হলে একদিন একটায় দুই টাকার নোট থাকবে, অন্যটায় না, আর
     * গণনা দুইটা আলাদা যোগফল দিত।
     */
    $notes = \App\Modules\Accounts\Models\CashCount::DENOMINATIONS;
@endphp

<div class="flex flex-col gap-3"
     x-data="moneyMovement({ amountField: @js($amountField) })">

    {{--
        ── কে বহন করল, আর কখন ──────────────────────────────────────
        ⛔ রসিদ ও পরিশোধে ঘর দুইটা **উপরে** বসে (নমুনার সারি ১), তাই
        সেখানে এই ব্লকটা আঁকা হয় না।

        ⚠️ দুই জায়গায় একই নামের ঘর থাকলে ব্রাউজার **শেষেরটার** মান
        পাঠাত, আর উপরের বাছাইটা নীরবে হারাত — ব্যবহারকারী উপরে বাহক
        বাছতেন, খাতায় বসত নিচের খালি ঘরটা। ⓘ ধরা পড়েছে গুনে: পর্দায়
        "কার মাধ্যমে" দুইবার ছিল।
    --}}
    @if ($carrierHere)
        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.select name="carried_by" :label="__('accounts::field.carried_by')"
                         :options="$carriers" :selected="$was('carried_by')"
                         {{--
                             ⛔ `blank` নামে কোনো প্রপ নেই — ১৮ সেপ্টেম্বর ২০২৬।
                             ⓘ শব্দটা HTML অ্যাট্রিবিউট হয়ে বসত, আর কম্পোনেন্ট
                             খালি সারিটা **আঁকতই না** — অর্থাৎ একবার কাউকে বাছলে
                             "কেউ নয়" ফেরার উপায়ই থাকত না। সঠিক নাম `placeholder`।
                         --}}
                         :placeholder="__('accounts::field.carried_by_nobody')" />

            <x-ui.field name="moved_at" type="time" :label="__('accounts::field.moved_at')"
                        :value="$was('moved_at')" />
        </div>
    @endif

    {{-- ── মাধ্যম ───────────────────────────────────────────────── --}}
    <fieldset class="flex flex-col gap-1.5">
        {{--
            ⓘ নামটা দিক অনুযায়ী — নকশায় খরচে "কীভাবে দেওয়া হলো",
            আর আদায়ে "কীভাবে এলো"। ⚠️ একটাই নাম রাখলে দুই দিকের
            একটায় সেটা উল্টো অর্থ বহন করত।
        --}}
        <legend class="text-2xs font-medium tracking-wide text-(--color-ink-muted) uppercase">
            {{ $direction === 'out'
                ? __('accounts::field.how_it_moved')
                : __('accounts::field.how_it_came') }}
        </legend>

        <div class="flex flex-wrap gap-2">
            {{--
                ⓘ নমুনায় চারটা চিপ — কার্ড নেই।

                ⚠️ `card` ধ্রুবক থেকে মোছা হয়নি: পুরনো ভাউচারে
                ওই মানটা বসা আছে, আর ধ্রুবক থেকে সরালে যাচাই ওগুলো
                সম্পাদনা করতে দিত না — ঠিক যে ভুলটা ১৪ সেপ্টেম্বরে ধরা
                পড়েছিল। ⓘ কেবল নতুন ভাউচারে চিপটা দেখানো হয় না।
            --}}
            @foreach (\App\Modules\Accounts\Models\Voucher::INSTRUMENTS as $way)
                @continue($way === 'card' && $was('instrument') !== 'card')
                <label class="cursor-pointer">
                    <input type="radio" name="instrument" value="{{ $way }}" class="peer sr-only"
                           x-model="method" @checked($was('instrument', 'cash') === $way)>
                    <span class="inline-flex items-center gap-2 rounded-(--radius-field) border
                                 border-(--color-border) bg-(--color-surface-sunken) px-3 py-1.5 text-sm
                                 text-(--color-ink-muted) transition-colors
                                 peer-checked:border-(--color-brand-500) peer-checked:bg-(--color-surface-card)
                                 peer-checked:font-medium peer-checked:text-(--color-ink)
                                 peer-focus-visible:outline-2 peer-focus-visible:outline-(--color-brand-500)">
                        {{ __('accounts::instrument.'.$way) }}
                    </span>
                </label>
            @endforeach
        </div>

        {{-- ⓘ নকশার ইশারা: প্রতিটা মাধ্যমের ঘরগুলো নিজে থেকে খোলে। --}}
        <p class="text-2xs text-(--color-ink-muted)">
            {{ __('accounts::message.instrument_opens_own_fields') }}
        </p>
    </fieldset>

    {{-- ── নগদ: নোটের হিসাব ─────────────────────────────────────
         ⓘ গোনা আর লেখা — দুইটা সংখ্যা **অঙ্কের নিয়মে** সমান হওয়ার
         কথা, তাই এখানে সতর্কতা নয়, থামানো। নিয়মটা কোথায় থামাবে আর
         কোথায় কেবল দেখাবে: [[docs]] আর নমুনার শেষ প্যানেল। --}}
    @if ($counting)
        <template x-if="method === 'cash'">
        <div class="rounded-(--radius-field) border border-(--color-border)
                    border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3">
            <p class="mb-2 text-2xs font-medium tracking-wide text-(--color-ink-muted) uppercase">
                {{ __('accounts::field.note_breakdown') }}
            </p>

            <div class="grid gap-x-5 gap-y-1 sm:grid-cols-2">
                @foreach ($notes as $note)
                    <label class="grid grid-cols-[3.5rem_0.75rem_3.5rem_1fr] items-center gap-1.5
                                  font-mono text-xs tabular-nums">
                        <span class="text-right text-(--color-ink-muted)">{{ number_format($note) }}</span>
                        <span class="text-center text-(--color-ink-faint)">×</span>
                        <input type="number" min="0" step="1" inputmode="numeric"
                               name="note_counts[{{ $note }}]"
                               x-model.number="notes[{{ $note }}]"
                               value="{{ $was('note_counts')[$note] ?? '' }}"
                               aria-label="{{ __('accounts::field.note_of', ['note' => number_format($note)]) }}"
                               class="w-full rounded border border-(--color-border) bg-(--color-surface-card)
                                      px-1.5 py-0.5 text-right">
                        <span class="text-right text-(--color-ink-muted)"
                              x-text="(notes[{{ $note }}] || 0) * {{ $note }} || '—'"></span>
                    </label>
                @endforeach
            </div>

            <div class="mt-2 flex items-baseline justify-between border-t border-(--color-border) pt-2">
                <span class="text-2xs font-medium tracking-wide text-(--color-ink-muted) uppercase">
                    {{ __('accounts::field.counted_total') }}
                </span>
                <span class="font-mono text-base tabular-nums"
                      :class="countMatches ? 'text-(--color-badge-success-ink)' : 'text-(--color-badge-warning-ink)'"
                      x-text="counted.toLocaleString('en-IN')"></span>
            </div>

            <p class="mt-2 rounded px-2 py-1 text-xs"
               :class="countMatches
                   ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                   : 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'"
               x-text="countMatches
                   ? @js(__('accounts::message.count_agrees'))
                   : @js(__('accounts::message.count_differs'))"></p>
        </div>
        </template>
    @endif

    {{-- ── মোবাইল ব্যাংকিং ──────────────────────────────────────── --}}
    <template x-if="method === 'mfs'">
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.select name="wallet" :label="__('accounts::field.wallet')" :selected="$was('wallet')"
                         :options="[
                             'bkash' => __('accounts::wallet.bkash'),
                             'nagad' => __('accounts::wallet.nagad'),
                             'rocket' => __('accounts::wallet.rocket'),
                             'upay' => __('accounts::wallet.upay'),
                         ]" />

            <x-ui.select name="wallet_medium" :label="__('accounts::field.wallet_medium')"
                         :selected="$was('wallet_medium')"
                         :options="[
                             'send_money' => __('accounts::wallet.send_money'),
                             'cash_out' => __('accounts::wallet.cash_out'),
                             'payment' => __('accounts::wallet.payment'),
                             'agent_deposit' => __('accounts::wallet.agent_deposit'),
                         ]" />

            {{-- ⛔ লেখাটা দিক ধরে বদলায়; কলামটা একটাই। --}}
            <x-ui.field name="counterparty_phone" inputmode="tel" :value="$was('counterparty_phone')"
                        :label="$inward ? __('accounts::field.sender_phone') : __('accounts::field.receiver_phone')" />

            <div class="sm:col-span-2">
                <x-ui.field name="instrument_no" :label="__('accounts::field.transaction_id')"
                            :value="$was('instrument_no')" />
            </div>

            <x-ui.field name="charge_amount" type="number" step="0.01" numeric
                        :label="__('accounts::field.charge')" :value="$was('charge_amount')"
                        x-model.number="charge" />
        </div>

        <x-ui.charge-bearer :direction="$direction" :record="$record" />
    </div>
    </template>

    {{-- ── ব্যাংক ট্রান্সফার ─────────────────────────────────────── --}}
    <template x-if="method === 'transfer'">
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.select name="transfer_mode_id" :label="__('accounts::field.transfer_mode')"
                         :options="$modes" :selected="$was('transfer_mode_id')" placeholder="—" />

            <x-ui.field name="from_bank" :value="$was('from_bank')"
                        :label="$inward ? __('accounts::field.from_bank') : __('accounts::field.our_bank')" />
            <x-ui.field name="from_branch" :label="__('accounts::field.branch')" :value="$was('from_branch')" />

            {{-- ⚠️ নম্বর দেখে বলা যায় না টাকাটা ঠিক হিসাব থেকে এসেছে
                 কি না — নাম দেখে যায়। --}}
            <x-ui.field name="from_account_name" :label="__('accounts::field.account_holder')"
                        :value="$was('from_account_name')" />
            <x-ui.field name="from_account_no" :label="__('accounts::field.account_no')"
                        :value="$was('from_account_no')" />
            <x-ui.field name="instrument_no" :label="__('accounts::field.transaction_id')"
                            :value="$was('instrument_no')" />

            <x-ui.field name="deposit_slip_no" :label="__('accounts::field.deposit_slip')"
                        :value="$was('deposit_slip_no')" />
            <x-ui.field name="charge_amount" type="number" step="0.01" numeric
                        :label="__('accounts::field.bank_charge')" :value="$was('charge_amount')"
                        x-model.number="charge" />

            {{-- ⛔ খাতটা দিক ধরে আলাদা: আসছে হলে 1103 (সম্পদ), যাচ্ছে
                 হলে 2114 (দায়)। এক খাতে বসালে ব্যালান্স শিট ভুল পাশে
                 দেখাত, আর যোগফল মিলে যেত। --}}
            <x-ui.field name="lands_on" type="date" :label="__('accounts::field.lands_on')"
                        :value="$was('lands_on')" />
        </div>

        <x-ui.charge-bearer :direction="$direction" :record="$record" />
    </div>
    </template>


    {{-- ── কার্ড ─────────────────────────────────────────────────
         ⓘ ডিপোতে কার্ড বিরল, কিন্তু ব্যবস্থায় মাধ্যমটা আগে থেকেই আছে
         (`VoucherRequest`-এর তালিকায়)। ⛔ ঘরটা না রাখলে কার্ডে নেওয়া
         একটা পুরনো ভাউচার সম্পাদনা করতে গেলে মাধ্যমটাই বেছে নেওয়া
         যেত না, আর সেভ করলে সেটা নীরবে বদলে যেত।

         ⚠️ কার্ডের নিজের প্রশ্ন কম: টার্মিনালের রেফারেন্স, আর ব্যাংকের
         কমিশন। চেক বা ওয়ালেটের ঘরগুলো এখানে অর্থহীন। --}}
    <template x-if="method === 'card'">
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.field name="instrument_no" :label="__('accounts::field.card_reference')"
                        :value="$was('instrument_no')" />
            <x-ui.field name="from_bank" :label="__('accounts::field.card_bank')"
                        :value="$was('from_bank')" />
            <x-ui.field name="charge_amount" type="number" step="0.01" numeric
                        :label="__('accounts::field.card_commission')"
                        :value="$was('charge_amount')" x-model.number="charge" />
        </div>

        <x-ui.charge-bearer :direction="$direction" :record="$record" />
    </div>
    </template>

    {{-- ── চেক ──────────────────────────────────────────────────── --}}
    <template x-if="method === 'cheque'">
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.field name="instrument_no" :label="__('accounts::field.cheque_no')"
                        :value="$was('instrument_no')" />
            <x-ui.field name="instrument_date" type="date" :label="__('accounts::field.cheque_date')"
                        :value="$was('instrument_date')" x-model="chequeDate" />
            <x-ui.field name="from_bank" :label="__('accounts::field.bank_name')" />
            <x-ui.field name="from_branch" :label="__('accounts::field.branch')" :value="$was('from_branch')" />
            <x-ui.field name="from_account_name" :label="__('accounts::field.account_holder')"
                        :value="$was('from_account_name')" />
            <x-ui.field name="from_account_no" :label="__('accounts::field.account_no')"
                        :value="$was('from_account_no')" />
        </div>

        {{-- ⚠️ আগাম তারিখের চেক — টাকা আজ খাতায় বসে না। ⓘ এটা থামায় না,
             কেবল বলে, কারণ আগাম চেক নেওয়া বৈধ আর রোজকার। --}}
        <p class="mt-2 rounded bg-(--color-badge-warning-bg) px-2 py-1 text-xs
                  text-(--color-badge-warning-ink)"
           x-show="postDated" x-cloak>
            {{ $inward ? __('accounts::message.pdc_received') : __('accounts::message.pdc_issued') }}
        </p>
    </div>
    </template>
</div>
