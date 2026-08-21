{{--
    একটা ক্রয় আদেশ।

    উপরে ডকুমেন্ট, নিচে লাইন, আর প্রতিটা লাইনে "কত এসেছে · কত বাকি" —
    এটাই আদেশের একমাত্র কাজ, তাই এটাই সবচেয়ে চোখে পড়া উচিত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $order->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$order->document_no" :subtitle="$order->supplier?->name()">
            <x-slot:actions>
                @can('update', $order)
                    <x-ui.button tone="secondary" :href="route('purchase.order.edit', $order)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.order.confirm', $order) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($order->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    @can('create', \App\Modules\Purchase\Models\PurchaseReceipt::class)
                        <x-ui.button tone="primary"
                                     :href="route('purchase.receipt.create', ['purchase_order_id' => $order->id])">
                            {{ __('purchase::action.receive_against') }}
                        </x-ui.button>
                    @endcan

                    {{--
                        চালান ছাড়াই সরাসরি বিল।

                        ছোট ডিপো মাল গ্রহণের কাগজ লেখে না — গাড়ি আসে, মাল
                        নামে, চালান হাতে। এতদিন এই পথটা ছিল না, তাই আদেশ
                        তৈরি হয়ে ঝুলে থাকত; আর Control Panel-এ GRN-এর
                        পর্দাটা বন্ধ করলে তো উপায়ই থাকত না।

                        মালটা তখন এই বিল নিশ্চিত করার সময়েই গুদামে ঢোকে,
                        কারণ মাঝে কোনো গ্রহণের কাগজ নেই।
                    --}}
                    @can('create', \App\Modules\Purchase\Models\PurchaseBill::class)
                        <x-ui.button tone="secondary"
                                     :href="route('purchase.bill.create', ['purchase_order_id' => $order->id])">
                            {{ __('purchase::action.bill_against_order') }}
                        </x-ui.button>
                    @endcan
                @endif
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
                    'purchase::field.date' => \App\Core\Support\DateFormat::format($order->trx_date),
                    'purchase::field.expected_on' => \App\Core\Support\DateFormat::format($order->expected_on) ?: '-',
                    'purchase::field.warehouse' => $order->warehouse?->name() ?: '-',
                    'purchase::field.narration' => $order->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5">
                        <x-purchase::status-badge :document="$order" />
                    </dd>
                </div>
            </dl>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('purchase::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('purchase::validation.no_lines')"
                :rows="$order->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('purchase::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'ordered_qty', 'label' => __('purchase::field.ordered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->ordered_qty)],
                    ['key' => 'received', 'label' => __('purchase::field.received'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->receivedQty())],
                    ['key' => 'pending', 'label' => __('purchase::field.pending'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->pendingQty())],
                    ['key' => 'rate', 'label' => __('purchase::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.subtotal' => $order->subtotal,
                    'purchase::field.discount' => $order->discount,
                    'purchase::field.tax' => $order->tax,
                    'purchase::field.total' => $order->total,
                ]" />
            </div>
        </section>

        @if ($order->receipts->isNotEmpty())
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('purchase::menu.receipts') }}</h2>
                <ul class="space-y-1 text-sm">
                    @foreach ($order->receipts as $receipt)
                        <li>
                            <x-purchase::doc-link :document="$receipt" route="purchase.receipt.show" />
                            <span class="text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($receipt->trx_date) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @can('delete', $order)
            @if ($order->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.order.cancel', $order)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
