{{-- একটা ডেলিভারি চালান। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $challan->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$challan->document_no" :subtitle="$challan->customer?->name()">
            <x-slot:actions>
                @can('update', $challan)
                    <x-ui.button tone="secondary" :href="route('sales.challan.edit', $challan)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.challan.confirm', $challan) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                {{-- ⭐ বিল হয়ে থাকলে তার লিংক; বোতাম কেবল বিল করার কিছু বাকি থাকলে ([[ChallanBills]]) --}}
                @if (($bills ?? collect())->isNotEmpty())
                    <span class="flex flex-wrap items-center gap-2" data-challan-bills>
                        <span class="text-sm text-(--color-ink-muted)">{{ __('sales::action.challan_bills') }}</span>
                        @foreach ($bills as $bill)
                            @can('view', $bill)
                                <x-ui.button :href="route('sales.invoice.show', $bill)">{{ $bill->document_no }}</x-ui.button>
                            @else
                                <span class="text-sm">{{ $bill->document_no }}</span>
                            @endcan
                        @endforeach
                    </span>
                @endif

                @if ($challan->status === \App\Core\Support\DocumentStatus::CONFIRMED && ($leftToBill ?? true))
                    @can('create', \App\Modules\Sales\Models\SalesInvoice::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.invoice.create', ['delivery_challan_id' => $challan->id])">
                            {{ __('sales::action.invoice_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                {{-- ⭐ মাল কীভাবে যাবে — নিশ্চিতের পরে, ছাপার আগে (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬) --}}
                @if (! in_array($challan->status, [\App\Core\Support\DocumentStatus::CANCELLED], true))
                    @can('sales.challan.create')
                        <x-ui.button tone="secondary" :href="route('sales.challan.transport', $challan)" data-transport-button>
                            {{ __('sales::transport.button') }}
                        </x-ui.button>
                    @endcan
                @endif
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::print.challan_with_amounts'), 'url' => route('sales.print.challan', [$challan, 'prices' => 1]), 'paper_setting' => 'sales.print.paper.challan', 'type' => 'sales_challan', 'id' => $challan->id, 'no' => $challan->document_no, 'share' => ['route' => 'sales.print.challan', 'params' => ['challan' => $challan->id]]],
                    ['label' => __('sales::print.challan_without_amounts'), 'url' => route('sales.print.challan', [$challan, 'prices' => 0]), 'paper_setting' => 'sales.print.paper.challan'],
                    ['label' => __('sales::doc.gatepass'), 'url' => route('sales.print.gatepass', $challan), 'paper_setting' => 'sales.print.paper.challan'],
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
                    'sales::field.date' => \App\Core\Support\DateFormat::format($challan->trx_date),
                    'sales::field.warehouse' => $challan->warehouse?->name() ?: '-',
                    // ⭐ মাল কীভাবে গেল — ছাপার সাথে একই উত্তর ([[DeliveryChallan::transportLabel()]])
                    'sales::field.carrier' => $challan->transportLabel() ?: '-',
                    'sales::field.vehicle_no' => $challan->vehiclePlate() ?: '-',
                    'sales::field.driver_name' => $challan->driver_name ?: '-',
                    'sales::field.driver_phone' => $challan->driver_phone ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$challan" /></dd>
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
                :rows="$challan->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'delivered_qty', 'label' => __('sales::field.delivered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->delivered_qty)],
                    ['key' => 'uninvoiced', 'label' => __('sales::field.uninvoiced'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->uninvoicedQty())],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.total' => $challan->total,
                ]" />
            </div>
        </section>

        {{-- গ্রাহকের সই করা চালানের কপি — ডেলিভারি নিয়ে প্রশ্ন উঠলে
             এটাই একমাত্র প্রমাণ --}}
        <x-ui.attachments :document="$challan" />

        {{-- ⭐ চালানের সময়রেখা — তৈরি → গাড়ি → লোডিং → প্যাক → গেট পাস → পথে → পৌঁছেছে, সময়, কে, গাড়ি আর চালকসহ (৪ অক্টোবর ২০২৬) --}}
        @include('sales::tracking.partials.timeline', ['timeline' => app(\App\Modules\Sales\Services\SaleTracking::class)->timeline($challan)])

        {{-- NEXUS 21-22: delivery stage timeline; the partial checks sales.delivery.view itself --}}
        @include('sales::delivery.partials.timeline', ['challan' => $challan])

        @can('delete', $challan)
            @if ($challan->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.challan.cancel', $challan)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
