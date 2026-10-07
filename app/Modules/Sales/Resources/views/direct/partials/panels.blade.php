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
{{-- ⭐ প্রতিটা বোতাম চাপলেই পপ-আপ — মালিকের নিয়ম, ৪ অক্টোবর ২০২৬ (নমুনা ৩)। ⓘ ঘরগুলো ফর্মের ভিতরেই থাকে,
     তাই বন্ধ করলেও মান যায়; কেবল দেখা-না-দেখা বদলায়। বাইরে চাপলে বা Esc-এ বন্ধ। --}}
<div x-show="panel" x-cloak @click.self="closePanel()" data-counter-popup
     class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
<div class="ds-gold ds-dialog rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 shadow-lg">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-base font-bold">
            <span x-show="panel === 'deposit'" x-cloak>{{ __('sales::field.btn_money') }}</span>
            <span x-show="panel === 'transport'" x-cloak>{{ __('sales::field.btn_transport') }}</span>
            <span x-show="panel === 'price'" x-cloak>{{ __('sales::field.btn_price') }}</span>
            <span x-show="panel === 'return'" x-cloak>{{ __('sales::field.btn_return') }}</span>
            <span x-show="panel === 'delivery'" x-cloak>{{ __('sales::field.btn_delivery') }}</span>
            <span x-show="panel === 'drafts'" x-cloak>{{ __('sales::field.btn_drafts') }}</span>
            <span x-show="panel === 'reprint'" x-cloak>{{ __('sales::field.btn_reprint') }}</span>
            <span x-show="panel === 'cancel'" x-cloak>{{ __('sales::field.btn_cancel') }}</span>
            <span x-show="panel === 'note'" x-cloak>{{ __('sales::field.note') }}</span>
        </h2>
        <div class="flex items-center gap-2">
            <kbd x-show="panel === 'deposit'" x-cloak class="ds-kbd">F2</kbd>
            <kbd x-show="panel === 'transport'" x-cloak class="ds-kbd">F4</kbd>
            <kbd x-show="panel === 'price'" x-cloak class="ds-kbd">F3</kbd>
            <kbd x-show="panel === 'return'" x-cloak class="ds-kbd">F9</kbd>
            <kbd x-show="panel === 'delivery'" x-cloak class="ds-kbd">F7</kbd>
            <kbd x-show="panel === 'drafts'" x-cloak class="ds-kbd">F6</kbd>
            <kbd x-show="panel === 'reprint'" x-cloak class="ds-kbd">F8</kbd>
            <kbd x-show="panel === 'cancel'" x-cloak class="ds-kbd">Ctrl+X</kbd>
            <button type="button" @click="closePanel()" class="px-2 text-lg leading-none text-(--color-ink-muted)"
                    aria-label="{{ __('sales::field.btn_close') }}">&times;</button>
        </div>
    </div>

    {{-- ── পরিবহন ─────────────────────────────────────────────────────
         গাড়ি ও চালক আগে থেকেই ছিল, কিন্তু উপরের ঘরে লুকানো। ভাড়াটা
         ছিলই না — আর ওটা ছাড়া "এই রুটে কত খরচ হলো" প্রশ্নের উত্তর নেই। --}}
    @if ($show['transport'])
    {{-- ⭐ দুই সারি — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত): *"বাহকের নাম Dropdown
         … tar pase mob. no, tar por ভাড়া; 2nd line গাড়ি নম্বর hate likbe, চালকের
         নাম Dropdown-eo asbe hateo likte parbe, tarpase Mobile no"*। --}}
    <div x-show="panel === 'transport'" x-cloak class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        {{-- ⭐ গাড়ি কার — মালিকের নমুনা ৩ (৪ অক্টোবর ২০২৬); সার্ভারে `vehicle_owner` (af) --}}
        <div class="sm:col-span-3">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.vehicle_owner') }}</span>
            <div class="ds-seg" role="radiogroup">
                <button type="button" @click="vehicleOwner = 'own'" :class="vehicleOwner === 'own' ? 'is-on' : ''">{{ __('sales::field.owner_own') }}</button>
                <button type="button" @click="vehicleOwner = 'hired'" :class="vehicleOwner === 'hired' ? 'is-on' : ''">{{ __('sales::field.owner_hired') }}</button>
                <button type="button" @click="vehicleOwner = 'customer'" :class="vehicleOwner === 'customer' ? 'is-on' : ''">{{ __('sales::field.owner_customer') }}</button>
                <button type="button" @click="vehicleOwner = 'none'" :class="vehicleOwner === 'none' ? 'is-on' : ''">{{ __('sales::field.owner_none') }}</button>
            </div>
            <input type="hidden" name="vehicle_owner" value="{{ old('vehicle_owner', $resume['fields']['vehicle_owner'] ?? '') }}"
                   x-init="seedDriver($el, 'vehicleOwner')" :value="vehicleOwner">
        </div>
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
        <div class="space-y-1">
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
                <input type="text" name="carrier_name" maxlength="191" value="{{ old('carrier_name', $resume['fields']['carrier_name'] ?? '') }}"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>
        </div>

        {{-- ⓘ বাহকের নম্বর পক্ষের খাতা থেকে — দেখানোর জন্য; ⚠️ `name` নেই, কারণ
             নম্বরটা পক্ষের সারিতেই থাকে, চালানে দ্বিতীয় কপি রাখলে কোনটা সত্যি সেই প্রশ্ন উঠত। --}}
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.carrier_phone') }}</span>
            <input type="text" readonly tabindex="-1" :value="carrierPhone"
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-2xs text-(--color-ink-muted)">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.transport_cost') }}</span>
            <input type="number" step="0.01" min="0" name="transport_cost" value="{{ old('transport_cost', $resume['fields']['transport_cost'] ?? '') }}"
                   x-model="transportCost" x-init="seedDriver($el, 'transportCost')"
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-end text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.vehicle_no') }}</span>
            <input type="text" name="vehicle_no" maxlength="64" value="{{ old('vehicle_no', $resume['fields']['vehicle_no'] ?? '') }}"
                   x-model="vehicleNo" x-init="seedDriver($el, 'vehicleNo')"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        {{-- ⓘ চালক — আগের চালান থেকে পরামর্শ, আবার নতুন নাম হাতেও ([[pickDriver()]])। --}}
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.driver_name') }}</span>
            <input type="text" name="driver_name" maxlength="191" list="direct-sale-drivers" autocomplete="off"
                   value="{{ old('driver_name', $resume['fields']['driver_name'] ?? '') }}"
                   x-model="driverName" x-init="seedDriver($el, 'driverName')" @change="pickDriver()"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
            <datalist id="direct-sale-drivers">
                <template x-for="d in drivers" :key="d.name">
                    <option :value="d.name" x-text="d.phone"></option>
                </template>
            </datalist>
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.driver_phone') }}</span>
            <input type="text" inputmode="tel" name="driver_phone" maxlength="32"
                   value="{{ old('driver_phone', $resume['fields']['driver_phone'] ?? '') }}"
                   x-model="driverPhone" x-init="seedDriver($el, 'driverPhone')"
                   class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        {{-- ⭐ ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬: নিশ্চিত করতে বাহক বা গাড়ি, নয়তো এই টিক ([[TransportRule]])।
             ⓘ প্যানেল বন্ধ থাকলেও ঘরটা ফর্মের ভিতরে — `x-show` কেবল লুকায়, পাঠানো বন্ধ করে না। --}}
        {{-- ⭐ ভাড়া কে দেবে — চারটা পছন্দ (মালিক, ৪ অক্টোবর ২০২৬, 63-এর মাধ্যমে); সার্ভারে `fare_paid_by` (af)।
             ⓘ "বিলে যোগ" হলে ভাড়াটা বিলের মোটে ওঠে (`freightOnBill`), "ক্রেতা চালককে" হলে মোট বদলায় না। --}}
        <div class="sm:col-span-3">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.fare_paid_by') }}</span>
            <div class="ds-seg" role="radiogroup">
                <button type="button" @click="farePaidBy = 'us'" :class="farePaidBy === 'us' ? 'is-on' : ''">{{ __('sales::field.fare_us') }}</button>
                <button type="button" @click="farePaidBy = 'us_add_to_bill'" :class="farePaidBy === 'us_add_to_bill' ? 'is-on' : ''">{{ __('sales::field.fare_us_add_to_bill') }}</button>
                <button type="button" @click="farePaidBy = 'customer'" :class="farePaidBy === 'customer' ? 'is-on' : ''">{{ __('sales::field.fare_customer') }}</button>
                <button type="button" @click="farePaidBy = 'none'" :class="farePaidBy === 'none' ? 'is-on' : ''">{{ __('sales::field.fare_none') }}</button>
            </div>
            <input type="hidden" name="fare_paid_by" value="{{ old('fare_paid_by', $resume['fields']['fare_paid_by'] ?? '') }}"
                   x-init="seedDriver($el, 'farePaidBy')" :value="farePaidBy">
        </div>

        {{-- ⓘ পুরনো "পরিবহন লাগবে না" চেকবক্স উঠে গেছে — গাড়ি "ক্রেতার নিজের" বা "নেই" থেকেই বোঝা যায় (af) --}}
        <input type="hidden" name="own_transport" :value="ownTransport">

        {{-- ⭐ "কার্টে যোগ করুন" — মালিকের প্রশ্ন, ২৮ সেপ্টেম্বর ২০২৬: *"etar add botam koi? botam
             cara add hobe kemone"*। ⓘ ঘরগুলো বিলের সাথেই যায় (নাম আছে), বোতামটা প্যানেল বন্ধ
             করে আর ডানে একটা ছোট সারাংশ দেখায় — যাতে বোঝা যায় তথ্যটা নেওয়া হয়েছে। --}}
        <div class="sm:col-span-3 flex justify-end">
            <x-ui.button type="button" tone="primary" class="h-(--spacing-field-compact) px-6 text-2xs"
                         @click="addTransport()">
                {{ __('sales::field.btn_keep') }}
            </x-ui.button>
        </div>
    </div>

    @endif

    {{-- ── চালান কোথায় যাচ্ছে ─────────────────────────────────────────
         গ্রাহকের ঠিকানা মাস্টারে আছে, কিন্তু মাল সবসময় সেখানে যায় না:
         দোকান এক জায়গায়, গুদাম আরেক জায়গায়, মাঝে মাঝে সরাসরি বাজারে।
         কাগজে ভুল ঠিকানা মানে গাড়ি ভুল জায়গায়। --}}
    {{-- ⭐ মাল কীভাবে নেবে — মালিকের নমুনা ৩ (৪ অক্টোবর ২০২৬)। শুরুতে "এখনই নেবেন"; "পরে পাঠাব" বাছলে ঠিকানা আর তারিখ
         (আগের শিপমেন্টের ঘর) — সার্ভারে তখন দুইটাই বাধ্যতামূলক (af)। ⓘ শিপমেন্টের সুইচ বন্ধ থাকলে "পরে পাঠাব" নেই। --}}
    <div x-show="panel === 'delivery'" x-cloak class="space-y-3" data-delivery>
        <div class="ds-seg" role="radiogroup">
            <button type="button" @click="deliveryMode = 'take_now'" :class="deliveryMode === 'take_now' ? 'is-on' : ''">{{ __('sales::field.mode_take_now') }}</button>
            <button type="button" @click="deliveryMode = 'pickup_later'" :class="deliveryMode === 'pickup_later' ? 'is-on' : ''">{{ __('sales::field.mode_pickup_later') }}</button>
            @if ($show['shipment'])
                <button type="button" @click="deliveryMode = 'send_later'" :class="deliveryMode === 'send_later' ? 'is-on' : ''">{{ __('sales::field.mode_send_later') }}</button>
            @endif
        </div>
        <input type="hidden" name="delivery_mode" value="{{ old('delivery_mode', $resume['fields']['delivery_mode'] ?? 'take_now') }}"
               x-init="seedDriver($el, 'deliveryMode')" :value="deliveryMode">

        @if ($show['shipment'])
        <div x-show="deliveryMode === 'send_later'" x-cloak class="space-y-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ship_to') }}</span>
            <input type="text" name="ship_to" maxlength="191" value="{{ old('ship_to', $resume['fields']['ship_to'] ?? '') }}"
                   placeholder="{{ __('sales::field.ship_to_hint') }}"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-2 text-2xs">
        </label>

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ship_date') }}</span>
            {{-- ব্রাউজারের নিজের তারিখের ঘর নয়: ওটা নিজের লোকেল ধরে
                 আঁকে, আর en-US-এ ০৫/০৬ মানে ৬ মে, বাংলাদেশে ৫ জুন —
                 দুইটাই বৈধ, তাই ভুলটা খাতা থেকে ধরা যায় না। --}}
            <x-ui.date name="ship_date" :value="old('ship_date', $resume['fields']['ship_date'] ?? null)" />
        </label>
        </div>
        @endif
    </div>

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
         class="space-y-3">
        {{-- ⓘ এই বিলে যা জমা নেওয়া হলো — মুছতেও এখানেই (মালিক, ৪ অক্টোবর ২০২৬: নিচে কেবল ছোট কার্ড) --}}
        <ul x-show="deposits.length > 0" x-cloak class="space-y-1 text-xs">
            <template x-for="(row, i) in deposits" :key="i">
                <li class="flex items-center justify-between gap-2 rounded-(--radius-field) bg-(--color-surface-sunken) px-2 py-1">
                    <span x-text="depositMethodName(row.methodId) + ' · ' + depositAccountName(row.accountId) + (depositRefText(row) ? ' · ' + depositRefText(row) : '')"></span>
                    <span class="flex items-center gap-2">
                        <b class="num" x-text="money(row.amount)"></b>
                        <button type="button" @click="dropDeposit(i)" class="px-1 text-(--color-danger)"
                                aria-label="{{ __('sales::action.remove_line') }}">&times;</button>
                    </span>
                </li>
            </template>
        </ul>
        {{-- ⭐ ক্রম — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত): *"রেফ. তারিখ er pase
             কখন, tar por কীভাবে, tar por cash hole cash er box, MFS hole MFS er,
             bank hole bank er, tar por যে খাতে জমা হলো, টাকা, বিবরণ, tar por কার্টে
             যোগ করুন"*। ⓘ আদায় ভাউচারের ক্রমই — আগে "কখন ও কীভাবে", তারপর
             কেবল সেই উপায়ের ঘর, শেষে টাকা কোথায় আর কত। --}}
        <div class="grid items-end gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.ref_date') }}</span>
                <x-ui.date name="deposit_ref_date" class="text-2xs" />
            </label>

            {{-- ── ⭐ আদায় ভাউচারের তিনটা ঘর, ২৫ সেপ্টেম্বর ২০২৬ ──────────────
                 মালিকের নির্দেশ: *"জমা যোগ botam clic korle eirokom 100% same
                 pop up open hobe"*, আর তিনটা ঘর বাদ — *"ডিপোজিটরের ধরন ·
                 ডিপোজিটরের নাম · কোন বিলের বিপরীতে — ei gulo bad dilei hobe"*।

                 ⓘ ঐ তিনটার উত্তরই চালান থেকে আগে থেকে জানা: ধরন সবসময়
                 "গ্রাহক", নামটা চালানের ক্রেতা, আর বিলটা এই চালানটাই।
                 ⚠️ রাখলে ক্ষতিও ছিল — কেউ **অন্য** গ্রাহক বা **অন্য** বিল
                 বেছে ফেলতে পারতেন, আর টাকাটা ভুল জায়গায় বসত। --}}
            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.moved_at') }}</span>
                <input type="time" x-model="depositDraft.movedAt"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
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

        </div>

        {{-- ⓘ উপায়ের নিজের ঘর — কিছু না বাছা পর্যন্ত একটাও নয়। --}}
        <div x-show="depositHasCharge || depositNeedsReference" x-cloak
             class="grid items-end gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            {{-- ── ⭐ ব্যাংক আর মোবাইল ব্যাংকিংয়ের ঘর — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর
                 ২০২৬: *"counter e bank e taka nile ei porda asena tik koro"*।

                 ⓘ আদায় ভাউচারের "ব্যাংক অনলাইন" আর "মোবাইল ব্যাংকিং"-এর হুবহু ঘর
                 ও নাম ([[money-movement]] — ⚠️ ঐ কম্পোনেন্ট এখানে বসানো যায় না, সে
                 নিজের নামে ফর্মের ঘর বানায়, আর এখানে প্রতিটা জমা একটা সারি)।

                 ⚠️ এই ঘরগুলোর `name` নেই — খসড়ার ঘর; "যোগ করুন" চাপলে মানগুলো
                 সারিতে ওঠে, আর সার্ভারে যায় সারির লুকানো ঘর দিয়ে
                 ([[depositDetailsOf()]], [[direct/index]])। --}}
            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.transfer_mode') }}</span>
                <select x-model="depositDraft.transferModeId"
                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-2xs">
                    <option value="">—</option>
                    <template x-for="m in transferModes" :key="m.id">
                        <option :value="m.id" x-text="m.label"></option>
                    </template>
                </select>
            </label>

            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.from_bank') }}</span>
                <input type="text" maxlength="120" x-model="depositDraft.fromBank"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.branch') }}</span>
                <input type="text" maxlength="120" x-model="depositDraft.fromBranch"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.account_holder') }}</span>
                <input type="text" maxlength="120" x-model="depositDraft.fromAccountName"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.account_no') }}</span>
                <input type="text" maxlength="64" x-model="depositDraft.fromAccountNo"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.deposit_slip') }}</span>
                <input type="text" maxlength="64" x-model="depositDraft.depositSlipNo"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            {{-- ⓘ সাধারণ তারিখের ঘর, `x-ui.date` নয় — ঐ কম্পোনেন্টের নিজের স্কোপ,
                 তাই `x-model` ভিতরে পৌঁছায় না; আদায় ভাউচারও এখানে সাধারণ ঘরই নেয়। --}}
            <label class="block" x-show="depositIsBank" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.lands_on') }}</span>
                <input type="date" x-model="depositDraft.landsOn"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositIsMfs" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.wallet') }}</span>
                <select x-model="depositDraft.wallet"
                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-2xs">
                    <option value="">—</option>
                    <option value="bkash">{{ __('accounts::wallet.bkash') }}</option>
                    <option value="nagad">{{ __('accounts::wallet.nagad') }}</option>
                    <option value="rocket">{{ __('accounts::wallet.rocket') }}</option>
                    <option value="upay">{{ __('accounts::wallet.upay') }}</option>
                </select>
            </label>

            <label class="block" x-show="depositIsMfs" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.wallet_medium') }}</span>
                <select x-model="depositDraft.walletMedium"
                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-2xs">
                    <option value="">—</option>
                    <option value="send_money">{{ __('accounts::wallet.send_money') }}</option>
                    <option value="cash_out">{{ __('accounts::wallet.cash_out') }}</option>
                    <option value="payment">{{ __('accounts::wallet.payment') }}</option>
                    <option value="agent_deposit">{{ __('accounts::wallet.agent_deposit') }}</option>
                </select>
            </label>

            <label class="block" x-show="depositIsMfs" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.sender_phone') }}</span>
                <input type="text" inputmode="tel" maxlength="20" x-model="depositDraft.counterpartyPhone"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            <label class="block" x-show="depositHasCharge" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)"
                      x-text="depositIsBank
                          ? @js(__('accounts::field.bank_charge'))
                          : @js(__('accounts::field.charge'))">{{ __('accounts::field.charge') }}</span>
                <input type="number" step="0.01" min="0" x-model="depositDraft.chargeAmount"
                       class="num h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-end text-2xs">
            </label>

            {{-- ⚠️ নম্বরের ঘরটা কেবল যে উপায়ে দরকার, আর তখন **বাধ্যতামূলক**:
                 চেক বা বিকাশের টাকা নম্বর ছাড়া ব্যাংকের কাগজের সাথে মেলানো
                 যায় না, আর ওই মেলানোটাই মাস শেষের কাজ। --}}
            {{-- ⓘ ব্যাংক ও মোবাইল ব্যাংকিংয়ে এই ঘরটাই "ট্রানজেকশন আইডি" — আদায়
                 ভাউচারের নাম ([[money-movement]]); সার্ভারে একই `reference`। --}}
            <label class="block" x-show="depositNeedsReference || depositHasCharge" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)"
                      x-text="depositHasCharge
                          ? @js(__('accounts::field.transaction_id'))
                          : @js(__('sales::field.deposit_ref'))">{{ __('sales::field.deposit_ref') }}</span>
                <input type="text" maxlength="64" x-model="depositDraft.reference"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            {{-- ⓘ চার্জটা কে দিয়েছে — [[charge-bearer]]-এর হুবহু দুই বোতাম আর
                 ফলের লেখা। ⚠️ খাতায় দুইটা সম্পূর্ণ আলাদা ফল, তাই ডিফল্ট `us`
                 (আদায় ভাউচারের মতোই)। --}}
            <fieldset class="col-span-2 sm:col-span-3 lg:col-span-6 flex flex-col gap-1.5"
                      x-show="depositHasCharge" x-cloak>
                <legend class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.charge_borne_by') }}</legend>

                <div class="flex flex-wrap gap-2">
                    @foreach (['us' => 'accounts::charge.we_paid', 'them' => 'accounts::charge.sender_paid'] as $who => $whoLabel)
                        <label class="cursor-pointer">
                            <input type="radio" name="deposit_charge_borne_by_draft" value="{{ $who }}"
                                   class="peer sr-only" x-model="depositDraft.chargeBorneBy">
                            <span class="inline-flex items-center rounded-(--radius-field) border
                                         border-(--color-border) bg-(--color-surface-card) px-2.5 py-1 text-xs
                                         text-(--color-ink-muted) transition-colors
                                         peer-checked:border-(--color-brand-500) peer-checked:font-medium
                                         peer-checked:text-(--color-ink)
                                         peer-focus-visible:outline-2 peer-focus-visible:outline-(--color-brand-500)">
                                {{ __($whoLabel) }}
                            </span>
                        </label>
                    @endforeach
                </div>

                <p class="text-2xs text-(--color-ink-faint)"
                   x-text="depositDraft.chargeBorneBy === 'us'
                       ? @js(__('accounts::message.charge_ours'))
                       : @js(__('accounts::message.charge_theirs_in'))"></p>
            </fieldset>

        </div>

        {{-- ── ⚠️ নোটের হিসাব — কেবল নগদে ──────────────────────────────
             ⓘ চেক বা বিকাশের টাকায় নোট গোনার প্রশ্নই ওঠে না, আর ঘরগুলো
             দেখালে বিক্রেতা ভাবতেন কিছু ভরতে হবে।

             ⛔ শর্তটা `kind === 'cash'` নয়, কারণ উপায়ের সারি না থাকলেও
             নগদ জমা নেওয়া যায় (তখন `methodId` খালি)। ⓘ তাই প্রশ্নটা
             উল্টো করে: **কোনো উপায় বাছা হয়নি, নাকি যেটা বাছা হয়েছে
             সেটা নগদ** — দুইটাই নগদের ক্ষেত্র। --}}
        <div class="col-span-2 sm:col-span-3 lg:col-span-6"
             x-show="depositIsCash" x-cloak>
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.note_breakdown') }}</span>

            <div class="grid grid-cols-5 gap-1">
                @foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $face)
                    <label class="flex items-center gap-1">
                        <span class="num w-8 text-end text-2xs text-(--color-ink-muted)">{{ $face }}</span>
                        <span class="text-2xs text-(--color-ink-muted)">×</span>
                        <input type="number" min="0" step="1"
                               x-model="depositDraft.noteCounts[{{ $face }}]"
                               class="num h-(--spacing-field-compact) w-full rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-1 text-end text-2xs">
                    </label>
                @endforeach
            </div>

            {{-- ⚠️ গোনা আর লেখা আলাদা হলে **দেখানো হয়, আটকানো হয় না**।
                 ⓘ বিক্রেতা আংশিক গুনতে পারেন, বা গুনতে ভুল করতে পারেন —
                 আদায় ভাউচারও ঠিক এভাবেই আচরণ করে। --}}
            {{-- ⓘ চাবি দুইটা আদায় ভাউচারেরই — `count_agrees` ও
                 `count_differs`। ⛔ প্রথমে `notes_match`/`notes_differ`
                 লিখেছিলাম, আর ঐ নামে কিছু **নেই**: পর্দায় চাবিটাই
                 ছাপা হত, আর [[EveryTranslationKeyExistsTest]] লাল হত।

                 ⚠️ `@js(...)` দিয়ে, স্ট্রিং জোড়া দিয়ে নয় — বাংলা লেখায়
                 একটা উদ্ধৃতি চিহ্ন থাকলেই Alpine-এর অভিব্যক্তিটা
                 ভাঙত, আর সেটা কেবল ঐ ভাষায় দেখা যেত। --}}
            <p class="mt-1 rounded px-1.5 py-0.5 text-2xs"
               x-show="$num(depositCounted) > 0" x-cloak
               :class="depositCountMatches
                   ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                   : 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'"
               x-text="depositCountMatches
                   ? @js(__('accounts::message.count_agrees'))
                   : @js(__('accounts::message.count_differs'))"></p>
        </div>


        <div class="grid items-end gap-2 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
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

            <label class="block">
                {{-- ⓘ "বিবরণ" — এটা আদায়ের ভাউচারের নিজের বিবরণ, পাশের নোট নয়।
                     উপহারের লাইনে "মন্তব্য"-ই থাকল, কারণ ওটা কোনো দাখিলায় যায় না। --}}
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.narration') }}</span>
                <input type="text" maxlength="191" x-model="depositDraft.narration"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-2xs">
            </label>

            {{-- ⓘ বাহক — গরমিল হলে এই নামটাই প্রথম প্রশ্ন।
                 ⚠️ তালিকাটা `carriers`, আর সেটা আগে থেকেই কম্পোনেন্টে আছে
                 (উপহারের পাশের ঘরটা ওটাই ব্যবহার করে) — তাই নতুন কিছু
                 পাঠাতে হয়নি। --}}
            <label class="block" x-show="carriers.length > 0" x-cloak>
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::field.carried_by') }}</span>
                <select x-model="depositDraft.carriedBy"
                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-2xs">
                    <option value="">—</option>
                    <template x-for="c in carriers" :key="c.id">
                        <option :value="c.id" x-text="c.name"></option>
                    </template>
                </select>
            </label>

            {{-- ⓘ বোতামটাও সারির শেষ ঘরে — `items-end` থাকায় ঘরগুলোর নিচের
                 কিনারার সাথে মিলে বসে, লেবেলের উচ্চতা যা-ই হোক। --}}
            <x-ui.button type="button" tone="primary"
                         class="h-(--spacing-field-compact) w-full justify-center text-2xs"
                         @click="addDeposit()" ::disabled="! depositReady">
                {{ __('sales::action.add_to_cart') }}
            </x-ui.button>
        </div>
    </div>

    @endif

    {{-- ── মন্তব্য ─────────────────────────────────────────────────────
         কাগজে ছাপা হয়, তাই যা লেখা হয় তা গ্রাহকও পড়েন। --}}
    <div x-show="panel === 'note'" x-cloak>
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.note') }}</span>
            <textarea name="narration" rows="3" maxlength="500" x-model="noteText" x-init="seedDriver($el, 'noteText')"
                      class="w-full rounded-(--radius-field) border border-(--color-border)
                             bg-(--color-surface-card) px-2 py-1 text-2xs">{{ old('narration', $resume['fields']['narration'] ?? '') }}</textarea>
        </label>
    </div>

    {{-- ⭐ দাম দেখুন (F3) — বিলে না তুলে দাম, মজুদ আর লট; পর্দার নিজের তালিকা থেকেই (af: price_check লাগছে না) --}}
    <div x-show="panel === 'price'" x-cloak class="space-y-2" data-price-check>
        <input type="search" x-model="priceTerm" x-ref="priceSearch" placeholder="{{ __('sales::field.price_search') }}"
               class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 text-sm">
        <div class="space-y-1" x-show="! priceProduct">
            <template x-for="p in priceMatches" :key="p.id">
                <button type="button" @click="pricePick(p)"
                        class="flex w-full justify-between gap-2 rounded-(--radius-field) px-2 py-1 text-start text-sm hover:bg-(--color-surface-hover)">
                    <span x-text="p.name"></span><span class="num text-(--color-ink-muted)" x-text="p.code"></span>
                </button>
            </template>
        </div>
        <template x-if="priceProduct">
            <div class="space-y-1 text-sm">
                <p class="font-semibold" x-text="priceProduct.name + ' · ' + (priceProduct.code || '')"></p>
                <div class="ds-rkv-plain"><span>{{ __('sales::field.price_rate') }}</span><b class="num" x-text="'৳' + money(priceProduct.rate) + ' / ' + (priceProduct.unit || '')"></b></div>
                <div class="ds-rkv-plain"><span>{{ __('sales::field.price_stock') }}</span><b class="num text-(--color-success)" x-text="qty(priceProduct.available) + ' ' + (priceProduct.unit || '')"></b></div>
                <template x-for="lot in priceLots" :key="lot.id">
                    <div class="ds-rkv-plain"><span x-text="lotLabel(lot)"></span></div>
                </template>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="priceProduct = null" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1 text-xs">&larr;</button>
                    <button type="button" @click="priceToBill()" class="rounded-(--radius-field) bg-(--color-brand-600) px-3 py-1 text-xs font-semibold text-(--color-brand-ink)">{{ __('sales::field.price_to_bill') }}</button>
                </div>
            </div>
        </template>
    </div>

    {{-- ⭐ খসড়া খুলুন (F6) — রাখা বিলগুলো; চাপলে এই পর্দাতেই খোলে --}}
    <div x-show="panel === 'drafts'" x-cloak class="space-y-1" data-drafts>
        <p x-show="pendingShown.length === 0" class="text-sm text-(--color-ink-muted)">{{ __('sales::field.drafts_none') }}</p>
        <template x-for="d in pendingShown" :key="d.id">
            <button type="button" @click="openDraftItem(d)"
                    class="block w-full rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)"
                    x-text="pendingLabel(d)"></button>
        </template>
    </div>

    {{-- ⭐ আবার ছাপুন (F8) — বিলের নম্বর দিয়ে বিলের তালিকায়, সেখান থেকে ছাপা --}}
    <div x-show="panel === 'reprint'" x-cloak class="space-y-2">
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.reprint_no') }}</span>
            <input type="text" x-model="reprintNo" class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 text-sm">
        </label>
        <div class="flex justify-end">
            <a :href="reprintUrl" data-no-peek
               class="rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold text-(--color-brand-ink)">{{ __('sales::field.reprint_go') }}</a>
        </div>
    </div>

    {{-- ⭐ ফেরত নিন (F9) — আগের বিল ধরে, ফেরতের পাতায় --}}
    <div x-show="panel === 'return'" x-cloak class="space-y-2">
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::field.return_hint') }}</p>
        <div class="flex justify-end">
            <a href="{{ route('sales.return.create') }}" data-no-peek
               class="rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold text-(--color-brand-ink)">{{ __('sales::field.return_go') }}</a>
        </div>
    </div>

    {{-- ⭐ বিল বাতিল (Ctrl+X) — পাকা হওয়ার আগে, কারণ লাগবেই; "সব মুছুন"-এর জায়গায় (মালিক, নমুনা ৩) --}}
    <div x-show="panel === 'cancel'" x-cloak class="space-y-2" data-void>
        <p class="text-xs text-(--color-ink-muted)">{{ __('sales::field.cancel_hint') }}</p>
        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::field.cancel_reason') }}</span>
            <select x-model="voidReason" class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                <option value=""></option>
                @foreach (explode('|', __('sales::field.cancel_reasons')) as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex justify-end gap-2">
            <button type="button" @click="closePanel()" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">{{ __('sales::field.cancel_back') }}</button>
            <button type="button" @click="voidBill()" :disabled="voidReason === ''"
                    class="rounded-(--radius-field) bg-(--color-danger) px-4 py-1.5 text-xs font-semibold text-white disabled:opacity-40">{{ __('sales::field.cancel_go') }}</button>
        </div>
    </div>

    <div x-show="panelHasFooter" class="mt-3 flex justify-end">
        <button type="button" @click="closePanel()"
                class="rounded-(--radius-field) border border-(--color-border) px-4 py-1.5 text-xs font-semibold">{{ __('sales::field.btn_close') }}</button>
    </div>
</div>
</div>
