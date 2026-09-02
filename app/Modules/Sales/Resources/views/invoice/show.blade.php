{{-- একটা বিক্রয় বিল। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $invoice->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$invoice->document_no" :subtitle="$invoice->customer?->name()">
            <x-slot:actions>
                @can('update', $invoice)
                    <x-ui.button tone="secondary" :href="route('sales.invoice.edit', $invoice)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.invoice.confirm', $invoice) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($invoice->status === \App\Core\Support\DocumentStatus::CONFIRMED
                    && bccomp($invoice->dueAmount(), '0', 4) > 0)
                    @can('create', \App\Modules\Sales\Models\Collection::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.collection.create', ['sales_invoice_id' => $invoice->id])">
                            {{ __('sales::action.collect_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::doc.invoice'), 'url' => route('sales.print.invoice', $invoice)],
                    ['label' => __('core.print.draft_notice'), 'url' => route('sales.print.draft', $invoice)],
                ]" />
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

        <x-ui.attachments :document="$invoice" />

        @can('delete', $invoice)
            @if ($invoice->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.invoice.cancel', $invoice)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
