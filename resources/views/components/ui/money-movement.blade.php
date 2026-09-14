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
     * নোটের মান — বড় থেকে ছোট।
     *
     * ⓘ [[CashCount]] হুবহু এই তালিকাই ব্যবহার করে। ⚠️ দুই জায়গায় দুই
     * তালিকা হলে একদিন একটায় দুই টাকার নোট থাকবে, অন্যটায় না, আর
     * গণনা দুইটা আলাদা যোগফল দিত।
     */
    $notes = \App\Modules\Accounts\Models\CashCount::DENOMINATIONS;
@endphp

<div class="flex flex-col gap-3"
     x-data="{
         method: 'cash',
         charge: 0,
         chargeBy: 'us',
         amount: 0,
         notes: {},
         chequeDate: '',

         get counted() {
             return Object.entries(this.notes)
                 .reduce((sum, [note, qty]) => sum + (Number(note) * (Number(qty) || 0)), 0)
         },
         get countMatches() {
             return Math.abs(this.counted - Number(this.amount || 0)) < 0.005
         },
         get postDated() {
             return this.method === 'cheque' && this.chequeDate
                 && this.chequeDate > new Date().toISOString().slice(0, 10)
         },
     }"
     x-init="
         amount = Number(($el.closest('form')?.querySelector('[name={{ $amountField }}]')?.value || 0))
             .toString().replace(/[^0-9.]/g, '') || 0
     ">

    {{-- ── কে বহন করল, আর কখন ─────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <x-ui.select name="carried_by" :label="__('accounts::field.carried_by')"
                     :options="$carriers" blank="{{ __('accounts::field.carried_by_nobody') }}" />

        <x-ui.field name="moved_at" type="time" :label="__('accounts::field.moved_at')" />
    </div>

    {{-- ── মাধ্যম ───────────────────────────────────────────────── --}}
    <fieldset class="flex flex-col gap-1.5">
        <legend class="text-2xs font-medium tracking-wide text-(--color-ink-muted) uppercase">
            {{ __('accounts::field.how_it_moved') }}
        </legend>

        <div class="flex flex-wrap gap-2">
            @foreach (['cash', 'mfs', 'online', 'cheque'] as $way)
                <label class="cursor-pointer">
                    <input type="radio" name="instrument" value="{{ $way }}" class="peer sr-only"
                           x-model="method" @checked($way === 'cash')>
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
    </fieldset>

    {{-- ── নগদ: নোটের হিসাব ─────────────────────────────────────
         ⓘ গোনা আর লেখা — দুইটা সংখ্যা **অঙ্কের নিয়মে** সমান হওয়ার
         কথা, তাই এখানে সতর্কতা নয়, থামানো। নিয়মটা কোথায় থামাবে আর
         কোথায় কেবল দেখাবে: [[docs]] আর নমুনার শেষ প্যানেল। --}}
    @if ($counting)
        <div class="rounded-(--radius-field) border border-(--color-border)
                    border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3"
             x-show="method === 'cash'" x-cloak>
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
    @endif

    {{-- ── মোবাইল ব্যাংকিং ──────────────────────────────────────── --}}
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3"
         x-show="method === 'mfs'" x-cloak>
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.select name="wallet" :label="__('accounts::field.wallet')"
                         :options="['bkash' => 'বিকাশ', 'nagad' => 'নগদ', 'rocket' => 'রকেট', 'upay' => 'উপায়']" />

            <x-ui.select name="wallet_medium" :label="__('accounts::field.wallet_medium')"
                         :options="[
                             'send_money' => __('accounts::wallet.send_money'),
                             'cash_out' => __('accounts::wallet.cash_out'),
                             'payment' => __('accounts::wallet.payment'),
                             'agent_deposit' => __('accounts::wallet.agent_deposit'),
                         ]" />

            {{-- ⛔ লেখাটা দিক ধরে বদলায়; কলামটা একটাই। --}}
            <x-ui.field name="counterparty_phone" inputmode="tel"
                        :label="$inward ? __('accounts::field.sender_phone') : __('accounts::field.receiver_phone')" />

            <div class="sm:col-span-2">
                <x-ui.field name="instrument_no" :label="__('accounts::field.transaction_id')" />
            </div>

            <x-ui.field name="charge_amount" type="number" step="0.01" numeric
                        :label="__('accounts::field.charge')" x-model.number="charge" />
        </div>

        <x-ui.charge-bearer :direction="$direction" />
    </div>

    {{-- ── ব্যাংক ট্রান্সফার ─────────────────────────────────────── --}}
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3"
         x-show="method === 'online'" x-cloak>
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.select name="transfer_mode_id" :label="__('accounts::field.transfer_mode')"
                         :options="$modes" blank="—" />

            <x-ui.field name="from_bank"
                        :label="$inward ? __('accounts::field.from_bank') : __('accounts::field.our_bank')" />
            <x-ui.field name="from_branch" :label="__('accounts::field.branch')" />

            {{-- ⚠️ নম্বর দেখে বলা যায় না টাকাটা ঠিক হিসাব থেকে এসেছে
                 কি না — নাম দেখে যায়। --}}
            <x-ui.field name="from_account_name" :label="__('accounts::field.account_holder')" />
            <x-ui.field name="from_account_no" :label="__('accounts::field.account_no')" />
            <x-ui.field name="instrument_no" :label="__('accounts::field.transaction_id')" />

            <x-ui.field name="deposit_slip_no" :label="__('accounts::field.deposit_slip')" />
            <x-ui.field name="charge_amount" type="number" step="0.01" numeric
                        :label="__('accounts::field.bank_charge')" x-model.number="charge" />

            {{-- ⛔ খাতটা দিক ধরে আলাদা: আসছে হলে 1103 (সম্পদ), যাচ্ছে
                 হলে 2114 (দায়)। এক খাতে বসালে ব্যালান্স শিট ভুল পাশে
                 দেখাত, আর যোগফল মিলে যেত। --}}
            <x-ui.field name="lands_on" type="date" :label="__('accounts::field.lands_on')" />
        </div>

        <x-ui.charge-bearer :direction="$direction" />
    </div>

    {{-- ── চেক ──────────────────────────────────────────────────── --}}
    <div class="rounded-(--radius-field) border border-(--color-border)
                border-l-2 border-l-(--color-brand-500) bg-(--color-surface-sunken) p-3"
         x-show="method === 'cheque'" x-cloak>
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.field name="instrument_no" :label="__('accounts::field.cheque_no')" />
            <x-ui.field name="instrument_date" type="date" :label="__('accounts::field.cheque_date')"
                        x-model="chequeDate" />
            <x-ui.field name="from_bank" :label="__('accounts::field.bank_name')" />
            <x-ui.field name="from_branch" :label="__('accounts::field.branch')" />
            <x-ui.field name="from_account_name" :label="__('accounts::field.account_holder')" />
            <x-ui.field name="from_account_no" :label="__('accounts::field.account_no')" />
        </div>

        {{-- ⚠️ আগাম তারিখের চেক — টাকা আজ খাতায় বসে না। ⓘ এটা থামায় না,
             কেবল বলে, কারণ আগাম চেক নেওয়া বৈধ আর রোজকার। --}}
        <p class="mt-2 rounded bg-(--color-badge-warning-bg) px-2 py-1 text-xs
                  text-(--color-badge-warning-ink)"
           x-show="postDated" x-cloak>
            {{ $inward ? __('accounts::message.pdc_received') : __('accounts::message.pdc_issued') }}
        </p>
    </div>
</div>
