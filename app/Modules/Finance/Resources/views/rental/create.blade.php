{{--
    নতুন ভাড়ার চুক্তি — নিজের পাতা (১৯ সেপ্টেম্বর ২০২৬ পর্যন্ত এটা তালিকার নিচে বসত)।

    ⭐ মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব পাতাতেই সমস্যা"*। ⓘ তালিকা একটা ফর্মের
    সাথে এক পাতায় থাকলে একটা আরেকটাকে চাপা দেয়। এখন হাতধারের মতো: তালিকা
    [[rental/index]]-এ, আর সংরক্ষণ হলে পর্দা চুক্তির নিজের পাতায় যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::action.rental_new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::action.rental_new')"
                          :subtitle="__('finance::menu.rental')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed
             class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST" enctype="multipart/form-data" x-data action="{{ route('finance.rental.store') }}"
              class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf

            <x-ui.field name="counterparty" :label="__('finance::field.rental_counterparty')" required />
            <x-ui.field name="counterparty_phone" :label="__('finance::field.rental_phone')" />
            <x-ui.field name="subject" :label="__('finance::field.rental_subject')" />

            <x-ui.field name="deposit_amount" type="number" step="0.0001" min="0"
                        :label="__('finance::field.rental_deposit')" required />
            <x-ui.field name="monthly_rent" type="number" step="0.0001" min="0"
                        :label="__('finance::field.rental_rent')" required />

            {{-- ⓘ নগদের অঙ্কটা চাওয়া হয় না — সেটা ভাড়া বিয়োগ এটা।
                 তিনটা সংখ্যা চাইলে কেউ এমন তিনটা বসাত যাদের যোগফল
                 মেলে না, আর ভাউচারটা ভারসাম্যহীন হয়ে থামত। --}}
            <x-ui.field name="monthly_adjustment" type="number" step="0.0001" min="0"
                        :label="__('finance::field.rental_from_deposit')" />

            <label class="grid gap-1">
                <span class="text-2xs text-(--color-ink-muted)">
                    {{ __('finance::field.rental_starts_on') }}
                </span>
                <x-ui.date name="starts_on" :value="old('starts_on', now()->toDateString())" required />
            </label>

            <x-ui.field name="term_months" type="number" min="1" max="600"
                        :label="__('finance::field.rental_term')" required />

            {{-- ⭐ তিনটা ঘরই কলামে ছিল, পর্দায় ছিল না — ১৫ সেপ্টেম্বর ২০২৬।

                 ⚠️ ভাড়ার দিনটা লেখা না থাকলে "দেরি হয়েছে কি না"
                 প্রশ্নের উত্তর দেওয়া যায় না, আর বাড়িওয়ালা ফোন
                 করলে তর্ক হয়। ⓘ ৫ তারিখ ডিফল্ট, কারণ বেশিরভাগ
                 চুক্তিতে ওটাই লেখা থাকে। --}}
            <x-ui.field name="rent_day" type="number" min="1" max="28"
                        :label="__('finance::field.rent_day')"
                        :value="old('rent_day', 5)" />

            {{-- ⛔ অগ্রিম আর জামানত দুইটা আলাদা জিনিস — স্যাম্পলের
                 ঐ লাইনটাই এখানে সবচেয়ে জরুরি।

                 ⓘ অগ্রিম ভাড়া **ভাড়ারই আগাম**, তাই প্রতি মাসে ওটা
                 থেকে কাটা পড়ে আর একদিন শূন্য হয়। ⚠️ জামানত ফেরতযোগ্য
                 — চুক্তি শেষ না হলে ওটা কমে না। দুইটাকে এক ধরলে
                 মালিক ভাবতেন তাঁর টাকা জমা আছে, অথচ সেটা খরচ হয়ে
                 গেছে। --}}
            <x-ui.field name="advance_months" type="number" min="0" max="36"
                        :label="__('finance::field.advance_months')"
                        :value="old('advance_months', 0)" />

            {{-- ⓘ ভাড়ার উপর উৎসে কর — ভাড়াটিয়া কেটে সরকারকে দেয়,
                 তাই বাড়িওয়ালা হাতে পান কম। ⚠️ হারটা না থাকলে
                 বাড়িওয়ালার খাতা আর আমাদের খাতা মিলত না। --}}
            <x-ui.field name="tax_rate" type="number" step="0.01" min="0" max="100"
                        :label="__('finance::field.rental_tax_rate')"
                        :value="old('tax_rate', 5)" />

            {{-- ⓘ খালি রাখা যায়: পুরনো চুক্তি বসানোর সময় টাকাটা আগেই
                 দেওয়া হয়ে গেছে আর খোলার জেরে বসেছে, তখন আবার পোস্ট
                 করলে দুইবার হত। --}}
            @include('finance::rental._money', [
                'money' => $money,
                'label' => __('finance::field.rental_money_account'),
                'blank' => __('finance::field.rental_already_paid'),
            ])

            {{--
                ⛔ চাপা ঘর সারানো — মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব পাতাতেই সমস্যা"*।

                ⓘ ফিতা, চুক্তির কাগজ আর বোতাম তিনটাই একটা মোড়কের ভেতরে ছিল, আর
                ভেতরের `col-span` কিছুই করত না — ওটা কেবল গ্রিডের সরাসরি সন্তানে
                খাটে। ⭐ এখন তিনটা আলাদা সারি: ফিতা পুরো প্রস্থে, কাগজ একটা
                সাধারণ ঘর, আর খোলা/বাতিল নিচে বাঁয়ে।
            --}}
            {{-- ⭐ ফিতাটা ঘরগুলোর পরে, ভাউচারের বাক্সের আগে — নমুনার ক্রম।
                 ⓘ আগে এটা ফর্মের বাইরে ছিল, তাই কার্ডের নিচে আলগা হয়ে
                 ঝুলত। মালিক পাঁচটা পর্দা পাশাপাশি দেখে ধরিয়ে দিয়েছেন। --}}
            <div class="sm:col-span-2 lg:col-span-3">
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

            {{-- ⭐ সংযুক্তি — ভাড়ার চুক্তিপত্র। ⚠️ কত বছর, কত বাড়বে, জামানত কত — সব ওখানে। --}}
            <div>
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
                <label for="rent-paper" class="mb-1 block text-sm font-medium">
                    {{ __('finance::field.book_paper') }}
                </label>
                <input id="rent-paper" type="file" name="paper"
                       x-on:change="$store.scanner.begin($el, 'paper')"
                       class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                              file:border file:border-(--color-border) file:bg-(--color-surface-app)
                              file:px-3 file:py-1.5 file:text-sm">
                <span class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('finance::field.book_paper_hint') }}</span>
            </div>

            {{-- ⓘ খোলা আর বাতিল নিচে বাঁয়ে, পুরো সারি জুড়ে — অন্য ফর্মের মতো --}}
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('finance::action.rental_open') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.rental.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
