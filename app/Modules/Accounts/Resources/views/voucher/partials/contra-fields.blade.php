{{--
    কন্ট্রা ভাউচারের নিজস্ব ঘরগুলো — নকশা ধরে ধরে।

    ── ⛔ কেন এই ফাইলটা লাগল, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────
    কন্ট্রাও `simple-form`-এর সাধারণ চেহারাটাই পরত: তিন ঘরের একটা সারি
    (তারিখ · কাগজের তারিখ · টাকার অঙ্ক), তারপর খাত দুইটা, তারপর পক্ষ ও
    টাকার শ্রেণি, আর শেষে টাকা-চলাচলের গোটা ব্লক — নোট গোনা সহ।

    ⚠️ অথচ কন্ট্রায় পক্ষ বলে কিছু নেই: টাকাটা **নিজেরই** এক খাত থেকে
    অন্য খাতে যায়। ⛔ "কার কাছ থেকে" ঘরটা ওখানে অর্থহীন, আর নোট গোনার
    বাক্সটা দুইবার লাগত (একবার যে দিচ্ছে, একবার যে নিচ্ছে) — তাই
    একবারের বাক্সটা ভুলই বলত।

    ── ⭐ নকশার ক্রম ───────────────────────────────────────────────────
    মালিক ছবি পাঠিয়ে বললেন *"কন্ট্রা ভাউচার emon koro"*:

        সারি ১   তারিখ · যে খাত থেকে · যে খাতে
        ভাঁজ     কাগজ ও সময় — জমা স্লিপ · কে হাতে নিয়ে গেল · ব্যাংক চার্জ
                 আর নিচে "কবে পৌঁছাবে": আজই / পথে আছে
        শেষ সারি টাকার পরিমাণ · বিবরণ · [বোতাম]
--}}
@php
    $was = fn (string $field, $fallback = null) => old($field, $voucher->{$field} ?? $fallback);

    /*
     * ⓘ `lands_on` একটা **তারিখের** কলাম, চিপ নয়।
     *
     * নকশায় দুইটা চিপ — "আজই" আর "পথে আছে"। ⭐ চিপটা কেবল প্রশ্নটা
     * সহজ করে; ভিতরে যা বসে তা তারিখই: আজই মানে আজকের তারিখ, পথে
     * আছে মানে ব্যবহারকারীর দেওয়া দিন।
     *
     * ⚠️ আলাদা কোনো কলাম বানানো হয়নি — তাহলে একই সত্যের দুইটা উৎস হত,
     * আর একদিন চিপে "আজই" আর তারিখে আগামীকাল বসে থাকত।
     */
    $landsOn = $was('lands_on');
    $onTheWay = filled($landsOn) && $landsOn instanceof \DateTimeInterface
        ? $landsOn->format('Y-m-d') !== now()->toDateString()
        : (filled($landsOn) && (string) $landsOn !== now()->toDateString());
@endphp

