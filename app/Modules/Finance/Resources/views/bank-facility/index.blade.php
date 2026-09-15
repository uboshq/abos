{{--
    ব্যাংকের সুবিধা — মঞ্জুরি, জামানত, নবায়ন।

    ── ⛔ যা এই পর্দায় নেই: টাকা নাড়ার বোতাম ──────────────────────────
    হাতধারের পর্দায় "টাকা দিলাম / নিলাম" আছে; এখানে নেই, আর সেটা
    ইচ্ছাকৃত। ⓘ **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে** — মালিকের
    সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬।

    ⚠️ দুইটা দরজা থাকলে একদিন একটায় চার্জের ঘর বসত, অন্যটায় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.bank_facility') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.bank_facility')"
                          :subtitle="__('finance::message.facility_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-badge-success-bg px-3 py-2 text-sm text-badge-success-ink">
            {{ session('saved') }}
        </div>
    @endif

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

    {{--
        ⭐ নবায়নের তালিকা — উপরে, আলাদা করে।

        ⛔ CC ও LTR বার্ষিক নবায়ন হয়, আর তারিখটা কেউ না দেখলে সুবিধাটা
        **নীরবে ফুরায়**। ⚠️ টের পাওয়া যায় একটা চেক ফেরত এলে — সাধারণত
        সরবরাহকারীর সামনে। ⓘ নিচের তালিকায় মিশিয়ে দিলে ঐ সারিটা আর
        দশটার মতোই দেখাত।
    --}}
    @if ($renewals->isNotEmpty())
        <section data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-badge-warning-bg p-4">
            <h2 class="font-semibold text-badge-warning-ink">
                {{ __('finance::message.facility_renewals_due') }}
            </h2>
            <ul class="mt-2 space-y-1 text-sm text-badge-warning-ink">
                @foreach ($renewals as $due)
                    <li>
                        <a href="{{ route('finance.bank_facility.show', $due) }}"
                           class="underline-offset-2 hover:underline">
                            {{ $due->bank }} —
                            {{ __('finance::field.facility_' . $due->kind) }} ·
                            <x-ui.amount :value="$due->limit_amount" />
                        </a>
                        <span class="text-(--color-ink-muted)">
                            {{ $due->renews_on?->translatedFormat('j F Y') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
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
        <form method="POST" action="{{ route('finance.bank_facility.store') }}"
              x-data="{ kind: '{{ old('kind', \App\Modules\Finance\Models\BankFacility::CC) }}' }"
              class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.select name="kind" :label="__('finance::field.facility_kind')"
                         x-model="kind"
                         :options="collect(\App\Modules\Finance\Models\BankFacility::KINDS)
                             ->mapWithKeys(fn (string $k) => [$k => __('finance::field.facility_' . $k)])"
                         :selected="old('kind', \App\Modules\Finance\Models\BankFacility::CC)" required />

            <x-ui.field name="bank" :label="__('finance::field.bank')" :value="old('bank')" required />
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
            <template x-if="kind === 'cc'">
                <div class="contents">
                    <x-ui.select name="money_account_id" :label="__('finance::field.facility_account')"
                                 :options="$moneyAccounts->mapWithKeys(fn ($a) => [$a->id => $a->code . ' · ' . $a->name()])"
                                 :selected="old('money_account_id')" />

                    <x-ui.field name="stock_value" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.stock_value')"
                                :value="old('stock_value')" numeric />

                    <x-ui.field name="margin_percent" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.margin_percent')"
                                :value="old('margin_percent', '30')" numeric />
                </div>
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

            <x-ui.select name="security_type" :label="__('finance::field.security_type')"
                         :options="collect(\App\Modules\Finance\Models\BankFacility::SECURITIES)
                             ->mapWithKeys(fn (string $s) => [$s => __('finance::field.security_' . $s)])"
                         :selected="old('security_type', \App\Modules\Finance\Models\BankFacility::UNSECURED)" />

            <x-ui.field name="security_value" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.security_value')"
                        :value="old('security_value')" numeric />

            <x-ui.field name="guarantors" :label="__('finance::field.guarantors')" :value="old('guarantors')" />
            <x-ui.field name="covenant" :label="__('finance::field.covenant')" :value="old('covenant')" />
            <x-ui.field name="charges" type="number" step="0.01" inputmode="decimal"
                        :label="__('finance::field.charges')" :value="old('charges', '0')" numeric />

            <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('finance::message.no_facilities')"
            :rows="$facilities"
            :columns="[
                ['key' => 'bank', 'label' => __('finance::field.bank'),
                 'render' => fn ($f) => new \Illuminate\Support\HtmlString(
                     '<a href=\'' . route('finance.bank_facility.show', $f->id) . '\' '
                     . 'class=\'text-brand-500 underline-offset-2 hover:underline\'>'
                     . e($f->bank) . '</a>')],
                ['key' => 'kind', 'label' => __('finance::field.facility_kind'), 'width' => '10rem',
                 'render' => fn ($f) => __('finance::field.facility_' . $f->kind)],
                {{-- ⓘ চুক্তিটা `numeric`, `align`/`money` নয় — কম্পোনেন্টের
                     নিজের নিয়ম ([[App\View\Components\Ui\Table]])। --}}
                ['key' => 'limit_amount', 'label' => __('finance::field.limit_amount'), 'numeric' => true,
                 'render' => fn ($f) => \App\Core\Support\Money::format($f->limit_amount)],

                {{-- ⚠️ `null` মানে "প্রশ্নটাই অপ্রাসঙ্গিক", শূন্য নয় —
                     মেয়াদি ঋণের সারিতে শূন্য দেখালে কেউ ভাবতেন সীমা
                     ফুরিয়ে গেছে। --}}
                ['key' => 'drawing', 'label' => __('finance::field.drawing_power'), 'numeric' => true,
                 'render' => fn ($f) => $f->drawingPower() === null
                     ? '—'
                     : \App\Core\Support\Money::format($f->drawingPower())],
                ['key' => 'renews_on', 'label' => __('finance::field.renews_on'), 'width' => '9rem',
                 'render' => fn ($f) => $f->renews_on?->translatedFormat('j M Y') ?? '—'],
                ['key' => 'status', 'label' => __('finance::field.state'), 'width' => '8rem',
                 'render' => fn ($f) => __('core.status.' . $f->status)],
            ]" />
    </div>
</x-layouts.app>
