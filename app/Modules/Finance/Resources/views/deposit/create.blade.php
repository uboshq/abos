{{--
    নতুন জমা — নিজের পাতা, ইস্যুয়ার সহ (১৯ সেপ্টেম্বর ২০২৬ পর্যন্ত এটা তালিকার উপরে বসত)।

    ⓘ তালিকা আর চারটা যোগফল [[deposit/index]]-এ। সংরক্ষণ হলে পর্দা জমার
    নিজের পাতায় যায়; ভুল হলে Laravel এই পাতাতেই ফেরায়, পুরনো লেখা সহ।

    ⓘ `depositOpener`-এর Alpine অপরিবর্তিত সরানো — নিবন্ধন `resources/js`-এ,
    আর `app.js` খোলসই (`x-layouts.app`) টানে।
--}}
@php
    /*
     * ধরনের আকৃতি JS-এ — কোন ঘরগুলো দেখাতে হবে সেটা ওটাই ঠিক করে।
     *
     * সব ঘর একসাথে দেখালে FDR খুলতে গিয়ে "কিস্তির দিন" আর "মুনাফা
     * কোথায় আসে" — দুইটা অপ্রাসঙ্গিক প্রশ্নের উত্তর দিতে হত, আর
     * তৃতীয়বারে কেউ যেকোনো একটা বসিয়ে দিত।
     */
    $shapes = $kinds->mapWithKeys(fn ($k) => [$k->id => [
        'shape' => $k->shape,
        'personal' => $k->personal_only,
    ]]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::field.open_a_deposit') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::field.open_a_deposit')"
                          :subtitle="__('finance::menu.deposit_'.($issuer === 'national_savings' ? 'savings' : $issuer))" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── নতুন জমা ──────────────────────────────────────────────────
         ঘরগুলো ধরনের আকৃতি অনুযায়ী দেখা যায়: কিস্তির জমায় কিস্তির ঘর,
         নিয়মিত মুনাফার জমায় মুনাফার খাত। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4"
             x-data="depositOpener({
                 kinds: @js($shapes),
                 kindId: @js((string) old('kind_id')),
                 heldBy: @js((string) old('held_by', $issuer === 'bank' ? 'business' : 'owner')),
             })">
        <h2 class="mb-3 font-semibold">{{ __('finance::field.open_a_deposit') }}</h2>


        <form method="POST" enctype="multipart/form-data" x-data
              action="{{ route('finance.deposit.store', ['issuer' => $issuer]) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.select name="kind_id" :label="__('finance::field.deposit_kind')" required
                         x-model="kindId"
                         :options="$kinds->mapWithKeys(fn ($k) => [$k->id => $k->name()])"
                         :placeholder="__('finance::field.choose')"
                         :selected="old('kind_id')" />

            {{-- ⭐ কোথায় রাখা — তালিকা থেকে; তালিকায় না থাকলে "+" এখানেই।
                 কারণটা ব্যাংক সুবিধার ফর্মে লেখা আছে। --}}
            @include('finance::components.institution-picker', [
                'institutions' => $institutions,
                'selected' => old('institution_id'),
                'label' => __('finance::field.institution'),
            ])

            <x-ui.field name="branch_name" :label="__('finance::field.branch_name')"
                        :value="old('branch_name')" />

            <x-ui.field name="reference_no" :label="__('finance::field.reference_no')"
                        :value="old('reference_no')" />

            {{-- কার নামে — আর এটাই ঠিক করে টাকাটা সম্পদ হবে না উত্তোলন।
                 সঞ্চয়পত্র ব্যবসার নামে কেনা যায় না, তাই তখন ঘরটা
                 আটকে থাকে; সেবাও একই কথা বলে, দুই জায়গাতেই। --}}
            <div>
                {{-- ── কেন আটকানো ঘরের পাশে একটা লুকানো ঘর ──────────────
                     `disabled` ঘর ফর্মের সাথে **কিছুই পাঠায় না** — HTML-এর
                     নিয়ম। সঞ্চয়পত্র বাছলে ঘরটা আটকে যেত, আর তখন
                     `held_by` ফাঁকা যেত: ব্যবহারকারী দেখতেন "মালিক" লেখা,
                     অথচ ফিরত "কার নামে বলুন" ভুল।

                     লুকানো ঘরটা select-এর **আগে**, ইচ্ছাকৃতভাবে: একই
                     নামে দুইটা ঘর গেলে পরেরটা জেতে। তাই ঘরটা খোলা
                     থাকলে ব্যবহারকারীর বাছাই জেতে, আর আটকানো থাকলে
                     কেবল লুকানোটাই যায়। --}}
                <input type="hidden" name="held_by" :value="heldBy">

                <x-ui.select name="held_by" :label="__('finance::field.held_by')" required
                             x-model="heldBy" ::disabled="personalOnly"
                             :options="[
                                 \App\Modules\Finance\Models\Deposit::BUSINESS => __('finance::who.business'),
                                 \App\Modules\Finance\Models\Deposit::OWNER => __('finance::who.owner'),
                             ]"
                             :selected="old('held_by', $issuer === 'bank' ? 'business' : 'owner')" />

                <p x-cloak x-show="heldBy === 'owner'"
                   class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('finance::message.owner_held_is_drawing') }}
                </p>
            </div>

            {{-- ⓘ মোড়কটা অপরিবর্তিত: ব্যবসার নামে রাখা আমানতের কোনো
                 ব্যক্তি নেই, তাই ঘরটা তখন পর্দাতেই আসে না। যে ঘর কখনো
                 ভরা হবে না, সেটা দেখানো মানে ভুল প্রশ্ন করা। --}}
            <div x-cloak x-show="heldBy === 'owner'" class="sm:col-span-2">
                @include('finance::components.person-picker', [
                    'people' => $people,
                    'label' => __('finance::field.holder_name'),
                ])
            </div>

            <x-ui.field name="principal" type="number" step="0.01" numeric required
                        :label="__('finance::field.principal')" :value="old('principal')" />


            {{-- এখানেই টাকা নড়ে, তাই ব্যাংক বা MFS হলে নম্বরটাও এখানেই। --}}
            <x-ui.money-account name="funded_from_account_id" required
                                :label="__('finance::field.funded_from')"
                                :accounts="$accounts"
                                :selected="old('funded_from_account_id')" />

            <x-ui.field name="profit_rate" type="number" step="0.01" numeric
                        :label="__('finance::field.profit_rate')" :value="old('profit_rate')" />

            {{-- সুদ না মুনাফা — ইসলামি ব্যাংকে কাগজে ওটাই ছাপা, আর
                 খাতের কথা কাগজের সাথে না মিললে হিসাবরক্ষক থেমে ভাবেন। --}}
            <x-ui.select name="return_word" :label="__('finance::field.return_word')" required
                         :options="['interest' => __('finance::who.interest'),
                                    'profit' => __('finance::who.profit')]"
                         :selected="old('return_word', 'interest')" />

            <x-ui.field name="opened_on" type="date" :label="__('finance::field.opened_on')" required
                        :value="old('opened_on', now()->toDateString())" />

            <x-ui.field name="matures_on" type="date" :label="__('finance::field.matures_on')"
                        :value="old('matures_on')" />

            {{-- ⭐ উৎসে কর — ঘরটা কলামে ছিল, পর্দায় ছিল না (১৫ সেপ্টেম্বর ২০২৬)।

                 ⚠️ ব্যাংক মুনাফা দেওয়ার আগেই কেটে রাখে, তাই হাতে আসা
                 টাকাটা ঘোষিত হারের চেয়ে কম। ⛔ হারটা লেখা না থাকলে
                 মালিক প্রতি বছর ভাবতেন ব্যাংক কম দিয়েছে, অথচ কাটাটা
                 সরকারের। ⓘ ১০% ডিফল্ট, কারণ TIN থাকলে ওটাই। --}}
            <x-ui.field name="tax_rate" type="number" step="0.01" numeric
                        :label="__('finance::field.tax_rate')"
                        :value="old('tax_rate', 10)" />

            {{-- ⭐ মেয়াদ শেষে কী হবে — আগে থেকেই লেখা থাকে।

                 ⛔ না লিখলে মেয়াদ শেষের দিনটায় কেউ জানে না কী করতে
                 হবে, আর ব্যাংক নিজে থেকে নবায়ন করে দেয় — প্রায়ই এমন
                 হারে যেটা কেউ দেখেনি। --}}
            <x-ui.select name="on_maturity" :label="__('finance::field.on_maturity')"
                         :options="collect(\App\Modules\Finance\Models\Deposit::ON_MATURITY)
                             ->mapWithKeys(fn ($m) => [$m => __('finance::field.on_maturity_'.str_replace('_only', '', $m))])"
                         :selected="old('on_maturity', \App\Modules\Finance\Models\Deposit::RENEW_WITH_PROFIT)" />

            <div x-cloak x-show="shape === 'instalment'">
                <x-ui.field name="instalment_amount" type="number" step="0.01" numeric
                            :label="__('finance::field.instalment_amount')"
                            :value="old('instalment_amount')" />
            </div>

            <div x-cloak x-show="shape === 'instalment'">
                <x-ui.field name="instalment_day" type="number" step="1" numeric
                            :label="__('finance::field.instalment_day')"
                            :value="old('instalment_day')" />
            </div>

            <div x-cloak x-show="shape === 'periodic_payout'" class="xl:col-span-2">
                {{-- ⓘ নম্বর চাওয়া হয় না: এটা ভবিষ্যতের লাভ কোথায় আসবে
                     তার ঠিকানা, আজকের কোনো লেনদেন নয়। --}}
                <x-ui.money-account name="payout_account_id" :reference="false"
                                    :label="__('finance::field.payout_account')"
                                    :accounts="$accounts"
                                    :selected="old('payout_account_id')" />
            </div>

            {{-- কোন ধারের বিপরীতে বন্ধক।

                 ---- কেন ঘরটা এখানে এল, ৩০ আগস্ট ২০২৬ ----
                 ঘরটা ছিল ঋণের ফর্মে, কারণ FD তখন ঋণেরই একটা ধরন ছিল।
                 FD জমার পর্দায় চলে আসায় ঘরটাও সাথে এল -- নাহলে দরজা
                 বন্ধ করতে গিয়ে "আমার FD-টা ঋণের বিপরীতে বাঁধা" কথাটা
                 বলার জায়গাই থাকত না, আর ওটা পরিষ্কার করা হত না,
                 ক্ষমতা হারানো হত।

                 খালি রাখলে জমাটা হাতের টাকা। বাঁধা থাকলে তালিকায়
                 "আছে" দেখাবে ঠিকই, কিন্তু ভাঙানো যাবে না।

                 ---- কেন কেবল ব্যবসার জমায় ----
                 মালিক নিজের নামে যেটা রেখেছেন সেটা ব্যবসার সম্পদ নয়,
                 তাই ব্যবসার ধারের জামানত হিসেবে ওটা দেখানো মানে এমন
                 কিছু গুনে ফেলা যা ব্যবসার নয়। --}}
            @if ($pledgeableLoans->isNotEmpty())
                <div x-cloak x-show="heldBy === 'business'" class="sm:col-span-2">
                    <x-ui.select name="pledged_to_loan_id"
                                 :label="__('finance::field.pledged_to_loan')"
                                 :options="$pledgeableLoans->mapWithKeys(fn ($l) => [$l->id => $l->lender.' — '.$l->document_no])"
                                 :placeholder="__('finance::field.not_pledged')"
                                 :selected="old('pledged_to_loan_id')" />
                </div>
            @endif

            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />
            </div>

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
                        'voucher' => 'payment',
                        'to' => route('accounts.voucher.create', ['type' => 'payment']),
                        'action' => __('finance::action.pay_money_voucher'),
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

                {{-- ⭐ সংযুক্তি — FDR বা সঞ্চয়পত্রের সার্টিফিকেট।
                     ⚠️ ঐ কাগজটাই একমাত্র প্রমাণ যে টাকাটা ব্যাংকে আছে;
                     মেয়াদ শেষে ওটা ছাড়া টাকা তোলাই যায় না। --}}
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
                    <label for="dep-paper" class="mb-1 block text-sm font-medium">
                        {{ __('finance::field.book_paper') }}
                    </label>
                    <input id="dep-paper" type="file" name="paper"
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
                <x-ui.button tone="secondary" :href="route('finance.deposit.index', ['issuer' => $issuer])">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
