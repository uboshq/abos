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

    /*
     * ⭐ স্লিপ/TrxID ঘরের নিচে তাৎক্ষণিক "আগে ব্যবহার হয়নি ✓" বা আগের পরিশোধের কথা (টাকা দেওয়ার মিল-তালিকা #৫)। ⓘ কেবল যে পর্দার
     * হোস্ট `slip` জানে ([[payBills]]); অন্য পর্দায় বন্ধ, তাই কিছুই আঁকা হয় না।
     */
    'slipCheck' => false,

    /*
     * ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe: SAP/Oracle-এর payment method → house bank)।
     * ⓘ `accounts` দিলে খাতের ঘর এই বাক্সেই, পদ্ধতির ঠিক পরে — পাতার ক্রম আর Tab-এর ক্রম দুটোই "পদ্ধতি → খাত → রেফারেন্স → চার্জ"।
     * প্রতিটা সারি `['id', 'label', 'kind']` (`kind` = খাতের `money_kind`)। ⛔ তালিকায় কেবল মিলে যাওয়া খাত — বাকিগুলো থাকেই না,
     * ধূসরও নয় (fe)। পদ্ধতি বদলালে তালিকা নতুন করে আঁকা হয় ([[money.js]] `fillAccounts`); আগের খাত মিললে থাকে, না মিললে ফাঁকা, কারণসহ;
     * একটাই খাত থাকলে নিজে বসে; একটাও না থাকলে তালিকার জায়গায় বাংলায় কারণ, আর জমার বোতাম বন্ধ।
     * ⓘ না দিলে আগের মতো: হোস্টের নিজের খাতের ঘর (`moneyField`), খাত বদলালে পদ্ধতি সরে (খরচের ভাউচার)।
     */
    'accounts' => null,

    /* খাতের ঘরের নাম — `from_account_id`, `to_account_id`, আদায়ে `account_id` */
    'accountName' => null,

    /* হোস্টের Alpine নাম — খাতের ঘর `x-model` দিয়ে হোস্টের এই মানে বাঁধা (স্থিতি, প্রিভিউ আর রেফারেন্সের উত্তর ওটাই দেখে) */
    'accountModel' => 'account',

    'accountLabel' => null,
    'accountSelected' => null,
    'accountHint' => null,
])

