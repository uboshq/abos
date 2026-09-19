@props([
    /* টাকা আসছে না যাচ্ছে — কেবল শিরোনামটা বদলায় */
    'direction' => 'in',

    /* কে কে টাকা বয়ে নিতে পারেন */
    'carriers' => [],
])

{{--
    ভাউচারের ঘর — টাকাটা কীভাবে হাতবদল হলো।

    ── ⭐ নমুনার কাঠামো, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────
    মালিক লোকালে পর্দা খুলে দেখিয়েছেন এই পুরো বাক্সটাই অনুপস্থিত ছিল।
    ⓘ পাঁচটা খাতার পাঁচটাতেই একই বাক্স, তাই একটাই ফাইল।

    ── ⛔ কেন ঘরগুলো এখানে, অথচ খাতার সারিতে নয় ────────────────────────
    ⚠️ **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে।** এই বাক্সের একটা ঘরও
    খাতার সারিতে বসে না — সবগুলো ভাউচারে যায়।

    ── ⚠️ কেন চারটা মাধ্যম, আর কেন আলাদা ঘর ────────────────────────────
    ⛔ চারটার আচরণ আলাদা, কেবল নাম নয়:
      · নগদ       — তখনই হাতবদল, কোনো নম্বর নেই
      · ট্রান্সফার — BEFTN পরদিন, RTGS একই দিনে (তারিখটাই বদলে যায়)
      · চেক       — আগাম তারিখের চেকে ঐ দিন আসার আগে টাকা নড়ে না
      · MFS       — চার্জ কাটে, আর ওটা খরচের খাতে বসে
    ⓘ এক ঘরে সব চাইলে ঐ পার্থক্যগুলো হারাত, আর খতিয়ান ভুল দিনে বসত।
--}}
<div x-data="{ pay: 'cash' }"
     {{-- ⓘ `border-l-2 border-l-(--color-brand-500)` — প্রকল্পের নিজের
          ছাঁচ, পাঁচ জায়গায় ব্যবহৃত। ⛔ আগে `border-s-4` লিখেছিলাম, আর
          ঐ ইউটিলিটিটা কম্পাইল করা CSS-এ **ছিল না** — দাগটা নীরবে
          অদৃশ্য থাকত, কোনো ভুলবার্তা ছাড়াই। --}}
     class="my-3 rounded-(--radius-card) border border-(--color-border)
            border-l-2 border-l-(--color-brand-500) bg-(--color-surface-card) p-4">

    <h3 class="mb-3 font-semibold text-(--color-brand-700)">
        {{ $direction === 'out'
            ? __('finance::field.voucher_box_out')
            : __('finance::field.voucher_box_in') }}
    </h3>

    <p class="mb-1 text-sm font-medium">{{ __('finance::field.pay_method') }}</p>

    <div class="mb-3 flex flex-wrap gap-2" role="group">
        @foreach (['cash' => 'pay_cash', 'transfer' => 'pay_transfer', 'cheque' => 'pay_cheque', 'mfs' => 'pay_mfs'] as $key => $word)
            {{-- ⓘ `aria-pressed` — পর্দা-পাঠকের কাছে এটা টগল, লিংক নয় --}}
            <button type="button"
                    x-on:click="pay = @js($key)"
                    x-bind:aria-pressed="pay === @js($key)"
                    x-bind:class="pay === @js($key)
                        ? 'border-(--color-brand-500) bg-(--color-brand-50) font-medium'
                        : 'border-(--color-border)'"
                    class="flex items-center gap-1.5 rounded-(--radius-field) border px-3 py-1.5 text-sm">
                <span aria-hidden="true"
                      x-bind:class="pay === @js($key) ? 'bg-(--color-brand-500)' : 'bg-(--color-ink-muted)'"
                      class="block size-1.5 rounded-full"></span>
                {{ __('finance::field.'.$word) }}
            </button>
        @endforeach
    </div>

    <input type="hidden" name="pay_method" x-bind:value="pay">

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {{-- ⚠️ কার মাধ্যমে — নমুনার শেষ বিকল্পটা "কেউ যায়নি"।
             ⓘ টাকা ব্যাংকে সরাসরি এলে কোনো মানুষ জড়িত থাকে না, আর
             তখন একটা নাম বসিয়ে রাখলে সেটা মিথ্যা হত। --}}
        <div class="sm:col-span-2">
            <x-ui.select name="carried_by" :label="__('finance::field.carried_by')"
                         :options="collect($carriers)->put('direct', __('finance::field.carried_direct'))"
                         :selected="old('carried_by')" />
        </div>

        <x-ui.field name="happened_at" type="time"
                    :label="__('finance::field.happened_at')"
                    :value="old('happened_at')" />
    </div>

    {{-- ── নগদ: নোটের হিসাব ───────────────────────────────────────────

         ⭐ নমুনার বাক্স, ১৮ সেপ্টেম্বর ২০২৬।

         ── ⛔ কেন নোট গোনা হয়, কেবল অঙ্ক নয় ───────────────────────────
         ⚠️ নগদে সবচেয়ে সাধারণ ভুল হলো **কাগজে এক অঙ্ক, হাতে আরেক**।
         ⓘ গোনা মোট আর লেখা অঙ্ক মিলিয়ে দেখালে পার্থক্যটা ঐ মুহূর্তেই
         ধরা পড়ে — সিন্দুক মেলানোর দিন নয়, যখন কেউ আর মনে করতে পারে না।

         ⛔ সংখ্যাগুলো সংরক্ষণ হয় না — ওগুলো ভাউচারের ঘর, খাতার নয়।
         ⓘ এখানে ওদের একমাত্র কাজ গুনে দেখানো।

         ── ⚠️ দশটা নোট, আর ২ টাকাও আছে ────────────────────────────────
         ⓘ ১০০০ · ৫০০ · ২০০ · ১০০ · ৫০ · ২০ · ১০ · ৫ · ২ · ১ — বাংলাদেশে
         চালু সবগুলো। ⛔ ছোটগুলো বাদ দিলে খুচরার হিসাব মিলত না, আর
         দোকানের নগদে খুচরাই সবচেয়ে বেশি। --}}
    <template x-if="pay === 'cash'">
        <fieldset x-data="noteTally({
                      matches: @js(__('finance::message.cash_matches')),
                      differs: @js(__('finance::message.cash_differs', ['counted' => '__C__', 'written' => '__W__', 'gap' => '__G__'])),
                  })"
                  x-on:input.window="readWritten()"
                  class="mt-3 rounded-(--radius-card) border border-(--color-border) p-3">

            <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                {{ __('finance::field.cash_title') }}
            </legend>

            <div class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                @foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $note)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="w-12 text-end tabular-nums">{{ number_format($note) }}</span>
                        <span aria-hidden="true" class="text-(--color-ink-muted)">×</span>

                        {{-- ⓘ `aria-label` লাগে, কারণ চোখে নোটের অঙ্কটাই
                             লেবেল — পর্দা-পাঠকের কাছে ওটা আলাদা ঘর নয়। --}}
                        <input type="number" min="0" step="1" inputmode="numeric"
                               x-model.number="n[{{ $note }}]"
                               aria-label="{{ __('finance::field.notes_of', ['note' => number_format($note)]) }}"
                               class="h-(--spacing-field-compact) w-20 rounded-(--radius-field)
                                      border border-(--color-border) px-2 text-end tabular-nums">

                        <span class="flex-1 text-end tabular-nums text-(--color-ink-muted)"
                              x-text="n[{{ $note }}] ? money({{ $note }} * n[{{ $note }}]) : '—'"></span>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 flex items-baseline justify-between gap-2 border-t border-(--color-border) pt-2">
                <span class="text-sm font-medium">{{ __('finance::field.counted_total') }}</span>
                <span class="text-lg font-semibold tabular-nums" x-text="'৳ ' + money(counted)"></span>
            </p>

            {{-- ⚠️ মিলছে কি না — আর না মিললে কথাটা স্পষ্ট করে বলা।
                 ⓘ রঙ একা যথেষ্ট নয়; লেখাটাই আসল বার্তা। --}}
            <p class="mt-2 rounded-(--radius-field) px-3 py-2 text-2xs"
               x-bind:class="counted === written
                   ? 'bg-badge-success-bg text-badge-success-ink'
                   : 'bg-badge-warning-bg text-badge-warning-ink'"
               x-text="verdict"></p>
        </fieldset>
    </template>

    <template x-if="pay === 'transfer'">
        <fieldset class="mt-3 rounded-(--radius-card) border border-(--color-border) p-3">
            <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                {{ __('finance::field.transfer_title') }}
            </legend>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.select name="rail" :label="__('finance::field.rail')"
                             :options="collect(['beftn', 'rtgs', 'npsb', 'ibanking', 'counter'])
                                 ->mapWithKeys(fn ($r) => [$r => __('finance::field.rail_'.$r)])"
                             :selected="old('rail', 'beftn')" />

                <x-ui.field name="from_bank" :label="__('finance::field.from_bank')" :value="old('from_bank')" />
                <x-ui.field name="bank_branch" :label="__('finance::field.bank_branch')" :value="old('bank_branch')" />
                <x-ui.field name="account_name" :label="__('finance::field.account_name')" :value="old('account_name')" />
                <x-ui.field name="account_no" :label="__('finance::field.account_no')" :value="old('account_no')" />
                <x-ui.field name="txn_ref" :label="__('finance::field.txn_ref')" :value="old('txn_ref')" />
                <x-ui.field name="slip_no" :label="__('finance::field.slip_no')" :value="old('slip_no')" />

                <x-ui.field name="bank_charge" type="number" step="0.01" numeric
                            :label="__('finance::field.bank_charge')" :value="old('bank_charge')" />

                {{-- ⛔ এই ঘরটাই ট্রান্সফারের আসল কথা: BEFTN পরদিন ক্রেডিট
                     হয়, তাই টাকাটা আজ খাতায় বসে না। ⚠️ ঘরটা না থাকলে
                     আজকের তারিখে বসে যেত, আর ব্যাংক স্টেটমেন্টের সাথে
                     এক দিনের ফারাক থাকত। --}}
                <x-ui.select name="arrives" :label="__('finance::field.arrives')"
                             :options="['today' => __('finance::field.arrives_today'), 'next' => __('finance::field.arrives_next')]"
                             :selected="old('arrives', 'today')" />
            </div>
        </fieldset>
    </template>

    <template x-if="pay === 'cheque'">
        <fieldset class="mt-3 rounded-(--radius-card) border border-(--color-border) p-3">
            <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                {{ __('finance::field.cheque_title') }}
            </legend>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.field name="cheque_no" :label="__('finance::field.cheque_no')" :value="old('cheque_no')" />

                {{-- ⛔ আগাম তারিখের চেক — ঐ তারিখের আগে খাতায় টাকা নড়ে না।
                     ⚠️ চেক হাতে আসা আর টাকা পাওয়া এক নয়, আর এই একটা
                     ভুলেই ক্যাশ বই মাস শেষে মেলে না। --}}
                <x-ui.field name="cheque_date" type="date"
                            :label="__('finance::field.cheque_date')" :value="old('cheque_date')" />

                <x-ui.field name="cheque_bank" :label="__('finance::field.cheque_bank')" :value="old('cheque_bank')" />
                <x-ui.field name="cheque_branch" :label="__('finance::field.bank_branch')" :value="old('cheque_branch')" />
                <x-ui.field name="cheque_holder" :label="__('finance::field.cheque_holder')" :value="old('cheque_holder')" />
                <x-ui.field name="cheque_account_no" :label="__('finance::field.account_no')" :value="old('cheque_account_no')" />
            </div>
        </fieldset>
    </template>

    <template x-if="pay === 'mfs'">
        <fieldset class="mt-3 rounded-(--radius-card) border border-(--color-border) p-3">
            <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                {{ __('finance::field.mfs_title') }}
            </legend>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.select name="wallet" :label="__('finance::field.wallet')"
                             :options="collect(['bkash', 'nagad', 'rocket', 'upay'])
                                 ->mapWithKeys(fn ($w) => [$w => __('finance::field.wallet_'.$w)])"
                             :selected="old('wallet')" />

                <x-ui.select name="mfs_kind" :label="__('finance::field.mfs_kind')"
                             :options="collect(['send', 'cashout', 'payment', 'agent'])
                                 ->mapWithKeys(fn ($k) => [$k => __('finance::field.mfs_'.$k)])"
                             :selected="old('mfs_kind')" />

                <x-ui.field name="from_number" :label="__('finance::field.from_number')" :value="old('from_number')" />
                <x-ui.field name="txn_id" :label="__('finance::field.txn_id')" :value="old('txn_id')" />

                {{-- ⓘ MFS-এর চার্জ খরচের খাতে বসে, মূল অঙ্কের সাথে নয় —
                     নাহলে মূলধনের অঙ্কটাই ভুল হত। --}}
                <x-ui.field name="mfs_charge" type="number" step="0.01" numeric
                            :label="__('finance::field.mfs_charge')" :value="old('mfs_charge')" />
            </div>
        </fieldset>
    </template>
</div>
