{{--
    খরচ ভাউচারের নিজস্ব ঘরগুলো — নমুনা ধরে ধরে।

    ── ⛔ কেন এই ফাইলটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ────────────────────────
    মালিক নমুনা আর আসল পর্দা পাশাপাশি রেখে দেখালেন। গুনে দেখা গেল
    নমুনার **চৌদ্দটা ঘরের মাত্র তিনটা** পর্দায় ছিল — তারিখ, যে খাত
    থেকে, বিবরণ।

    ⚠️ কারণ পাঁচটা ভাউচারের চারটাই একটাই `simple-form` ব্যবহার করত, আর
    সেখানে টাইপভেদে আলাদা কিছুই ছিল না। ⓘ ফলে খরচ ভাউচার দেখতে হুবহু
    রসিদের মতো — অথচ খরচের নিজের প্রশ্নগুলো (কোন খাতে, কার খরচ, কোন
    চালানের জন্য) কোথাও জিজ্ঞেসই করা হত না।

    ── ⭐ ক্রম ও দল নমুনার হুবহু ───────────────────────────────────────
    মালিকের নির্দেশ: *"এই লিংকে যা যেখানে যেভাবে আছে সেইভাবে বসাও"*।
    তাই সারিগুলো নমুনার `s-exp` অংশের ক্রমেই:

        সারি ১   তারিখ · খরচের খাত · খরচের কেন্দ্র
        সারি ২   কাকে দেওয়া হলো (দুই ঘর চওড়া) · বিল/ভাউচার নম্বর
        ভাঁজ     কোন চালানের জন্য
        ভাঁজ     উৎসে কর্তন

    ⓘ তারিখ উপরের সেকশনেই আছে, তাই এখানে বাকি তেরোটা।
--}}
@php
    $was = fn (string $field, $fallback = null) => old($field, $voucher->{$field} ?? $fallback);

    /*
     * পর্দার লেখাগুলো — Alpine-এর স্কোপে একবারে।
     *
     * ⓘ Js::from কোটেশন, ব্যাকস্ল্যাশ ও ইউনিকোড সবই নিরাপদে পালায়,
     * তাই অনুবাদে অ্যাপস্ট্রফি থাকলেও অভিব্যক্তিটা ভাঙে না।
     */
    /*
     * ভাগ বসানোর জন্য প্রতিটা চালানের দুইটা সংখ্যা — পরিমাণ ও মূল্য।
     *
     * ⓘ চাবিটা সারির ক্রম (`$i`), চালানের id নয় — কারণ ঘরের
     * `x-ref`-ও ওই ক্রমই ব্যবহার করে, আর দুইটা এক রাখলে মিলানোর
     * জন্য আলাদা কোনো তালিকা রাখতে হয় না।
     */
    $billFacts = collect($taggableBills ?? [])->values()
        ->map(fn ($b) => [
            'qty' => (float) ($b->total_qty ?? 0),
            'value' => (float) ($b->total_value ?? 0),
        ])->all();

    /* সম্পাদনার সময় যে সারিগুলো আগেই টিক দেওয়া। */
    $pickedRows = collect($taggableBills ?? [])->values()
        ->filter(function ($bill) use ($voucher) {
            $rows = collect(old('bill_shares', $voucher->billShares?->all() ?? []));

            return $rows->contains(function ($s) use ($bill) {
                $id = is_array($s) ? ($s['purchase_bill_id'] ?? null) : $s->purchase_bill_id;

                return (int) $id === (int) $bill->id;
            });
        })->keys()->all();

    $texts = [
        'directLabel' => __('accounts::field.direct_cost'),
        'indirectLabel' => __('accounts::field.indirect_cost'),
        'landsGoods' => __('accounts::field.into_goods_cost'),
        'landsHead' => __('accounts::field.into_expense_head'),
        'directEffect' => __('accounts::message.direct_effect'),
        'indirectEffect' => __('accounts::message.indirect_effect'),
        'noBillLabel' => __('accounts::field.no_bill_picked'),
    ];
@endphp

