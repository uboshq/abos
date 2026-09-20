{{--
    নতুন ব্যাংক সুবিধা — নিজের পাতা (১৯ সেপ্টেম্বর ২০২৬ পর্যন্ত এটা তালিকার উপরে বসত)।

    ⓘ তালিকা আর নবায়নের সতর্কতা [[bank-facility/index]]-এ। সংরক্ষণ হলে
    পর্দা সুবিধাটার নিজের পাতায় যায়।

    ⓘ `drawingPower` আর `kind`-এর Alpine অপরিবর্তিত সরানো — নিবন্ধন
    `resources/js`-এ, আর `app.js` খোলসই (`x-layouts.app`) টানে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::message.facility_new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::message.facility_new')"
                          :subtitle="__('finance::menu.bank_facility')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-badge-danger-bg px-3 py-2 text-sm text-badge-danger-ink">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- নতুন সুবিধা --}}
    <section data-boxed
             class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="font-semibold">{{ __('finance::message.facility_new') }}</h2>

        {{--
            ⚠️ ধরন বাছলে যে ঘরগুলো লাগে সেগুলোই দেখানো হয়।

            ⛔ পাঁচ ধরনের সব ঘর একসাথে দেখালে ব্যবহারকারী ভাবতেন
            সবগুলোই ভরতে হবে — আর গ্যারান্টিতে "কিস্তির সংখ্যা" ঘরটা
            দেখলে একটা সংখ্যা বসিয়ে দিতেন, যা অর্থহীন।
        --}}

        <form method="POST" enctype="multipart/form-data" action="{{ route('finance.bank_facility.store') }}"
              x-data="{ kind: '{{ old('kind', \App\Modules\Finance\Models\BankFacility::CC) }}' }"
              class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.select name="kind" :label="__('finance::field.facility_kind')"
                         x-model="kind"
                         :options="collect(\App\Modules\Finance\Models\BankFacility::KINDS)
                             ->mapWithKeys(fn (string $k) => [$k => __('finance::field.facility_' . $k)])"
                         :selected="old('kind', \App\Modules\Finance\Models\BankFacility::CC)" required />

            {{-- ⭐ ব্যাংকটা এখন তালিকা থেকে — ২০ সেপ্টেম্বর ২০২৬।

                 ⓘ আগে ঘরটা মুক্ত লেখা ছিল, আর একই ব্যাংক তিন বানানে বসত
                 ("IBBL", "Islami Bank", "Islami Bank Bangladesh Ltd.")।
                 ⚠️ তখন "এই ব্যাংকে আমাদের মোট কত" প্রশ্নের উত্তরই বের করা
                 যেত না। তালিকায় না থাকলে "+" দিয়ে এখানেই যোগ করা যায়,
                 এক জমায় ([[App\Modules\Finance\Services\InstitutionService::resolve]])। --}}
            @include('finance::components.institution-picker', [
                'institutions' => $institutions,
                'selected' => old('institution_id'),
                'label' => __('finance::field.bank'),
            ])
            <x-ui.field name="branch_name" :label="__('finance::field.branch_name')" :value="old('branch_name')" />
            <x-ui.field name="sanction_no" :label="__('finance::field.sanction_no')" :value="old('sanction_no')" />

            <x-ui.field name="sanctioned_on" type="date" :label="__('finance::field.sanctioned_on')"
                        :value="old('sanctioned_on', now()->format('Y-m-d'))" required />

            <x-ui.field name="limit_amount" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.limit_amount')"
                        :value="old('limit_amount')" required numeric />

            <x-ui.field name="interest_rate" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.interest_rate')"
                        :value="old('interest_rate', '0')" numeric />

            <x-ui.field name="renews_on" type="date" :label="__('finance::field.renews_on')"
                        :value="old('renews_on')" />

            {{-- CC — স্টকই আজকের সীমা ঠিক করে --}}
            {{-- ⭐ ড্রয়িং পাওয়ার ও ব্যবহার — নমুনার নিজস্ব বাক্স।

                 ── ⛔ কেন ঘরগুলো আলাদা বাক্সে, ছড়িয়ে নয় ────────────────
                 ⚠️ দোকানদার প্রায়ই ভাবেন মঞ্জুরিকৃত সীমাটাই তাঁর টাকা।
                 ⛔ নয় — CC-তে স্টক ঠিক করে কত তোলা যাবে, আর স্টক কমলে
                 সীমাও কমে। ⓘ ঘরগুলো একসাথে না দেখালে সম্পর্কটা চোখে
                 পড়ে না, আর টের পাওয়া যায় চেক ফেরত এলে। --}}
            <template x-if="kind === 'cc'">
                <fieldset x-data="drawingPower"
                          class="sm:col-span-2 xl:col-span-4 rounded-(--radius-card)
                                 border border-(--color-border) bg-(--color-surface-app) p-3">

                    <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                        {{ __('finance::field.drawing_power_box') }}
                    </legend>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-ui.select name="money_account_id" :label="__('finance::field.facility_account')"
                                 :options="$moneyAccounts->mapWithKeys(fn ($a) => [$a->id => $a->code . ' · ' . $a->name()])"
                                 :selected="old('money_account_id')" />

                    <x-ui.field name="stock_value" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.stock_value')"
                                :value="old('stock_value')" numeric
                                x-model.number="stock" />

                    <x-ui.field name="margin_percent" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.margin_percent')"
                                :value="old('margin_percent', '30')" numeric
                                x-model.number="margin" />

                    {{-- ⭐ শেষ স্টেটমেন্ট কবের — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ হলো।

                         ⛔ কলামটা ছিল, পর্দায় ছিল না। ⚠️ আর এই তারিখটাই
                         ড্রয়িং পাওয়ারের **বয়স** বলে: স্টকের অঙ্ক তিন মাসের
                         পুরনো হলে ঐ সীমাটাও তিন মাসের পুরনো, অথচ পর্দায়
                         সংখ্যাটা আজকের মতোই ঝকঝকে দেখায়।

                         ⓘ তারিখ ছাড়া কেউ জিজ্ঞেস করত না *"এই হিসাবটা
                         কবেকার"* — আর চেক ফেরত আসার দিন ওটাই প্রথম প্রশ্ন। --}}
                    <x-ui.field name="last_statement_on" type="date"
                                :label="__('finance::field.last_statement_on')"
                                :value="old('last_statement_on')" />

                    {{-- ⭐ আজ ব্যবহৃত — নমুনার ঘর, ১৫ সেপ্টেম্বর ২০২৬।

                         ⛔ এই ঘরটা নিয়ে আমার আপত্তি ছিল, আর আপত্তিটা
                         এখনো সত্যি: CC-তে আসল ব্যবহৃত অঙ্ক হলো **ব্যাংক
                         হিসাবের ঋণাত্মক ব্যালান্স**, আর সেটা খতিয়ানে
                         থাকে। ⚠️ এখানে হাতে লেখা সংখ্যা খতিয়ানের সাথে
                         এক থাকবে না।

                         ⓘ তাই নামটা `opening_drawn` — **ব্যবস্থায় তোলার
                         দিনের ছবি**, চলতি অঙ্ক নয়। ⭐ এরপর থেকে "এখনো
                         কত তোলা যাবে" প্রশ্নের উত্তর দেয় খতিয়ান। --}}
                    <x-ui.field name="opening_drawn" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.opening_drawn')"
                                :value="old('opening_drawn', 0)" numeric
                                x-model.number="drawn" />
                    </div>

                    {{-- ⭐ এখনো তোলা যাবে — নমুনার গণনা করা লাইন।

                         ⓘ অঙ্কটা স্টক×(১−মার্জিন) থেকে ব্যবহৃত বাদ।
                         ⛔ সংখ্যাটা এখানে কেবল দেখানো — সংরক্ষণ হয় না।
                         আসল হিসাব খতিয়ানের; এটা টাইপ করার সময়ের সাহায্য। --}}
                    <p class="mt-3 flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-sm font-medium">{{ __('finance::field.still_drawable') }}</span>
                        <span class="text-xl font-semibold tabular-nums"
                              x-text="drawable"></span>
                    </p>

                    <p class="mt-1 text-2xs text-(--color-ink-muted)"
                       x-text="sum"></p>

                    {{-- ⚠️ সীমা ছাড়ালে চুপ করে থাকা যায় না — ব্যাংক ঐ দিনই
                         দণ্ডসুদ বসায়, আর সেটা ধরা পড়ে মাস শেষে, অনেক দেরিতে। --}}
                    <p class="mt-2 rounded-(--radius-field) px-3 py-2 text-sm"
                       x-bind:class="drawn > stock * (1 - margin / 100)
                           ? 'bg-badge-danger-bg text-badge-danger-ink'
                           : 'bg-badge-success-bg text-badge-success-ink'"
                       x-text="drawn > stock * (1 - margin / 100)
                           ? @js(__('finance::message.over_the_limit'))
                           : @js(__('finance::message.within_the_limit'))"></p>
                </fieldset>
            </template>

            {{-- মেয়াদি ও লিজ — কিস্তি --}}
            <template x-if="kind === 'term' || kind === 'lease'">
                <div class="contents">
                    <x-ui.field name="instalments" type="number" inputmode="numeric"
                                :label="__('finance::field.instalments')"
                                :value="old('instalments')" numeric />

                    <x-ui.field name="instalment_amount" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.instalment_amount')"
                                :value="old('instalment_amount')" numeric />
                </div>
            </template>

            <template x-if="kind === 'lease'">
                <x-ui.field name="down_payment" type="number" step="0.01" inputmode="decimal"
                            :label="__('finance::field.down_payment')"
                            :value="old('down_payment')" numeric />
            </template>

            {{-- LTR ও গ্যারান্টি — মার্জিন --}}
            <template x-if="kind === 'ltr' || kind === 'bg'">
                <x-ui.field name="margin_percent" type="number" step="0.01" inputmode="decimal"
                            :label="__('finance::field.margin_percent')"
                            :value="old('margin_percent', '15')" numeric />
            </template>

            {{-- ⚠️ গ্যারান্টিতে দায়ের খাত চাওয়া হয় না — ওটা দায় নয় --}}
            <template x-if="kind !== 'cc' && kind !== 'bg'">
                <x-ui.select name="liability_account_id" :label="__('finance::field.liability_account')"
                             :options="$liabilityAccounts->mapWithKeys(fn ($a) => [$a->id => $a->code . ' · ' . $a->name()])"
                             :selected="old('liability_account_id')" />
            </template>

            {{-- ⭐ জামানত ও শর্ত — নমুনায় ভাঁজ করা।

                 ⓘ রোজকার কাজে এই ঘরগুলো একবারই ভরা হয়, খোলার দিন।
                 ⛔ খোলা রাখলে পর্দাটা লম্বা হয়ে যেত আর যে তিনটা ঘর
                 সত্যিই রোজ লাগে সেগুলো নিচে হারাত। --}}
            <details class="sm:col-span-2 xl:col-span-4">
                <summary class="cursor-pointer text-sm font-medium text-(--color-brand-600)">
                    {{ __('finance::field.security_and_terms') }}
                </summary>

                <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.select name="security_type" :label="__('finance::field.security_type')"
                         :options="collect(\App\Modules\Finance\Models\BankFacility::SECURITIES)
                             ->mapWithKeys(fn (string $s) => [$s => __('finance::field.security_' . $s)])"
                         :selected="old('security_type', \App\Modules\Finance\Models\BankFacility::UNSECURED)" />

            <x-ui.field name="security_value" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.security_value')"
                        :value="old('security_value')" numeric />

            <x-ui.field name="guarantors" :label="__('finance::field.guarantors')" :value="old('guarantors')" />
            <x-ui.field name="covenant" :label="__('finance::field.covenant')" :value="old('covenant')" />
                </div>
            </details>

            <x-ui.field name="charges" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.charges')" :value="old('charges', '0')" numeric />

            <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />

            {{--
                ⛔ চাপা কলাম সারানো — ১৯ সেপ্টেম্বর ২০২৬ (মালিক: *"সব পাতাতেই সমস্যা"*)।

                ⓘ ফিতা, চুক্তির কাগজ আর Save — তিনটাই একটা `flex items-end`-এর
                ভেতরে ছিল, আর ঐ div চার কলামের গ্রিডের **একটা** ঘর নিত। ⚠️ ভেতরের
                `col-span` কিছুই করত না — ওটা গ্রিডের সরাসরি সন্তানেই খাটে।
                ⭐ এখন তিনটা আলাদা সারি: ফিতা পুরো প্রস্থে, কাগজ একটা সাধারণ ঘর,
                আর Save নিচে বাঁয়ে।
            --}}
                    {{-- ⭐ ফিতাটা ঘরগুলোর পরে, ভাউচারের বাক্সের আগে — নমুনার ক্রম।
                         ⓘ আগে এটা ফর্মের বাইরে ছিল, তাই কার্ডের নিচে আলগা হয়ে
                         ঝুলত। মালিক পাঁচটা পর্দা পাশাপাশি দেখে ধরিয়ে দিয়েছেন। --}}
                    <div class="sm:col-span-2 xl:col-span-4">
                        @include('finance::partials.handoff', [
                            'voucher' => 'receipt',
                            'to' => route('accounts.voucher.create', ['type' => 'receipt']),
                            'action' => __('finance::action.take_money_receipt'),
                        ])
                    </div>

                    {{-- ⛔ ভাউচারের বাক্সটা এখান থেকে সরানো হলো — ১৮ সেপ্টেম্বর ২০২৬।

                         ── মালিকের প্রশ্ন, আর সেটাই সঠিক ছিল ───────────────────────────
                         *"যদি Accounts থেকেই টাকা নেওয়া হয়, তাহলে 'টাকাটা কীভাবে এল'
                         সেটা Accounts-এর ব্যাপার — Finance থেকে শুধু work order যাবে না?"*

                         ── ⚠️ তিনটা কারণে বাক্সটা দুর্বল ছিল ────────────────────────────
                         ⓘ ওর একটা ঘরও খাতার সারিতে **সংরক্ষণ হত না** — বাইশটা ঘর বসে
                         থাকত কেবল তিনটা মান বয়ে নেওয়ার জন্য।

                         ⛔ টাকার নিয়মগুলো ওপাশে, ঘরগুলো এপাশে: আগাম তারিখের চেকে টাকা
                         নড়ে না, BEFTN পরদিন ক্রেডিট হয়, MFS-এর চার্জ খরচের খাতে যায়,
                         একই ব্যাংক রেফারেন্স দুইবার নেওয়া যায় না
                         ([[VoucherService::assertBankReferenceIsFree]])। ⚠️ খাতার পর্দা
                         এর একটাও জানত না, তাই এখানে এমন সমন্বয় ভরা যেত যা ভাউচার পরে
                         অস্বীকার করত — আর ব্যবহারকারী জানতেন এক পর্দা পরে।

                         ⛔ আর এটা ঠিক সেই ফাঁদ যা [[capital/partials/state]]-এ আগেই লেখা
                         আছে: *"টাকা ঢোকার দুইটা আলাদা পথ থাকলে একদিন একটায় চার্জের ঘর
                         যোগ হবে, অন্যটায় না, আর কেউ ধরবে না কারণ দুইটাই কাজ করে।"*

                         ── ⭐ এখন যা হয় ────────────────────────────────────────────────
                         খাতা লেখে **ঘটনা ও শর্ত**, আর ফিতার বোতাম ভাউচারকে একটা ছোট
                         নির্দেশ পাঠায় — কে · কত · কী বাবদ। ⓘ বাকি সব প্রশ্ন রসিদ বা
                         পরিশোধের পর্দা করে, যেখানে নিয়মগুলোও থাকে।

                         ⓘ `partials/voucher-box.blade.php` মোছা হয়নি: নমুনার কাগজে
                         বাক্সটা আছে, আর সিদ্ধান্তটা কোনোদিন উল্টালে ফাইলটা ফিরিয়ে আনা
                         এক লাইনের কাজ। ⚠️ কিন্তু আজ কোনো পর্দা ওটা ডাকে না। --}}

                    {{-- ⭐ সংযুক্তি — ব্যাংকের মঞ্জুরিপত্র। ⚠️ ব্যাংক পরে অন্য কথা বললে ওটাই উত্তর। --}}
                    <div class="sm:col-span-2">
                        {{-- ⭐ নাম বদলাল — "সংযুক্তি" নয়, "চুক্তি / সনদ" (১৮ সেপ্টেম্বর ২০২৬)।

                             ── মালিকের প্রশ্ন, আর সেটা ন্যায্য ছিল ─────────────────────────
                             *"টাকা যদি Accounts নেয়, সংযুক্তিও তো সেখানেই থাকার কথা।"*

                             ── ⓘ উত্তর: কাগজ দুই রকম, আর দুইটার মালিক আলাদা ────────────────
                             · **ভাউচারের কাগজ** — ব্যাংক স্লিপ, চেকের ছবি, MFS-এর স্ক্রিনশট।
                               ⓘ ওগুলো *টাকা নড়ার প্রমাণ*, আর ওগুলো রসিদেই থাকে।
                             · **খাতার কাগজ** — মঞ্জুরিপত্র, FDR-এর সার্টিফিকেট, ভাড়ার
                               চুক্তিপত্র, ধারের স্ট্যাম্প। ⓘ ওগুলো *শর্তের প্রমাণ*।

                             ── ⛔ দ্বিতীয় দলটা ভাউচারে রাখা যায় না, দুই কারণে ────────────
                             ⚠️ ব্যাংক সুবিধা মঞ্জুর হয়েছে অথচ এক টাকাও তোলা হয়নি — ভাউচারই
                             নেই, কাগজটা তখন কোথায় থাকবে?

                             ⚠️ আর একটা FDR-এ ছয় বছরে বিশটা লেনদেন হয়, সার্টিফিকেট একটাই।
                             ⛔ যেটাতেই রাখা হোক, বাকি উনিশটা থেকে ওটা খুঁজে পাওয়া যেত না।

                             ⭐ তাই ঘরটা থাকে, কিন্তু নামটা সৎ হয়: "সংযুক্তি" পড়ে মানুষ
                             ব্যাংক স্লিপ দিতেন, আর ওটা ভুল জায়গায় বসত। --}}
                        <label for="bf-paper" class="mb-1 block text-sm font-medium">
                            {{ __('finance::field.book_paper') }}
                        </label>
                        <input id="bf-paper" type="file" name="paper"
                               x-on:change="$store.scanner.begin($el, 'paper')"
                               class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                                      file:border file:border-(--color-border) file:bg-(--color-surface-app)
                                      file:px-3 file:py-1.5 file:text-sm">
                    <span class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('finance::field.book_paper_hint') }}</span>
                    </div>

            {{-- ⓘ Save আর বাতিল নিচে বাঁয়ে, পুরো সারি জুড়ে — অন্য ফর্মের মতো --}}
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-4">
                <x-ui.button type="submit" tone="primary">
                    {{ __('core.action.save') }}
                </x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.bank_facility.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