{{--
    ⛔ খাত দুইটা খালি থেকে শুরু হয় — ১৮ সেপ্টেম্বর ২০২৬।

    মালিকের ছবিতে দুই ঘরেই একই খাত বসা ছিল — `1101-TIL-0001`।
    ⓘ কারণ ফাঁকা সারি না থাকলে ব্রাউজার তালিকার প্রথম সারিটাই
    দেখায়, আর দুই ঘরের প্রথম সারি একই।

    ⚠️ যাচাই ওটা আটকাত (`different:to_account_id`), কিন্তু সেটা
    বলত **সেভ চাপার পরে**। ⭐ খালি থেকে শুরু হলে বাছাইটা
    মানুষের, আর ভুলটা ঘটার আগেই চোখে পড়ে।
--}}
{{-- ── সারি ১ — তারিখ · যে খাত থেকে · যে খাতে (নকশার হুবহু) ────────── --}}
<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <div class="grid gap-3 sm:grid-cols-3">
        <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                    :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                    required />

        {{--
            ⓘ খাত দুইটা এখানে, নিচের সেকশনে নয়।

            ⚠️ নিচের পুরনো ঘর দুইটা তখন লুকানো **আর নিষ্ক্রিয়** — দুইটাই
            লাগে। ⛔ কেবল লুকালে ব্রাউজার শেষেরটার মান পাঠাত, আর উপরের
            বাছাইটা নীরবে হারাত।
        --}}
        <x-ui.select name="from_account_id"
                     :label="__('accounts::field.from_head')"
                     :options="collect($moneyAccounts)->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()"
                     :selected="old('from_account_id', $creditLine?->account_id)"
                     :placeholder="__('core.form.choose')"
                     required />

        <x-ui.select name="to_account_id"
                     :label="__('accounts::field.to_head')"
                     :options="collect($moneyAccounts)->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()"
                     :selected="old('to_account_id', $debitLine?->account_id)"
                     :placeholder="__('core.form.choose')"
                     required />
    </div>

    {{--
        ── কাগজ ও সময় ─────────────────────────────────────────────────
        ⓘ নকশায় বাক্সটা **খোলা**, আর বাম ধারে সবুজ রেখা — কারণ জমা
        স্লিপের নম্বরটাই ছয় মাস পরে ব্যাংকের কাগজের সাথে মেলানোর
        একমাত্র সূত্র, আর ভাঁজ করা থাকলে কেউ ওটা লিখত না।
    --}}
    <details class="mt-4 rounded-(--radius-card) border border-(--color-border)
                    border-l-4 border-l-(--color-badge-success-ink) p-3"
             open
             x-data="{ onTheWay: {{ $onTheWay ? 'true' : 'false' }} }">
        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2
                        text-sm font-semibold">
            <span>{{ __('accounts::section.paper_and_time') }}</span>
            <span class="text-xs font-normal text-(--color-ink-muted)">
                <span class="num">{{ $was('deposit_slip_no') }}</span>
                <span x-text="onTheWay
                    ? '{{ __('accounts::field.lands_later') }}'
                    : '{{ __('accounts::field.lands_today') }}'"></span>
            </span>
        </summary>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <x-ui.field name="deposit_slip_no"
                        :label="__('accounts::field.deposit_slip')"
                        :value="$was('deposit_slip_no')" />

            <x-ui.select name="carried_by" :label="__('accounts::field.carried_by_hand')"
                         :options="$carriers ?? []"
                         :selected="$was('carried_by')"
                         :placeholder="__('accounts::field.carried_by_none')" />

            {{--
                ⓘ ব্যাংক চার্জ — কন্ট্রায় এটা **খরচ**, টাকা সরানো নয়।

                ⚠️ পাঁচ লাখ পাঠিয়ে ব্যাংক দুইশো কাটলে দুই খাতের যোগফল
                মেলে না; পার্থক্যটা চার্জের খাতে বসে, আর সেটা না লিখলে
                কেউ বলতে পারত না টাকাটা কোথায় গেল।
            --}}
            <x-ui.field name="charge_amount" type="number" step="0.01" inputmode="decimal"
                        :label="__('accounts::field.bank_charge')"
                        :value="$was('charge_amount') ?? '0.00'" numeric />
        </div>

        {{--
            ── কবে পৌঁছাবে ─────────────────────────────────────────────
            ⭐ প্রশ্নটা কন্ট্রার নিজের: সিন্দুক থেকে ব্যাংকে টাকা আজই
            বসে, কিন্তু এক ব্যাংক থেকে আরেক ব্যাংকে দুই দিন লাগে।

            ⛔ পার্থক্যটা না ধরলে আজকের ব্যাংক ব্যালেন্স বেশি দেখাত,
            আর কেউ ওই টাকার উপর ভিত্তি করে চেক লিখে ফেলতেন।
        --}}
        <div class="mt-3">
            <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.lands_on') }}</span>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="onTheWay
                            ? 'border-(--color-border)'
                            : 'border-(--color-badge-success-ink) bg-(--color-badge-success-bg) font-semibold text-(--color-badge-success-ink)'"
                        x-on:click="onTheWay = false">
                    {{ __('accounts::field.lands_today') }}
                </button>

                <button type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="onTheWay
                            ? 'border-(--color-badge-pending-ink) bg-(--color-badge-pending-bg) font-semibold text-(--color-badge-pending-ink)'
                            : 'border-(--color-border)'"
                        x-on:click="onTheWay = true">
                    {{ __('accounts::field.lands_later') }}
                </button>

                {{--
                    ⓘ "আজই" বাছলে ঘরটা খালি যায়, আর সার্ভার তারিখটা
                    নিজেই আজকের ধরে নেয় — ব্যবহারকারীকে আজকের তারিখ
                    টাইপ করতে বলার কোনো মানে নেই।
                --}}
                {{--
                    ⛔ `x-ui.field` নয় — ওতে লেবেল লুকানোর কোনো প্রপ নেই,
                    আর অচেনা প্রপ চুপচাপ HTML অ্যাট্রিবিউট হয়ে বসত।
                    ⓘ লেবেলটা উপরেই আছে ("কবে পৌঁছাবে"), তাই স্ক্রিন
                    রিডারের জন্য `aria-label`।
                --}}
                <input type="date" name="lands_on"
                       value="{{ $onTheWay ? $landsOn : '' }}"
                       aria-label="{{ __('accounts::field.lands_on') }}"
                       x-show="onTheWay" x-cloak
                       class="h-(--spacing-field) w-44 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3">
            </div>

            <p class="mt-2 text-xs text-(--color-ink-muted)"
               x-text="onTheWay
                   ? '{{ __('accounts::message.contra_on_the_way') }}'
                   : '{{ __('accounts::message.contra_lands_today') }}'"></p>
        </div>
    </details>
</section>
