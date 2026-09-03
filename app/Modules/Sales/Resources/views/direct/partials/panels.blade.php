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

    {{-- ⚠️ "খরচ"-এর প্যানেলটা এখান থেকে পুরো তুলে দেওয়া হয়েছে
         (৩ সেপ্টেম্বর ২০২৬, মালিকের সিদ্ধান্ত)।

         খরচের দুইটা ঘরই — টাকা আর "কীসের" — এখন ডান পাশের "এই চালান"
         প্যানেলে। ⚠️ **এখানে আবার বসাবেন না**: একই `name` দুইবার থাকলে
         ব্রাউজার দুইটা মান পাঠায়, আর সার্ভারে শেষেরটা জেতে — নীরবে,
         কোনো ত্রুটি ছাড়াই।

         ⓘ "খরচ" বোতামটাও সরাসরি বিক্রয়ের পর্দা থেকে গেছে, একই কারণে। --}}
<div x-show="panel" x-cloak
     class="rounded-(--radius-card) border border-(--color-border)
            bg-(--color-surface-sunken) p-3">

    {{-- ── পরিবহন ─────────────────────────────────────────────────────
         গাড়ি ও চালক আগে থেকেই ছিল, কিন্তু উপরের ঘরে লুকানো। ভাড়াটা
         ছিলই না — আর ওটা ছাড়া "এই রুটে কত খরচ হলো" প্রশ্নের উত্তর নেই। --}}
    @if ($show['transport'])
    <div x-show="panel === 'transport'" x-cloak class="grid grid-cols-2 gap-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.carrier') }}</span>
            <input type="text" name="carrier_name" maxlength="191"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.transport_cost') }}</span>
            <input type="number" step="0.01" min="0" name="transport_cost"
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.vehicle_no') }}</span>
            <input type="text" name="vehicle_no" maxlength="64"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.driver_name') }}</span>
            <input type="text" name="driver_name" maxlength="191"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
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
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ship_date') }}</span>
            {{-- ব্রাউজারের নিজের তারিখের ঘর নয়: ওটা নিজের লোকেল ধরে
                 আঁকে, আর en-US-এ ০৫/০৬ মানে ৬ মে, বাংলাদেশে ৫ জুন —
                 দুইটাই বৈধ, তাই ভুলটা খাতা থেকে ধরা যায় না। --}}
            <x-ui.date name="ship_date" />
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
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <div class="grid grid-cols-2 gap-2">
            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.deposit_method') }}</span>
                <select name="deposit_method" x-model="depositMethod"
                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
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
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
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
