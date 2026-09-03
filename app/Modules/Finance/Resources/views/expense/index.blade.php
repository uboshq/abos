{{--
    খরচ — কোন খাতে কত গেল।

    ── কেন তালিকা নয়, খাত ─────────────────────────────────────────────
    ভাউচারের তালিকা হিসাবে আছেই। ম্যানেজার তালিকা পড়েন না; তিনি জানতে
    চান এই মাসে জ্বালানিতে কত গেল, আর গত মাসের চেয়ে বেশি না কম।

    ── কেন আগের সময়টা পাশে ────────────────────────────────────────────
    "জ্বালানিতে ১২,৪০০" একা কিছু বলে না। "আগে ছিল ৮,১০০" বলার পরেই
    সংখ্যাটা একটা প্রশ্ন হয়ে ওঠে — আর ওই প্রশ্নটাই খরচ কমানোর শুরু।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.expense') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.expense')"
                          :subtitle="__('finance::message.expense_note')" />
    </x-slot:header>

    {{-- সময়ের পরিসর — ডিফল্ট চলতি মাস, কারণ ভাড়া-বেতন-বিদ্যুৎ মাসের
         হিসাব, আর "আজ কত গেল" প্রশ্নটা কেউ করে না। --}}
    <form method="GET" class="mb-3 flex flex-wrap items-end gap-2">
        <x-ui.field name="from" type="date" :label="__('finance::field.from')" :value="$from" />
        <x-ui.field name="to" type="date" :label="__('finance::field.to')" :value="$to" />

        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>

        <span class="flex-1"></span>

        {{-- খরচ লেখা হয় ভাউচারেই — এখানে আরেকটা ফর্ম বানালে একই
             জিনিসের দুইটা পথ হত, আর দুইটার যাচাই একদিন আলাদা হয়ে যেত। --}}
        <x-ui.button tone="primary" icon="plus"
                     :href="route('accounts.voucher.create', ['type' => 'expense'])">
            {{ __('finance::action.new_expense') }}
        </x-ui.button>
    </form>

    <section data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.by_head') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_expense_yet')"
            :rows="$heads"
            :columns="[
                ['key' => 'head', 'label' => __('finance::field.head'),
                 'render' => fn ($r) => $r['account']->name()],
                ['key' => 'now', 'label' => __('finance::field.this_period'), 'numeric' => true, 'width' => '11rem',
                 'render' => fn ($r) => view('ui.amount-link', [
                     'value' => $r['now'],
                     'href' => route('accounts.coa.show', $r['account']).'#transactions',
                 ])],
                ['key' => 'before', 'label' => __('finance::field.period_before'), 'numeric' => true, 'width' => '11rem',
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['before'])],
                ['key' => 'change', 'label' => __('finance::field.change'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($r) => view('finance::expense.partials.change', ['row' => $r])],
            ]" />
    </section>

    {{--
        অনুমোদনের অপেক্ষায় — উপরের যোগফলে এগুলো **নেই**।

        ── কেন এটা উপরের তালিকার পরে, নিচেরটার আগে ──────────────────────
        উপরে "কোন খাতে কত গেল", আর এই কাগজগুলো ঠিক ওই সংখ্যাগুলো থেকেই
        বাদ পড়েছে — খসড়া খতিয়ানে বসেনি, তাই কোনো খাতে যোগও হয়নি।
        পাশাপাশি না রাখলে ম্যানেজার কম খরচ দেখে সিদ্ধান্ত নিতেন, আর
        অনুমোদন হয়ে গেলে সংখ্যাটা মাসের মাঝখানে হঠাৎ বেড়ে যেত।

        ⚠️ ── ছক না বসালে এই অংশটা কোনোদিন দেখাই যায় না ──────────────────
        `@if` ইচ্ছাকৃত। কোনো কোম্পানি অনুমোদনের ছক না বসালে সংগ্রহটা
        চিরকাল খালি, আর একটা চিরকাল-খালি বাক্স পর্দায় থাকা মানে **প্রতিদিন
        একটা প্রশ্ন যার উত্তর কেউ জানে না** ("এখানে কী আসার কথা ছিল?")।
        আজ চারটা কোম্পানির একটাতেও খরচের ছক নেই — অর্থাৎ আজ এই অংশটা
        কারো পর্দায় নেই, আর সেটাই ঠিক।
    --}}
    @if ($waiting->isNotEmpty())
        <section data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-center gap-2 border-b border-(--color-border)
                       bg-(--color-section-head) px-4 py-3 font-semibold">
                <x-ui.icon name="clock" :size="16" />
                {{ __('finance::field.waiting_approval') }}

                {{-- সংখ্যাটা শিরোনামেই, কারণ প্রশ্নটা "কয়টা" — আর
                     উত্তরটা ঠিক নিচেই, গোনা যায় এমন সারিতে (নিয়ম ১) --}}
                <span class="rounded-full bg-(--color-badge-warning-bg) px-2 py-0.5 text-xs
                             text-(--color-badge-warning-ink)">{{ $waiting->count() }}</span>
            </h2>

            <p class="border-b border-(--color-border) px-4 py-2 text-xs text-(--color-ink-muted)">
                {{ __('finance::message.waiting_approval_note') }}
            </p>

            <x-ui.table
                :rows="$waiting"
                :columns="[
                    ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                     'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
                    /* নম্বরটাই লিংক — ঝুলে থাকা কাগজটা দেখার পথ (নিয়ম ১) */
                    ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                     'render' => fn ($v) => view('finance::expense.partials.paper', ['voucher' => $v])],
                    ['key' => 'narration', 'label' => __('finance::field.what_for')],
                    ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($v) => \App\Core\Support\Money::format($v->totals()['debit'])],
                ]" />
        </section>
    @endif

    {{-- শেষ কুড়িটা — "আজ কী কী লেখা হয়েছে" প্রশ্নের উত্তর, আর ওটাই
         দিনের শেষে মিলিয়ে দেখার একমাত্র জায়গা। --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.recent_expenses') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_expense_yet')"
            :rows="$recent"
            :columns="[
                ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                 'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
                /* নম্বরটাই লিংক — কাগজটা দেখার একমাত্র পথ (নিয়ম ১) */
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                 'render' => fn ($v) => view('finance::expense.partials.paper', ['voucher' => $v])],
                ['key' => 'narration', 'label' => __('finance::field.what_for')],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($v) => \App\Core\Support\Money::format($v->totals()['debit'])],
            ]" />
    </section>
</x-layouts.app>
