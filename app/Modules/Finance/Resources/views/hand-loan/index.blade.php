{{--
    হাতধার — কে আমার কাছে পায়, আর আমি কার কাছে পাই।

    ── কেন একটাই তালিকা, দুইটা নয় ──────────────────────────────────────
    "পাওনা" আর "দেনা" আলাদা দুইটা পর্দা হলে কেউ ওদের মিলিয়ে দেখত না, আর
    একই মানুষ দুই তালিকায় থাকতে পারতেন — একদিকে পাঁচ হাজার পাওনা,
    অন্যদিকে তিন হাজার দেনা, অথচ সত্যিটা দুই হাজার। চিহ্নটাই ভাগ করে
    দেয়, আর যোগফল দুইটা উপরে আলাদা করে বলা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.hand_loan') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.hand_loan')"
                          :subtitle="__('finance::message.hand_loan_note')" />
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── দুই দিকের যোগফল ──────────────────────────────────────────── --}}
    <section data-boxed class="mb-4 grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['finance::field.owed_to_us', \App\Core\Support\Money::format($standing['owed_to_us'])],
            ['finance::field.we_owe', \App\Core\Support\Money::format($standing['we_owe'])],
            ['finance::field.how_many_people', count($standing['rows'])],
        ] as [$label, $value])
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ── নতুন মানুষ ────────────────────────────────────────────────
         নাম আর একটা নম্বর, ব্যস। এদের বেশিরভাগ গ্রাহকও নন, সরবরাহকারীও
         নন — আগে একটা পক্ষ-রেকর্ড বানাতে বললে ফিচারটা কেউ ব্যবহার করত না। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-1 font-semibold">{{ __('finance::field.open_a_hand_loan') }}</h2>

        <p class="mb-3 text-2xs text-(--color-ink-muted)">
            {{ __('finance::message.hand_loan_is_not_a_loan') }}
        </p>


        <form method="POST" enctype="multipart/form-data" x-data
              action="{{ route('finance.hand_loan.store') }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            {{-- ⓘ মোবাইলের আলাদা ঘরটা আর নেই — নম্বরটা ব্যক্তির সারিতে
                 বসে, আর নতুন নাম যোগ করার সময় নিচেই একসাথে নেওয়া হয়।
                 দুই জায়গায় নম্বর রাখলে একদিন আলাদা হত। --}}
            <div class="sm:col-span-2">
                @include('finance::components.person-picker', [
                    'people' => $people,
                    'label' => __('finance::field.person_name'),
                    'required' => true,
                ])
            </div>

            {{--
                ⭐ ধারের শর্ত — ১৫ সেপ্টেম্বর ২০২৬।

                ── ⛔ এতদিন এই পর্দায় কেবল নাম আর নোট ছিল ─────────────
                টাকার গতিবিধি নিচের তালিকায় ঠিকই বসত, কিন্তু **শর্ত
                কোথাও না**: সুদ কত · কবে ফেরত · কী কাগজে দাঁড়ানো।
                ⚠️ আর ঐ তিনটা প্রশ্নই ওঠে ছয় মাস পরে, ঠিক তখন যখন
                সম্পর্কটা আর ভালো নেই।

                ── ⓘ সবগুলোই ঐচ্ছিক, আর সেটা ইচ্ছাকৃত ────────────────
                পরিচিত মানুষের ধার প্রায়ই সুদবিহীন ও মেয়াদহীন।
                ⛔ বাধ্যতামূলক করলে মানুষ বানানো সংখ্যা বসাতেন, আর সেটা
                না লেখার চেয়েও খারাপ — কারণ তখন মিথ্যাটা খাতায় বসে যায়।
            --}}
            {{-- ⭐ টাকার পরিমাণ — নমুনার ঘর, ১৫ সেপ্টেম্বর ২০২৬।

                 ⛔ এতদিন খাতাটা খোলা যেত কত টাকা না জানিয়েই: অঙ্কটা
                 ছিল কেবল নড়াচড়ার সারিতে, তাই প্রথম নড়াচড়ার আগে
                 খাতাটা সংখ্যাহীন থাকত। --}}
            <x-ui.field name="principal" type="number" step="0.01" inputmode="decimal" numeric
                        :label="__('finance::field.loan_principal')"
                        :value="old('principal')" />

            {{-- ⚠️ এ পর্যন্ত ফেরত — **খোলার জের**, চলতি ব্যালান্স নয়।

                 ⓘ পুরনো খাতা ব্যবস্থায় তোলার সময় যেটুকু আগে ফেরত
                 এসেছে সেটুকু এখানে বসে। ⛔ এরপর থেকে হিসাব রাখে
                 খতিয়ান — ঘরটা দ্বিতীয় কপি নয়, শুরুর বিন্দু। --}}
            <x-ui.field name="opening_repaid" type="number" step="0.01" inputmode="decimal" numeric
                        :label="__('finance::field.opening_repaid')"
                        :value="old('opening_repaid', 0)" />

            {{-- ⓘ যে খাতে ঢুকল — ঐচ্ছিক, কারণ পুরনো ধার বসানোর সময়
                 টাকাটা আগেই হাতবদল হয়ে গেছে; তখন আবার পোস্ট করলে
                 দুইবার হত। --}}
            <x-ui.money-account name="money_account_id"
                                :label="__('finance::field.loan_money_account')"
                                :accounts="$accounts" codes
                                :blank="__('finance::field.choose')"
                                :selected="old('money_account_id')" />

            <x-ui.field name="interest_rate" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.interest_rate')"
                        :value="old('interest_rate', '0')" numeric />

            <x-ui.field name="term_months" type="number" inputmode="numeric"
                        :label="__('finance::field.term_months')"
                        :value="old('term_months')" numeric />

            <x-ui.field name="due_on" type="date"
                        :label="__('finance::field.due_on')"
                        :value="old('due_on')" />

            {{-- ⭐ পরের কিস্তি — ১৫ সেপ্টেম্বর ২০২৬-এ ঘরটা যোগ হলো।

                 ⛔ কলামটা ছিল, কিন্তু কেউ লিখতে পারত না: সার্ভিস ওটা
                 `due_on` থেকে নিজে বসিয়ে দিত।

                 ⚠️ আর সেটা **কিস্তির ধারে ভুল** — মাসে মাসে ফেরত দিলে
                 পরের কিস্তি আগামী মাসে, চুক্তির শেষ দিনে নয়। ⓘ ফলে
                 "কার কাছে এখন টাকা চাইতে হবে" প্রশ্নের উত্তর সবসময়
                 চুক্তির শেষ তারিখ দেখাত, অর্থাৎ কোনোদিন কিছু বকেয়া
                 দেখাত না।

                 ⓘ খালি রাখলে আগের মতোই নিজে বসে — পুরনো আচরণ অক্ষত। --}}
            <x-ui.field name="next_due_on" type="date"
                        :label="__('finance::field.next_due_on')"
                        :value="old('next_due_on')" />

            <x-ui.select name="repayment" :label="__('finance::field.repayment')"
                         :options="collect(\App\Modules\Finance\Models\HandLoanAccount::REPAYMENTS)
                             ->mapWithKeys(fn (string $r) => [$r => __('finance::field.repayment_'.$r)])"
                         :selected="old('repayment', \App\Modules\Finance\Models\HandLoanAccount::LUMP)" />

            {{-- ⛔ এটাই সেই ঘর যেটা না থাকলে কেউ বলতে পারে না "কাগজ ছিল
                 কি না" — আর অস্বীকার করলে প্রমাণও থাকে না। --}}
            <x-ui.select name="security" :label="__('finance::field.security')"
                         :options="collect(\App\Modules\Finance\Models\HandLoanAccount::SECURITIES)
                             ->mapWithKeys(fn (string $s) => [$s => __('finance::field.security_'.$s)])"
                         :selected="old('security', \App\Modules\Finance\Models\HandLoanAccount::VERBAL)" />

            <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />

            <div class="flex items-end">
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

                {{-- ⭐ সংযুক্তি — ধারের স্ট্যাম্প বা লেখা কাগজ।
                     ⚠️ মুখের কথা আদালতে দাঁড়ায় না, আর ঝগড়াটা বাধে বছর পরে। --}}
                <div class="mb-2">
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
                    <label for="loan-paper" class="mb-1 block text-sm font-medium">
                        {{ __('finance::field.book_paper') }}
                    </label>
                    <input id="loan-paper" type="file" name="paper"
                           x-on:change="$store.scanner.begin($el, 'paper')"
                           class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                                  file:border file:border-(--color-border) file:bg-(--color-surface-app)
                                  file:px-3 file:py-1.5 file:text-sm">
                    <span class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('finance::field.book_paper_hint') }}</span>
                </div>

                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    {{-- ── কে কোথায় দাঁড়িয়ে ─────────────────────────────────────────── --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.where_each_stands') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_hand_loan_yet')"
            :rows="$standing['rows']"
            :columns="[
                ['key' => 'person', 'label' => __('finance::field.person_name'),
                 'render' => fn ($r) => view('finance::hand-loan.partials.person', ['row' => $r])],
                ['key' => 'movements', 'label' => __('finance::field.movements_count'),
                 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['movements']],
                ['key' => 'balance', 'label' => __('finance::field.hand_loan_balance'),
                 'numeric' => true, 'width' => '13rem',
                 'render' => fn ($r) => view('finance::hand-loan.partials.balance', ['row' => $r])],
                ['key' => 'do', 'label' => '', 'width' => '7rem',
                 'render' => fn ($r) => view('finance::hand-loan.partials.open-it', ['row' => $r])],
            ]" />
    </section>
</x-layouts.app>
