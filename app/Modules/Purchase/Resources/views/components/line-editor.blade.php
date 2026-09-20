{{--
    লাইনের সম্পাদক — তিনটা ফর্মেই একই।

    Alpine দিয়ে সারি যোগ-বিয়োগ হয়, আর প্রতিটা সারির টাকা তখনই গোনা হয়।
    সার্ভারই শেষ কথা (CalculatesLineTotals), কিন্তু ব্যবহারকারী সেভ করার
    আগেই মোটটা দেখতে পান — নাহলে ভুল দর বসিয়ে সেভ করে তারপর বুঝতেন।

    পরিমাণের ঘরের নামটা ডকুমেন্টভেদে আলাদা (ordered_qty · received_qty ·
    qty), তাই সেটা প্রপ হিসেবে আসে।
--}}
@props([
    'products',
    'lines' => [],
    'qtyField' => 'qty',
    'linkField' => null,
    'linkOptions' => [],
    'showDiscount' => true,
    'showSalesPrice' => false,

    /*
     * ফ্রি পরিমাণের ঘর আঁকা হবে কি না।
     *
     * ── ⛔ কেন এটা লাগল, ১৮ সেপ্টেম্বর ২০২৬ ─────────────
     * মালিক বললেন *"free item gor nai"* — আর ঠিকই বললেন।
     *
     * ⓘ `free_qty` কলাম আছে (`pur_bill_lines`), যাচাইয়ে আছে,
     * সেবা সেভও করে, আর মাল বুঝে নেওয়ার সেবা ওটা পড়ে
     * স্টকে বসায়। ⛔ কেবল এই কম্পোনেন্ট ঘরটা কখনো আঁকত না —
     * কাজটা হয়েছিল, দরজাটা কেউ বসায়নি।
     *
     * ⚠️ ডিফল্ট `false` — ক্রয় **আদেশে** ফ্রির কলাম নেই
     * (`pur_order_lines`), তাই ভুলে গেলে ঘরটা **থাকে না**, আর
     * সেটাই নিরাপদ দিক — ঠিক `lots` প্রপটার মতো।
     */
    'showFree' => false,

    /**
     * এই কাগজটা কি লট রাখতে পারে।
     *
     * ── কেন এটা একটা প্রপ, কম্পোনেন্টের নিজের সিদ্ধান্ত নয় ───────────
     * উত্তরটা নির্ভর করে **কোন টেবিলে সারিটা বসবে** তার উপর, আর
     * কম্পোনেন্ট সেটা জানে না:
     *
     *   pur_receipt_lines · pur_bill_lines   batch_no · expiry_date · mrp আছে
     *   pur_order_lines                      ⛔ একটাও নেই
     *
     * ⚠️ ডিফল্ট `false`, আর সেটাই নিরাপদ দিক: ভুলে গেলে ঘরটা **থাকে
     * না**। উল্টো ডিফল্টে ভুলে গেলে ঘরটা **থাকত অথচ সেভ হত না** — আর
     * সেটাই মৃত ঘর, যা এই রিপো টুলবারের ছয়টা বোতামে একবার দেখেছে।
     *
     * ⓘ প্রথমে এটা প্রপ ছাড়াই লেখা হয়েছিল, আর তখন ক্রয় **আদেশের**
     * পর্দাতেও ঘর তিনটা বসে গিয়েছিল — যেখানে লট জানার কথাই নয় (মাল
     * তো এখনো আসেনি), আর কলামও নেই। ⭐ ধরা পড়েছে ব্রাউজারে খুলে,
     * কোড পড়ে নয়।
     */
    'lots' => false,
])

