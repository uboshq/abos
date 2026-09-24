{{-- একটা বিক্রয় বিল। --}}
{{-- ⓘ কাউন্টারে আটকে থাকা বিক্রয় কি — পাতার ওপরে একবার, যাতে শিরোনাম আর দেহ দুই জায়গায় পাওয়া যায় --}}
@php($held = ($heldDeposits ?? []) !== [])
{{-- ⓘ কাগজটা এখনো পাকা হয়নি — খসড়া হওয়ার দুইটা পথ, আর ছাপা দুইটাতেই বন্ধ --}}
@php($draft = $invoice->isNotFinalYet())

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $invoice->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$invoice->document_no" :subtitle="$invoice->customer?->name()">
            <x-slot:actions>
                {{-- ⭐ কাউন্টারে আটকে থাকা বিক্রয় (১৯ সেপ্টেম্বর ২০২৬): সম্পাদনা নেই,
                     ছাপা নেই, আর "নিশ্চিত" বোতামের নাম বলে দেয় সেটা কী করবে।
                     ⓘ বোতামটা একই রুটে যায় — [[SalesInvoiceController::confirm()]]
                     আটকে থাকা বিক্রয় চিনে ঠিক পথে পাঠায়। --}}

                @can('update', $invoice)
                    @unless ($held)
                        <x-ui.button tone="secondary" :href="route('sales.invoice.edit', $invoice)">
                            {{ __('core.action.edit') }}
                        </x-ui.button>
                    @endunless

                    <form method="POST" action="{{ route('sales.invoice.confirm', $invoice) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">
                            {{ $held ? __('sales::action.finish_held') : __('sales::action.confirm') }}
                        </x-ui.button>
                    </form>
                @endcan

                @if ($invoice->status === \App\Core\Support\DocumentStatus::CONFIRMED
                    && bccomp($invoice->dueAmount(), '0', 4) > 0)
                    {{-- ⭐ আদায় এখন হিসাবের রসিদ ভাউচারে (মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬) —
                         পক্ষ, বিল আর বকেয়া অঙ্ক আগে থেকে ভরা ([[VoucherController::prefill()]])। --}}
                    @can('accounts.voucher.create')
                        <x-ui.button tone="primary"
                                     :href="route('accounts.voucher.create', [
                                         'type' => \App\Modules\Accounts\Models\Voucher::RECEIPT,
                                         'party_type' => 'customer',
                                         'party_id' => $invoice->customer_id,
                                         'against_type' => \App\Modules\Sales\Models\SalesInvoice::drillSourceType(),
                                         'against_id' => $invoice->id,
                                         'amount' => $invoice->dueAmount(),
                                     ])">
                            {{ __('sales::action.collect_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                {{-- ⛔ খসড়া ছাপা হয় না — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬:
                     *"খসড়া print hobe na"*।

                     ⚠️ শর্তটা `$held` নয়, `$draft` — ⓘ `$held` কেবল সইয়ের
                     অপেক্ষায় থাকা জমাওয়ালা কাগজ চেনে, আর "খসড়া রাখুন"
                     বোতামে বানানো কাগজে কোনো জমাই থাকে না। ⛔ `$held`
                     রাখলে ছাপার মেনুটা ঐ কাগজগুলোয় দিব্যি দেখা যেত।

                     ⓘ সার্ভারের পাহারাটাও আছে
                     ([[SalesPrintController]]), কারণ বোতাম লুকানো আর
                     দরজা বন্ধ করা এক জিনিস নয় — ঠিকানা টাইপ করেও আসা যায়। --}}
                @unless ($draft)
                    <x-ui.print-menu :documents="[
                        [
                            'label' => __('sales::doc.invoice'),
                            'url' => route('sales.print.invoice', $invoice),

                            /* ⓘ কোন মাপে ছাপা হবে, কতবার বেরিয়েছে, আর গ্রাহককে
                               পাঠানোর পথ — তিনটাই এই তিনটা ঘর থেকে */
                            'paper_setting' => 'sales.print.paper.invoice',
                            'type' => \App\Modules\Sales\Models\PrintJob::INVOICE,
                            'id' => $invoice->id,
                            'no' => $invoice->document_no,
                            'share' => ['route' => 'sales.print.invoice', 'params' => ['invoice' => $invoice->id]],
                        ],

                        /* ⛔ খসড়ায় পাঠানোর পথ নেই: ওটা এখনো চূড়ান্ত নয়, আর
                           গ্রাহকের হাতে গেলে সেটাই বিল বলে ধরে নেওয়া হত */
                        [
                            'label' => __('core.print.draft_notice'),
                            'url' => route('sales.print.draft', $invoice),
                            'paper_setting' => 'sales.print.paper.invoice',
                        ],
                    ]" />
                @endunless
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- গ্রাহককে পাঠানোর লিংক — "পাঠান" চাপার পরে --}}
    <x-ui.shared-link :message="__('sales::doc.invoice').' '.$invoice->document_no" />

    <x-ui.errors />

    {{-- ⭐ কোন ডিপোজিট কার সইয়ের অপেক্ষায় — আটকে থাকা বিক্রয়ে কেবল --}}
    @if ($held)
        <section role="status" data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-badge-pending-ink)/30
                        bg-(--color-badge-pending-bg) p-4 text-sm text-(--color-badge-pending-ink)">
            <h2 class="mb-2 font-semibold">{{ __('sales::field.held_deposits') }}</h2>
            <p class="mb-2">{{ __('sales::message.held_explain') }}</p>
            <ul class="space-y-1">
                @foreach ($heldDeposits as $row)
                    <li class="flex flex-wrap gap-x-3">
                        <span class="font-medium">{{ $row['voucher']->document_no }}</span>
                        <span>{{ \App\Core\Support\Money::format($row['voucher']->amount) }}</span>
                        <span>{{ __('sales::field.deposit_state.'.($row['approval']?->status ?? 'none')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($invoice->trx_date),
                    'sales::field.due_on' => \App\Core\Support\DateFormat::format($invoice->due_on) ?: '-',
                    'sales::field.collected' => \App\Core\Support\Money::format($invoice->collectedAmount()),
                    'sales::field.due' => \App\Core\Support\Money::format($invoice->dueAmount()),
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$invoice" /></dd>
                </div>
            </dl>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$invoice->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'qty', 'label' => __('sales::field.quantity'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->qty)],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'discount', 'label' => __('sales::field.discount'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->discount)],
                    ['key' => 'tax', 'label' => __('sales::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],

                    /*
                     * নিয়মের বাইরে যাওয়া সংখ্যাগুলো — আলাদা একটা ঘরে।
                     *
                     * ── কেন দর ও ভ্যাটের ঘরে ঢোকানো হয়নি ────────────
                     * ওই দুইটা সংখ্যার ঘর, আর সংখ্যার ঘরে অক্ষর ঢুকলে
                     * উপরে-নিচে মিলিয়ে পড়া যায় না — এই তালিকার পুরো
                     * কাজটাই তো মিলিয়ে পড়া।
                     *
                     * ── কেন বেশিরভাগ সারিতে খালি, আর সেটাই ঠিক ───────
                     * ব্যতিক্রম দুর্লভ। রোজ ভরা থাকলে কেউ পড়ত না —
                     * খালি থাকে বলেই যেদিন কিছু লেখা থাকে সেদিন চোখে পড়ে।
                     */
                    ['key' => 'off_rule', 'label' => __('sales::field.off_rule'), 'width' => '11rem',
                     'render' => fn ($l) => implode(' · ', array_filter([
                         $l->price_variance === null ? null : __('sales::field.off_standard_price', [
                             'pct' => \App\Core\Support\Money::format($l->price_variance, 2),
                         ]),
                         $l->tax_variance === null ? null : __('sales::field.off_standard_tax', [
                             'amount' => \App\Core\Support\Money::format($l->tax_variance),
                         ]),
                     ]))],
                ]" />

            {{--
                বিক্রীত পণ্যের ব্যয় কেবল যাঁর দেখার কথা তাঁকেই।

                সারিটা এখানে ছিল সবার জন্য, অথচ ওটা ক্রয়মূল্য — বিলটা
                যিনি কাটছেন তাঁর কাজে লাগে না, আর জানা থাকলে দরকষাকষিতে
                ব্যবহার হয়। রিপোর্টের মুনাফার কলামটাও একই অনুমতির পেছনে,
                যাতে এক জায়গায় ঢাকা আর অন্য জায়গায় খোলা না থাকে।
            --}}
            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="array_filter([
                    'sales::field.subtotal' => $invoice->subtotal,
                    'sales::field.discount' => $invoice->discount,
                    'sales::field.tax' => $invoice->tax,
                    'sales::field.cost_of_goods' => auth()->user()?->can('sales.cost.view')
                        ? $invoice->cost_of_goods
                        : null,
                    'sales::field.total' => $invoice->total,
                ], fn ($value) => $value !== null)" />
            </div>
        </section>

        {{--
        ⭐ টাকাটা কোন কাগজে এল — ২১ সেপ্টেম্বর ২০২৬।

        ── ⛔ মালিকের প্রশ্ন ────────────────────────────────────────────
        *"INV-0004 ekta deposit diyechi ta haralo keno?"* — আর টাকাটা
        হারায়নি: আদায় ৫৪৩, বকেয়া ১৭৪.৬৫, সবই ঠিক গোনা হচ্ছিল।

        ⚠️ **হারিয়েছিল কাগজটা।** রসিদের নম্বরটা এই পাতায় কোথাও লেখা ছিল
        না। ⓘ পাতাটা রসিদ দেখাত কেবল **সইয়ের অপেক্ষায়** থাকা জমার
        বেলায় (উপরের ব্লকটা); নিশ্চিত হয়ে গেলে সে তালিকা থেকে উধাও।

        ⭐ একটা সংখ্যা যোগ হয়েছে দেখা, আর **কোন কাগজে** যোগ হয়েছে জানা —
        দুইটা আলাদা প্রশ্ন। দ্বিতীয়টার উত্তর ছাড়া কেউ মেলাতে পারেন না,
        আর তখন মনে হয় টাকাটাই হারিয়ে গেছে।

        ⓘ নম্বরটা ক্লিকযোগ্য (নিয়ম ১) — ভাউচারের নিজের পাতায় যায়।
    --}}
    @if ($invoice->receiptVouchers->isNotEmpty())
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::field.money_received') }}</h2>

            <x-ui.table
                :rows="$invoice->receiptVouchers"
                :empty="__('sales::message.no_receipts_yet')"
                :columns="[
                    [
                        'key' => 'trx_date',
                        'label' => __('core.print.date'),
                        'width' => '8rem',
                        'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date),
                    ],
                    [
                        'key' => 'document_no',
                        'label' => __('core.table.document'),
                        'width' => '10rem',
                        'render' => fn ($v) => view('sales::components.doc-link', [
                            'document' => $v,
                            'route' => 'accounts.voucher.show',
                        ]),
                    ],
                    [
                        'key' => 'narration',
                        'label' => __('accounts::field.narration'),
                        'render' => fn ($v) => $v->narration ?: '—',
                    ],
                    [
                        'key' => 'amount',
                        'label' => __('sales::field.amount'),
                        'numeric' => true,
                        'width' => '10rem',
                        'render' => fn ($v) => \App\Core\Support\Money::format($v->amount),
                    ],
                ]" />
        </section>
    @endif

    <x-ui.attachments :document="$invoice" />

        @can('delete', $invoice)
            @if ($invoice->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.invoice.cancel', $invoice)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
