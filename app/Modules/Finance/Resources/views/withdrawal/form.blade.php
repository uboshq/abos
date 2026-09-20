{{--
    উত্তোলন লেখা — মালিকের পুঁজির **অন্য দিক**।

    ── ⭐ নমুনার সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
    মালিক নমুনা আর লাইভ পাশাপাশি রেখে দেখিয়েছেন: উত্তোলন আলাদা চেহারার
    পাতা নয়, **একই খাতার দ্বিতীয় দিক**। ⓘ হেডার এক, ঘরের ক্রম এক,
    ফিতা এক, ভাউচারের বাক্স এক — কেবল তিনটা শব্দ বদলায়:

        পুঁজির পরিমাণ   →  উত্তোলনের পরিমাণ
        যে খাতে জমা     →  যে খাত থেকে
        টাকাটা কীভাবে এল →  টাকাটা কীভাবে গেল

    ── ⛔ আর একটা বাক্স বাড়ে: উত্তোলনের ধরন ────────────────────────────
    ⚠️ তিনটা ধরনের হিসাব তিন রকম, আর এটাই এই পাতার সবচেয়ে দামি ঘর:
      · উত্তোলন       — মূলধন কমে (৩২০০)
      · মালিকের বেতন  — **খরচ**, মুনাফা কমে (৫২০১)
      · মুনাফার ভাগ   — মূলধন কমে (৩২১০)
    ⛔ একটাকে অন্যটা ধরলে মুনাফার অঙ্কই মিথ্যা হয়ে যায়।

    ── ⓘ তালিকা, সীমা আর মাসের হিসাব কোথায় ────────────────────────────
    ওগুলো [[finance.withdrawal.index]]-এ রয়ে গেছে — ওটা **পড়ার** পাতা।
    ⭐ এটা **লেখার** পাতা, আর নমুনায় লেখার পাতাটাই দেখানো আছে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.withdrawal') }}</x-slot:title>

    <x-slot:header>
        {{-- ⓘ হেডারটা মূলধনের হুবহু — একই খাতা, তাই একই নাম। --}}
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-lg font-semibold">{{ __('finance::field.capital_book_name') }}</h1>

            <span class="text-sm text-(--color-ink-muted)">
                {{ __('finance::field.capital_book_tag') }}
            </span>

            <span class="text-2xs text-(--color-ink-muted)">
                ← {{ __('finance::field.capital_book_english') }}
            </span>
        </div>
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 max-w-4xl rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed
             class="max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">

        <form method="POST" enctype="multipart/form-data" x-data
              action="{{ route('finance.withdrawal.store') }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf

            {{-- ⭐ কোন দিকে — এখানে দ্বিতীয় চিপটা সক্রিয়।

                 ⓘ প্রথমটা লিংক, মূলধনের পাতায় নিয়ে যায়। ⛔ এক ফর্মে
                 দুই দিক রাখা হয়নি: উত্তোলনের নিজস্ব সীমা, অনুমোদন আর
                 ধরন আছে, আর সেগুলো মূলধনে বসালে দুইটা নিয়ম মিশত। --}}
            <div class="sm:col-span-2 xl:col-span-3">
                <span class="mb-1 block text-sm font-medium">{{ __('finance::field.which_way') }}</span>

                <div class="flex flex-wrap gap-2" role="group">
                    <a href="{{ route('finance.capital.create') }}"
                       class="flex items-center gap-1.5 rounded-(--radius-field)
                              border border-(--color-border) px-3 py-1.5 text-sm
                              transition-colors hover:bg-(--color-surface-hover)">
                        <span aria-hidden="true" class="block size-1.5 rounded-full bg-(--color-ink-muted)"></span>
                        {{ __('finance::field.way_in') }}
                    </a>

                    <span aria-current="page"
                          class="flex items-center gap-1.5 rounded-(--radius-field)
                                 border border-(--color-brand-500) bg-(--color-brand-50)
                                 px-3 py-1.5 text-sm font-medium">
                        <span aria-hidden="true" class="block size-1.5 rounded-full bg-(--color-brand-500)"></span>
                        {{ __('finance::field.way_out') }}
                    </span>
                </div>
            </div>

            <x-ui.field name="trx_date" type="date" :label="__('finance::field.date')" required
                        :value="old('trx_date', now()->toDateString())" />

            <div>
                @include('finance::components.person-picker', [
                    'people' => $people,
                    'label' => __('finance::field.who'),
                    'required' => true,
                    'selected' => old('person_id'),
                ])
            </div>

            {{-- ⓘ বাছাই নয়, তথ্য — কোম্পানি ও শাখা আগেই বাছা
                 ([[CapitalController::writingFor()]]-এর একই যুক্তি)। --}}
            <div>
                <label for="writing-for" class="mb-1 block text-sm font-medium">
                    {{ __('finance::field.writing_for') }}
                </label>
                <p id="writing-for" class="rounded-(--radius-field) bg-(--color-surface-app) px-3 py-2 text-sm">
                    {{ $writingFor }}
                </p>
            </div>

            <x-ui.field name="amount" type="number" step="0.01" numeric required
                        :label="__('finance::field.withdrawal_amount')"
                        :value="old('amount')" />

            {{-- ⭐ কী দিয়ে — মালিকের নির্দেশে, ১৮ সেপ্টেম্বর ২০২৬।

                 ⚠️ মালিক একটা ফ্রিজ নিয়ে গেলে সেটা নগদ উত্তোলন নয়।
                 ⛔ ঘরটা না থাকায় সবই টাকা ধরা হত: সিন্দুক থেকে টাকা
                 বেরোনো দেখাত, অথচ সিন্দুক অক্ষত — আর মাস শেষে ক্যাশ
                 বই মিলত না।

                 ⓘ মানগুলো মূলধনেরটার হুবহু, কারণ এক খাতার দুই দিক। --}}
            <x-ui.select name="in_kind" :label="__('finance::field.in_kind')"
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::IN_KINDS)
                             ->mapWithKeys(fn ($k) => [$k => __('finance::field.in_kind_'.$k)])"
                         :selected="old('in_kind', \App\Modules\Finance\Models\CapitalEntry::CASH)" />

            {{-- ⓘ ঐচ্ছিক, মূলধনের মতোই: খালি রাখলে পরিশোধের পর্দাই
                 খাতটা ঠিক করে। --}}
            <x-ui.money-account name="money_account_id" :reference="false"
                                :label="__('finance::field.taken_from')"
                                :accounts="$moneyAccounts" codes
                                :blank="__('finance::field.choose')"
                                :selected="old('money_account_id')" />

            {{-- ⭐ উত্তোলনের ধরন — নমুনার নিজস্ব বাক্স, আর এই পাতার
                 সবচেয়ে দামি ঘর।

                 ⛔ তিনটার হিসাব তিন রকম: উত্তোলন ও মুনাফার ভাগ মূলধন
                 কমায়, কিন্তু **মালিকের বেতন একটা খরচ**। ⚠️ বেতনকে
                 উত্তোলন ধরলে মুনাফা বেশি দেখাত, আর কর বেশি বসত। --}}
            <fieldset class="sm:col-span-2 xl:col-span-3 rounded-(--radius-card)
                             border border-(--color-border) bg-(--color-surface-app) p-3">
                <legend class="px-1 text-sm font-medium text-(--color-brand-700)">
                    {{ __('finance::field.withdrawal_kind_box') }}
                </legend>

                <div x-data="{ kind: @js(old('kind', \App\Modules\Finance\Models\Withdrawal::DRAWING)) }"
                     class="flex flex-wrap gap-2" role="group">
                    @foreach (\App\Modules\Finance\Models\Withdrawal::KINDS as $k)
                        <button type="button"
                                x-on:click="kind = @js($k)"
                                x-bind:aria-pressed="kind === @js($k)"
                                x-bind:class="kind === @js($k)
                                    ? 'border-(--color-brand-500) bg-(--color-brand-50) font-medium'
                                    : 'border-(--color-border)'"
                                class="flex items-center gap-1.5 rounded-(--radius-field)
                                       border px-3 py-1.5 text-sm">
                            <span aria-hidden="true"
                                  x-bind:class="kind === @js($k) ? 'bg-(--color-brand-500)' : 'bg-(--color-ink-muted)'"
                                  class="block size-1.5 rounded-full"></span>
                            {{ __('finance::field.kind_'.$k) }}
                        </button>
                    @endforeach

                    <input type="hidden" name="kind" x-bind:value="kind">
                </div>

                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('finance::message.withdrawal_kind_note') }}
                </p>
            </fieldset>

            {{-- ⭐ ফিতা — টাকা **যাচ্ছে**, তাই পরিশোধ ভাউচার। --}}
            <div class="sm:col-span-2 xl:col-span-3">
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

            <div>
                <x-ui.field name="reason" :label="__('finance::field.narration')"
                            :value="old('reason')" />
            </div>

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
                <label for="wdr-paper" class="mb-1 block text-sm font-medium">
                    {{ __('finance::field.book_paper') }}
                </label>
                <input id="wdr-paper" type="file" name="paper"
                       x-on:change="$store.scanner.begin($el, 'paper')"
                       class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                              file:border file:border-(--color-border) file:bg-(--color-surface-app)
                              file:px-3 file:py-1.5 file:text-sm">
                    <span class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('finance::field.book_paper_hint') }}</span>
            </div>

            {{-- ⭐ বোতাম দুইটা নিজের সারিতে, নিচে বাঁয়ে — মালিক, ১৯ সেপ্টেম্বর
                 ২০২৬: *"সব পাতাতেই সমস্যা"*। ⛔ আগে ওরা তিন কলামের গ্রিডের
                 **একটা** ঘরে বসত, তাই লম্বা লেখা কয়েক লাইনে ভাঙত। --}}
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">
                    {{ __('finance::action.save_withdrawal_row') }}
                </x-ui.button>

                <x-ui.button tone="secondary" :href="route('finance.withdrawal.index')">
                    {{ __('finance::action.withdrawal_list') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    {{-- ⓘ খতিয়ানের ভাঁজ — মূলধনের মতোই, কিন্তু দিকটা উল্টো। --}}
    <details class="mt-3 max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3">
        <summary class="cursor-pointer text-sm font-medium text-(--color-brand-600)">
            {{ __('finance::field.what_lands_in_the_ledger') }} · {{ __('finance::field.lifeline') }}
        </summary>

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="font-medium">{{ __('finance::message.ledger_debit') }}</dt>
            <dd class="text-(--color-ink-muted)">{{ __('finance::message.ledger_debit_withdrawal') }}</dd>

            <dt class="font-medium">{{ __('finance::message.ledger_credit') }}</dt>
            <dd class="text-(--color-ink-muted)">{{ __('finance::message.ledger_credit_withdrawal') }}</dd>
        </dl>

        <p class="mt-3 text-2xs text-(--color-ink-muted)">
            {{ __('finance::message.ledger_only_after_posting') }}
        </p>
    </details>
</x-layouts.app>
