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
    $texts = \Illuminate\Support\Js::from([
        'directLabel' => __('accounts::field.direct_cost'),
        'indirectLabel' => __('accounts::field.indirect_cost'),
        'landsGoods' => __('accounts::field.into_goods_cost'),
        'landsHead' => __('accounts::field.into_expense_head'),
        'directEffect' => __('accounts::message.direct_effect'),
        'indirectEffect' => __('accounts::message.indirect_effect'),
    ]);
@endphp

<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         x-data="{
             gross: {{ (float) ($was('gross_amount') ?? 0) }},
             ait: {{ (float) ($was('ait_amount') ?? 0) }},
             vds: {{ (float) ($was('vds_amount') ?? 0) }},

             /*
              * হাতে যাবে — বিলের মোট থেকে কর্তন বাদ।
              *
              * ⚠️ শতাংশ নয়, টাকার অঙ্কই রাখা হয়: হার বদলায়, আর পুরনো
              * ভাউচার নতুন হারে হিসাব করলে ভুল দেখাত।
              */
             get net() { return Math.max(0, this.gross - this.ait - this.vds) },

             /* একটাও চালান বাছা হয়নি মানে পরোক্ষ খরচ — মালিকের নিয়ম। */
             tagged: {{ count(old('bill_shares', $voucher->billShares?->pluck('purchase_bill_id')->all() ?? [])) }},
             get isDirect() { return this.tagged > 0 },

             /*
              * লেখাগুলো এক জায়গায়, PHP-তে বানানো — কারণ দুইটা।
              *
              * ⚠️ এক: x-text একটা JS অভিব্যক্তি পড়ে। অনুবাদে একটা
              * অ্যাপস্ট্রফি থাকলেই স্ট্রিংটা ওখানেই শেষ হয়ে যেত, আর
              * গোটা অভিব্যক্তিটা নীরবে ভেঙে পড়ত। Js::from সেটা পালায়।
              *
              * ⛔ দুই: এখানে প্রথমে প্রতিটা লাইনে আলাদা করে js নির্দেশ
              * দেওয়া হয়েছিল, আর উপরের ব্যাখ্যার ভিতরে একটা নমুনা লেখা
              * ছিল দুই বন্ধনী দিয়ে। ⓘ Blade জাভাস্ক্রিপ্টের মন্তব্য
              * চেনে না — সে ঐ নমুনাটাকেও কোড ধরে পার্স করেছে, আর গোটা
              * অ্যাট্রিবিউটটা ভেঙে পাতাটা ৫০০ দিয়েছে।
              *
              * ⭐ তাই এখন একটাই বস্তু, আর ব্যাখ্যায় কোনো নমুনা নেই।
              */
             ...{{ $texts }},
         }">

    <div class="grid gap-3 sm:grid-cols-3">
        {{-- খরচের খাত — ৫৩১০ পরিবহন ভাড়া, ৫৩২০ বিদ্যুৎ বিল… --}}
        <x-ui.select name="expense_account_id"
                     :label="__('accounts::field.expense_head')"
                     :value="$was('expense_account_id')"
                     required>
            <option value="">—</option>
            @foreach ($expenseAccounts as $account)
                <option value="{{ $account->id }}"
                        @selected((int) $was('expense_account_id') === (int) $account->id)>
                    {{ $account->code }} · {{ $account->display_name }}
                </option>
            @endforeach
        </x-ui.select>

        {{--
            খরচের কেন্দ্র — কোন ডিপো, গুদাম, অফিস বা গাড়ির খরচ।

            ⚠️ `branch_id`-র সাথে গুলিয়ে ফেলা যাবে না: ওটা বলে **কোথায়
            বসে লেখা হলো**, এটা বলে **কার খরচ**। ⓘ একজন প্রধান অফিসে
            বসে নেত্রকোনা গুদামের বিদ্যুৎ বিল লিখতে পারেন।
        --}}
        <x-ui.select name="cost_centre_id"
                     :label="__('accounts::field.cost_centre')"
                     :value="$was('cost_centre_id')">
            <option value="">—</option>
            @foreach ($costCentres ?? [] as $id => $name)
                <option value="{{ $id }}" @selected((int) $was('cost_centre_id') === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        {{-- কাকে দেওয়া হলো — নমুনায় দুই ঘর চওড়া --}}
        <div class="sm:col-span-2">
            <x-ui.field name="payee_name"
                        :label="__('accounts::field.payee')"
                        :value="$was('payee_name')" />
        </div>

        {{-- সরবরাহকারীর নিজের বিল নম্বর — ছয় মাস পরে মেলানোর একমাত্র সূত্র --}}
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
    <details class="mt-4 rounded-(--radius-card) border border-(--color-border) p-3"
             @if ((float) ($was('ait_amount') ?? 0) > 0 || (float) ($was('vds_amount') ?? 0) > 0) open @endif>
        <summary class="cursor-pointer text-sm font-semibold">
            {{ __('accounts::field.deduction_at_source') }}
            <span class="ms-2 text-xs font-normal text-(--color-ink-muted)"
                  x-text="ait + vds > 0
                      ? '{{ __('accounts::field.deducted') }} ' + (ait + vds).toFixed(2)
                      : ''"></span>
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

    {{-- নমুনার শেষ ঘর — বিলের ছবি, যা ছয় মাস পরে একমাত্র সাক্ষী --}}
    <div class="mt-4">
        @include('accounts::voucher.partials.attachment-field')
    </div>
</section>
