{{--
    সরাসরি বিক্রয়ের ছয়টা কাজের প্যানেল।

    ── কেন একবারে একটাই ────────────────────────────────────────────────
    ডান পাশের কলামটা সরু আর লম্বা: উপরে গ্রাহক, তারপর টাকার হিসাব, নিচে
    বোতাম। দুইটা প্যানেল একসাথে খুললে "দিতে হবে" সংখ্যাটা পর্দা থেকে
    নেমে যেত — অথচ কাউন্টারে ওটাই সবচেয়ে বেশি পড়া হয়।

    ── কেন প্রতিটার নিজের ঘর, একটা সাধারণ "বিবরণ" নয় ───────────────────
    এক ঘরে সব লিখলে এক মাস পরে কিছুই বের করা যায় না। ভাড়া কত গেল,
    গাড়ি কোনটা ছিল, টাকাটা চেকে না বিকাশে — প্রতিটা আলাদা প্রশ্ন, আর
    আলাদা ঘরে বসলেই কেবল রিপোর্টে যোগ হয়।
--}}
<div x-show="panel" x-cloak
     class="border-t border-(--color-border) bg-(--color-surface-sunken) p-3">

    {{-- ── খরচ ────────────────────────────────────────────────────────
         অঙ্কটা আগে থেকেই ছিল, কারণটা ছিল না। "খরচ ২০০" এক মাস পরে কারও
         কাজে আসে না — ওটা ভাড়া ছিল না হাম্মালি, জানার একমাত্র সময় এখনই। --}}
    @if ($show['expense'])
    <div x-show="panel === 'expense'" x-cloak class="space-y-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.expense') }}</span>
            <input type="number" step="0.01" min="0" name="expense_amount" x-model="expenseAmount"
                   class="num h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.expense_for') }}</span>
            <input type="text" name="expense_narration" maxlength="191"
                   placeholder="{{ __('sales::field.expense_for_hint') }}"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>
    </div>

    @endif

    {{-- ── পরিবহন ─────────────────────────────────────────────────────
         গাড়ি ও চালক আগে থেকেই ছিল, কিন্তু উপরের ঘরে লুকানো। ভাড়াটা
         ছিলই না — আর ওটা ছাড়া "এই রুটে কত খরচ হলো" প্রশ্নের উত্তর নেই। --}}
    @if ($show['transport'])
    <div x-show="panel === 'transport'" x-cloak class="grid grid-cols-2 gap-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.carrier') }}</span>
            <input type="text" name="carrier_name" maxlength="191"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.transport_cost') }}</span>
            <input type="number" step="0.01" min="0" name="transport_cost"
                   class="num h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.vehicle_no') }}</span>
            <input type="text" name="vehicle_no" maxlength="64"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.driver_name') }}</span>
            <input type="text" name="driver_name" maxlength="191"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>
    </div>

    @endif

    {{-- ── চালান কোথায় যাচ্ছে ─────────────────────────────────────────
         গ্রাহকের ঠিকানা মাস্টারে আছে, কিন্তু মাল সবসময় সেখানে যায় না:
         দোকান এক জায়গায়, গুদাম আরেক জায়গায়, মাঝে মাঝে সরাসরি বাজারে।
         কাগজে ভুল ঠিকানা মানে গাড়ি ভুল জায়গায়। --}}
    @if ($show['shipment'])
    <div x-show="panel === 'shipment'" x-cloak class="space-y-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ship_to') }}</span>
            <input type="text" name="ship_to" maxlength="191"
                   placeholder="{{ __('sales::field.ship_to_hint') }}"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ship_date') }}</span>
            <input type="date" name="ship_date"
                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>
    </div>

    @endif

    {{-- ── জমা ────────────────────────────────────────────────────────
         অঙ্কটা ছিল, বিবরণ ছিল না। নগদ ছাড়া অন্য কিছুতে নম্বর না থাকলে
         টাকাটা আর খুঁজে পাওয়া যায় না, আর ব্যাংকের কাগজের সাথে মেলানোও
         যায় না। --}}
    @if ($show['deposit'])
    <div x-show="panel === 'deposit'" x-cloak class="space-y-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.received_deposit') }}</span>
            <input type="number" step="0.01" min="0" name="deposit" x-model="deposit"
                   class="num h-8 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <div class="grid grid-cols-2 gap-2">
            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.deposit_method') }}</span>
                <select name="deposit_method" x-model="depositMethod"
                        class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-2xs">
                    <option value="cash">{{ __('sales::field.cash') }}</option>
                    <option value="cheque">{{ __('sales::field.cheque') }}</option>
                    <option value="mfs">{{ __('sales::field.mfs') }}</option>
                    <option value="bank">{{ __('sales::field.bank') }}</option>
                </select>
            </label>

            {{-- নগদে নম্বর লাগে না, বাকি সবটায় লাগে। ঘরটা তখনই খোলে,
                 নাহলে প্রতিটা নগদ বিক্রিতে একটা খালি ঘর পার হতে হত। --}}
            <label class="block" x-show="depositMethod !== 'cash'" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.deposit_ref') }}</span>
                <input type="text" name="deposit_ref" maxlength="64"
                       class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>
        </div>
    </div>

    @endif

    {{-- ── মন্তব্য ─────────────────────────────────────────────────────
         কাগজে ছাপা হয়, তাই যা লেখা হয় তা গ্রাহকও পড়েন। --}}
    <div x-show="panel === 'note'" x-cloak>
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.note') }}</span>
            <textarea name="narration" rows="3" maxlength="500"
                      class="w-full rounded-(--radius-field) border border-(--color-border)
                             bg-(--color-surface-card) px-2 py-1 text-2xs"></textarea>
        </label>
    </div>
</div>
