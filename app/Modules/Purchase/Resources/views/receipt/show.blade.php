{{-- একটা চালান — কী এসেছে, আর তার কতটুকুর বিল হয়েছে। --}}
{{-- ⛔ ক্রয়মূল্য কেবল খরচ দেখার চাবিতে — ছাপার একই নিয়ম ([[PurchasePrintController::showsPurchasePrice()]]); fe, ১৩ অক্টোবর ২০২৬ --}}
{{-- ⛔ খোলা-বন্ধ রূপে, ছোট এক-লাইনের রূপে নয় — নিচের আনলোডিং কার্ডের ব্লকের সাথে দুই রূপ মিশলে Blade প্রথম খোলা থেকে
     পরের বন্ধ পর্যন্ত এক ব্লক ধরে পাতা ভাঙত (২০ নভেম্বর ২০২৬)। ⚠️ এই মন্তব্যে নির্দেশের নাম লেখা নয় — মন্তব্যের ভেতরেও ধরা পড়ে। --}}
@php
    $showsPrice = \App\Core\Security\FieldSecurity::visible(\App\Modules\Inventory\Models\Product::class, 'purchase_price');
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $receipt->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$receipt->document_no" :subtitle="$receipt->supplier?->name()">
            <x-slot:actions>
                @can('update', $receipt)
                    <x-ui.edit-button tone="secondary" :paper="$receipt" :href="route('purchase.receipt.edit', $receipt)">
                        {{ __('core.action.edit') }}
                    </x-ui.edit-button>

                    <form id="confirm" method="POST" action="{{ route('purchase.receipt.confirm', $receipt) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                {{-- ⭐ বিল এখন মাল গ্রহণেই আপনা থেকে (মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬)।
                     ⓘ তাই এখানে বিলের লিংক, আর কিছু বাকি থাকলে তবেই "বিল তৈরি করুন" —
                     পুরনো মাল গ্রহণ, বা যেটার বিল আটকে গিয়েছিল। --}}
                @foreach ($bills ?? [] as $bill)
                    <x-ui.button tone="secondary" :href="route('purchase.bill.show', $bill->id)">
                        {{ __('purchase::action.open_bill', ['no' => $bill->document_no]) }}
                    </x-ui.button>
                @endforeach

                @if ($unbilled ?? false)
                    @can('create', \App\Modules\Purchase\Models\PurchaseBill::class)
                        <form method="POST" action="{{ route('purchase.receipt.bill', $receipt) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('purchase::action.bill_against') }}</x-ui.button>
                        </form>
                    @endcan
                @endif
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
                    ['label' => __('purchase::doc.receipt'), 'url' => route('purchase.print.receipt', $receipt), 'paper_setting' => 'purchase.print.paper.receipt', 'type' => 'purchase_receipt', 'id' => $receipt->id, 'no' => $receipt->document_no],
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
                    'purchase::field.date' => \App\Core\Support\DateFormat::format($receipt->trx_date),
                    'purchase::field.warehouse' => $receipt->warehouse?->name() ?: '-',
                    'purchase::field.supplier_challan_no' => $receipt->supplier_challan_no ?: '-',
                    'purchase::field.narration' => $receipt->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.order') }}</dt>
                    <dd class="mt-0.5">
                        @if ($receipt->order)
                            <x-purchase::doc-link :document="$receipt->order" route="purchase.order.show" />
                        @else
                            -
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$receipt" /></dd>
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
                :rows="$receipt->lines"
                :columns="array_values(array_filter([
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('purchase::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'received_qty', 'label' => __('purchase::field.received'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->received_qty)],
                    ['key' => 'unbilled', 'label' => __('purchase::field.unbilled'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->unbilledQty())],
                    /*
                     * ⭐ লেখা দর — ভ্যাটসহ যা লেখা হয়েছিল (মালিক, ১০ অক্টোবর ২০২৬: লেখা ১১৫, ভ্যাট বাদে ১০০, ভ্যাট ১৫ আলাদা;
                     * [[AReceiptShowsTheRateAsTypedTest]])। ⓘ কেবল যে চালানে দামের ভেতরের ভ্যাট আছে — বাকিগুলোয় দুটো একই সংখ্যা।
                     */
                    ...($receipt->lines->contains(fn ($l) => bccomp((string) $l->tax, '0', 4) !== 0) ? [
                        ['key' => 'typed_rate', 'label' => __('purchase::field.typed_rate'),
                         'numeric' => true, 'width' => '8rem',
                         'render' => fn ($l) => \App\Core\Support\Money::format($l->typedRate())],
                    ] : []),
                    ['key' => 'rate', 'label' => __('purchase::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    /* ⭐ দর ও মূল্য ভ্যাট বাদে, ভ্যাট আলাদা, মোট ভ্যাটসহ — বিলের হুবহু ছাঁদে (মালিক, ১০ অক্টোবর ২০২৬) */
                    ['key' => 'tax', 'label' => __('purchase::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ], fn ($c) => $showsPrice || ! in_array($c['key'], ['rate', 'amount', 'discount', 'tax', 'typed_rate'], true)))" />

            @if ($showsPrice)
            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.subtotal' => $receipt->total,
                    'purchase::field.tax' => $receipt->taxTotal(),
                    'purchase::field.total' => $receipt->totalWithTax(),
                ]" />
            </div>
            @endif
        </section>

        {{-- ⭐ আনলোডিং খরচ — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; [[LabourCost]], [[UnloadingSplit]], [[PurchaseReceiptController::unloading()]])।
             ⓘ খসড়ায় ও চাবি থাকলে বাছাইয়ের ফর্ম আর সারি ধরে হাতের দর; পাকা বা বাতিল কাগজে কেবল দেখা।
             ⛔ মালের দাম আর পৌঁছানো খরচ কেবল খরচ দেখার চাবিতে — উপরের ছকের একই নিয়ম ($showsPrice)। --}}
        @php
            $labour = app(\App\Modules\Supplier\Services\LabourCost::class);
            $unloadDraft = $receipt->status === \App\Core\Support\DocumentStatus::DRAFT;
            $unloadEdit = $unloadDraft && (auth()->user()?->can('purchase.receipt.create') ?? false);
            $unloadRows = \App\Modules\Purchase\Services\UnloadingSplit::rows($receipt->lines, 'received_qty');

            // ⓘ খসড়ায় আজকের দর (সংরক্ষণ আবার বসায়), পাকা কাগজে লেখার দিনের ছবি
            $unloadRate = ! $unloadDraft && $receipt->unload_rate !== null
                ? (string) $receipt->unload_rate
                : \App\Modules\Purchase\Services\UnloadingSplit::rate();
            $unloadRateText = fn (string $rate) => \App\Core\Support\Money::format($rate, bccomp(\App\Core\Support\Money::round($rate, 2), $rate, 4) === 0 ? 2 : 4);
            $unloadCartons = \App\Modules\Purchase\Services\UnloadingSplit::cartonsOf($unloadRows);
            $unloadExpected = __('supplier::labour.expected', [
                'cartons' => \App\Core\Support\Money::quantity($unloadCartons, 2),
                'rate' => $unloadRateText($unloadRate),
                'amount' => \App\Core\Support\Money::format(\App\Modules\Purchase\Services\UnloadingSplit::expected($unloadRows, $unloadRate)),
            ]);
            $unloadMode = (string) old('unload_mode', $receipt->unload_mode ?? '');
            $unloadContractors = $unloadEdit ? $labour->contractors()->pluck('label', 'id') : collect();
        @endphp
        <section data-boxed data-receipt-unloading
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-2 font-semibold">{{ __('supplier::labour.unloading') }}</h2>

            @if ($unloadDraft && blank($receipt->unload_mode))
                <p role="alert" data-unloading-missing
                   class="mb-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
                    {{ __('supplier::labour.unloading_required', ['no' => $receipt->document_no]) }}
                </p>
            @endif

            @if ($unloadEdit)
                <form method="POST" action="{{ route('purchase.receipt.unloading', $receipt) }}" class="grid gap-3" data-no-peek
                      x-data="{ unloadMode: @js($unloadMode) }">
                    @csrf
                    @method('PUT')

                    <p class="text-sm text-(--color-ink-muted)">{{ __('supplier::labour.how') }}</p>
                    <div class="ds-seg" role="radiogroup" aria-label="{{ __('supplier::labour.how') }}">
                        @foreach (\App\Modules\Supplier\Services\LabourCost::UNLOADING as $choice)
                            <button type="button" role="radio" @click="unloadMode = '{{ $choice }}'"
                                    :class="unloadMode === '{{ $choice }}' ? 'is-on' : ''"
                                    :aria-checked="unloadMode === '{{ $choice }}' ? 'true' : 'false'">{{ __('supplier::labour.mode_'.$choice) }}</button>
                        @endforeach
                    </div>
                    <input type="hidden" name="unload_mode" :value="unloadMode" value="{{ $unloadMode }}">

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div x-show="unloadMode === 'cash' || unloadMode === 'due'">
                            <x-ui.field name="unload_amount" type="number" step="0.01" min="0" inputmode="decimal" numeric
                                        :label="__('supplier::labour.amount')"
                                        :value="old('unload_amount', \App\Modules\Supplier\Services\LabourCost::costsUs($receipt->unload_mode) ? \App\Core\Support\Money::round((string) $receipt->unload_amount, 2) : '')"
                                        :hint="__('supplier::labour.amount_hint')" />
                        </div>

                        <div x-show="unloadMode === 'cash'">
                            <x-ui.select name="unload_account_id" :label="__('supplier::labour.till')"
                                         :options="collect($labour->cashTills())->pluck('label', 'id')"
                                         :selected="$receipt->unload_account_id"
                                         placeholder="-" />
                        </div>

                        <div x-show="unloadMode === 'due'">
                            @if ($unloadContractors->isEmpty())
                                <p class="rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
                                    {{ __('supplier::labour.no_contractor_party') }}
                                </p>
                            @else
                                <x-ui.select name="unload_contractor_id" :label="__('supplier::labour.contractor')"
                                             :options="$unloadContractors"
                                             :selected="$receipt->unload_contractor_id"
                                             placeholder="-" />
                            @endif
                        </div>

                        <div x-show="unloadMode === 'none'">
                            <x-ui.field name="unload_reason" maxlength="255" :label="__('supplier::labour.reason')"
                                        :value="old('unload_reason', $receipt->unload_reason)" />
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-(--color-border)">
                                    <th class="px-2 py-1 text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="px-2 py-1 text-end">{{ __('supplier::labour.cartons') }}</th>
                                    <th class="px-2 py-1 text-end" title="{{ __('supplier::labour.hand_rate') }}">{{ __('supplier::labour.hand_rate_short') }}</th>
                                    <th class="px-2 py-1 text-end">{{ __('supplier::labour.share') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($receipt->lines as $line)
                                    <tr class="border-b border-(--color-border)">
                                        <td class="px-2 py-1" id="unload-line-{{ $line->id }}">{{ $line->product?->code }} - {{ $line->product?->name() }}</td>
                                        <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::quantity($unloadRows[(int) $line->id]['cartons'] ?? '0', 2) }}</td>
                                        <td class="px-2 py-1 text-end">
                                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                                   name="unload_rates[{{ $line->id }}]"
                                                   value="{{ old('unload_rates.'.$line->id, $line->unload_rate === null ? '' : \App\Core\Support\Money::quantity((string) $line->unload_rate, 4)) }}"
                                                   aria-label="{{ __('supplier::labour.hand_rate') }}"
                                                   aria-describedby="unload-line-{{ $line->id }}"
                                                   class="num h-(--spacing-field-compact) w-24 rounded-(--radius-field) border border-(--color-border)
                                                          bg-(--color-surface-card) px-2 text-end">
                                            @error('unload_rates.'.$line->id)
                                                <p class="mt-1 text-xs text-(--color-danger)">{{ $message }}</p>
                                            @enderror
                                        </td>
                                        <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::format((string) ($line->unload_cost ?? '0')) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-2xs text-(--color-ink-muted)" data-unload-expected>{{ $unloadExpected }}</p>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.split_hint') }}</p>

                    @foreach (\App\Modules\Supplier\Services\LabourCost::fields('unload_') as $field)
                        @error($field)
                            <p class="text-sm text-(--color-danger)" data-unload-error>{{ $message }}</p>
                        @enderror
                    @endforeach

                    <div>
                        <x-ui.button type="submit" tone="primary">{{ __('supplier::labour.save') }}</x-ui.button>
                    </div>
                </form>
            @else
                <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('supplier::labour.how') }}</dt>
                        <dd class="mt-0.5" data-unload-describe>{{ $labour->describe($receipt, 'unload_') }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('supplier::labour.cartons') }}</dt>
                        <dd class="num mt-0.5">{{ \App\Core\Support\Money::quantity((string) ($receipt->unload_cartons ?? $unloadCartons), 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('supplier::labour.rate_per_carton') }}</dt>
                        <dd class="num mt-0.5">{{ $unloadRateText($unloadRate) }}</dd>
                    </div>
                </dl>

                @if ($receipt->lines->isNotEmpty())
                    <div class="table-responsive mt-3">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-(--color-border)">
                                    <th class="px-2 py-1 text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="px-2 py-1 text-end">{{ __('supplier::labour.cartons') }}</th>
                                    <th class="px-2 py-1 text-end" title="{{ __('supplier::labour.hand_rate') }}">{{ __('supplier::labour.hand_rate_short') }}</th>
                                    <th class="px-2 py-1 text-end">{{ __('supplier::labour.share') }}</th>
                                    @if ($showsPrice)
                                        <th class="px-2 py-1 text-end">{{ __('purchase::unloading.line_amount') }}</th>
                                        <th class="px-2 py-1 text-end">{{ __('supplier::labour.landed') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($receipt->lines as $line)
                                    <tr class="border-b border-(--color-border)">
                                        <td class="px-2 py-1">{{ $line->product?->code }} - {{ $line->product?->name() }}</td>
                                        <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::quantity((string) ($line->unload_cartons ?? '0'), 2) }}</td>
                                        <td class="num px-2 py-1 text-end">{{ $line->unload_rate === null ? __('purchase::unloading.equal_share') : $unloadRateText((string) $line->unload_rate) }}</td>
                                        <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::format((string) ($line->unload_cost ?? '0')) }}</td>
                                        @if ($showsPrice)
                                            <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::format((string) $line->amount) }}</td>
                                            <td class="num px-2 py-1 text-end">{{ \App\Core\Support\Money::format(bcadd((string) ($line->amount ?? '0'), (string) ($line->unload_cost ?? '0'), 4)) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="px-2 py-1 text-end font-medium">{{ __('purchase::unloading.total') }}</td>
                                    <td class="num px-2 py-1 text-end font-medium">{{ \App\Core\Support\Money::quantity(\App\Core\Support\Money::sumOf($receipt->lines, fn ($l) => (string) ($l->unload_cartons ?? '0')), 2) }}</td>
                                    <td></td>
                                    <td class="num px-2 py-1 text-end font-semibold">{{ \App\Core\Support\Money::format(\App\Core\Support\Money::sumOf($receipt->lines, fn ($l) => (string) ($l->unload_cost ?? '0'))) }}</td>
                                    @if ($showsPrice)
                                        <td class="num px-2 py-1 text-end font-medium">{{ \App\Core\Support\Money::format(\App\Core\Support\Money::sumOf($receipt->lines, fn ($l) => (string) ($l->amount ?? '0'))) }}</td>
                                        <td class="num px-2 py-1 text-end font-semibold">{{ \App\Core\Support\Money::format(\App\Core\Support\Money::sumOf($receipt->lines, fn ($l) => bcadd((string) ($l->amount ?? '0'), (string) ($l->unload_cost ?? '0'), 4))) }}</td>
                                    @endif
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif

                @if ($unloadDraft)
                    <p class="mt-2 text-2xs text-(--color-ink-muted)" data-unload-expected>{{ $unloadExpected }}</p>
                @endif
            @endif
        </section>

        <x-ui.attachments :document="$receipt" />

        @can('delete', $receipt)
            @if ($receipt->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.receipt.cancel', $receipt)" />
            @endif
        @endcan
    </div>

    {{-- ⭐ জোড়া ডকুমেন্ট (ডকুমেন্ট পরিকল্পনা §১৫, ৯ অক্টোবর ২০২৬) — কোরের উপাদান; কিছু না থাকলে কিছুই বসে না --}}
    <x-ui.linked-documents :record="$receipt" />
</x-layouts.app>
