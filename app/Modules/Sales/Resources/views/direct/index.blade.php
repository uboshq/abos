{{--
    সরাসরি বিক্রয় — নমুনার চারটা অংশ।

        ১. এন্ট্রি স্ট্রিপ  (উপরে বাঁয়ে)  পণ্য খোঁজা, লাইভ মজুদ, পরিমাণ, দর
        ২. ডকুমেন্ট হেডার  (উপরে ডানে)   গুদাম, তারিখ, মেয়াদ, DO, ক্রেতা
        ৩. কার্ট           (মাঝে)        পণ্যের সারি, নিচে উপহারের সারি
        ৪. টোটাল প্যানেল   (ডান কলাম)    মোট থেকে বকেয়া পর্যন্ত

    ── কেন এন্ট্রি উপরে, কার্ট নিচে ─────────────────────────────────────
    হাত যে ক্রমে কাজ করে সেই ক্রমেই: পণ্য বাছা → পরিমাণ → কার্টে যোগ →
    পরের পণ্য। কার্ট উপরে থাকলে প্রতিটা পণ্যের পর চোখ নিচ থেকে উপরে ফিরে
    আসত।

    ── লাইভ মজুদ কেন ছয়টা সংখ্যা ────────────────────────────────────────
    "বিক্রয়যোগ্য ৭৪৬" দেখে কেউ জানে না তার মধ্যে কতটা অন্য অর্ডারে ধরা,
    কতটা আটকানো, আর ফ্রি ভাণ্ডারে আলাদা কতটা পড়ে আছে। ছয়টাই পাশে থাকলে
    প্রশ্নটা করার আগেই উত্তর থাকে — অন্য পর্দায় যেতে হয় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.direct') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.direct')"
                          :subtitle="__('sales::message.direct_note')" />
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

    <form method="POST" action="{{ route('sales.direct.store') }}"
          x-data="directSale({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($customers->mapWithKeys(fn ($c) => [$c->id => ['limit' => (string) $c->credit_limit, 'due' => $c->outstanding(), 'days' => (int) $c->credit_days]])) }}, {{ $walkinId }})"
          class="space-y-4">
        @csrf

        <div class="grid gap-4 xl:grid-cols-2">

            {{-- ── ১. এন্ট্রি স্ট্রিপ ───────────────────────────────── --}}
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ __('sales::field.product') }}</h2>

                <input type="search" x-model="term" x-ref="search"
                       x-init="$nextTick(() => $refs.search.focus())"
                       @keydown.enter.prevent="pickFirst()"
                       placeholder="{{ __('sales::message.pos_search') }}"
                       class="h-11 w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-3 text-base">

                {{-- বাছাই করা পণ্যটার নাম বড় করে — কাউন্টারে ডেস্কের ওপাশ
                     থেকেও পড়া যেতে হবে --}}
                <p class="mt-2 min-h-6 text-base font-semibold" x-text="picked ? picked.name : ''"></p>

                {{-- লাইভ মজুদ — ছয়টা সংখ্যা --}}
                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3" x-show="picked" x-cloak>
                    @foreach ([
                        'main' => 'sales::field.main_stock',
                        'reserved' => 'sales::field.reserved_short',
                        'available' => 'sales::field.available_short',
                        'free' => 'sales::field.free_stock',
                        'free_available' => 'sales::field.free_available',
                        'inCart' => 'sales::field.in_cart',
                    ] as $key => $label)
                        <div class="rounded-(--radius-field) bg-(--color-surface-app) px-2 py-1">
                            <span class="block text-2xs text-(--color-ink-muted)">{{ __($label) }}</span>
                            <span class="num text-sm font-semibold"
                                  x-text="stockFigure('{{ $key }}')"></span>
                        </div>
                    @endforeach
                </div>

                <p x-show="! picked" x-cloak class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.pick_item_to_see_stock') }}
                </p>

                {{-- তালিকা --}}
                <div class="mt-3 max-h-48 space-y-1 overflow-y-auto">
                    <template x-for="p in visible" :key="p.id">
                        <button type="button" @click="pick(p)"
                                class="flex w-full items-baseline justify-between gap-2 rounded-(--radius-field)
                                       px-2 py-1.5 text-start text-sm transition-colors
                                       hover:bg-(--color-surface-hover)">
                            <span class="min-w-0 truncate" x-text="p.name"></span>
                            <span class="num shrink-0 text-2xs text-(--color-ink-muted)"
                                  x-text="qty(p.available)"></span>
                        </button>
                    </template>
                </div>

                {{-- পরিমাণ ও দর --}}
                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="block">
                        <span class="mb-1 block text-2xs font-medium">{{ __('sales::field.quantity') }}</span>
                        <input type="number" step="0.01" min="0.01" x-model="entry.qty"
                               class="num h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-end text-sm">
                    </label>

                    @if ($show['free_qty'])
                        <label class="block">
                            <span class="mb-1 block text-2xs font-medium">{{ __('sales::field.free_qty') }}</span>
                            <input type="number" step="0.01" min="0" x-model="entry.freeQty"
                                   class="num h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 text-end text-sm">
                        </label>
                    @endif

                    <label class="block">
                        <span class="mb-1 block text-2xs font-medium">{{ __('sales::field.rate') }}</span>
                        <input type="number" step="0.0001" min="0" x-model="entry.rate"
                               class="num h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-end text-sm">
                    </label>

                    @if ($show['line_discount'])
                        <label class="block">
                            <span class="mb-1 block text-2xs font-medium">{{ __('sales::field.discount') }} %</span>
                            <input type="number" step="0.01" min="0" max="100" x-model="entry.discountPercent"
                                   class="num h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 text-end text-sm">
                        </label>
                    @endif
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" tone="primary" ::disabled="! picked"
                                 x-on:click="addToCart()">
                        {{ __('sales::action.add_to_cart') }}
                    </x-ui.button>

                    <x-ui.button type="button" tone="secondary" x-on:click="clearEntry()">
                        {{ __('sales::action.clear_data') }}
                    </x-ui.button>

                    <span class="num ms-auto text-sm font-semibold" x-text="money(entryNet)"></span>
                </div>
            </section>

            {{-- ── ২. ডকুমেন্ট হেডার ────────────────────────────────── --}}
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ __('sales::section.header') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.select name="warehouse_id" :label="__('sales::field.warehouse')"
                                 :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                                 :selected="$warehouse?->id" placeholder="-" />

                    <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                                :value="old('trx_date', now()->toDateString())" />

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="customer_id">
                            {{ __('sales::field.customer') }}
                        </label>
                        <select id="customer_id" name="customer_id" x-model="customerId"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}"
                                        @selected($customer->id === $walkinId)>{{ $customer->name() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-ui.field name="credit_period_days" type="number" min="0" max="365"
                                :label="__('sales::field.credit_period')"
                                x-model="creditDays" />

                    @if ($show['do_no'])
                        <x-ui.field name="do_no" :label="__('sales::field.do_no')" />
                    @endif

                    <x-ui.field name="vehicle_no" :label="__('sales::field.vehicle_no')" />
                </div>

                @if ($show['credit_limit'])
                    {{-- ক্রেতার অবস্থা — সীমা ও বকেয়া পাশাপাশি।

                         দুইটা একসাথে না দেখালে "সীমা ৫০,০০০" দেখে মনে হত
                         পুরোটাই খালি, অথচ ৪৮,০০০ আগেই বাকি। --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-(--radius-field) bg-(--color-surface-app) px-2 py-1">
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ __('sales::field.credit_limit') }}
                            </span>
                            <span class="num font-semibold" x-text="money(customer.limit)"></span>
                        </div>
                        <div class="rounded-(--radius-field) bg-(--color-surface-app) px-2 py-1">
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ __('sales::field.previous_balance') }}
                            </span>
                            <span class="num font-semibold" x-text="money(customer.due)"></span>
                        </div>
                    </div>
                @endif
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1fr_20rem]">

            {{-- ── ৩. কার্ট ও উপহার ─────────────────────────────────── --}}
            <div class="space-y-4">
                <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-3 text-sm font-semibold">
                        {{ __('sales::message.lines') }}
                    </h2>

                    <div class="table-responsive">
                        <table class="table-cards w-full text-sm">
                            <thead class="border-b border-(--color-border) text-(--color-ink-muted)">
                                <tr>
                                    <th class="p-2 text-start font-medium">#</th>
                                    <th class="p-2 text-start font-medium">{{ __('sales::field.product') }}</th>
                                    <th class="p-2 text-end font-medium">{{ __('sales::field.rate') }}</th>
                                    <th class="p-2 text-end font-medium">{{ __('sales::field.quantity') }}</th>
                                    @if ($show['free_qty'])
                                        <th class="p-2 text-end font-medium">{{ __('sales::field.free_qty') }}</th>
                                    @endif
                                    @if ($show['line_discount'])
                                        <th class="p-2 text-end font-medium">{{ __('sales::field.discount') }}</th>
                                    @endif
                                    <th class="p-2 text-end font-medium">{{ __('sales::field.amount') }}</th>
                                    <th class="p-2"><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(line, i) in lines" :key="line.key">
                                    <tr class="border-b border-(--color-border)">
                                        <td class="p-2" x-text="i + 1"></td>

                                        <td class="p-2" data-label="{{ __('sales::field.product') }}">
                                            <span x-text="line.name"></span>
                                            <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
                                        </td>

                                        <td class="p-1 text-end" data-label="{{ __('sales::field.rate') }}">
                                            <input type="number" step="0.0001" min="0" x-model="line.rate"
                                                   :name="`lines[${i}][rate]`"
                                                   class="num h-8 w-full sm:w-24 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>

                                        <td class="p-1 text-end" data-label="{{ __('sales::field.quantity') }}">
                                            <input type="number" step="0.01" min="0.01" x-model="line.qty"
                                                   :name="`lines[${i}][qty]`"
                                                   class="num h-8 w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>

                                        @if ($show['free_qty'])
                                            <td class="p-1 text-end" data-label="{{ __('sales::field.free_qty') }}">
                                                <input type="number" step="0.01" min="0" x-model="line.freeQty"
                                                       :name="`lines[${i}][free_qty]`"
                                                       class="num h-8 w-full sm:w-20 rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                            </td>
                                        @endif

                                        @if ($show['line_discount'])
                                            <td class="p-1 text-end" data-label="{{ __('sales::field.discount') }}">
                                                <input type="number" step="0.01" min="0" max="100"
                                                       x-model="line.discountPercent"
                                                       :name="`lines[${i}][discount_percent]`"
                                                       class="num h-8 w-full sm:w-20 rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                            </td>
                                        @endif

                                        <td class="num p-2 text-end" data-label="{{ __('sales::field.amount') }}"
                                            x-text="money(lineNet(line))"></td>

                                        <td class="p-1 text-end">
                                            <button type="button" @click="lines.splice(i, 1)"
                                                    aria-label="{{ __('sales::action.remove_line') }}"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                           hover:bg-(--color-surface-hover)">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p x-show="lines.length === 0" x-cloak
                       class="p-6 text-center text-sm text-(--color-ink-muted)">
                        {{ __('sales::message.nothing_added') }}
                    </p>
                </section>

                @if ($show['gift'])
                    {{-- উপহারের গ্রিড — আলাদা, কারণ ওগুলো বিক্রির নয়।

                         একই টেবিলে মেশালে বিলের যোগফলের সাথে সারির সংখ্যা
                         মিলত না, আর গ্রাহক ভাবতেন উপহারের জন্যও টাকা নেওয়া
                         হয়েছে। --}}
                    <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card)">
                        <h2 class="flex items-center justify-between border-b border-(--color-border) px-4 py-3">
                            <span class="text-sm font-semibold">{{ __('sales::field.gift_item') }}</span>
                            <span class="text-2xs text-(--color-ink-muted)">
                                {{ __('sales::message.not_for_sales') }}
                            </span>
                        </h2>

                        <div class="table-responsive">
                            <table class="table-cards w-full text-sm">
                                <thead class="border-b border-(--color-border) text-(--color-ink-muted)">
                                    <tr>
                                        <th class="p-2 text-start font-medium">#</th>
                                        <th class="p-2 text-start font-medium">{{ __('sales::field.gift_for') }}</th>
                                        <th class="p-2 text-start font-medium">{{ __('sales::field.gift_item') }}</th>
                                        <th class="p-2 text-end font-medium">{{ __('sales::field.quantity') }}</th>
                                        <th class="p-2 text-start font-medium">{{ __('sales::field.remarks') }}</th>
                                        <th class="p-2"><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <template x-for="(gift, i) in gifts" :key="gift.key">
                                        <tr class="border-b border-(--color-border)">
                                            <td class="p-2" x-text="i + 1"></td>

                                            <td class="p-1" data-label="{{ __('sales::field.gift_for') }}">
                                                <select x-model="gift.againstProductId"
                                                        :name="`gifts[${i}][against_product_id]`"
                                                        class="h-8 w-full rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-app) px-2">
                                                    <option value="">-</option>
                                                    <template x-for="line in lines" :key="line.key">
                                                        <option :value="line.id" x-text="line.name"></option>
                                                    </template>
                                                </select>
                                            </td>

                                            <td class="p-1" data-label="{{ __('sales::field.gift_item') }}">
                                                <select x-model="gift.productId" :name="`gifts[${i}][product_id]`"
                                                        class="h-8 w-full rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-app) px-2">
                                                    <option value="">-</option>
                                                    @foreach ($products as $product)
                                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>

                                            <td class="p-1 text-end" data-label="{{ __('sales::field.quantity') }}">
                                                <input type="number" step="0.01" min="0" x-model="gift.qty"
                                                       :name="`gifts[${i}][qty]`"
                                                       class="num h-8 w-full sm:w-20 rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                            </td>

                                            <td class="p-1" data-label="{{ __('sales::field.remarks') }}">
                                                <input type="text" x-model="gift.remarks"
                                                       :name="`gifts[${i}][remarks]`"
                                                       class="h-8 w-full rounded-(--radius-field) border
                                                              border-(--color-border) bg-(--color-surface-app) px-2">
                                            </td>

                                            <td class="p-1 text-end">
                                                <button type="button" @click="gifts.splice(i, 1)"
                                                        aria-label="{{ __('sales::action.remove_line') }}"
                                                        class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                               hover:bg-(--color-surface-hover)">&times;</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between p-3">
                            <p x-show="gifts.length === 0" x-cloak class="text-2xs text-(--color-ink-muted)">
                                {{ __('sales::message.gift_none') }}
                            </p>
                            <button type="button" @click="addGift()"
                                    class="ms-auto rounded-(--radius-field) border border-(--color-border)
                                           px-3 py-1.5 text-2xs transition-colors hover:bg-(--color-surface-hover)">
                                + {{ __('sales::action.add_gift') }}
                            </button>
                        </div>
                    </section>
                @endif
            </div>

            {{-- ── ৪. টোটাল প্যানেল ─────────────────────────────────── --}}
            <section class="space-y-2 rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4 xl:sticky xl:top-4 xl:h-fit">

                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('sales::field.subtotal') }}</span>
                    <span class="num" x-text="money(subTotal)"></span>
                </div>

                <label class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('sales::field.discount_amount') }}</span>
                    <input type="number" step="0.01" min="0" name="discount_amount" x-model="discountAmount"
                           class="num h-8 w-24 rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-2 text-end">
                </label>

                @if ($show['expense'])
                    <label class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.expense') }}</span>
                        <input type="number" step="0.01" min="0" name="expense_amount" x-model="expenseAmount"
                               class="num h-8 w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-end">
                    </label>
                @endif

                @if ($show['rounding'])
                    <label class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.rounding') }}</span>
                        <input type="number" step="0.01" min="0" name="rounding_amount" x-model="roundingAmount"
                               class="num h-8 w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-end">
                    </label>
                @endif

                <div class="flex items-baseline justify-between border-t border-(--color-border) pt-2">
                    <span class="font-semibold">{{ __('sales::field.net_payable') }}</span>
                    <span class="num text-2xl font-bold" x-text="money(netPayable)"></span>
                </div>

                @if ($show['deposit'])
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('sales::field.deposit') }}</span>
                        <input type="number" step="0.01" min="0" name="deposit" x-model="deposit"
                               class="num h-10 w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-3 text-end text-lg">
                    </label>

                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.invoice_due') }}</span>
                        <span class="num font-semibold" x-text="money(invoiceDue)"></span>
                    </div>
                @endif

                <div class="flex items-baseline justify-between border-t border-(--color-border) pt-2 text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('sales::field.outstanding') }}</span>
                    <span class="num font-semibold" x-text="money(outstanding)"></span>
                </div>

                {{-- গোনার ঘরগুলো — নমুনার শেষ চারটা --}}
                <dl class="grid grid-cols-2 gap-1 border-t border-(--color-border) pt-2 text-2xs">
                    @foreach ([
                        'totalItem' => 'sales::field.total_item',
                        'totalSalesQty' => 'sales::field.total_sales_qty',
                        'totalFreeQty' => 'sales::field.total_free_qty',
                        'totalQty' => 'sales::field.total_qty',
                    ] as $key => $label)
                        <div class="flex justify-between gap-1">
                            <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="num" x-text="counts.{{ $key }}"></dd>
                        </div>
                    @endforeach
                </dl>

                <x-ui.button type="submit" tone="primary" class="w-full"
                             ::disabled="lines.length === 0">
                    {{ __('sales::action.confirm') }}
                </x-ui.button>

                <x-ui.button type="button" tone="secondary" class="w-full" x-on:click="clearAll()">
                    {{ __('sales::action.clear_full') }}
                </x-ui.button>
            </section>
        </div>
    </form>

    @push('scripts')
        <script>
            function directSale(catalogue, customers, walkinId) {
                return {
                    catalogue,
                    customers,
                    term: '',
                    picked: null,
                    entry: { qty: '1', freeQty: '', rate: '', discountPercent: '' },
                    lines: [],
                    gifts: [],
                    customerId: String(walkinId || ''),
                    creditDays: '',
                    discountAmount: '',
                    expenseAmount: '',
                    roundingAmount: '',
                    deposit: '',
                    nextKey: 1,

                    get visible() {
                        const t = this.term.trim().toLowerCase();
                        const list = t === ''
                            ? this.catalogue
                            : this.catalogue.filter(p =>
                                p.name.toLowerCase().includes(t)
                                || p.code.toLowerCase().includes(t)
                                || (p.barcode || '').toLowerCase().includes(t));
                        return list.slice(0, 40);
                    },

                    get customer() {
                        return this.customers[this.customerId] || { limit: '0', due: '0', days: 0 };
                    },

                    pick(product) {
                        this.picked = product;
                        this.entry.rate = product.rate;
                        this.term = '';
                    },

                    pickFirst() {
                        const first = this.visible[0];
                        if (first) this.pick(first);
                    },

                    /*
                     * পর্দার মজুদ সংখ্যা কার্টের সাথে মিলিয়ে দেখানো।
                     *
                     * কার্টে ইতিমধ্যে ৫০ থাকলে "আছে ৭৪৬" আর সত্যি নয় — বাকি
                     * ৬৯৬। সংখ্যাটা না মেলালে দ্বিতীয়বার একই পণ্য যোগ করার
                     * সময় ভুল সংখ্যা দেখে বেশি বেচা হয়ে যেত।
                     */
                    stockFigure(key) {
                        if (! this.picked) return '';

                        if (key === 'inCart') {
                            return this.qty(this.lines
                                .filter(l => l.id === this.picked.id)
                                .reduce((s, l) => s + (Number(l.qty) || 0), 0));
                        }

                        return this.qty(this.picked[key]);
                    },

                    get entryNet() {
                        const base = (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
                        return base - base * (Number(this.entry.discountPercent) || 0) / 100;
                    },

                    addToCart() {
                        if (! this.picked) return;

                        this.lines.push({
                            key: this.nextKey++,
                            id: this.picked.id,
                            name: this.picked.name,
                            qty: this.entry.qty || '1',
                            freeQty: this.entry.freeQty || '',
                            rate: this.entry.rate || '0',
                            discountPercent: this.entry.discountPercent || '',
                        });

                        this.clearEntry();
                        this.$nextTick(() => this.$refs.search.focus());
                    },

                    clearEntry() {
                        this.picked = null;
                        this.entry = { qty: '1', freeQty: '', rate: '', discountPercent: '' };
                        this.term = '';
                    },

                    clearAll() {
                        this.lines = [];
                        this.gifts = [];
                        this.discountAmount = '';
                        this.expenseAmount = '';
                        this.roundingAmount = '';
                        this.deposit = '';
                        this.clearEntry();
                    },

                    addGift() {
                        this.gifts.push({
                            key: this.nextKey++,
                            productId: '',
                            againstProductId: '',
                            qty: '',
                            remarks: @js(__('sales::message.not_for_sales')),
                        });
                    },

                    lineNet(line) {
                        const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);
                        return base - base * (Number(line.discountPercent) || 0) / 100;
                    },

                    get subTotal() {
                        return this.lines.reduce((s, l) => s + this.lineNet(l), 0);
                    },

                    get netPayable() {
                        return this.subTotal
                            - (Number(this.discountAmount) || 0)
                            + (Number(this.expenseAmount) || 0)
                            + (Number(this.roundingAmount) || 0);
                    },

                    get invoiceDue() {
                        const due = this.netPayable - (Number(this.deposit) || 0);
                        return due > 0 ? due : 0;
                    },

                    /* আগের বকেয়া + এই বিলের বাকি — কাউন্টারে এই সংখ্যাটাই
                       বলে দেয় গ্রাহককে আর কত টাকা চাইতে হবে */
                    get outstanding() {
                        return (Number(this.customer.due) || 0) + this.invoiceDue;
                    },

                    get counts() {
                        const sales = this.lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
                        const free = this.lines.reduce((s, l) => s + (Number(l.freeQty) || 0), 0)
                            + this.gifts.reduce((s, g) => s + (Number(g.qty) || 0), 0);

                        return {
                            totalItem: this.lines.length,
                            totalSalesQty: this.qty(sales),
                            totalFreeQty: this.qty(free),
                            totalQty: this.qty(sales + free),
                        };
                    },

                    money(v) {
                        return Number(v || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    qty(v) {
                        return String(Number(v || 0));
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
