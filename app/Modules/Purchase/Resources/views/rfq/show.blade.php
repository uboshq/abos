{{--
    একটা দরপত্র — আর তার তুলনার ছক।

    ── ⭐ এই পাতাটাই পুরো কাজটার উদ্দেশ্য ───────────────────────────────
    ⓘ কে কত চাইল, কে কত দিনে দেবে, কার শর্ত কী — সব এক পর্দায়, পাশাপাশি।
    ⚠️ আগে এগুলো থাকত তিনটা আলাদা হোয়াটসঅ্যাপ বার্তায়, আর তুলনাটা হত
    কারও মাথায়।

    ── ⛔ ছকটা কাউকে বাছে না ───────────────────────────────────────────
    সবচেয়ে কম দরটা দাগানো হয়, কিন্তু *"এটাই নেওয়া হোক"* বলা হয় না।
    ⚠️ সস্তা মানেই সেরা নয়: একজন কম দর বলেন আর ত্রিশ দিনে মাল দেন,
    আরেকজন একটু বেশি বলেন আর তিন দিনে দেন। ⭐ সংখ্যাগুলো পাশাপাশি রাখা
    আমাদের কাজ; সিদ্ধান্তটা মানুষের।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $rfq->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$rfq->document_no"
                          :subtitle="__('purchase::menu.rfqs')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-4">
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.date') }}</dt>
                    <dd>{{ \App\Core\Support\DateFormat::format($rfq->trx_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.respond_by') }}</dt>
                    <dd>{{ $rfq->respond_by
                        ? \App\Core\Support\DateFormat::format($rfq->respond_by)
                        : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.warehouse') }}</dt>
                    <dd>{{ $rfq->warehouse?->name() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd>@include('purchase::rfq.partials.status', ['rfq' => $rfq])</dd>
                </div>
            </dl>

            @if ($rfq->terms)
                <p class="mt-3 text-sm">
                    <span class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.terms') }}:</span>
                    {{ $rfq->terms }}
                </p>
            @endif
        </section>

        {{-- ── কী চাওয়া হয়েছে ──────────────────────────────────────── --}}
        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="table-responsive">
                <table class="ui-lines table-cards w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('purchase::field.product') }}</th>
                            <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                            <th class="text-start">{{ __('purchase::field.specification') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rfq->lines as $line)
                            <tr class="border-b border-(--color-border)">
                                <td data-label="{{ __('purchase::field.product') }}">
                                    {{ $line->product?->name() ?? '—' }}
                                </td>
                                <td class="num text-end" data-label="{{ __('purchase::field.quantity') }}">
                                    {{ $line->qty }}
                                </td>
                                <td data-label="{{ __('purchase::field.specification') }}">
                                    {{ $line->specification ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ── পাঠানো ───────────────────────────────────────────────── --}}
        @can('send', $rfq)
            <form method="POST" action="{{ route('purchase.rfq.send', $rfq) }}"
                  data-boxed
                  class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                         border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf

                <x-ui.button type="submit" tone="primary">
                    {{ __('purchase::action.send_rfq') }}
                </x-ui.button>

                <p class="text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.rfq_send_note') }}
                </p>
            </form>
        @endcan

        {{-- ── তুলনা ─────────────────────────────────────────────────
             ⓘ ছকটা সবসময় আঁকা হয়, এমনকি একটাও দর না এলেও — ⚠️ তখন
             খালি ছকটাই সঠিক বার্তা: কেউ এখনো জবাব দেননি। --}}
        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b
                        border-(--color-border) bg-(--color-section-head) px-4 py-3">
                <h2 class="font-semibold">{{ __('purchase::field.comparison') }}</h2>

                @can('quote', $rfq)
                    <x-ui.button tone="secondary" :href="route('purchase.rfq.quote', $rfq)">
                        {{ __('purchase::action.add_quotation') }}
                    </x-ui.button>
                @endcan
            </div>

            @if ($comparison === [])
                <x-ui.empty-state :message="__('purchase::message.no_quotations_yet')" />
            @else
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('purchase::field.supplier') }}</th>
                                <th class="text-end">{{ __('purchase::field.goods_total') }}</th>
                                <th class="text-end">{{ __('purchase::field.freight') }}</th>
                                <th class="text-end">{{ __('purchase::field.other_charges') }}</th>
                                <th class="text-end">{{ __('purchase::field.total') }}</th>
                                <th class="text-end">{{ __('purchase::field.delivery_days') }}</th>
                                <th class="text-start">{{ __('purchase::field.payment_terms') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($comparison as $row)
                                <tr @class([
                                        'border-b border-(--color-border)',
                                        /* ⭐ সবচেয়ে কমটা দাগানো — কিন্তু কেবল
                                             **টেকা** দরগুলোর মধ্যে। ⚠️ মেয়াদ
                                             পেরোনো দর সস্তা হলেও ওটাকে "সেরা"
                                             দাগালে মানুষ ওটাই নিতেন, আর
                                             সরবরাহকারী বলতেন "ওটা পুরনো দর"। */
                                        'bg-(--color-badge-success-bg)' => $row['lowest'],
                                    ])>
                                    <td data-label="{{ __('purchase::field.supplier') }}">
                                        {{ $row['supplier']?->name() ?? '—' }}

                                        @if ($row['lowest'])
                                            <span class="ms-1 text-2xs font-medium">
                                                · {{ __('purchase::field.lowest') }}
                                            </span>
                                        @endif

                                        {{-- ⚠️ মেয়াদ পেরোনো দর তালিকা থেকে বাদ
                                             যায় না, কেবল দাগানো হয় — ⛔ বাদ দিলে
                                             ইতিহাসটাই অসম্পূর্ণ হত, আর পুরনো
                                             একটা দরও দরাদরির ভিত্তি হতে পারে। --}}
                                        @if ($row['expired'])
                                            <span class="ms-1 text-2xs text-(--color-badge-danger-ink)">
                                                · {{ __('purchase::field.quote_expired') }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="num text-end" data-label="{{ __('purchase::field.goods_total') }}">
                                        {{ \App\Core\Support\Money::format($row['goods']) }}
                                    </td>
                                    <td class="num text-end" data-label="{{ __('purchase::field.freight') }}">
                                        {{ \App\Core\Support\Money::format($row['freight']) }}
                                    </td>
                                    <td class="num text-end" data-label="{{ __('purchase::field.other_charges') }}">
                                        {{ \App\Core\Support\Money::format($row['other']) }}
                                    </td>
                                    <td class="num text-end font-semibold" data-label="{{ __('purchase::field.total') }}">
                                        {{ \App\Core\Support\Money::format($row['total']) }}
                                    </td>
                                    <td class="num text-end" data-label="{{ __('purchase::field.delivery_days') }}">
                                        {{ $row['delivery_days'] ?? '—' }}
                                    </td>
                                    <td data-label="{{ __('purchase::field.payment_terms') }}">
                                        {{ $row['payment_terms'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ── কে জবাব দেননি ─────────────────────────────────────────
             ⭐ এটাই তাগাদার তালিকা। ⛔ এটা ছাড়া RFQ কেবল একটা পাঠানো
             অনুরোধ — যার কোনো শেষ নেই। --}}
        @if ($rfq->isWaiting() && $silent->isNotEmpty())
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-1 text-sm font-semibold">{{ __('purchase::field.no_answer_yet') }}</h2>
                <p class="mb-2 text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.rfq_silent_note') }}
                </p>

                <ul class="list-inside list-disc text-sm">
                    @foreach ($silent as $supplier)
                        <li>{{ $supplier->name() }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
