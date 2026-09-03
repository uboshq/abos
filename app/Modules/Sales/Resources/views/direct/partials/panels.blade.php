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
        {{--
            ── বাহক — তালিকা থেকে, নয়তো হাতে লেখা ──────────────────────

            ⚠️ এতদিন এখানে কেবল **নাম লেখার একটা ঘর** ছিল, তাই চালানের
            `carrier_id` কখনো বসতই না — আর ভাড়ার দাখিলাটা কোনো পক্ষ পেত না।

            ⭐ মালিকের কথা (৪ সেপ্টেম্বর ২০২৬): *"transporter-এর সাথে হিসাব
            হবে"* — অর্থাৎ ভাড়াটা তার খাতায় **পাওনা** হয়ে জমে, মাস শেষে
            মেটে। নাম লেখা থাকলে সেই খতিয়ানটাই দাঁড়ায় না।

            ── কেন লেখার ঘরটা তবু রইল ─────────────────────────────────
            ⓘ বহরের বাইরের **একবারের গাড়ির** কোনো চলতি হিসাব থাকে না —
            টাকা ওই দিনই মেটে। তখন নামটাই যথেষ্ট, আর ভাড়াটা সাধারণ
            প্রদেয়তে যায়। ⭐ একই গাড়ি তিনবার এলে ব্যবহারকারী তাকে পক্ষ
            বানিয়ে নেবেন — **সিদ্ধান্তটা তাঁর, কোডের নয়।**

            ⓘ পণ্যের ব্র্যান্ডেও হুবহু এই জোড়াটাই আছে: বাছাই + মুক্ত লেখা।
        --}}
        <label class="block" x-show="carriers.length > 0" x-cloak>
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.carrier') }}</span>
            <select name="carrier_id" x-model="carrierId"
                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-card) px-2 text-2xs">
                <option value="" disabled hidden>{{ __('sales::field.choose') }}</option>
                <option value="">{{ __('sales::field.carrier_not_listed') }}</option>
                <template x-for="c in carriers" :key="c.id">
                    <option :value="c.id" x-text="c.label"></option>
                </template>
            </select>
        </label>

        {{-- ⓘ তালিকা খালি থাকলে (কেউ এখনো পরিবহনকারী বানাননি) ঘরটা
             সবসময় দেখা যায়, নাহলে কেবল "তালিকায় নেই" বাছলে। --}}
        <label class="block" x-show="carriers.length === 0 || carrierId === ''" x-cloak>
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.carrier_name') }}</span>
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
    {{--
        ── জমা — একটা সারি বানাও, তালিকায় যোগ করো ──────────────────────

        মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"Add deposit-এ Ref Date,
        Payment Method, into, Amount, Narration/Remarks, Add to Cart …
        Payment Method Cash, MFS, Bank … এই list Item Chart-এর নিচে
        বাম পাশে থাকবে, একাধিক payment add করতে পারবে"*।

        ⭐ ইঞ্জিনটা নতুন নয় — POS-এ একাধিক পেমেন্ট আগে থেকেই চলছে
        (`payments[][...]`)। এখানে ঘরগুলো একটু আলাদা, কারণ কাউন্টারের
        জমায় **তারিখ ও বিবরণ** লাগে আর **ফেরত** লাগে না।

        ⚠️ **আর যেটা এতদিন ভুল হচ্ছিল:** উপায় লেখা হত ঠিকই, কিন্তু
        **টাকাটা সবসময় নগদ ড্রয়ারে বসত** — খাতের কোনো ঘরই ছিল না।
        গ্রাহক বিকাশে দিলেও খাতা বলত নগদ, আর মাস শেষে বিকাশের ব্যালেন্স
        মিলত না। এখন প্রতিটা জমা **নিজের খাতে** যায়।
    --}}
    {{-- ⚠️ এক সারিতে — মালিকের প্রশ্ন (৩ সেপ্টেম্বর ২০২৬):
         *"egulo ek line dile ki somossaw?"* — উত্তর: কোনো সমস্যা নেই,
         আর আলাদা সারিতে রাখাটাই ভুল ছিল।

         ── কেন ─────────────────────────────────────────────────────────
         প্যানেলটা কার্টের নিচে, **পুরো প্রস্থে** — ১২৪৫px। ছয়টা ছোট ঘর
         ওখানে অনায়াসে ধরে। প্রতিটাকে নিজের সারি দিলে প্যানেলটা ছয় গুণ
         উঁচু হত, আর ⚠️ তখন ডান পাশের "দিতে হবে" সংখ্যাটা পর্দা থেকে
         নেমে যেত — যেটা জমা লেখার সময়েই সবচেয়ে বেশি দেখা হয়।

         ⓘ সরু পর্দায় নিজে থেকেই ভাগ হয় (২ → ৩ → ৬), তাই ফোনে কিছু
         চেপে যায় না। --}}
    <div x-show="panel === 'deposit'" x-cloak
         class="grid items-end gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ref_date') }}</span>
            <x-ui.date name="deposit_ref_date" class="text-2xs" />
        </label>

        {{-- ⓘ উপায়ের তালিকাটা সেটিংসের সারি, আর নতুন কোম্পানিতে ওটা
             খালি থাকতে পারে। খালি হলে ঘরটাই দেখানো হয় না — একটা
             বিকল্পহীন ড্রপডাউন কেবল বিভ্রান্তি। জমা তখনও নেওয়া যায়,
             কারণ **আসল শর্ত খাত**, উপায় নয়। --}}
        <label class="block" x-show="depositMethods.length > 0" x-cloak>
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.deposit_method') }}</span>
            <select x-model="depositDraft.methodId" @change="pickDepositMethod()"
                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-card) px-2 text-2xs">
                {{-- ⚠️ `disabled hidden` — মালিকের প্রশ্ন (৪ সেপ্টেম্বর ২০২৬):
                     *"Choose dropdown-এ এটা কেন থাকবে?"*

                     ⓘ ঘরটা বন্ধ থাকলে "বেছে নিন" লেখাই দেখা যায়, কিন্তু
                     তালিকা খুললে ওটা **বিকল্প হিসেবে আসে না** — কারণ ওটা
                     কোনো উত্তর নয়, প্রশ্নটাই। খোলা তালিকায় ওটা রাখলে
                     ব্যবহারকারী "বেছে নিন" বেছে নিতে পারতেন, আর ঘরটা
                     আবার খালি হয়ে যেত। --}}
                <option value="" disabled hidden>{{ __('sales::field.choose') }}</option>
                <template x-for="m in depositMethods" :key="m.id">
                    <option :value="m.id" x-text="m.label"></option>
                </template>
            </select>
        </label>

        {{-- ⓘ খাতটা উপায় বাছলেই বসে যায়, কিন্তু তালাবদ্ধ নয় — এক
             "ব্যাংক" উপায়ে তিনটা ব্যাংক হিসাব থাকতে পারে। --}}
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.account') }}</span>
            <select x-model="depositDraft.accountId"
                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-card) px-2 text-2xs">
                {{-- ⓘ একই কারণে এখানেও — উপরের মন্তব্য দেখুন। --}}
                <option value="" disabled hidden>{{ __('sales::field.choose') }}</option>
                <template x-for="a in depositAccounts" :key="a.id">
                    <option :value="a.id" x-text="a.label"></option>
                </template>
            </select>
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.amount') }}</span>
            <input type="number" step="0.01" min="0" x-model="depositDraft.amount"
                   @keydown.enter.prevent="addDeposit()"
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        {{-- ⚠️ নম্বরের ঘরটা কেবল যে উপায়ে দরকার, আর তখন **বাধ্যতামূলক**:
             চেক বা বিকাশের টাকা নম্বর ছাড়া ব্যাংকের কাগজের সাথে মেলানো
             যায় না, আর ওই মেলানোটাই মাস শেষের কাজ। --}}
        <label class="block" x-show="depositNeedsReference" x-cloak>
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.deposit_ref') }}</span>
            <input type="text" maxlength="64" x-model="depositDraft.reference"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            {{-- ⓘ "বিবরণ" — এটা আদায়ের ভাউচারের নিজের বিবরণ, পাশের নোট নয়।
                 উপহারের লাইনে "মন্তব্য"-ই থাকল, কারণ ওটা কোনো দাখিলায় যায় না। --}}
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.narration') }}</span>
            <input type="text" maxlength="191" x-model="depositDraft.narration"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        {{-- ⓘ বোতামটাও সারির শেষ ঘরে — `items-end` থাকায় ঘরগুলোর নিচের
             কিনারার সাথে মিলে বসে, লেবেলের উচ্চতা যা-ই হোক। --}}
        <x-ui.button type="button" tone="primary"
                     class="h-(--spacing-field-compact) w-full justify-center text-2xs"
                     @click="addDeposit()" ::disabled="! depositReady">
            {{ __('sales::action.add_to_cart') }}
        </x-ui.button>
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
