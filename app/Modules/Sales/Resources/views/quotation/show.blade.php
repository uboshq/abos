{{--
    একটা বিক্রয় উদ্ধৃতি।

    ⓘ বোতামগুলো অবস্থা ধরে আসে — যে ধাপ এই মুহূর্তে সম্ভব, কেবল সেটাই।
    ⚠️ তবু বোতাম দেখানোই পাহারা নয়: প্রতিটা ধাপ সেবায় আবার দেখা হয়
    ([[SalesQuotationService]]), আর দরজাগুলো অনুমতির মিডলওয়্যারে।
--}}
@use('App\Modules\Sales\Models\SalesQuotation', 'Q')

@php
    $state = $quotation->effectiveStatus();
    $expired = $state === Q::EXPIRED;
    $status = $quotation->status;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $quotation->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$quotation->document_no" :subtitle="$quotation->customer?->name()">
            <x-slot:actions>
                @can('update', $quotation)
                    <x-ui.button tone="secondary" :href="route('sales.quotation.edit', $quotation)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('advance', $quotation)
                    @if ($status === Q::DRAFT && ! $expired)
                        <form method="POST" action="{{ route('sales.quotation.submit', $quotation) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('sales::quotation.action.submit') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($status === Q::SUBMITTED && ! $expired)
                        <form method="POST" action="{{ route('sales.quotation.approve', $quotation) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('sales::quotation.action.approve') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($status === Q::APPROVED && ! $expired)
                        <form method="POST" action="{{ route('sales.quotation.send', $quotation) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">{{ __('sales::quotation.action.send') }}</x-ui.button>
                        </form>
                    @endif

                    @if (in_array($status, [Q::SUBMITTED, Q::APPROVED, Q::SENT, Q::ACCEPTED, Q::REJECTED], true))
                        <form method="POST" action="{{ route('sales.quotation.revise', $quotation) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">{{ __('sales::quotation.action.revise') }}</x-ui.button>
                        </form>
                    @endif
                @endcan

                @if ($status === Q::ACCEPTED && ! $expired)
                    @can('convert', $quotation)
                        <form method="POST" action="{{ route('sales.quotation.convert', $quotation) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('sales::quotation.action.convert') }}</x-ui.button>
                        </form>
                    @endcan
                @endif

                <x-ui.print-menu :documents="[
                    ['label' => __('sales::quotation.doc'), 'url' => route('sales.quotation.paper', $quotation), 'paper_setting' => 'sales.print.paper.order'],
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
        @if ($expired)
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                        text-(--color-badge-warning-ink)">
                {{ __('sales::quotation.expired_banner', ['date' => \App\Core\Support\DateFormat::format($quotation->valid_until)]) }}
            </div>
        @endif

        @if ($quotation->order)
            <div role="status"
                 class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                        text-(--color-badge-success-ink)">
                {{ __('sales::quotation.converted_banner') }}
                @include('sales::components.doc-link', ['document' => $quotation->order, 'route' => 'sales.order.show'])
            </div>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($quotation->trx_date),
                    'sales::quotation.field.valid_until' => \App\Core\Support\DateFormat::format($quotation->valid_until),
                    'sales::quotation.field.price_list' => $quotation->priceList?->name() ?: '-',
                    'sales::quotation.field.payment_term' => $quotation->paymentTerm?->name() ?: '-',
                    'sales::quotation.field.delivery_terms' => $quotation->delivery_terms ?: '-',
                    'sales::field.narration' => $quotation->narration ?: '-',
                    'sales::quotation.field.answer_note' => $quotation->answer_note ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5">@include('sales::quotation.partials.status', ['quotation' => $quotation])</dd>
                </div>
            </dl>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::quotation.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$quotation->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'qty', 'label' => __('sales::field.quantity'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->packedQty('qty'))],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->packedUnitName()],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->packedRate('rate', 'qty'))],
                    ['key' => 'discount', 'label' => __('sales::field.discount'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->fullDiscount())],
                    ['key' => 'tax', 'label' => __('sales::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $quotation->subtotal,
                    'sales::quotation.field.header_discount' => $quotation->header_discount,
                    'sales::field.discount' => $quotation->discount,
                    'sales::field.tax' => $quotation->tax,
                    'sales::field.total' => $quotation->total,
                ]" />
            </div>
        </section>

        {{-- ডিলারের উত্তর — "না"-র কারণ বাধ্যতামূলক, পরের দরটা ওখান থেকেই আসে --}}
        @can('advance', $quotation)
            @if (in_array($status, [Q::APPROVED, Q::SENT], true))
                <div class="grid gap-4 sm:grid-cols-2">
                    @unless ($expired)
                        <details class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                            <summary class="cursor-pointer text-sm font-medium">{{ __('sales::quotation.action.accept') }}</summary>

                            <form method="POST" action="{{ route('sales.quotation.accept', $quotation) }}" class="mt-3 space-y-3">
                                @csrf
                                <x-ui.field name="note" :label="__('sales::quotation.message.accept_note')" />
                                <x-ui.button type="submit" tone="primary">{{ __('sales::quotation.action.accept') }}</x-ui.button>
                            </form>
                        </details>
                    @endunless

                    <details class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                        <summary class="cursor-pointer text-sm font-medium">{{ __('sales::quotation.action.reject') }}</summary>

                        <form method="POST" action="{{ route('sales.quotation.reject', $quotation) }}" class="mt-3 space-y-3">
                            @csrf
                            <x-ui.field name="note" :label="__('sales::quotation.message.reject_note')" required />
                            <x-ui.button type="submit" tone="danger">{{ __('sales::quotation.action.reject') }}</x-ui.button>
                        </form>
                    </details>
                </div>
            @endif
        @endcan

        {{-- ডিলারের পাঠানো কাগজ বা সই করা কপি — দর নিয়ে প্রশ্ন উঠলে এটাই প্রমাণ --}}
        <x-ui.attachments :document="$quotation" />

        @can('delete', $quotation)
            @if (in_array($status, [...Q::EXPIRABLE, Q::REJECTED], true))
                <x-sales::cancel-form :action="route('sales.quotation.cancel', $quotation)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
