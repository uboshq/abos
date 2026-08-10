{{--
    সরাসরি ক্রয় চালান — গাড়ি আসে, মাল নামে, কাগজ হাতে।

    ── বিক্রয়ের পর্দার আয়না, ইচ্ছাকৃতভাবে ─────────────────────────────
    বাঁ দিকে খোঁজা ও এন্ট্রি, নিচে কার্ট, ডানে যোগফল — হুবহু সরাসরি
    বিক্রয়ের বিন্যাস। দুইটা পর্দা আলাদা দেখালে ডিপোর মানুষটাকে দুইবার
    শিখতে হত, অথচ কাজ দুইটা একই আকারের: পণ্য বাছা, সংখ্যা বসানো, দাম
    ঠিক করা, শেষে নিশ্চিত করা।

    ── এই পর্দার নিজের জিনিস: দর নির্ধারণ ──────────────────────────────
    বিক্রয়ে দাম আগে থেকেই ঠিক থাকে। ক্রয়ে ঠিক উল্টো — মাল ঢোকার
    মুহূর্তেই বিক্রয়মূল্য বসাতে হয়, আর ক্রয়দর তখন চোখের সামনে। তাই
    এখানে তিনটা বাড়তি ঘর: markup, margin আর বিক্রয়মূল্য। যেকোনো একটায়
    লিখলে বাকি দুইটা নিজে বসে (resources/js/pricing.js), আর যে ঘরে
    কার্সর আছে সেটায় কখনো হাত পড়ে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.direct') }}</x-slot:title>

    @if ($errors->any())
        <div role="alert"
             class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('purchase.direct.store') }}"
          x-data="directPurchase({{ Illuminate\Support\Js::from($products) }}, {{ $show['vat'] ? 'true' : 'false' }})"
          @submit="guard($event)"
          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="min-w-0 space-y-3">

            {{-- ── ডকুমেন্ট স্ট্রিপ ──────────────────────────────────── --}}
            <section class="rounded-(--radius-card) border-t-2 border-(--color-brand-500)
                            border-x border-b border-(--color-border)
                            bg-(--color-surface-card) p-3">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.supplier') }}
                        </span>
                        <select name="supplier_id" required
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            <option value="">—</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}"
                                        @selected(old('supplier_id') == $supplier->id)>
                                    {{ $supplier->name() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('inventory::field.warehouse') }}
                        </span>
                        <select name="warehouse_id"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach ($warehouses as $house)
                                <option value="{{ $house->id }}"
                                        @selected(old('warehouse_id', $warehouse?->id) == $house->id)>
                                    {{ $house->name() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.supplier_bill_no') }}
                        </span>
                        <input type="text" name="supplier_bill_no" value="{{ old('supplier_bill_no') }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.trx_date') }}
                        </span>
                        <input type="date" name="trx_date"
                               value="{{ old('trx_date', now()->toDateString()) }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>
                </div>
            </section>

            {{-- ── এন্ট্রি স্ট্রিপ ───────────────────────────────────── --}}
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                <div class="relative">
                    <input type="text" x-model="term" x-ref="search"
                           @keydown.enter.prevent="pickFirst()"
                           placeholder="{{ __('purchase::message.search_product') }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-3 text-sm">

                    <ul x-show="visible.length > 0" x-cloak
                        class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-(--radius-field)
                               border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        <template x-for="p in visible" :key="p.id">
                            <li>
                                <button type="button" @click="pick(p)"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2
                                               text-start text-sm hover:bg-(--color-surface-hover)">
                                    <span>
                                        <span x-text="p.name"></span>
                                        <span class="block text-2xs text-(--color-ink-muted)" x-text="p.code"></span>
                                    </span>
                                    <span class="num shrink-0 text-2xs text-(--color-ink-muted)">
                                        <span x-text="qty(p.on_hand)"></span>
                                    </span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>

                {{-- x-show, x-if নয়।

                     x-if ভেতরের অংশটা DOM থেকে সরিয়ে-এনে বসায়, আর ওই
                     ক্লোন করা অংশ থেকে বাইরের x-ref দেখা যায় না — তাই
                     addToCart()-এ $refs.search অনির্ধারিত ছিল, আর
                     "Cannot read properties of undefined (reading 'focus')"
                     এররে Alpine ওখানেই থেমে যেত। থেমে যাওয়া মানে কার্টের
                     সারিগুলোর ::name বাঁধা হত না, ফলে সাবমিটে lines ফাঁকা
                     যেত আর সার্ভার "The lines field is required" বলত —
                     কার্ট চোখের সামনে ঠিক দেখালেও।

                     সরাসরি বিক্রয়ের পর্দা শুরু থেকেই x-show ব্যবহার করে;
                     এটাও তা-ই করে। --}}
                <div x-show="picked" x-cloak>
                    <div class="mt-3">
                        <div class="mb-2 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span class="font-semibold" x-text="picked.name"></span>
                            <span class="text-2xs text-(--color-ink-muted)">
                                {{ __('purchase::message.on_hand') }}:
                                <span class="num" x-text="qty(picked.on_hand)"></span>
                            </span>
                            {{-- শেষ কত দামে কেনা হয়েছিল — নতুন দর এর সাথেই মেলানো হয় --}}
                            <span class="text-2xs text-(--color-ink-muted)" x-show="picked.last_rate > 0">
                                {{ __('purchase::message.last_rate') }}:
                                <span class="num" x-text="money(picked.last_rate)"></span>
                            </span>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.qty') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal" x-model="entry.qty"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                            </label>

                            @if ($show['free_qty'])
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('purchase::field.free_qty') }}
                                    </span>
                                    <input type="number" step="0.01" inputmode="decimal" x-model="entry.free_qty"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                                </label>
                            @endif

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.rate') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.rate" @input="priced('rate')"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                            </label>

                            {{-- ── দর নির্ধারণের তিনটা ঘর ───────────────────
                                 তিনটা একই সম্পর্কের তিনটা মুখ। যেটায় লেখা
                                 হয় সেটাই নীতি, বাকি দুইটা তার ফল। markup
                                 মাপা হয় ক্রয়দরের উপর, margin বিক্রয়দরের
                                 উপর — ১০০-তে কিনে ১৫০-তে বেচা মানে ৫০%
                                 markup আর ৩৩.৩৩% margin। --}}
                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.markup') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.markup" @input="priced('markup')"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.margin') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.margin" @input="priced('margin')"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                    {{ __('purchase::field.sales_price') }}
                                </span>
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-model="entry.sales_price" @input="priced('sales_price')"
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                            </label>
                        </div>

                        <div class="mt-2 flex flex-wrap items-end gap-2">
                            @if ($show['line_discount'])
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('purchase::field.discount') }}
                                    </span>
                                    <input type="number" step="0.01" inputmode="decimal" x-model="entry.discount"
                                           class="num h-(--spacing-field) w-28 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                                </label>
                            @endif

                            @if ($show['vat'])
                                <label class="block">
                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                        {{ __('purchase::field.tax') }}
                                    </span>
                                    <input type="number" step="0.01" inputmode="decimal" x-model="entry.tax"
                                           class="num h-(--spacing-field) w-28 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                                </label>
                            @endif

                            <span class="ms-auto text-sm">
                                {{ __('purchase::field.line_total') }}:
                                <span class="num font-semibold" x-text="money(entryNet)"></span>
                            </span>

                            <x-ui.button type="button" tone="primary" x-on:click="addToCart()"
                                         ::disabled="! picked">
                                {{ __('purchase::action.add_line') }}
                            </x-ui.button>

                            <x-ui.button type="button" tone="ghost" x-on:click="clearEntry()">
                                {{ __('purchase::action.clear_line') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── কার্ট ─────────────────────────────────────────────── --}}
            <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="px-2 py-2 text-start">{{ __('core.table.serial') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('purchase::field.product') }}</th>
                                <th class="px-2 py-2 text-end">{{ __('purchase::field.qty') }}</th>
                                @if ($show['free_qty'])
                                    <th class="px-2 py-2 text-end">{{ __('purchase::field.free_qty') }}</th>
                                @endif
                                <th class="px-2 py-2 text-end">{{ __('purchase::field.rate') }}</th>
                                <th class="px-2 py-2 text-end">{{ __('purchase::field.sales_price') }}</th>
                                @if ($show['line_discount'])
                                    <th class="px-2 py-2 text-end">{{ __('purchase::field.discount') }}</th>
                                @endif
                                @if ($show['vat'])
                                    <th class="px-2 py-2 text-end">{{ __('purchase::field.tax') }}</th>
                                @endif
                                <th class="px-2 py-2 text-end">{{ __('purchase::field.line_total') }}</th>
                                <th class="px-2 py-2"><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(line, index) in lines" :key="line.key">
                                <tr class="border-t border-(--color-border)">
                                    <td class="num px-2 py-1.5" x-text="index + 1"></td>
                                    <td class="px-2 py-1.5">
                                        <span x-text="line.name"></span>
                                        <input type="hidden" ::name="`lines[${index}][product_id]`" ::value="line.id">
                                        <input type="hidden" ::name="`lines[${index}][sales_price]`"
                                               ::value="line.sales_price">
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               ::name="`lines[${index}][qty]`" x-model="line.qty"
                                               class="num h-8 w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    </td>
                                    @if ($show['free_qty'])
                                        <td class="px-2 py-1.5">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   ::name="`lines[${index}][free_qty]`" x-model="line.free_qty"
                                                   class="num h-8 w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    <td class="px-2 py-1.5">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               ::name="`lines[${index}][rate]`" x-model="line.rate"
                                               class="num h-8 w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    </td>
                                    <td class="num px-2 py-1.5 text-end text-(--color-ink-muted)"
                                        x-text="line.sales_price ? money(line.sales_price) : '—'"></td>
                                    @if ($show['line_discount'])
                                        <td class="px-2 py-1.5">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   ::name="`lines[${index}][discount]`" x-model="line.discount"
                                                   class="num h-8 w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    @if ($show['vat'])
                                        <td class="px-2 py-1.5">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   ::name="`lines[${index}][tax]`" x-model="line.tax"
                                                   class="num h-8 w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    <td class="num px-2 py-1.5 text-end font-medium" x-text="money(lineNet(line))"></td>
                                    <td class="px-2 py-1.5 text-end">
                                        <button type="button" @click="lines.splice(index, 1)"
                                                class="rounded-(--radius-field) px-2 py-1 text-(--color-danger)"
                                                aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>
                                    </td>
                                </tr>
                            </template>

                            <tr x-show="lines.length === 0" x-cloak>
                                <td colspan="10" class="px-3 py-6 text-center text-sm text-(--color-ink-muted)">
                                    {{ __('purchase::message.no_lines_yet') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- ══ ডান কলাম: যোগফল ════════════════════════════════════════ --}}
        <aside class="space-y-3">
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-(--color-ink-muted)">{{ __('purchase::field.sub_total') }}</dt>
                        <dd class="num" x-text="money(subTotal)"></dd>
                    </div>

                    @if ($show['vat'])
                        <div class="flex justify-between">
                            <dt class="text-(--color-ink-muted)">{{ __('purchase::field.tax') }}</dt>
                            <dd class="num" x-text="money(taxTotal)"></dd>
                        </div>
                    @endif

                    <div class="flex justify-between border-t border-(--color-border) pt-1.5 font-semibold">
                        <dt>{{ __('purchase::field.net_payable') }}</dt>
                        <dd class="num" x-text="money(netPayable)"></dd>
                    </div>
                </dl>

                {{-- ── এখন পরিশোধ ────────────────────────────────────────
                     ডিপোতে অনেক সময় গাড়ির লোককেই টাকা ধরিয়ে দিতে হয়।
                     আলাদা পর্দায় পাঠালে বেশিরভাগ দিন সেটা লেখাই হত না,
                     আর সরবরাহকারীর খাতা ফুলে থাকত। --}}
                <div class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.paid_now') }}
                        </span>
                        <input type="number" step="0.01" inputmode="decimal" name="paid_now" x-model="paidNow"
                               class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                    </label>

                    <label class="block" x-show="Number(paidNow) > 0" x-cloak>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.paid_from') }}
                        </span>
                        <select name="paid_from_account_id"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach ($moneyAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex justify-between text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('purchase::field.balance_due') }}</span>
                        <span class="num font-semibold" x-text="money(balanceDue)"></span>
                    </div>
                </div>
            </section>

            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3 text-2xs text-(--color-ink-muted)">
                <div class="flex justify-between">
                    <span>{{ __('purchase::field.total_item') }}</span>
                    <span class="num" x-text="lines.length"></span>
                </div>
                <div class="mt-1 flex justify-between">
                    <span>{{ __('purchase::field.total_qty') }}</span>
                    <span class="num" x-text="qty(totalQty)"></span>
                </div>
            </section>

            <x-ui.button type="submit" tone="primary" class="w-full"
                         ::class="(busy || lines.length === 0) && 'pointer-events-none opacity-50'">
                {{ __('purchase::action.confirm_direct') }}
            </x-ui.button>

            <x-ui.button type="button" tone="ghost" class="w-full" x-on:click="clearAll()">
                {{ __('purchase::action.clear_all') }}
            </x-ui.button>
        </aside>
    </form>

    @push('scripts')
        <script>
            /*
             * সরাসরি ক্রয়ের পর্দা।
             *
             * দর নির্ধারণের অঙ্কটা এখানে লেখা নেই — window.abos.reprice
             * (resources/js/pricing.js) সেটা করে, আর ওটার নিজের ১৪টা
             * পরীক্ষা আছে। Blade-এর ভেতরে লিখলে ওই অঙ্কটার কোনো পরীক্ষা
             * লেখা যেত না, অথচ সেটাই প্রতিটা পণ্যের বিক্রয়মূল্য ঠিক করে।
             */
            function directPurchase(catalogue, vatEnabled) {
                return {
                    catalogue,
                    vatEnabled,
                    busy: false,
                    term: '',
                    picked: null,
                    entry: {},
                    lines: [],
                    paidNow: '',
                    nextKey: 1,

                    get visible() {
                        const t = this.term.trim().toLowerCase();
                        if (t === '') return [];

                        return this.catalogue.filter(p =>
                            p.name.toLowerCase().includes(t) || p.code.toLowerCase().includes(t)
                        ).slice(0, 30);
                    },

                    blankEntry() {
                        return {
                            qty: '', free_qty: '', rate: '', discount: '', tax: '',
                            markup: '', margin: '', sales_price: '', anchor: '',
                        };
                    },

                    init() { this.entry = this.blankEntry(); },

                    pick(product) {
                        this.picked = product;
                        this.entry = this.blankEntry();
                        this.entry.qty = '1';

                        /*
                         * শেষ ক্রয়দর আর চলতি বিক্রয়মূল্য বসিয়ে দেওয়া হয়,
                         * কিন্তু নোঙর ফাঁকাই থাকে।
                         *
                         * নোঙর বসালে ঘরগুলো নিজে থেকেই একটা দর "বলত" যা
                         * কেউ বেছে নেয়নি, আর সেটাই সেভ হয়ে যেত। মানুষটা
                         * তিনটার একটায় হাত দিলে তবেই অঙ্ক শুরু হয়।
                         */
                        if (product.last_rate > 0) this.entry.rate = String(product.last_rate);
                        if (product.sales_price > 0) this.entry.sales_price = String(product.sales_price);

                        this.term = '';
                    },

                    pickFirst() {
                        const first = this.visible[0];
                        if (first) this.pick(first);
                    },

                    /** তিনটা ঘরের একটায় লেখা হল — বাকিগুলো নতুন করে বসে। */
                    priced(edited) {
                        Object.assign(this.entry, window.abos.reprice(this.entry, edited));
                    },

                    // ── চলতি লাইনের অঙ্ক ────────────────────────────────
                    get entryNet() {
                        const base = (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
                        const tax = this.vatEnabled ? (Number(this.entry.tax) || 0) : 0;

                        return base - (Number(this.entry.discount) || 0) + tax;
                    },

                    addToCart() {
                        if (! this.picked) return;

                        this.lines.push({
                            key: this.nextKey++,
                            id: this.picked.id,
                            name: this.picked.name,
                            qty: this.entry.qty || '1',
                            free_qty: this.entry.free_qty || '',
                            rate: this.entry.rate || '0',
                            discount: this.entry.discount || '',
                            tax: this.entry.tax || '',
                            sales_price: this.entry.sales_price || '',
                        });

                        this.clearEntry();

                        /* ?. — একটা ঘর খুঁজে না পাওয়া কখনো পুরো পর্দা
                           থামানোর কারণ হওয়া উচিত নয়। এখানে ঠিক তা-ই
                           হয়েছিল: focus() এররে Alpine থেমে যেত, কার্টের
                           ঘরগুলোর name বাঁধা হত না, আর সাবমিটে সার্ভার
                           কোনো লাইনই পেত না। */
                        this.$nextTick(() => this.$refs.search?.focus());
                    },

                    clearEntry() {
                        this.picked = null;
                        this.entry = this.blankEntry();
                        this.term = '';
                    },

                    clearAll() {
                        this.lines = [];
                        this.paidNow = '';
                        this.clearEntry();
                    },

                    // ── কার্টের অঙ্ক ────────────────────────────────────
                    lineNet(line) {
                        const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);
                        const tax = this.vatEnabled ? (Number(line.tax) || 0) : 0;

                        return base - (Number(line.discount) || 0) + tax;
                    },

                    get subTotal() {
                        return this.lines.reduce(
                            (s, l) => s + (Number(l.qty) || 0) * (Number(l.rate) || 0) - (Number(l.discount) || 0), 0,
                        );
                    },

                    get taxTotal() {
                        if (! this.vatEnabled) return 0;
                        return this.lines.reduce((s, l) => s + (Number(l.tax) || 0), 0);
                    },

                    get netPayable() {
                        return this.subTotal + this.taxTotal;
                    },

                    get balanceDue() {
                        const due = this.netPayable - (Number(this.paidNow) || 0);
                        return due > 0 ? due : 0;
                    },

                    get totalQty() {
                        return this.lines.reduce(
                            (s, l) => s + (Number(l.qty) || 0) + (Number(l.free_qty) || 0), 0,
                        );
                    },

                    /*
                     * পাঠানোর আগে দুইটা প্রশ্ন।
                     *
                     * বেশি টাকা দেওয়া মানে সরবরাহকারীর কাছে অগ্রিম জমা —
                     * সেটা বৈধ, কিন্তু বেশিরভাগ সময় ওটা টাইপো। আর দুইবার
                     * পাঠানো মানে দুইটা চালান, দুইবার মাল।
                     */
                    guard(event) {
                        if (this.busy || this.lines.length === 0) {
                            event.preventDefault();

                            return;
                        }

                        const paid = Number(this.paidNow) || 0;

                        if (paid > this.netPayable
                            && ! window.confirm(@js(__('purchase::message.paid_more_confirm')))) {
                            event.preventDefault();

                            return;
                        }

                        this.busy = true;
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