<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         {{-- ⓘ যুক্তিটা `resources/js/components/forms.js`-এ (expenseFields) —
              ভাগ বসানোর নিয়ম, হাতে লেখা ভাগ রক্ষা, আর শেষ সারির পয়সা। --}}
         x-data="expenseFields({
             head: @js((string) ($was('expense_account_id') ?? $was('to_account_id'))),
             payeeType: @js((string) $was('payee_type_id')),
             payeesByType: @js($payeesByType ?? []),
             gross: @js((float) ($was('gross_amount') ?? 0)),
             ait: @js((float) ($was('ait_amount') ?? 0)),
             vds: @js((float) ($was('vds_amount') ?? 0)),
             tagged: @js(count(old('bill_shares', $voucher->billShares?->pluck('purchase_bill_id')->all() ?? []))),
             bills: @js($billFacts ?? []),
             basis: @js(old('alloc_basis', 'qty')),
             amount: @js((float) old('amount', 0)),
             picked: @js($pickedRows ?? []),
             texts: @js($texts),
         })"
         x-on:abos-expense-amount.window="setAmount($event.detail)">

    {{-- সারি ১ — তারিখ · খরচের খাত · খরচের কেন্দ্র (নকশার হুবহু) --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                    :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                    required />

        {{-- খরচের খাত — ৫৩১০ পরিবহন ভাড়া, ৫৩২০ বিদ্যুৎ বিল… --}}
        {{--
            ⛔ `:options` প্রপ, স্লটে `<option>` নয় — ১৫ সেপ্টেম্বর ২০২৬।
            কম্পোনেন্টটা স্লটের অপশনগুলো চুপচাপ ফেলে দেয়, আর ড্রপডাউনটা
            খালি থাকে — ঘরটা পর্দায় থাকে, ভিতরে কিছুই না।
        --}}
        {{--
            ⛔ "খরচের খাত" একটাই, দুইটা নয় — ১৮ সেপ্টেম্বর ২০২৬।
            পর্দায় ঘরটা **দুইবার** ছিল: এখানে `expense_account_id`, আর নিচে
            `to_account_id` — দুইটার লেবেলই "খরচের খাত", আর মালিক ছবিতে
            সেটাই ধরিয়ে দেন। ⚠️ দুই ঘরে দুই উত্তর দিলে খাতায় কোনটা
            বসত তা বলা যেত না। ⓘ এখন দেখা যায় একটা, আর খাতার
            দিকটা (`to_account_id`) লুকানো ঘরে সেই একটাকেই অনুসরণ করে।
        --}}
        <x-ui.select name="expense_account_id"
                     :label="__('accounts::field.expense_head')"
                     :options="collect($expenseAccounts)->mapWithKeys(fn ($a) => [$a->id => $a->code.' · '.$a->label()])->all()"
                     :selected="$was('expense_account_id') ?? old('to_account_id')"
                     x-model="head"
                     required />
        <input type="hidden" name="to_account_id" :value="head">

        {{--
            খরচের কেন্দ্র — কোন ডিপো, গুদাম, অফিস বা গাড়ির খরচ।

            ⚠️ `branch_id`-র সাথে গুলিয়ে ফেলা যাবে না: ওটা বলে **কোথায়
            বসে লেখা হলো**, এটা বলে **কার খরচ**। ⓘ একজন প্রধান অফিসে
            বসে নেত্রকোনা গুদামের বিদ্যুৎ বিল লিখতে পারেন।
        --}}
        <x-ui.select name="cost_centre_id"
                     :label="__('accounts::field.cost_centre')"
                     :options="$costCentres ?? []"
                     :selected="$was('cost_centre_id')" />
    </div>

    {{--
        সারি ২ — কাকে দেওয়া হলো, তিনটা ঘর এক লাইনে (মালিকের ছবি, ১৮ সেপ্টেম্বর ২০২৬)

        মালিকের কথা: *"কাকে দেওয়া হলো ei line Paytype (vendor,
        transporter/carrier, others …) … tar por boxe কাকে দেওয়া হলো/payee,
        বিল / ভাউচার নম্বর"*।
    --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_2fr_1fr]">
        {{--
            ধরনগুলো মাস্টার থেকে (`mdm_party_types`) — সরবরাহকারী · পরিবহনকারী ·
            কুরিয়ার · হাম্মালি ঠিকাদার · সার্ভিস প্রোভাইডার · প্রতিষ্ঠান।
            ⓘ ফাঁকা সারিটাই "অন্যান্য": ধরন না বাছলে নামটা হাতে লেখা হয়,
            আর সেটাই রিকশাভাড়া বা চা-নাস্তার স্ভাভাবিক উত্তর।
        --}}
        <x-ui.select name="payee_type_id"
                     :label="__('accounts::field.payee_type')"
                     :options="$payeeTypes ?? []"
                     :selected="$was('payee_type_id')"
                     :placeholder="__('accounts::field.payee_type_other')"
                     x-model="payeeType" />

        {{--
            নামের ঘরটা লেখাও যায়, বাছাও যায় — দুইটা আলাদা ঘর নয়।
            ⛔ দুইটা ঘর বসালে একটায় লেখা আর অন্যটায় বাছা — দুই উত্তর থাকত,
            আর খাতায় কোনটা বসত তা বলা যেত না। ⭐ তাই একটাই ঘর, আর ধরন
            বাছলে তাঁর লোকজনের নাম সাজেশনে আসে (`<datalist>`)।

            ⚠️ আজ কোনো সরবরাহকারীর ধরন বসানো নেই, তাই সাজেশন খালি —
            ঘরটা তবু কাজ করে, আর ধরন বসানো শুরু হলেই তালিকা ভরে ওঠে।
        --}}
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.payee') }}</span>
            <input type="text" name="payee_name" list="payee-name-options"
                   value="{{ $was('payee_name') }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-card) px-3">
            <datalist id="payee-name-options">
                <template x-for="p in payeeList" :key="p.id">
                    <option :value="p.label"></option>
                </template>
            </datalist>
            @error('payee_name')
                <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
            @enderror
        </label>

        <x-ui.field name="bill_no"
                    :label="__('accounts::field.bill_no')"
                    :value="$was('bill_no')" />
    </div>

    @include('accounts::voucher.partials.expense-bill-tag')

    {{--
        ── উৎসে কর্তন ──────────────────────────────────────────────────
        ⚠️ `amount` হাতে যাওয়া টাকা, `gross_amount` বিলের মোট। ⓘ দুইটা
        এক নয়, আর পার্থক্যটা সরকারের ঘরে যায় (২১২১ উৎসে কর্তিত কর)।
    --}}
    {{--
        নকশার দুই কলাম — উৎসে কর্তন · কীভাবে দেওয়া হলো

        ⓘ দুইটাই "টাকাটা কত আর কীভাবে গেল" প্রশ্নের উত্তর, তাই
        নকশায় পাশাপাশি। ⚠️ "কার মাধ্যমে · কখন" ঘর দুইটা নেই —
        মালিকের নির্দেশ: *"দুইটাই বাদ দাও"*।
    --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <details class="self-start rounded-(--radius-card) border border-(--color-border)
                        border-l-4 border-l-(--color-danger) p-3"
                 @if ((float) ($was('ait_amount') ?? 0) > 0 || (float) ($was('vds_amount') ?? 0) > 0) open @endif>
        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm font-semibold">
            <span>{{ __('accounts::field.deduction_at_source') }}</span>
            <span class="num text-xs font-normal text-(--color-ink-muted)"
                  x-text="'{{ __('accounts::field.deducted') }} ' + (ait + vds).toFixed(2)
                      + ' · {{ __('accounts::field.net_payable') }} ' + net.toFixed(2)"></span>
        </summary>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <x-ui.field name="gross_amount" type="number" step="0.01" inputmode="decimal"
                        :label="__('accounts::field.bill_gross')"
                        :value="$was('gross_amount')" numeric
                        x-model.number="gross" />

            <x-ui.field name="ait_amount" type="number" step="0.01" inputmode="decimal"
                        :label="__('accounts::field.ait')"
                        :value="$was('ait_amount')" numeric
                        x-model.number="ait" />

            <x-ui.field name="vds_amount" type="number" step="0.01" inputmode="decimal"
                        :label="__('accounts::field.vds')"
                        :value="$was('vds_amount')" numeric
                        x-model.number="vds" />
        </div>

        <p class="mt-3 flex items-center justify-between text-sm">
            <span class="text-(--color-ink-muted)">{{ __('accounts::field.net_payable') }}</span>
            <span class="num font-semibold" x-text="net.toFixed(2)"></span>
        </p>
        </details>

        {{--
            ⓘ সংযুক্তি মালিকের ছবির মার্ক করা খালি জায়গায় — উৎসে কর্তনের ঠিক নিচে।
            ⭐ বাম কলামটা উৎসে কর্তনের পরে খালি পড়ে থাকত, আর ডান কলামে
            নোটের হিসাব লম্বা হয়ে যেত — জায়গাটা এখন কাজে লাগে।
        --}}
        <div class="lg:col-start-1">
            @include('accounts::voucher.partials.attachment-field')
        </div>

        <x-ui.money-movement direction="out"
                             :carriers="$carriers ?? []"
                             :modes="$transferModes ?? []"
                             :carrier-here="false"
                             :record="$voucher" />
    </div>

    {{-- নমুনার শেষ ঘর — বিলের ছবি, যা ছয় মাস পরে একমাত্র সাক্ষী --}}
    {{-- ⓘ সংযুক্তি নকশার শেষ সারিতে চলে গেছে, তাই এখানে আর নেই। --}}
</section>
