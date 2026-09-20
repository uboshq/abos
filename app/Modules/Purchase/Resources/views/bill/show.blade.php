{{-- একটা ক্রয় বিল। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $bill->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$bill->document_no" :subtitle="$bill->supplier?->name()">
            <x-slot:actions>
                @can('update', $bill)
                    <x-ui.button tone="secondary" :href="route('purchase.bill.edit', $bill)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.bill.confirm', $bill) }}">
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
                    ['label' => __('purchase::doc.bill'), 'url' => route('purchase.print.bill', $bill), 'paper_setting' => 'purchase.print.paper.bill', 'type' => 'purchase_bill', 'id' => $bill->id, 'no' => $bill->document_no],
                ]" />
                {{--
                    ⛔ নিশ্চিত বিলে কোনো পথই ছিল না — ১৮ সেপ্টেম্বর ২০২৬।

                    মালিকের কথা: *"Edite update delate er kono bebosta nai, Keno?"*।

                    ⓘ সম্পাদনা কেবল খসড়ায় — আর সেটা ঠিক: নিশ্চিত বিল
                    সরবরাহকারীর খাতায় দেনা বসিয়ে ফেলেছে।

                    ⚠️ কিন্তু বাতিলের রুট, কন্ট্রোলার আর অনুমতি তিনটাই আগে থেকে
                    বসানো ছিল (`purchase.bill.cancel`) — কেবল বোতামটা কেউ বসায়নি।
                    ⛔ কাজটা হয়েছিল, দরজাটা নয় — আর ব্যবহারকারীর কাছে দুইটা একই।

                    ⓘ কারণ বাধ্যতামূলক: কারণ ছাড়া বাতিল হওয়া কাগজ পরে কেউ
                    ব্যাখ্যা করতে পারে না — হিসাবের ভাউচারেও একই নিয়ম।
                --}}
                @unless ($bill->status === \App\Core\Support\DocumentStatus::CANCELLED)
                    @can('delete', $bill)
                        <form method="POST" action="{{ route('purchase.bill.cancel', $bill) }}"
                              x-data="reasonPrompt({ question: @js(__('purchase::message.cancel_reason_prompt')) })"
                              @submit="ask($event)">
                            @csrf
                            <input type="hidden" name="reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('purchase::action.cancel_document') }}
                            </x-ui.button>
                        </form>
                    @endcan
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

    <x-ui.errors />

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'purchase::field.date' => \App\Core\Support\DateFormat::format($bill->trx_date),
                    'purchase::field.due_on' => \App\Core\Support\DateFormat::format($bill->due_on) ?: '-',
                    'purchase::field.supplier_bill_no' => $bill->supplier_bill_no ?: '-',
                    'purchase::field.narration' => $bill->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$bill" /></dd>
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
                :rows="$bill->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'receipt', 'label' => __('purchase::field.receipt'), 'width' => '10rem',
                     'render' => fn ($l) => $l->receiptLine?->receipt?->document_no ?: '-'],
                    ['key' => 'qty', 'label' => __('purchase::field.quantity'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->qty)],

                    /*
                     * ⛔ ফ্রি-র পরিমাণ কোথাও দেখা যেত না — ১৮ সেপ্টেম্বর ২০২৬।
                     *
                     * মালিকের কথা: *“free gulo kothaw asteche na”*।
                     *
                     * ⓘ সংখ্যাটা সেভ হয় আর স্টকেও যায় (`free_qty` কলাম,
                     * [[PurchaseReceiptService]] ওটা পড়ে) — কেবল লেখার পরে
                     * আর কোনো পর্দায় দেখা যেত না। ⚠️ সরবরাহকারীর বিলে লেখা
                     * “২৪ + ১ ফ্রি”, আর আমাদের কাগজে কেবল “২৪” — ছয় মাস
                     * পরে মিলাতে গিয়ে কেউ বলতে পারত না ফ্রিটা কোথায় গেল।
                     *
                     * ⓘ কলামটা কেবল তখনই আসে যখন সত্যিই ফ্রি আছে —
                     * চিরকাল-শূন্য একটা কলাম কেবল জায়গা নেয়।
                     */
                    ...($bill->lines->contains(fn ($l) => bccomp((string) $l->free_qty, '0', 4) > 0)
                        ? [[
                            'key' => 'free_qty', 'label' => __('purchase::field.free_qty'),
                            'numeric' => true, 'width' => '7rem',
                            'render' => fn ($l) => bccomp((string) $l->free_qty, '0', 4) > 0
                                ? \App\Core\Support\Money::format($l->free_qty)
                                : '-',
                        ]]
                        : []),
                    ['key' => 'rate', 'label' => __('purchase::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'discount', 'label' => __('purchase::field.discount'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->discount)],
                    ['key' => 'tax', 'label' => __('purchase::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.subtotal' => $bill->subtotal,
                    'purchase::field.discount' => $bill->discount,
                    'purchase::field.tax' => $bill->tax,
                    'purchase::field.total' => $bill->total,
                ]" />
            </div>
        </section>

        {{-- সরবরাহকারীর আসল বিলের ছবি বা স্ক্যান — মিলিয়ে দেখার জন্য
             ছয় মাস পরেও এটাই লাগে --}}
        <x-ui.attachments :document="$bill" />

        @can('delete', $bill)
            @if ($bill->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.bill.cancel', $bill)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
