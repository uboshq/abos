{{--
    ⭐ টাকা কীভাবে নড়ল — পদ্ধতির সেগমেন্ট, আর কেবল সেই পদ্ধতির ঘর (মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে; নকশা:
    মালিকের-ফাইল/টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf, দফা #২ আর #৯)।

    ⓘ একটাই ব্লক তিন পর্দায়: হিসাবের রসিদ ও পরিশোধ ভাউচার, ক্রয়ের সরবরাহকারী-পরিশোধ, বিক্রয়ের আদায় (a5)। ঘরের নাম
    সবখানে এক, তাই সার্ভারের নিয়মও এক জায়গায় ([[MoneyMethod]])।

      নগদ          — কোনো ঘর নেই (চাইলে নোট গোনা, `counting`)
      MFS          — TrxID, চার্জ
      ব্যাংক ট্রান্সফার — মোড (BEFTN/NPSB/RTGS), রেফারেন্স/TrxID (পোস্টে বাধ্যতামূলক), মূল্য-তারিখ (ডিফল্ট আজ), চার্জ
      নগদ জমা      — জমা স্লিপ, মূল্য-তারিখ
      চেক          — নম্বর, তারিখ, ব্যাংক

    ⛔ যা নেই, ইচ্ছাকৃত: টাকা যিনি দিলেন তাঁর ব্যাংক, ব্রাঞ্চ, হিসাবধারী আর হিসাব নম্বর (ব্যাংকের স্টেটমেন্টেই থাকে), আর
    "চার্জটা কে দিয়েছে" বোতাম — চার্জ সবসময় আমাদের খরচ, চার্জের খাতে; নিচে এক লাইনে লেখা থাকে কোথায় যাবে। পুরনো
    ভাউচারে ঐ ঘরগুলোর মান থাকলে দেখানোর পাতায় থাকে, মোছা হয় না।

    ⛔ প্রতিটা পদ্ধতির ব্লক `x-if`, `x-show` নয় — লুকানো ব্লকের একই নামের খালি ঘরও জমা যেত, আর ভরা ঘরটা মুছে দিত
    ([[ui.money-movement]]-এর ১৮ সেপ্টেম্বরের শিক্ষা; [[EveryFormScreenAnswersForItselfTest]])।
--}}
@props([
    /* `in` — আমরা পাচ্ছি, `out` — আমরা দিচ্ছি। চার্জের লাইন আর আগাম চেকের কথা এটা ধরে। */
    'direction' => 'in',

    /* সম্পাদনায় খোলা কাগজ — ঘরগুলো এর মান নিয়ে বসে; নতুনে `null`, তখন `old()` */
    'record' => null,

    /* BEFTN · NPSB · RTGS — `mdm_transfer_modes` থেকে, id → নাম */
    'modes' => [],

    /*
     * কোন পদ্ধতিগুলো এই পর্দায় চলে। ⓘ ডিফল্ট পাঁচটা, নকশার ক্রমে। পুরনো কাগজে তালিকার বাইরের মান (যেমন কার্ড) থাকলে
     * সেটাও আসে — নইলে সম্পাদনায় পদ্ধতিটা নীরবে বদলে যেত।
     */
    'methods' => ['cash', 'mfs', 'transfer', 'deposit', 'cheque'],

    /* কাগজের টাকার ঘরের নাম — চার্জের লাইনে পুরো টাকাটা এখান থেকে পড়া হয় */
    'amountField' => 'amount',

    /* চার্জের ঘরের নাম — ভাউচারে `charge_amount`, আদায়ে `bank_charge` */
    'chargeField' => 'charge_amount',

    /* টাকার খাতের ঘর — খাতের ধরন (`data-kind`) বদলালে পদ্ধতিও সরে */
    'moneyField' => null,

    /* নগদে নোট গোনা — কেবল যে পর্দা নগদ গোনে (হিসাবের ভাউচার) */
    'counting' => false,

    /*
     * ⭐ নকশার সারি: "যে খাত থেকে | পদ্ধতি", তার নিচে প্রাপকের ব্যাংক, তার নিচে পদ্ধতির ঘর। ⓘ `inline` দিলে বাক্সটা নিজে কোনো
     * ঘর নেয় না (`contents`) — তার অংশগুলো হোস্টের ৪-কলামের গ্রিডে বসে, আর `order` দিয়ে সাজে: হোস্টের খাত `order-0`,
     * সেগমেন্ট `order-1` (দুই কলাম), হোস্টের প্রাপকের ব্যাংক `order-2`, পদ্ধতির ঘর `order-3`, চার্জের লাইন `order-4`।
     */
    'inline' => false,
])

@php
    $inward = $direction === 'in';
    $was = fn (string $field, $fallback = null) => old($field, $record?->{$field} ?? $fallback);
    $dateOf = fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');

    $chosen = (string) $was('instrument', 'cash');
    $shown = in_array($chosen, $methods, true) ? $methods : [...$methods, $chosen];

    /* ⓘ মূল্য-তারিখ ডিফল্ট আজ — নকশার নিয়ম; পুরনো কাগজে ফাঁকা থাকলে ফাঁকাই (আন্দাজে তারিখ বসানো হয় না) */
    $valueDate = $dateOf($was('value_date', $record === null ? today() : null));
    $charge = (string) $was($chargeField, '');
    $notes = \App\Modules\Accounts\Models\CashCount::DENOMINATIONS;

    /*
     * ⛔ পদ্ধতির ঘরের কলাম বাক্সের নিজের চওড়া ধরে, পর্দার চওড়া ধরে নয় — fe, ২২ অক্টোবর ২০২৬ (চূড়ান্ত ছবি, ১৩৬৬)।
     * খরচের ভাউচারে বাক্সটা অর্ধেক কলামে (~৫১০px), অথচ `lg:grid-cols-4` পর্দা দেখে চারটা ঘর এক সারিতে বসাত: TrxID
     * "NP26102104", মূল্য-তারিখ "22-10-2" — কাটা। ⭐ বাক্স নিজে container; ঘর চারটা কেবল ৪৮rem-এর বেশি জায়গায়, ২০rem-এর
     * বেশিতে দুইটা। ⚠️ rem এখানে মূলের ফন্ট ধরে, আর মূল ফন্ট বড় পর্দায় ২০px ([[app.css]] html) — তাই ২৮rem মানে ৫৬০px, খরচের
     * ৫১২px বাক্সে দুই কলামও আসত না (মাপা)। ⓘ `inline` (টাকা নেওয়া/দেওয়ার পর্দা) `contents` — container হতে পারে না, তাই
     * সেখানে আগের নিয়ম অক্ষত।
     */
    $fourUp = $inline ? 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' : 'grid gap-3 @xs:grid-cols-2 @3xl:grid-cols-4';
    $twoWide = $inline ? 'lg:col-span-2' : '@3xl:col-span-2';
    $threeUp = $inline ? 'grid gap-3 sm:grid-cols-3' : 'grid gap-3 @xs:grid-cols-2 @2xl:grid-cols-3';
@endphp

<div @class(['contents' => $inline, '@container flex flex-col gap-3' => ! $inline]) data-money-method
     x-data="moneyMethod({ method: @js($chosen), charge: @js($charge), chequeDate: @js($dateOf($was('instrument_date'))), amountField: @js($amountField), chargeField: @js($chargeField), moneyField: @js($moneyField ?? ''), direction: @js($direction), notes: @js((array) ($was('note_counts') ?? [])) })"
     data-line-in="{{ __('money.charge_line.in') }}"
     data-line-out="{{ __('money.charge_line.out') }}"
     data-head-bank="{{ __('money.charge_line.head_bank') }}"
     data-head-mfs="{{ __('money.charge_line.head_mfs') }}">

    {{-- ── পদ্ধতি: এক সারির সেগমেন্ট (নকশার "নগদ · MFS · ব্যাংক ট্রান্সফার · নগদ জমা · চেক") ── --}}
    {{-- ⛔ বাছা চিপের রং — fe, ২২ অক্টোবর ২০২৬: সাদা লেখা brand-600-এ বসত। গাঢ় থিমে brand-600 হালকা নীল (২.৬:১), আর বাছা
         চিপে মাউস রাখলে hover-এর প্রায়-সাদা জমিন জিতত (১.০৮:১) — লেখাটা মুছে যেত। ⭐ এখন brand-700 আর brand-ink: আলোয় অ্যাকসেন্টের
         ৭০০ নিজের কালির সাথে, গাঢ়ে অ্যাকসেন্টের গাঢ় ধাপ নিজের গাঢ় কালির সাথে — দুই জোড়াই ≥ ৪.৫:১ মাপা ([[AppearanceTest]]);
         আর বাছা চিপে hover রং বদলায় না। --}}
    <fieldset @class(['order-1 sm:col-span-2' => $inline])>
        <legend class="mb-1 block text-sm font-medium">{{ __('money.method.legend') }}</legend>
        <div class="inline-flex max-w-full overflow-hidden rounded-(--radius-field) border border-(--color-border)" role="radiogroup">
            @foreach ($shown as $way)
                <label class="cursor-pointer border-e border-(--color-border) last:border-e-0">
                    <input type="radio" name="instrument" value="{{ $way }}" class="peer sr-only"
                           data-method-chip="{{ $way }}" x-model="method" @checked($chosen === $way)>
                    <span class="block whitespace-nowrap px-2.5 py-2 text-sm text-(--color-ink-body) transition-colors
                                 hover:bg-(--color-surface-hover)
                                 peer-checked:bg-(--color-brand-700) peer-checked:font-medium peer-checked:text-(--color-brand-ink)
                                 peer-checked:hover:bg-(--color-brand-700)
                                 peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-(--color-brand-500)">
                        {{ __('money.method.'.$way) }}
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>

    {{-- ── নগদ: চাইলে নোট গোনা ── --}}
    @if ($counting)
        <template x-if="method === 'cash'">
            <div data-method-block="cash" @class(['rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-sunken) p-3', 'order-3 col-span-full' => $inline])>
                <div class="grid gap-x-5 gap-y-1 sm:grid-cols-2">
                    @foreach ($notes as $note)
                        <label class="grid grid-cols-[3.5rem_0.75rem_3.5rem_1fr] items-center gap-1.5 font-mono text-xs tabular-nums">
                            <span class="text-right text-(--color-ink-muted)">{{ number_format($note) }}</span>
                            <span class="text-center text-(--color-ink-faint)">×</span>
                            <input type="number" min="0" step="1" inputmode="numeric" name="note_counts[{{ $note }}]"
                                   x-model.number="notes[{{ $note }}]" value="{{ $was('note_counts')[$note] ?? '' }}"
                                   aria-label="{{ __('accounts::field.note_of', ['note' => number_format($note)]) }}"
                                   class="w-full rounded border border-(--color-border) bg-(--color-surface-card) px-1.5 py-0.5 text-right">
                            <span class="text-right text-(--color-ink-muted)" x-text="noteTotal({{ $note }})"></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-2 rounded px-2 py-1 text-xs" :class="countClass" x-text="countText"
                   data-agrees="{{ __('accounts::message.count_agrees') }}" data-differs="{{ __('accounts::message.count_differs') }}"></p>
            </div>
        </template>
    @endif

    {{-- ── MFS: TrxID আর চার্জ ── --}}
    <template x-if="method === 'mfs'">
        <div data-method-block="mfs" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <div class="{{ $twoWide }}">
                <x-ui.field name="instrument_no" :label="__('money.field.trx_id')" :value="$was('instrument_no')"
                            :hint="__('money.hint.reference_needed')" required />
            </div>
            <x-ui.field :name="$chargeField" type="number" step="0.01" min="0" numeric
                        :label="__('money.field.mfs_charge')" :value="$charge" x-model="charge" />
        </div>
    </template>

    {{-- ── ব্যাংক ট্রান্সফার: মোড · রেফারেন্স · মূল্য-তারিখ · চার্জ (নকশার এক সারি) ── --}}
    <template x-if="method === 'transfer'">
        <div data-method-block="transfer" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <x-ui.select name="transfer_mode_id" :label="__('money.field.transfer_mode')"
                         :options="$modes" :selected="$was('transfer_mode_id')" placeholder="—" />
            <x-ui.field name="instrument_no" :label="__('money.field.reference')" :value="$was('instrument_no')"
                        :hint="__('money.hint.reference_needed')" required />
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('money.field.value_date') }}</span>
                <x-ui.date name="value_date" :value="$valueDate" />
            </label>
            <x-ui.field :name="$chargeField" type="number" step="0.01" min="0" numeric
                        :label="__('money.field.bank_charge')" :value="$charge" x-model="charge" />
        </div>
    </template>

    {{-- ── ব্যাংকে নগদ জমা: জমা স্লিপ আর মূল্য-তারিখ ── --}}
    <template x-if="method === 'deposit'">
        <div data-method-block="deposit" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <x-ui.field name="deposit_slip_no" :label="__('money.field.deposit_slip')" :value="$was('deposit_slip_no')"
                        :hint="__('money.hint.reference_needed')" required />
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('money.field.value_date') }}</span>
                <x-ui.date name="value_date" :value="$valueDate" />
            </label>
            <x-ui.field :name="$chargeField" type="number" step="0.01" min="0" numeric
                        :label="__('money.field.bank_charge')" :value="$charge" x-model="charge" />
        </div>
    </template>

    {{-- ── চেক: নম্বর · তারিখ · ব্যাংক ── --}}
    <template x-if="method === 'cheque'">
        <div data-method-block="cheque" @class(['flex flex-col gap-2', 'order-3 col-span-full' => $inline])>
            <div class="{{ $threeUp }}">
                <x-ui.field name="instrument_no" :label="__('money.field.cheque_no')" :value="$was('instrument_no')" required />
                {{-- ⓘ দেশের তারিখের ঘর ([[ui.date]]), ব্রাউজারেরটা নয়; মান `chequeDate`-এ যায় আগাম চেকের কথার জন্য --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('money.field.cheque_date') }}</span>
                    <x-ui.date name="instrument_date" :value="$dateOf($was('instrument_date'))" bind-model="chequeDate" />
                </label>
                <x-ui.field name="from_bank" :label="__('money.field.cheque_bank')" :value="$was('from_bank')" />
            </div>
            <p class="rounded px-2 py-1 text-xs bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)"
               data-pdc-note x-show="postDated" x-cloak>{{ $inward ? __('money.pdc.in') : __('money.pdc.out') }}</p>
        </div>
    </template>

    {{-- ── চার্জ কোথায় যাবে — এক লাইনে (নকশা #৯) ── --}}
    <p data-charge-line x-show="hasCharge" x-cloak x-text="chargeLine"
       @class(['border-s-2 border-(--color-brand-500) bg-(--color-badge-info-bg) px-3 py-2 text-xs text-(--color-badge-info-ink)', 'order-4 col-span-full' => $inline])></p>
</div>
