{{--
    একটা সুবিধার নথি — মঞ্জুরি, জামানত, শর্ত।

    ── ⛔ এখানেও টাকা নাড়ার বোতাম নেই ────────────────────────────────
    **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে।** ⓘ CC-র ব্যালান্স দেখতে
    হলে ওর ব্যাংক হিসাবের খতিয়ানে যেতে হয় — আর সেটাই সঠিক, কারণ
    ওখানেই সংখ্যাটা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $facility->bank }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$facility->bank"
            :subtitle="__('finance::field.facility_' . $facility->kind) . ' · ' . $facility->drillDocumentNo()" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-badge-success-bg px-3 py-2 text-sm text-badge-success-ink">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⭐ কেন কাজটা হলো না — নাহলে অস্বীকারটা নীরব থাকে।

         নিচে সুবিধা বন্ধ করার ফর্ম আছে, আর `note` ৫০০ অক্ষরে আটকায়।
         ⛔ এটা না থাকলে বোতাম চাপা যেত, কিছুই ঘটত না, আর কোনো কারণও
         দেখা যেত না — ব্যবহারকারী ধরে নিতেন ব্যবস্থাটা ভাঙা, অথচ সে
         একটা ভুল আটকাচ্ছিল ([[components/ui/errors]])।

         ⓘ ধরেছে abos-e8-এর `test_every_show_screen_can_say_why_it_refused`,
         ব্যবহারকারী নয় — ৩৬টা show-পর্দার ২৩টাতেই এটা ছিল না। --}}
    <x-ui.errors />

    {{-- ⭐ চলতি অবস্থা — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।

         ── ⓘ তিনটা সংখ্যা, তিনটাই খাতা থেকে ──────────────────────────
         কয়টা কিস্তি শোধ, কয়টা বাকি, আর এখন কত বকেয়া। ⛔ কোনোটাই
         সংরক্ষিত নয় ([[BankFacilityService::instalmentStanding]] ও
         [[standing()]]) — সংরক্ষিত সংখ্যা আর খতিয়ান একদিন আলাদা কথা
         বলত, আর ব্যাংকের সাথে মেলানোর দিন কোনটা সত্যি তা বলা যেত না।

         ⚠️ পট্টিটা পাতার একদম উপরে, কারণ ঋণের পাতা খুলে মানুষ এই
         তিনটাই দেখতে আসেন — মঞ্জুরির শর্ত নয়, ওগুলো একবার পড়া হয়। --}}
    <section data-boxed
             class="mb-4 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4 sm:grid-cols-3">
        <div>
            <p class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.instalments_paid') }}</p>
            <p class="num text-lg font-semibold">{{ $instalments['paid'] }}</p>
        </div>

        <div>
            <p class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.instalments_left') }}</p>
            <p class="num text-lg font-semibold">{{ $instalments['left'] }}</p>
        </div>

        <div>
            <p class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.outstanding_today') }}</p>
            <p class="num text-lg font-semibold"><x-ui.amount :value="$outstanding" /></p>
        </div>
    </section>

    {{-- ⛔ ঋণের টাকা ঋণের নামে — অডিট গ১৬, ৪ অক্টোবর ২০২৬।

         ⓘ সব মেয়াদি ঋণ একই দায়ের খাতে বসে, তাই খাতা নিজে জানে না কোন কিস্তি কোন ঋণের। বোতাম দুইটা
         ভাউচার খোলে `against_type=bank_facility` নিয়ে ([[insurance/partials/pay]]-এর ছাঁচ), আর
         [[BankFacilityService::standing()]] ও কিস্তির গোনা কেবল এই ঋণের নামের সারি ধরে। --}}
    @if ($facility->isBalanceSheetDebt() && $facility->liability_account_id !== null
        && $facility->status !== \App\Core\Support\DocumentStatus::CLOSED)
        @can('accounts.voucher.create')
            <div class="mb-4 flex flex-wrap gap-2">
                <x-ui.button tone="primary"
                             :href="route('accounts.voucher.create', [
                                 'type' => 'payment',
                                 'against_type' => \App\Modules\Finance\Models\BankFacility::drillSourceType(),
                                 'against_id' => $facility->getKey(),
                                 'narration' => __('finance::field.facility_pay_instalment').' — '.$facility->drillLabel(),
                             ])">
                    {{ __('finance::field.facility_pay_instalment') }}
                </x-ui.button>
                <x-ui.button tone="secondary"
                             :href="route('accounts.voucher.create', [
                                 'type' => 'receipt',
                                 'against_type' => \App\Modules\Finance\Models\BankFacility::drillSourceType(),
                                 'against_id' => $facility->getKey(),
                                 'narration' => __('finance::field.facility_draw').' — '.$facility->drillLabel(),
                             ])">
                    {{ __('finance::field.facility_draw') }}
                </x-ui.button>
            </div>
        @endcan
    @endif

    {{-- ⭐ আজ শোধ করলে কত — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
         ⓘ চার্জ লেখা না থাকলে সারিটা আসে না — শূন্য চার্জ দেখানো
         আর চার্জ না থাকা এক কথা নয়। --}}
    @if (($settlement['unknown'] ?? false) || bccomp($settlement['charge'] ?? '0', '0', 4) > 0)
        <p class="mb-4 rounded-(--radius-field) bg-badge-warning-bg px-3 py-2 text-sm text-badge-warning-ink">
            <strong>{{ __('finance::field.settlement_today') }}:</strong>
            @if ($settlement['unknown'])
                {{ __('finance::message.settlement_basis_unknown') }}
            @else
                {{ __('finance::message.settlement_charge_line', [
                    'outstanding' => \App\Core\Support\Money::format($settlement['outstanding']),
                    'charge' => \App\Core\Support\Money::format($settlement['charge']),
                    'total' => \App\Core\Support\Money::format($settlement['total']),
                ]) }}
            @endif
        </p>
    @endif

    {{-- ⭐ কিস্তির তালিকা — মালিকের ছবি, ২১ সেপ্টেম্বর ২০২৬।

         ⓘ চারটা কলাম: মাস · আসল · সুদ · জের। প্রথম মাসে সুদ বেশি
         আসল কম, শেষ মাসে উল্টো — ক্ষয়িষ্ণু জেরে সুদ বসে বলেই।
         ⚠️ শেষ সারিতে জের ঠিক শূন্য; না হলে খাতায় দুই পয়সার ঋণ
         চিরকাল পড়ে থাকত। --}}
    @if (($schedule ?? null) !== null)
        <section data-boxed
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-(--color-border) p-4">
                <h2 class="font-semibold">{{ __('finance::field.instalment_schedule') }}</h2>

                <p class="text-sm text-(--color-ink-muted)">
                    {{ __('finance::message.schedule_totals', [
                        'instalment' => \App\Core\Support\Money::format($schedule['instalment']),
                        'interest' => \App\Core\Support\Money::format($schedule['interest_total']),
                        'total' => \App\Core\Support\Money::format($schedule['paid_total']),
                    ]) }}
                </p>
            </header>

            {{-- ⓘ লম্বা তালিকা ভাঁজ করা, কারণ রোজকার কাজে লাগে না —
                 লাগে বছরে একবার, বা ব্যাংকের কাগজ মেলানোর দিন। --}}
            <details>
                <summary class="cursor-pointer px-4 py-2 text-sm text-(--color-brand-600)">
                    {{ __('finance::field.show_schedule') }}
                </summary>

                <div class="overflow-x-auto">
                    <table class="ui-list w-full">
                        <thead>
                            <tr class="text-2xs text-(--color-ink-muted)">
                                <th class="text-start">{{ __('finance::field.month') }}</th>
                                {{-- ⭐ কিস্তির দিন আর অবস্থা — পরিকল্পনা ৩.২, ৬ অক্টোবর ২০২৬ --}}
                                <th class="text-start">{{ __('finance::bank_loan_report.due_on') }}</th>
                                <th class="text-start">{{ __('finance::bank_loan_report.state') }}</th>
                                <th class="text-end">{{ __('finance::field.principal_part') }}</th>
                                <th class="text-end">{{ __('finance::field.interest_part') }}</th>
                                <th class="text-end">{{ __('finance::field.balance_left') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($schedule['rows'] as $row)
                                <tr class="border-t border-(--color-border)">
                                    <td class="num">{{ $row['month'] }}</td>
                                    <td>{{ ($row['due_on'] ?? null) ? \App\Core\Support\DateFormat::format($row['due_on']) : '—' }}</td>
                                    <td data-instalment-state="{{ $row['state'] ?? '' }}"
                                        @class(['font-semibold text-(--color-danger)' => ($row['state'] ?? '') === 'overdue',
                                                'text-(--color-success)' => ($row['state'] ?? '') === 'paid'])>
                                        {{ __('finance::bank_loan_report.state_'.($row['state'] ?? 'undated')) }}
                                    </td>
                                    <td class="text-end"><x-ui.amount :value="$row['principal']" /></td>
                                    <td class="text-end"><x-ui.amount :value="$row['interest']" /></td>
                                    <td class="text-end"><x-ui.amount :value="$row['balance']" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">

        {{-- মঞ্জুরি --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_sanction') }}</h2>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('finance::field.limit_amount') }}</dt>
                <dd class="text-end"><x-ui.amount :value="$facility->limit_amount" /></dd>

                {{-- ⭐ হার যেমন লেখা হয়েছে, তেমনই ছাপা — ২৭ সেপ্টেম্বর ২০২৬।

                     ⛔ এখানে হারটা সোজা ছাপা হত, আর
                     ঘরটার কাস্ট `decimal:4` ([[BankFacility::casts]]) — অর্থাৎ
                     অ্যাট্রিবিউটটা **স্ট্রিং** `"12.5000"`। ⚠️ ফল: মালিক `12.5`
                     লিখলেন, পর্দা বলল `12.5000%`, আর সুদবিহীন ঋণে `0.0000%`।

                     ⓘ সংখ্যাটা ভুল ছিল না, কেবল চেহারাটা — তাই কোনো দাবি লাল
                     হয়নি, কোনো হিসাবও মেলেনি এমন হয়নি। ধরা পড়ে কেবল চোখে।

                     ⓘ পথটা আমানতের পর্দার নিজের ([[finance::deposit.show]]:৮১)
                     আর হাতধারের পর্দার ([[finance::hand-loan.show]]) — নতুন কিছু নয়।

                     ⚠️ `?: '0'` টা খালি আর শূন্য — দুইটার জন্যই, কারণ PHP-তে
                     `'0'` নিজেও মিথ্যা; দুই বেলাতেই উত্তর এক, তাই পাশের
                     হাতধারের `=== ''` পরীক্ষার সাথে ছাপা এক থাকে। ⓘ কলামটা
                     `NOT NULL DEFAULT 0` (মাইগ্রেশনের ৮৪ নম্বর লাইন), তাই খাতা
                     থেকে আসা মান কখনো খালি নয় আর *"হার জানা নেই"* বলে কোনো
                     অবস্থাই এখানে নেই। ⛔ সেজন্যই শূন্যে `—` নয়, `0%` — সুদবিহীন
                     ঋণ সত্যিই হয়, আর ওটা একটা **উত্তর**, ফাঁকা ঘর নয়।

                     ⓘ `10.0000` থেকে গুরুত্বপূর্ণ শূন্যটা কাটে না, কারণ কাস্টের
                     কারণে দশমিক বিন্দুটা সর্বদা থাকে — প্রথম `rtrim` বিন্দুতেই
                     থামে। ⚠️ দাবিটা তবু লেখা আছে, কাস্ট একদিন সরলে ধরা পড়বে। --}}
                <dt class="text-(--color-ink-muted)">{{ __('finance::field.interest_rate') }}</dt>
                <dd class="text-end">{{ rtrim(rtrim((string) $facility->interest_rate, '0'), '.') ?: '0' }}%</dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.sanctioned_on') }}</dt>
                <dd class="text-end">{{ $facility->sanctioned_on?->translatedFormat('j F Y') }}</dd>

                {{-- ⭐ নামটা ধরন ধরে — গ্যারান্টি নবায়ন হয় না, ফুরায়। --}}
                <dt class="text-(--color-ink-muted)">
                    {{ $facility->kind === \App\Modules\Finance\Models\BankFacility::GUARANTEE
                        ? __('finance::field.expires_on')
                        : __('finance::field.renews_on') }}
                </dt>
                <dd @class(['text-end', 'font-semibold text-badge-warning-ink' => $facility->renewalIsNear()])>
                    {{ $facility->renews_on?->translatedFormat('j F Y') ?? '—' }}
                </dd>

                {{-- ⭐ কিস্তি — কয়টা দেওয়া, কয়টা বাকি (২০ সেপ্টেম্বর ২০২৬)।

                     ⛔ সংখ্যাটা কোথাও সংরক্ষণ করা নেই — মালিকের নিয়ম: যা ওই
                     ঋণের খাতে শোধ হয়েছে, তাই শোধ। ⓘ দায়ের খাতে যত ডেবিট,
                     তত শোধ — কিস্তির অঙ্ক দিয়ে ভাগ। ⚠️ সংরক্ষিত গুনতি আর
                     খাতা একদিন আলাদা কথা বলত।

                     ⓘ শুরুর দিনের গুনতিটা যোগ হয়: ব্যবস্থায় তোলার আগের
                     কিস্তিগুলো খাতায় খুঁজে পাওয়ার কোনো পথ নেই। --}}
                @if ((int) ($facility->instalments ?? 0) > 0)
                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.instalments') }}</dt>
                    <dd class="text-end">
                        {{ __('finance::message.instalment_standing', [
                            'paid' => $instalments['paid'],
                            'left' => $instalments['left'],
                        ]) }}
                    </dd>
                @endif
            </dl>

            {{--
                ⚠️ নবায়ন কাছে এলে কথাটা লেখা হয়, কেবল রঙ বদলানো হয় না।

                ⓘ রঙ একা কিছু বলে না — যিনি রং চেনেন না বা পর্দা-পাঠক
                ব্যবহার করেন, তাঁর কাছে ওটা নীরব।
            --}}
            @if ($facility->renewalIsNear())
                <p class="mt-3 rounded-(--radius-field) bg-badge-warning-bg px-3 py-2 text-sm text-badge-warning-ink">
                    {{ __('finance::message.facility_renew_soon') }}
                </p>
            @endif
        </section>

        {{--
            ⭐ ড্রয়িং পাওয়ার — কেবল CC-তে, আর এটাই এই পাতার আসল সংখ্যা।

            ⛔ দোকানদার প্রায়ই ভাবেন মঞ্জুরিকৃত সীমাটাই তাঁর টাকা।
            ⚠️ স্টক কমলে সীমাও কমে, আর টের পাওয়া যায় চেক ফেরত এলে।
        --}}
        @if ($facility->drawingPower() !== null)
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('finance::field.drawing_power') }}</h2>

                <p class="text-2xl font-semibold tabular-nums">
                    <x-ui.amount :value="$facility->drawingPower()" />
                </p>

                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.stock_value') }}</dt>
                    <dd class="text-end"><x-ui.amount :value="$facility->stock_value" /></dd>

                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.margin_percent') }}</dt>
                    {{-- ⓘ উপরের হারের হুবহু একই ভুল, একই সারাই — কেবল কাস্ট
                         এখানে `decimal:2` ([[BankFacility::casts]]), তাই কাঁচা
                         রূপটা `30.00%` আর `0.00%`। ⚠️ আলাদা করে লেখা হলো
                         কারণ দুইটা ঘরের কাস্ট আলাদা, আর একটা সহায়ক বানিয়ে
                         স্কেলটা লুকিয়ে ফেললে পরের কেউ ভুল স্কেল ধরে নিত। --}}
                    <dd class="text-end">{{ rtrim(rtrim((string) $facility->margin_percent, '0'), '.') ?: '0' }}%</dd>

                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.last_statement_on') }}</dt>
                    <dd class="text-end">{{ $facility->last_statement_on?->translatedFormat('j M Y') ?? '—' }}</dd>
                </dl>

                <p class="mt-3 text-sm text-(--color-ink-muted)">
                    {{ __('finance::message.drawing_power_note') }}
                </p>

                @if ($facility->moneyAccount)
                    <p class="mt-2 text-sm text-(--color-ink-muted)">
                        {{ __('finance::message.facility_balance_lives_in', [
                            'account' => $facility->moneyAccount->code . ' · ' . $facility->moneyAccount->name(),
                        ]) }}
                    </p>
                @endif
            </section>
        @endif

        {{-- জামানত ও শর্ত --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_security') }}</h2>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('finance::field.security_type') }}</dt>
                <dd class="text-end">{{ __('finance::field.security_' . $facility->security_type) }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.security_value') }}</dt>
                <dd class="text-end">
                    {{ $facility->security_value === null ? '—' : \App\Core\Support\Money::format($facility->security_value) }}
                </dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.guarantors') }}</dt>
                <dd class="text-end">{{ $facility->guarantors ?: '—' }}</dd>
            </dl>

            @if ($facility->covenant)
                <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) px-3 py-2 text-sm">
                    {{ $facility->covenant }}
                </p>
            @endif
        </section>

        {{--
            ⭐ স্থিতিপত্রে এটা কোথায় বসে — আর কোথায় বসে না।

            ⛔ গ্যারান্টি দায় নয় যতক্ষণ না কেউ ভাঙায়; CC-ও নয় এই
            অর্থে — ওর দেনা ব্যাংক হিসাবের ঋণাত্মক ব্যালান্স।
            ⚠️ দুইটাকে ঋণ ধরে বসালে ব্যবসাটা নিজের চেয়ে বেশি ঋণগ্রস্ত
            দেখাত, আর ব্যাংক পরের সুবিধা দিতে দ্বিধা করত।
        --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_in_the_books') }}</h2>

            <p class="text-sm">
                {{ $facility->isBalanceSheetDebt()
                    ? __('finance::message.facility_is_debt')
                    : __('finance::message.facility_is_not_debt') }}
            </p>

            @if ($facility->liabilityAccount)
                <p class="mt-2 text-sm text-(--color-ink-muted)">
                    {{ $facility->liabilityAccount->code }} · {{ $facility->liabilityAccount->name() }}
                </p>
            @endif

            @can('finance.bank_facility.close')
                @if ($facility->closed_on === null)
                    <form method="POST" action="{{ route('finance.bank_facility.close', $facility) }}"
                          class="mt-4"
                          data-confirm="{{ __('finance::message.facility_close_confirm') }}">
                        @csrf
                        <x-ui.button type="submit" tone="secondary">
                            {{ __('finance::action.close_facility') }}
                        </x-ui.button>
                    </form>
                @endif
            @endcan
        </section>
    </div>
    {{-- ⭐ কাগজপত্র — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ হলো।

         ⛔ এতদিন এই খাতায় কাগজ রাখার কোনো জায়গাই ছিল না, কারণ মডেলটা
         `Drillable` ছিল না — আর [[components/ui/attachments]] ঐ চুক্তি
         ধরেই কাগজ খোঁজে।

         ⚠️ মঞ্জুরিপত্রটাই বলে দেয় শর্ত কী ছিল — ব্যাংক পরে অন্য কথা বললে ওটাই উত্তর।
         ⓘ কাগজ বসে খাতায়, নড়াচড়ায় নয় — যতবারই টাকা ওঠানামা করুক,
         মূল কাগজটা একটাই। --}}
    <x-ui.attachments :document="$facility" />

    {{-- ⭐ ব্যাংকের বিবরণী বনাম খাতা — অর্থ-মডিউলের পরিকল্পনা ৩.৬, ৬ অক্টোবর ২০২৬ ([[BankFacilityService::statementGaps()]]) --}}
    <section data-boxed data-facility-statements
             class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <header class="border-b border-(--color-border) p-4">
            <h2 class="font-semibold">{{ __('finance::bank_loan_report.statements') }}</h2>
            <p class="mt-1 text-sm text-(--color-ink-muted)">{{ __('finance::bank_loan_report.statement_note') }}</p>
        </header>

        @can('finance.bank_facility.create')
            <form method="POST" action="{{ route('finance.bank_facility.statement', $facility) }}"
                  class="grid gap-3 border-b border-(--color-border) p-4 sm:grid-cols-4">
                @csrf
                <x-ui.field name="statement_on" type="date" required
                            :label="__('finance::bank_loan_report.statement_on')"
                            :value="old('statement_on', now()->toDateString())" />
                <x-ui.field name="bank_balance" type="number" step="0.01" inputmode="decimal" numeric required
                            :label="__('finance::bank_loan_report.bank_balance')" :value="old('bank_balance')" />
                <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />
                <div class="flex items-end">
                    <x-ui.button type="submit" tone="primary">{{ __('finance::bank_loan_report.record_statement') }}</x-ui.button>
                </div>
            </form>
        @endcan

        <x-ui.table
            :empty="__('finance::bank_loan_report.no_statement_yet')"
            :rows="$statements ?? []"
            :columns="[
                ['key' => 'on', 'label' => __('finance::bank_loan_report.statement_on'), 'width' => '9rem',
                 'render' => fn ($r) => \App\Core\Support\DateFormat::format($r['statement']->statement_on)],
                ['key' => 'bank', 'label' => __('finance::bank_loan_report.bank_balance'), 'numeric' => true,
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['statement']->bank_balance)],
                ['key' => 'books', 'label' => __('finance::bank_loan_report.books_balance'), 'numeric' => true,
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['books'])],
                ['key' => 'gap', 'label' => __('finance::bank_loan_report.gap'), 'numeric' => true,
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['gap'])],
                ['key' => 'note', 'label' => __('finance::field.note'),
                 'render' => fn ($r) => $r['statement']->note ?: '—'],
            ]" />
    </section>
</x-layouts.app>
