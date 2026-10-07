{{-- একটা বিক্রয় আদেশ। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $order->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$order->document_no" :subtitle="$order->customer?->name()">
            <x-slot:actions>
                @can('update', $order)
                    <x-ui.button tone="secondary" :href="route('sales.order.edit', $order)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.order.confirm', $order) }}" data-confirm-overview="{{ route('sales.order.overview', $order) }}">
                        @csrf
                        {{-- ⭐ নতুন ধারায় "নিশ্চিত" মানে "জমা" — বাকির যাচাই, তারপর সুপারভাইজার (নকশার ধাপ ৮) --}}
                        <x-ui.button type="submit" tone="primary" data-overview-trigger>{{ $replacesDo ? __('sales::order_status.submit') : __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($order->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    @can('create', \App\Modules\Sales\Models\DeliveryChallan::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.challan.create', ['sales_order_id' => $order->id])">
                            {{ __('sales::action.deliver_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::doc.order'), 'url' => route('sales.print.order', $order), 'paper_setting' => 'sales.print.paper.order', 'type' => 'sales_order', 'id' => $order->id, 'no' => $order->document_no, 'share' => ['route' => 'sales.print.order', 'params' => ['order' => $order->id]]],
                    ['label' => __('sales::doc.delivery_order'), 'url' => route('sales.print.delivery_order', $order), 'paper_setting' => 'sales.print.paper.order'],
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

    <x-ui.errors />

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($order->trx_date),
                    'sales::field.deliver_on' => \App\Core\Support\DateFormat::format($order->deliver_on) ?: '-',
                    'sales::field.warehouse' => $order->warehouse?->name() ?: '-',
                    'sales::field.narration' => $order->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5" data-order-header-state>
                        @include('sales::order.partials.state-chip', [
                            'status' => (string) $order->status,
                            'delivery' => $status['delivery'],
                            'billing' => $status['billing'],
                            'back' => $status['back'],
                            'stale' => $status['stale'],
                            'days' => $status['age_days'],
                            'paperDate' => \App\Core\Support\DateFormat::format($order->trx_date),
                        ])
                    </dd>
                </div>

                {{-- ⓘ বাতিলের কারণ থাকে — পাতায় দেখা যায়, মোছা হয় না (নিয়ম ৫) --}}
                @if ($order->status === \App\Core\Support\DocumentStatus::CANCELLED && filled($order->cancel_reason))
                    <div data-cancel-reason>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::order_status.field_cancel_reason') }}</dt>
                        <dd class="mt-0.5">{{ $order->cancel_reason }}</dd>
                    </div>
                @endif

                @if ($order->status === \App\Core\Support\DocumentStatus::CLOSED)
                    <div data-close-reason>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::order_status.field_close_reason') }}</dt>
                        <dd class="mt-0.5">{{ $order->close_reason ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::order_status.field_closed_by') }}</dt>
                        <dd class="mt-0.5">{{ $order->closer?->name ?? '—' }} · {{ \App\Core\Support\DateFormat::format($order->closed_at) }}</dd>
                    </div>
                @endif

                @if ($order->quotation)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::quotation.doc') }}</dt>
                        <dd class="mt-0.5">
                            @can('view', $order->quotation)
                                @include('sales::components.doc-link', ['document' => $order->quotation, 'route' => 'sales.quotation.show'])
                            @else
                                {{ $order->quotation->document_no }}
                            @endcan
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- ⭐ সইয়ের অপেক্ষা, সীমায় আটকে, সতর্কবার্তা, ধরা মাল (নকশার ধাপ ৮) --}}
        @include('sales::order.partials.flow-boxes')

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$order->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'ordered_qty', 'label' => __('sales::field.ordered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->ordered_qty)
                        . ($l->requested_qty !== null && bccomp((string) $l->requested_qty, (string) $l->ordered_qty, 4) !== 0
                            ? ' · ' . __('sales::order_status.requested', ['qty' => \App\Core\Support\Money::quantity((string) $l->requested_qty)])
                            : '')],
                    ['key' => 'delivered', 'label' => __('sales::field.delivered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->deliveredQty())],
                    ['key' => 'pending', 'label' => __('sales::field.pending'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->pendingQty())],
                    ['key' => 'billed', 'label' => __('sales::order_status.field_billed'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($status['lines'][$l->id]['billed'] ?? '0')],
                    ['key' => 'line_state', 'label' => __('sales::order_status.field_progress'), 'width' => '12rem',
                     'render' => fn ($l) => view('sales::order.partials.state-chip', [
                         'lineStatus' => $status['lines'][$l->id]['line_status'] ?? 'open',
                         'delivery' => $status['lines'][$l->id]['delivery'] ?? null,
                         'billing' => $status['lines'][$l->id]['billing'] ?? null,
                         'back' => $status['lines'][$l->id]['back'] ?? false,
                     ])],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $order->subtotal,
                    'sales::field.discount' => $order->discount,
                    'sales::field.tax' => $order->tax,
                    'sales::field.total' => $order->total,
                ]" />
            </div>
        </section>

        {{-- ⭐ সুপারভাইজারের পরিমাণ, আর এক লাইনের বাকিটা বন্ধ (নকশার ধাপ ৮) --}}
        @include('sales::order.partials.flow-forms')

        @can('delete', $order)
            {{-- ⓘ বন্ধ আদেশ বাতিল হয় না — বন্ধের দিনেই তার বাকি মাল ছাড়া হয়েছে --}}
            @if (! in_array($order->status, [\App\Core\Support\DocumentStatus::CANCELLED, \App\Core\Support\DocumentStatus::CLOSED], true))
                <x-sales::cancel-form :action="route('sales.order.cancel', $order)" />
            @endif
        @endcan

        {{-- ⭐ বন্ধ — পুরো বিলের পরে কারণ ছাড়া, কম রেখে কারণসহ (মালিক, ৪ অক্টোবর ২০২৬; [[SalesOrderService::close()]]) --}}
        @can('sales.order.close')
            @if ($order->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                <details data-close-order
                         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <summary class="cursor-pointer text-sm font-medium">{{ __('sales::order_status.close') }}</summary>

                    <form method="POST" action="{{ route('sales.order.close', $order) }}" class="mt-3 grid gap-3">
                        @csrf
                        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::order_status.close_hint') }}</p>
                        <x-ui.field name="close_reason" :label="__('sales::order_status.close_reason')" />
                        <x-ui.button type="submit" tone="secondary">{{ __('sales::order_status.close') }}</x-ui.button>
                    </form>
                </details>
            @endif
        @endcan
    </div>
    {{-- ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ ([[confirm-overview.js]], ৪ অক্টোবর ২০২৬) — আদেশটা আগেই খসড়া, তাই "খসড়া রাখুন" নেই --}}
    <x-ui.confirm-overview :draft="false" />
</x-layouts.app>