@php
    $inward = $direction === 'in';
    $was = fn (string $field, $fallback = null) => old($field, $record?->{$field} ?? $fallback);
    $dateOf = fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');

    $chosen = (string) $was('instrument', 'cash');

    /*
     * ⓘ খাতের তালিকা দিলে — নতুন কাগজে প্রথম পদ্ধতি বাছা খাতের ধরনে, নইলে যে পদ্ধতির খাত আছে ([[MoneyMethod::startWith()]]);
     * আগে থেকে বলা পদ্ধতি (পুরনো কাগজ, ভুল নিয়ে ফেরা) বদলায় না — তখন না-মেলা খাত সরে, কারণসহ।
     */
    $picking = is_array($accounts);
    $accountName ??= $moneyField;
    $accountList = $picking ? array_values(array_map(fn ($a) => ['id' => (string) $a['id'], 'label' => (string) $a['label'], 'kind' => (string) ($a['kind'] ?? '')], $accounts)) : [];
    $picked = $picking ? (string) old($accountName, $accountSelected ?? '') : '';
    $pickedRow = collect($accountList)->firstWhere('id', $picked);
    if ($picking && old('instrument') === null && blank($record?->instrument)) {
        $chosen = \App\Core\Support\MoneyMethod::startWith($chosen, $pickedRow['kind'] ?? null, $methods, array_column($accountList, 'kind'));
    }
    $matching = array_values(array_filter($accountList, fn ($a) => \App\Core\Support\MoneyMethod::fits($chosen, $a['kind'])));
    $cleared = '';
    if ($picking && $pickedRow !== null && ! \App\Core\Support\MoneyMethod::fits($chosen, $pickedRow['kind'])) {
        $cleared = __('money.account.cleared', ['account' => $pickedRow['label'], 'method' => __('money.method.'.$chosen)]);
        $picked = '';
    }
    if ($picking && $picked === '' && count($matching) === 1) {
        $picked = $matching[0]['id'];
    }
    $methodNames = collect([...$methods, $chosen])->unique()->mapWithKeys(fn ($m) => [$m => __('money.method.'.$m)])->all();

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
     x-data="moneyMethod({ method: @js($chosen), charge: @js($charge), chequeDate: @js($dateOf($was('instrument_date'))), amountField: @js($amountField), chargeField: @js($chargeField), moneyField: @js($picking ? '' : ($moneyField ?? '')), direction: @js($direction), notes: @js((array) ($was('note_counts') ?? [])), accounts: @js($picking ? $accountList : null), methodNames: @js($methodNames), cleared: @js($cleared) })"
     data-account-none-line="{{ __('money.account.none') }}"
     data-account-cleared-line="{{ __('money.account.cleared') }}"
     data-line-in="{{ __('money.charge_line.in') }}"
     data-line-out="{{ __('money.charge_line.out') }}"
     data-head-bank="{{ __('money.charge_line.head_bank') }}"
     data-head-mfs="{{ __('money.charge_line.head_mfs') }}">

    {{-- ── পদ্ধতি: এক সারির সেগমেন্ট (নকশার "নগদ · MFS · ব্যাংক ট্রান্সফার · নগদ জমা · চেক") ── --}}
    {{-- ⛔ বাছা চিপের রং — fe, ২২ অক্টোবর ২০২৬: সাদা লেখা brand-600-এ বসত। গাঢ় থিমে brand-600 হালকা নীল (২.৬:১), আর বাছা
         চিপে মাউস রাখলে hover-এর প্রায়-সাদা জমিন জিতত (১.০৮:১) — লেখাটা মুছে যেত। ⭐ এখন brand-700 আর brand-ink: আলোয় অ্যাকসেন্টের
         ৭০০ নিজের কালির সাথে, গাঢ়ে অ্যাকসেন্টের গাঢ় ধাপ নিজের গাঢ় কালির সাথে — দুই জোড়াই ≥ ৪.৫:১ মাপা ([[AppearanceTest]]);
         আর বাছা চিপে hover রং বদলায় না। --}}
    <fieldset @class(['order-1 sm:col-span-2' => $inline && ! $picking, 'order-0 sm:col-span-2' => $inline && $picking])>
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

    {{--
        ── খাত: পদ্ধতির ঠিক পরে, কেবল সেই পদ্ধতির খাত ──
        ⓘ সার্ভার চলতি পদ্ধতির খাতগুলোই আঁকে; পদ্ধতি বদলালে [[money.js]] `fillAccounts` তালিকা নতুন করে আঁকে আর `change` ছাড়ে, তাই
        হোস্টের `x-model` (স্থিতি, প্রিভিউ, রেফারেন্সের উত্তর) নিজের পথেই খবর পায়। ⛔ `x-for` নয়: select-এর `x-model` আর `x-for`-এর
        option একসাথে বসলে বাছা মান এক ঝলক হারায়।
    --}}
    @if ($picking)
        @php $fid = 'money-account-'.$accountName; $hasError = $errors->has($accountName); @endphp
        <div @class(['order-1 sm:col-span-2' => $inline]) data-money-account-row>
            <label for="{{ $fid }}" class="mb-1 block text-sm font-medium">
                {{ $accountLabel ?? __('money.screen.from_account') }}
                <span class="text-(--color-danger)" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('core.form.required') }})</span>
            </label>
            <select id="{{ $fid }}" name="{{ $accountName }}" data-money-account x-model="{{ $accountModel }}" required
                    x-show="hasAccounts" @if ($matching === []) style="display: none" @endif
                    @if ($hasError) aria-invalid="true" aria-describedby="{{ $fid }}-error" @endif
                    @class(['h-(--spacing-field) w-full rounded-(--radius-field) border bg-(--color-surface-card) px-3 text-sm',
                            'border-(--color-danger)' => $hasError, 'border-(--color-border)' => ! $hasError])>
                <option value="">&mdash;</option>
                @foreach ($matching as $row)
                    <option value="{{ $row['id'] }}" data-kind="{{ $row['kind'] }}" @selected($picked === $row['id'])>{{ $row['label'] }}</option>
                @endforeach
            </select>
            <p class="rounded-(--radius-field) border border-(--color-border) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)"
               data-account-none role="status" x-show="! hasAccounts" x-text="noAccountsLine"
               @if ($matching !== []) style="display: none" @endif>{{ $matching === [] ? __('money.account.none', ['method' => __('money.method.'.$chosen)]) : '' }}</p>
            <p class="mt-1 text-2xs text-(--color-badge-warning-ink)" data-account-cleared role="status" aria-live="polite"
               x-show="clearedLine !== ''" x-text="clearedLine" @if ($cleared === '') style="display: none" @endif>{{ $cleared }}</p>
            @if ($accountHint)
                <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ $accountHint }}</p>
            @endif
            @error($accountName)
                <span id="{{ $fid }}-error" class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
            @enderror
            {{ $accountNote ?? '' }}
        </div>
    @endif

    {{-- ── নগদ: চাইলে নোট গোনা ── --}}
    @if ($counting)
        <template x-if="method === 'cash'">
            {{--
                ⭐ ভাঁজ করা, ঐচ্ছিক — "▸ নোট গুনে মেলান — ঐচ্ছিক" (fe, ২৪ অক্টোবর ২০২৬; খরচের পরিকল্পনার একই ভাঁজ)। ⓘ নোট গোনা ক্যাশ
                কাউন্টারের কাজ, রসিদ-পরিশোধে রোজ লাগে না; খোলা থাকলে দশটা ঘর পর্দার অর্ধেক নিত। ⓘ `<details>`: বন্ধ থাকলেও ঘরগুলো একবারই
                আছে, তাই খুলে গুনে বন্ধ করলেও গোনা মান জমা যায় — একই নামের দুই ঘর নেই, তাই ফাঁকা ঘর ভরা ঘরকে মোছে না।
            --}}
            <details data-method-block="cash" data-notes-fold @class(['rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-sunken) p-3', 'order-3 col-span-full' => $inline])>
                <summary class="cursor-pointer text-sm font-medium">{{ __('money.counting.fold') }}</summary>
                <div class="mt-2 grid gap-x-5 gap-y-1 sm:grid-cols-2">
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
            </details>
        </template>
    @endif

    {{-- ── MFS: TrxID আর চার্জ ── --}}
    <template x-if="method === 'mfs'">
        <div data-method-block="mfs" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <div class="{{ $twoWide }}">
                <x-ui.field name="instrument_no" :label="__('money.field.trx_id')" :value="$was('instrument_no')"
                            :hint="__('money.hint.reference_needed')" required />
                @if ($slipCheck)
                    <p class="mt-1 text-2xs" data-slip-state role="status" aria-live="polite" x-show="slipShown" x-cloak
                       :class="slipUsed ? 'text-(--color-danger)' : 'text-(--color-success)'" x-text="slip.message"></p>
                @endif
            </div>
            <x-ui.field :name="$chargeField" type="money" model="charge" empty-shows="0.00"
                        :label="__('money.field.mfs_charge')" :value="$charge" />
        </div>
    </template>

    {{-- ── ব্যাংক ট্রান্সফার: মোড · রেফারেন্স · মূল্য-তারিখ · চার্জ (নকশার এক সারি) ── --}}
    <template x-if="method === 'transfer'">
        <div data-method-block="transfer" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <x-ui.select name="transfer_mode_id" :label="__('money.field.transfer_mode')"
                         :options="$modes" :selected="$was('transfer_mode_id')" placeholder="—" />
            <div>
                <x-ui.field name="instrument_no" :label="__('money.field.reference')" :value="$was('instrument_no')"
                            :hint="__('money.hint.reference_needed')" required />
                @if ($slipCheck)
                    <p class="mt-1 text-2xs" data-slip-state role="status" aria-live="polite" x-show="slipShown" x-cloak
                       :class="slipUsed ? 'text-(--color-danger)' : 'text-(--color-success)'" x-text="slip.message"></p>
                @endif
            </div>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('money.field.value_date') }}</span>
                <x-ui.date name="value_date" :value="$valueDate" />
            </label>
            <x-ui.field :name="$chargeField" type="money" model="charge" empty-shows="0.00"
                        :label="__('money.field.bank_charge')" :value="$charge" />
        </div>
    </template>

    {{-- ── ব্যাংকে নগদ জমা: জমা স্লিপ আর মূল্য-তারিখ ── --}}
    <template x-if="method === 'deposit'">
        <div data-method-block="deposit" @class([$fourUp, 'order-3 col-span-full' => $inline])>
            <div>
                <x-ui.field name="deposit_slip_no" :label="__('money.field.deposit_slip')" :value="$was('deposit_slip_no')"
                            :hint="__('money.hint.reference_needed')" required />
                {{-- ⓘ নগদ জমার স্লিপই ব্যাংকের রেফারেন্স ([[VoucherRequest]]) — তাই তারও চলতি উত্তর --}}
                @if ($slipCheck)
                    <p class="mt-1 text-2xs" data-slip-state role="status" aria-live="polite" x-show="slipShown" x-cloak
                       :class="slipUsed ? 'text-(--color-danger)' : 'text-(--color-success)'" x-text="slip.message"></p>
                @endif
            </div>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('money.field.value_date') }}</span>
                <x-ui.date name="value_date" :value="$valueDate" />
            </label>
            <x-ui.field :name="$chargeField" type="money" model="charge" empty-shows="0.00"
                        :label="__('money.field.bank_charge')" :value="$charge" />
        </div>
    </template>

    {{-- ── চেক: নম্বর · তারিখ · ব্যাংক ── --}}
    <template x-if="method === 'cheque'">
        <div data-method-block="cheque" @class(['flex flex-col gap-2', 'order-3 col-span-full' => $inline])>
            <div class="{{ $threeUp }}">
                <div>
                    <x-ui.field name="instrument_no" :label="__('money.field.cheque_no')" :value="$was('instrument_no')" required />
                    @if ($slipCheck)
                        <p class="mt-1 text-2xs" data-slip-state role="status" aria-live="polite" x-show="slipShown" x-cloak
                           :class="slipUsed ? 'text-(--color-danger)' : 'text-(--color-success)'" x-text="slip.message"></p>
                    @endif
                </div>
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