@php
    // প্যাকের তালিকা — কন্ট্রোল প্যানেলের সুইচের পেছনে (বিক্রয়ের
    // সম্পাদকেও হুবহু এই নিয়ম; দুই কাগজে দুই রকম হলে একই পণ্য
    // কেনার সময় বাক্সে আর বেচার সময় পিসে লিখতে হত)
    $packs = app(App\Core\Services\SettingsService::class)->enabled('inventory.pack_entry_enabled')
        ? app(App\Modules\Inventory\Services\PackConversion::class)->optionsFor($products)
        : [];

    /*
     * ⭐ কোন প্যাকটা আগে থেকে বসবে — মালিকের বাছাই, ধাপ ৫, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ পণ্যের ফর্মের রেডিও ("কেনায়") যা বলে, কেবল সেটাই — গুদামে কেনা হয়
     * কার্টনে, দোকানে বেচা হয় পিসে, তাই দুই কাগজে দুই ডিফল্ট। ⚠️ কেউ কিছু না
     * বাছলে খালি, আর তখন আগের মতোই পণ্যের নিজের একক।
     */
    $packDefaults = $packs === []
        ? []
        : app(App\Modules\Inventory\Services\PackConversion::class)->defaultsFor($products, 'purchase');

    /*
     * লট ধরা পণ্যগুলোর আইডি — প্যাকের তালিকার মতোই।
     *
     * ── কেন ঘরগুলো শর্তসাপেক্ষে ─────────────────────────────────────
     * ডিপোর চাল, ডাল আর সাবানে লট নেই আর কোনোদিন হবেও না। সবসময়
     * দেখালে ওই পর্দাগুলোর প্রতিটা লাইনে তিনটা খালি ঘর পড়ে থাকত, আর
     * মানুষ ওগুলোকে "ঐচ্ছিক আবর্জনা" পড়তে শিখত — তারপর ওষুধের লাইনেও
     * এড়িয়ে যেত।
     *
     * ⓘ তালিকা খালি হলে কলাম তিনটা রেন্ডারই হয় না; ভরা থাকলে কলাম
     * থাকে, কিন্তু ঘরগুলো কেবল সেই সারিতে দেখা যায় যার পণ্য লট ধরে।
     */
    $lotProducts = collect($products)->filter(fn ($p) => (bool) $p->track_batch)->pluck('id')->values();
@endphp

