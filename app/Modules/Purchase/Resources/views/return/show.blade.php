{{-- একটা ক্রয় ফেরত। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $return->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$return->document_no" :subtitle="$return->supplier?->name()">
            <x-slot:actions>
                @can('update', $return)
                    <x-ui.button tone="secondary" :href="route('purchase.return.edit', $return)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.return.confirm', $return) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
                @endcan
                {{--
                    ⛔ ছাপার দরজাটা কোথাও ছিল না — ১৮ সেপ্টেম্বর ২০২৬।

                    ── ⓘ যা পাওয়া গেল ──────────────────────────────────
                    মালিক বললেন *"print er bebosta nai"*। ⚠️ খুঁজে দেখা গেল
                    ছাপার **সবকিছুই বানানো**: চারটা রুট
                    (`purchase.print.bill/order/receipt/return`), একটা
                    কন্ট্রোলার, আর ছাপার ইঞ্জিন।

                    ⛔ কেবল একটাও পর্দা ঐ রুটগুলোয় লিংক দেয়নি। অর্থাৎ
                    কাজটা হয়েছিল, দরজাটা কেউ বসায়নি — আর ব্যবহারকারীর
                    কাছে "নেই" আর "পৌঁছানো যায় না" এক জিনিস।

                    ⓘ বিক্রয়ে এই কাজটা প্রথম দিন থেকেই ছিল
                    ([[Sales/invoice/show]]), আর সেখানে ব্যবহৃত
                    [[components/ui/print-menu]] কম্পোনেন্টটাই এখানে বসল —
                    দুই মডিউলে দুই রকম ছাপার বোতাম হলে একদিন একটায়
                    খসড়ার কপি থাকত, অন্যটায় না।
                --}}
                <x-ui.print-menu :documents="[
                    ['label' => __('purchase::doc.return'), 'url' => route('purchase.print.return', $return)],
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
                    'purchase::field.date' => \App\Core\Support\DateFormat::format($return->trx_date),
                    'purchase::field.warehouse' => $return->warehouse?->name() ?: '-',
                    'purchase::field.bill' => $return->bill?->document_no ?: '-',
                    'purchase::field.reason' => $return->reasonCode?->name() ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$return" /></dd>
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
                :rows="$return->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->name()],
                    ['key' => 'qty', 'label' => __('purchase::field.quantity'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => rtrim(rtrim((string) $l->qty, '0'), '.').' '.($l->product?->unit?->code ?? '')],
                    ['key' => 'rate', 'label' => __('purchase::field.rate'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'tax', 'label' => __('purchase::field.tax'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.subtotal' => $return->subtotal,
                    'purchase::field.tax' => $return->tax,
                    'purchase::field.total' => $return->total,
                ]" />
            </div>
        </section>

        @can('delete', $return)
            @if ($return->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.return.cancel', $return)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