{{--
    দাম · markup · margin — তিনটা জ্যান্ত বাক্স, চারটা সংখ্যা।

    ── কেন এই ব্যাখ্যাটা x-data-র ভেতরে নেই ────────────────────────────
    প্রথমে ছিল, আর তাতে পুরো ক্রয় মডিউল অচল হয়ে গিয়েছিল। x-data একটা
    HTML অ্যাট্রিবিউট, তার সীমানা `"` চিহ্নে। মন্তব্যের ভেতরে সাধারণ
    উদ্ধৃতি লেখা ছিল ("৪০%"), আর ব্রাউজার ওখানেই অ্যাট্রিবিউটটা শেষ ধরে
    নেয় — বাকিটা এলোমেলো HTML হয়ে যায়, Alpine কিছুই পড়তে পারে না, আর
    কনসোলে আসে `rows is not defined`।

    ফল: আদেশ, চালান আর বিল — তিনটা পর্দাতেই "+ লাইন যোগ করুন" নিষ্ক্রিয়,
    অর্থাৎ একটাও ক্রয় করা যায় না। ধরা পড়েছে ব্রাউজারে ক্লিক করে; কোনো
    টেস্ট এটা ধরেনি, কারণ HTML ঠিকই ২০০ ফেরত দিচ্ছিল।

    Blade মন্তব্য রেন্ডার হওয়া HTML-এ পৌঁছায়ই না, তাই এখানে যা খুশি
    লেখা যায়।

    ── markup আর margin এক জিনিস নয় ────────────────────────────────────
    markup মাপা হয় খরচের ওপর, margin দামের ওপর। ১০০-তে কিনে ১৫০-তে বেচা
    মানে ৫০% markup, কিন্তু ৩৩.৩% margin। যে ডিপো "৪০%" বলতে margin
    বোঝে আর পায় markup, সে প্রতিটা লাইনেই কম দামে বেচে — সারা বছর, আর
    বছরশেষে কেউ ধরতে পারে না কেন কম পড়ল। তাই দুইটা বাক্সই থাকে।

    ── কোনটা স্থির থাকে, তা নির্ভর করে শেষে কী বলা হয়েছিল ──────────────
    markup বা margin লিখলে মানুষটা একটা নীতি বলেছেন — দর বদলালে দামটা
    নতুন দর ধরে বসবে। দাম লিখলে তিনি একটা দাম বলেছেন, সচরাচর প্যাকেটে
    ছাপা বা ডিলারের সাথে ঠিক করা — দর বদলালেও দামটা টেকে, বদলায় margin।

    যেটাতে কার্সর আছে সেটা ছোঁয়া হয় না, আর কোনোটা না ছোঁয়া পর্যন্ত
    কিছুই বসে না। পুরো নিয়মটা resources/js/pricing.js-এ, তার ১৪টা
    পরীক্ষা সহ।
--}}
<div x-data="purchaseLineEditor({
                 rows: @js($lines),
                 packs: @js($packs),
                 packDefaults: @js($packDefaults),
                 lots: @js($lotProducts),
               })">

    <div class="table-responsive">
        <table class="ui-lines table-cards w-full text-sm">
            <thead>
                <tr>
                    <th class="text-start">{{ __('purchase::field.product') }}</th>
                    @if ($linkField)
                        <th class="text-start">{{ __('purchase::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}</th>
                    @endif
                    <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                    @if ($showFree)
                        <th class="text-end">{{ __('purchase::field.free_qty') }}</th>
                    @endif
                    @if ($packs !== [])
                        <th class="text-start">{{ __('purchase::field.unit') }}</th>
                    @endif
                    @if ($lots && $lotProducts->isNotEmpty())
                        <th class="text-start">{{ __('inventory::field.batch_no') }}</th>
                        <th class="text-start">{{ __('inventory::field.expiry_date') }}</th>
                        <th class="text-end">{{ __('inventory::field.mrp') }}</th>
                    @endif

                    <th class="text-end">{{ __('purchase::field.rate') }}</th>
                    @if ($showSalesPrice)
                        <th class="text-end">{{ __('purchase::field.sales_price') }}</th>
                        <th class="text-end">{{ __('purchase::field.markup') }}</th>
                        <th class="text-end">{{ __('purchase::field.margin') }}</th>
                    @endif
                    @if ($showDiscount)
                        <th class="text-end">{{ __('purchase::field.discount') }}</th>
                        <th class="text-end">{{ __('purchase::field.tax') }}</th>
                    @endif
                    <th class="text-end">{{ __('purchase::field.amount') }}</th>
                    <th><span class="sr-only">{{ __('purchase::action.remove_line') }}</span></th>
                </tr>
            </thead>

            <tbody>
                <template x-for="(row, i) in rows" :key="i">
                    <tr class="border-b border-(--color-border)">
                        <td class="cell-input" data-label="{{ __('purchase::field.product') }}">
                            <select :name="'lines[' + (i) + '][product_id]'" x-model="row.product_id" required
                                    @change="row.unit_id = defaultUnit(row.product_id)"
                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2">
                                <option value="">-</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name() }}</option>
                                @endforeach
                            </select>
                        </td>

                        @if ($linkField)
                            <td class="cell-input" data-label="{{ __('purchase::field.'.($linkField === 'purchase_order_line_id' ? 'order' : 'receipt')) }}">
                                <select :name="'lines[' + (i) + '][{{ $linkField }}]'" x-model="row.link"
                                        class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">-</option>
                                    @foreach ($linkOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif

                        <td class="cell-input" data-label="{{ __('purchase::field.quantity') }}">
                            <input type="number" step="0.01" inputmode="decimal" required
                                   :name="'lines[' + (i) + '][{{ $qtyField }}]'" x-model="row.qty"
                                   class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        {{-- ⓘ ফ্রি পরিমাণ — দামে যোগ হয় না, গুদামে ঠিকই ঢোকে।
                             ⚠️ তাই ঘরটা `required` নয় আর অঙ্কেও হাত দেয় না। --}}
                        @if ($showFree)
                            <td class="cell-input" data-label="{{ __('purchase::field.free_qty') }}">
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       :name="'lines[' + (i) + '][free_qty]'" x-model="row.free_qty"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        @if ($packs !== [])
                            {{--
                                একক — কেবল যে পণ্যের একাধিক প্যাক আছে তার সারিতে।

                                ফাঁকা মানে পণ্যের নিজের একক, অর্থাৎ আগের মতোই।
                                পণ্য বদলালে মুছে যায়: আগের পণ্যের "বাক্স" নতুন
                                পণ্যের সিঁড়িতে না-ও থাকতে পারে।

                                দর, বিক্রয়মূল্য আর markup সবই এন্ট্রির এককে
                                লেখা হয়; সার্ভার তিনটাকেই একসাথে নামায়, তাই
                                পর্দার markup আর খাতার markup এক থাকে।
                            --}}
                            <td class="cell-input" data-label="{{ __('purchase::field.unit') }}">
                                <select :name="'lines[' + (i) + '][unit_id]'" x-model="row.unit_id"
                                        x-show="unitsFor(row).length > 0"
                                        class="h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">-</option>
                                    <template x-for="unit in unitsFor(row)" :key="unit.id">
                                        <option :value="unit.id" x-text="unit.label"></option>
                                    </template>
                                </select>
                            </td>
                        @endif

                        @if ($lots && $lotProducts->isNotEmpty())
                            {{--
                                লট · মেয়াদ · MRP — কেবল লট ধরা পণ্যের সারিতে।

                                ── কেন এই তিনটা একসাথে ────────────────────────────
                                লট নম্বরটা [[BatchService::receive]]-এ **বাধ্যতামূলক**,
                                আর ওটা ছাড়া মালটা ঢোকেই না। মেয়াদ ও MRP ঐচ্ছিক,
                                কিন্তু একই মুহূর্তে ছাড়া আর কখনো জানা যায় না —
                                কার্টনটা তখন হাতে, পরে আর নয়।

                                ⭐ ৫ সেপ্টেম্বর ২০২৬ — এখানে আগে কাঁচা
                                `<input type="date">` ছিল, আর পাশে লেখা ছিল কেন:
                                `x-ui.date` নামটা স্থির প্রপ হিসেবে নিত, অথচ এখানে
                                নামটা সারির ক্রম ধরে বাঁধা (`lines[${i}][…]`)। ⛔ ফল:
                                একমাত্র এই ঘরটাতেই তারিখ ব্রাউজারের ছকে আঁকা হত,
                                কোম্পানির ছকে নয়।

                                ⓘ সমাধানটা ওই মন্তব্যেই লেখা ছিল — কম্পোনেন্টকে
                                বাঁধা নাম নিতে শেখানো। সেটা করা হয়েছে
                                (`bind-name` · `bind-iso` · `bind-model`), তাই ফাঁকটা
                                আর নেই।

                                ⓘ সার্ভারে যায় ISO-ই, আগের মতোই — বদলেছে কেবল
                                দেখাটা।
                            --}}
                            <td class="cell-input" data-label="{{ __('inventory::field.batch_no') }}">
                                <input type="text" maxlength="60"
                                       x-show="tracksLot(row)"
                                       :required="tracksLot(row)"
                                       :name="'lines[' + (i) + '][batch_no]'" x-model="row.batch_no"
                                       class="h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2">
                            </td>

                            <td class="cell-input" data-label="{{ __('inventory::field.expiry_date') }}">
                                {{-- ⚠️ x-show মোড়কের উপরে: কম্পোনেন্টের `$attributes`
                                     ভেতরের লেখার ঘরে বসে, বাইরের `<div>`-এ নয় —
                                     সরাসরি দিলে ঘরটা লুকাত, বাক্সটা নয়। --}}
                                <div x-show="tracksLot(row)" class="w-full sm:w-36">
                                    <x-ui.date name="expiry_date"
                                               bind-name="`lines[${i}][expiry_date]`"
                                               bind-iso="row.expiry_date"
                                               bind-model="row.expiry_date" />
                                </div>
                            </td>

                            <td class="cell-input" data-label="{{ __('inventory::field.mrp') }}">
                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                       x-show="tracksLot(row)"
                                       :name="'lines[' + (i) + '][mrp]'" x-model="row.mrp"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        <td class="cell-input" data-label="{{ __('purchase::field.rate') }}">
                            <input type="number" step="0.0001" inputmode="decimal" required
                                   :name="'lines[' + (i) + '][rate]'" x-model="row.rate"
                                   @input="priced(row, 'rate')"
                                   class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2 text-end">
                        </td>

                        @if ($showSalesPrice)
                            {{--
                                বিক্রয়মূল্যের ঘরটাই একমাত্র যেটা সার্ভারে
                                যায়। markup ও margin-এর name নেই — ওরা
                                দুইটা জানালা, সংরক্ষিত তথ্য নয়। একই
                                জিনিস দুই জায়গায় জমা রাখলে একদিন আলাদা
                                হবেই, আর তখন কোনটা সত্যি বলার উপায় থাকে না।
                            --}}
                            <td class="cell-input" data-label="{{ __('purchase::field.sales_price') }}">
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       :name="'lines[' + (i) + '][sales_price]'" x-model="row.sales_price"
                                       @input="priced(row, 'sales_price')"
                                       class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="cell-input" data-label="{{ __('purchase::field.markup') }}">
                                {{--
                                    ⛔ `step="any"`, `0.01` নয় — ১৮ সেপ্টেম্বর ২০২৬।

                                    মালিক পর্দার ছবি পাঠালেন: markup ঘরে `4.0042`, আর
                                    ব্রাউজার বলছে *"The two nearest valid values are 4 and
                                    4.01"* — ফর্মটা সেভই হত না।

                                    ⓘ অঙ্কটা ভুল নয়। [[resources/js/pricing]] ইচ্ছাকৃতভাবে
                                    **চার দশমিক** রাখে, আর কারণটা ওই ফাইলে মাপা:
                                    দুই দশমিকে বসালে পরে দর বদলালে দাম তিনশো টাকা
                                    পর্যন্ত কম বসত — নীরবে।

                                    ⚠️ আর ঘর দুইটার `name`ই নেই — ওগুলো সার্ভারে
                                    যায়ই না। ⛔ যে ঘর তথ্য পাঠায় না, সে তথ্য সংরক্ষণ
                                    আটকাতেও পারে না — এটাই আসল ভুলটা ছিল।
                                --}}
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="row.markup" @input="priced(row, 'markup')"
                                       class="num h-(--spacing-field-compact) w-full sm:w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="cell-input" data-label="{{ __('purchase::field.margin') }}">
                                <input type="number" step="any" inputmode="decimal"
                                       x-model="row.margin" @input="priced(row, 'margin')"
                                       class="num h-(--spacing-field-compact) w-full sm:w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        @if ($showDiscount)
                            <td class="cell-input" data-label="{{ __('purchase::field.discount') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="'lines[' + (i) + '][discount]'" x-model="row.discount"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                            <td class="cell-input" data-label="{{ __('purchase::field.tax') }}">
                                <input type="number" step="0.01" inputmode="decimal"
                                       :name="'lines[' + (i) + '][tax]'" x-model="row.tax"
                                       class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-card) px-2 text-end">
                            </td>
                        @endif

                        <td class="num cell" data-label="{{ __('purchase::field.amount') }}"
                            x-text="amount(row).toFixed(2)"></td>

                        <td class="cell-input text-end">
                            <button type="button" @click="remove(i)"
                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                           hover:bg-(--color-surface-hover)">
                                &times;<span class="sr-only">{{ __('purchase::action.remove_line') }}</span>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>

            <tfoot>
                <tr>
                    <td class="cell text-end font-medium"
                        colspan="{{ ($showDiscount ? 5 : 3) + ($linkField ? 1 : 0) + ($showSalesPrice ? 3 : 0) + ($packs !== [] ? 1 : 0) }}">
                        {{ __('purchase::field.total') }}
                    </td>
                    <td class="num cell font-semibold" x-text="total.toFixed(2)"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{--
        ⭐ দর না লিখলে পর্দা কেন চুপ, সেটা বলে দেওয়া — ১৮ সেপ্টেম্বর ২০২৬।

        ── ⛔ কী ঘটেছিল ────────────────────────────────────────────────
        মালিক বিক্রয়মূল্য লিখলেন, markup ও margin খালিই রইল, আর তিনি
        জিজ্ঞেস করলেন *"egulo te auto hoto ekhon hocche na keno?"*।

        ⓘ অঙ্ক ভাঙেনি — markup মাপা হয় **ক্রয়দরের উপর**, আর দর খালি
        থাকলে ভাগটাই অসম্ভব ([[resources/js/pricing]] তখন ইচ্ছাকৃতভাবে
        কিছুই বসায় না, কারণ মনগড়া সংখ্যা বসানোর চেয়ে চুপ থাকা ভালো)।

        ⚠️ কিন্তু **চুপ থাকাটা কারণ ব্যাখ্যা করে না**। ব্যবহারকারী ভাবেন
        জিনিসটা ভেঙে গেছে — মালিক নিজেই তাই ভেবেছিলেন। ⓘ তাই এখন পর্দা
        নিজেই বলে দেয় কী লাগবে।
    --}}
    <p x-show="needsRate" x-cloak
       class="mt-2 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
              text-xs text-(--color-badge-pending-ink)">
        {{ __('purchase::message.rate_first') }}
    </p>

    <button type="button" @click="add()"
            class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                   transition-colors hover:bg-(--color-surface-hover)">
        + {{ __('purchase::action.add_line') }}
    </button>
</div>
