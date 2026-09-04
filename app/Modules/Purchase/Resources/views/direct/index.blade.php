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
          x-data="directPurchase(
              {{ Illuminate\Support\Js::from($products) }},
              {{ $show['vat'] ? 'true' : 'false' }},
              {{ Illuminate\Support\Js::from(route('purchase.direct.last_rates', ['supplier' => 0])) }}
          )"
          @submit="guard($event)"
          x-effect="saveDraft()"
          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{-- ── খসড়া পাওয়া গেছে ───────────────────────────────────────

             ⚠️ প্রস্তাব, ফেরানো নয়। ⓘ নিজে থেকে ফিরিয়ে দিলে কেউ নতুন
             ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে।

             ⓘ বারটা ফর্মের ভিতরে, তাই `x-data`-র স্কোপেই আছে — আর
             দুইটা বোতামেই `type="button"`, নাহলে ওগুলো ফর্মটাই সাবমিট
             করে দিত। --}}
        <div x-show="draftFound" x-cloak
             class="xl:col-span-2 flex flex-wrap items-center gap-2 rounded-(--radius-card)
                    border border-(--color-border) bg-(--color-badge-warning-bg)
                    px-3 py-2 text-sm text-(--color-badge-warning-ink)">
            <span class="font-medium">{{ __('purchase::message.draft_found') }}</span>
            <span class="num text-2xs opacity-80" x-text="draftAt"></span>

            <span class="ms-auto flex gap-2">
                <button type="button" @click="restoreDraft()"
                        class="rounded-(--radius-field) bg-(--color-surface-card) px-2 py-1
                               text-2xs font-medium text-(--color-ink)">
                    {{ __('purchase::action.draft_restore') }}
                </button>
                <button type="button" @click="discardDraft()"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)">
                    {{ __('purchase::action.draft_discard') }}
                </button>
            </span>
        </div>

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="min-w-0 space-y-3">

            {{-- ── ডকুমেন্ট স্ট্রিপ ──────────────────────────────────── --}}
            <section data-boxed class="rounded-(--radius-card) border-t-2 border-(--color-brand-500)
                            border-x border-b border-(--color-border)
                            bg-(--color-surface-card) p-3">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.supplier') }}
                        </span>
                        {{-- সরবরাহকারী বদলালেই গতবারের দরগুলো নতুন করে আসে।

                             ⚠️ দরগুলো সরবরাহকারী-ভেদে আলাদা। বাছাই বদলে
                             পুরনো তালিকা রেখে দিলে কার্টের সারিতে **অন্য
                             একজনের দর** বসে থাকত — নীরবে, আর ঠিক তখনই যখন
                             মানুষটা ওই সংখ্যাটা দেখে দরাদরি করছেন। --}}
                        <select name="supplier_id" required
                                x-on:change="loadLastRates($event.target.value)"
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
                        <x-ui.date name="trx_date"
                                   :value="old('trx_date', now()->toDateString())"
                                   class="w-full text-sm" />
                                   </label>
                                   </div>
                                   </section>

            {{-- ── এন্ট্রি স্ট্রিপ ───────────────────────────────────── --}}
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
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
                {{--
                    `picked` নাল থাকতে পারে, তাই ভিতরের প্রতিটা পড়া
                    `picked?.` দিয়ে।

                    ── কী ভাঙা ছিল ─────────────────────────────────────
                    `x-show` উপাদানটা **লুকায়**, কিন্তু Alpine ভিতরের
                    অভিব্যক্তিগুলো তবু মূল্যায়ন করে। শুরুতে `picked`
                    নাল, তাই পাতা খোলা মাত্রই কনসোলে:

                        Cannot read properties of null (reading 'name')

                    পর্দায় কিছু ভাঙত না, তাই কেউ টের পেত না। কিন্তু
                    ত্রুটিতে ভরা কনসোল **আসল ত্রুটিকে ঢেকে দেয়** — আর
                    ওটাই এই বাগের আসল দাম।

                    ধরা পড়েছে ২৬ আগস্ট ২০২৬, লাইভের ১৪৪টা পর্দা ঘুরে
                    কনসোল পড়ে — কোনো মানুষের চোখে নয়।
                --}}
                <div x-show="picked" x-cloak>
                    <div class="mt-3">
                        <div class="mb-2 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span class="font-semibold" x-text="picked?.name"></span>
                            <span class="text-2xs text-(--color-ink-muted)">
                                {{ __('purchase::message.on_hand') }}:
                                <span class="num" x-text="qty(picked?.on_hand)"></span>
                            </span>
                            {{-- শেষ কত দামে কেনা হয়েছিল — নতুন দর এর সাথেই মেলানো হয় --}}
                            <span class="text-2xs text-(--color-ink-muted)" x-show="picked?.last_rate > 0">
                                {{ __('purchase::message.last_rate') }}:
                                <span class="num" x-text="money(picked?.last_rate)"></span>
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
            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="overflow-x-auto">
                    <table class="ui-grid is-compact w-full text-sm">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('core.table.serial') }}</th>
                                <th class="text-start">{{ __('purchase::field.product') }}</th>
                                <th class="text-end">{{ __('purchase::field.qty') }}</th>
                                @if ($show['free_qty'])
                                    <th class="text-end">{{ __('purchase::field.free_qty') }}</th>
                                @endif
                                <th class="text-end">{{ __('purchase::field.rate') }}</th>
                                <th class="text-end">{{ __('purchase::field.sales_price') }}</th>
                                @if ($show['line_discount'])
                                    <th class="text-end">{{ __('purchase::field.discount') }}</th>
                                @endif
                                @if ($show['vat'])
                                    <th class="text-end">{{ __('purchase::field.tax') }}</th>
                                @endif
                                <th class="text-end">{{ __('purchase::field.line_total') }}</th>
                                <th><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(line, index) in lines" :key="line.key">
                                <tr class="border-t border-(--color-border)">
                                    <td class="num" x-text="index + 1"></td>
                                    <td>
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="`lines[${index}][product_id]`" :value="line.id">
                                        <input type="hidden" :name="`lines[${index}][sales_price]`"
                                               :value="line.sales_price">

                                        {{-- ⭐ গতবারের দর — সারিতেই, ভাসমান নয়।

                                             এন্ট্রি স্ট্রিপেও একটা "শেষ ক্রয়দর" আছে, কিন্তু
                                             সেটা সারিটা কার্টে যাওয়ামাত্র মিলিয়ে যায়। বারো
                                             লাইন পরে, তিনটা গতবারের চেয়ে দামি — আর যিনি
                                             চূড়ান্ত বোতাম চাপতে যাচ্ছেন তিনি জানতেই পারতেন না।

                                             ⚠️ দুইটা সংখ্যা দুইটা আলাদা প্রশ্নের উত্তর:
                                               স্ট্রিপেরটা  কোম্পানি-ব্যাপী শেষ দর, যে কারো কাছ থেকে
                                               এইটা         **এই সরবরাহকারীর** কাছ থেকে — দরাদরির সংখ্যা
                                             তাই লেবেল দুইটাও আলাদা। --}}
                                        <template x-if="lastRateFor(line)">
                                            <span class="block text-2xs text-(--color-ink-muted)">
                                                {{ __('purchase::message.last_from_supplier') }}:
                                                <span class="num" x-text="money(lastRateFor(line).rate)"></span>
                                                <span x-text="`· ${lastRateFor(line).on}`"></span>
                                            </span>
                                        </template>

                                        {{-- ⛔ শূন্য লেখা হয় না।

                                             "গতবারের দর" শিরোনামের নিচে ০.০০ মানে "ফ্রি
                                             দিয়েছিল", আর সেটা মিথ্যা। আগে কখনো না কেনা থাকলে
                                             কথাটা সরাসরি লেখা থাকে। --}}
                                        <template x-if="supplierChosen && ! lastRateFor(line)">
                                            <span class="block text-2xs text-(--color-ink-subtle)">
                                                {{ __('purchase::message.first_from_supplier') }}
                                            </span>
                                        </template>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${index}][qty]`" x-model="line.qty"
                                               class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    </td>
                                    @if ($show['free_qty'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="`lines[${index}][free_qty]`" x-model="line.free_qty"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    <td>
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${index}][rate]`" x-model="line.rate"
                                               class="num h-(--spacing-field-dense) w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    </td>
                                    <td class="num text-(--color-ink-muted)"
                                        x-text="line.sales_price ? money(line.sales_price) : '—'"></td>
                                    @if ($show['line_discount'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="`lines[${index}][discount]`" x-model="line.discount"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    @if ($show['vat'])
                                        <td>
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="`lines[${index}][tax]`" x-model="line.tax"
                                                   class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                        </td>
                                    @endif
                                    <td class="num font-medium" x-text="money(lineNet(line))"></td>
                                    <td class="text-end">
                                        <div class="flex items-center justify-end gap-1">
                                            {{-- উপহার যোগ — এই সারির সাথে বাঁধা --}}
                                            <button type="button" @click="addGift(line)"
                                                    class="rounded-(--radius-field) border border-(--color-border)
                                                           px-2 py-0.5 text-2xs text-(--color-ink-muted)
                                                           hover:text-(--color-ink)">
                                                {{ __('purchase::action.add_gift') }}
                                            </button>

                                            <button type="button" @click="lines.splice(index, 1)"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-danger)"
                                                    aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            {{-- ── উপহারের সারিগুলো ─────────────────────────────

                                 ⚠️ কেন মূল সারির **নিচে**, আলাদা কোনো তালিকায় নয়:
                                 মালিকের নির্দেশ — *"উপহার কোন পণ্যের সাথে আসল তাও
                                 manage করতে হবে"*। আলাদা তালিকায় বসালে পর্দাতেও
                                 জোড়াটা দেখা যেত না, আর মানুষটা ভুল পণ্যের সাথে
                                 জুড়ে দিতেন।

                                 ⓘ নামের চাবিতে `line.key` ব্যবহার করা হয়, ক্রমিক
                                 সংখ্যা নয় — মাঝখানের একটা সারি মুছে দিলে ক্রমিক
                                 সংখ্যাগুলো পিছিয়ে যেত আর দুইটা উপহার একই নামে
                                 জমা পড়ত। --}}
                            <template x-for="line in lines" :key="`g${line.key}`">
                                <template x-for="(gift, gi) in line.gifts" :key="gift.key">
                                    <tr class="bg-(--color-surface-sunken)/50">
                                        <td></td>
                                        <td colspan="3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-(--radius-field) bg-(--color-badge-info-bg)
                                                             px-1.5 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                                    {{ __('purchase::field.gift') }}
                                                </span>

                                                <select :name="`gifts[${line.key}-${gi}][product_id]`"
                                                        x-model="gift.product_id" required
                                                        class="h-(--spacing-field-dense) rounded-(--radius-field) border
                                                               border-(--color-border) bg-(--color-surface-card)
                                                               px-1 text-xs">
                                                    <option value="">—</option>
                                                    <template x-for="p in catalogue" :key="p.id">
                                                        <option :value="p.id" x-text="p.name"></option>
                                                    </template>
                                                </select>

                                                <input type="number" step="0.01" inputmode="decimal" min="0"
                                                       :name="`gifts[${line.key}-${gi}][qty]`" x-model="gift.qty"
                                                       placeholder="{{ __('purchase::field.qty') }}"
                                                       class="num h-(--spacing-field-dense) w-16 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card)
                                                              px-1 text-end text-xs">

                                                <input type="text" maxlength="191"
                                                       :name="`gifts[${line.key}-${gi}][remarks]`"
                                                       x-model="gift.remarks"
                                                       placeholder="{{ __('purchase::field.narration') }}"
                                                       class="h-(--spacing-field-dense) w-40 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card)
                                                              px-2 text-xs">

                                                {{-- ⭐ জোড়াটা এখানেই বসে, আর মানুষটাকে বাছতে হয় না।

                                                     সারিটা যে পণ্যের, উপহারটা তার বিপরীতেই — সেটাই
                                                     পর্দায় লেখা আছে, আর সেটাই সার্ভারে যায়। বাছতে
                                                     দিলে একদিন ভুল পণ্য বাছা হত, আর তখন "সাবানে আসল
                                                     ক্রয়দর কত পড়ল" হিসাবটা নীরবে ভুল হত। --}}
                                                <input type="hidden"
                                                       :name="`gifts[${line.key}-${gi}][against_product_id]`"
                                                       :value="line.id">

                                                <span class="text-2xs text-(--color-ink-muted)">
                                                    {{ __('purchase::field.gift_against') }}:
                                                    <span x-text="line.name"></span>
                                                </span>
                                            </div>
                                        </td>
                                        {{-- বাকি কলামগুলো ফাঁকা।

                                             সংখ্যাটা Blade গোনে, JS নয় — কোন ঘরগুলো
                                             দেখা যাবে সেটা সেটিংসের সিদ্ধান্ত, আর সেটা
                                             পাতা তৈরির সময়েই জানা। JS-এ গুনলে একই কথা
                                             দুই জায়গায় থাকত। --}}
                                        <td colspan="{{ 2
                                            + ($show['free_qty'] ? 1 : 0)
                                            + ($show['line_discount'] ? 1 : 0)
                                            + ($show['vat'] ? 1 : 0) }}"></td>
                                        <td class="text-end">
                                            <button type="button" @click="line.gifts.splice(gi, 1)"
                                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-danger)"
                                                    aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </template>

                            <tr x-show="lines.length === 0" x-cloak>
                                <td colspan="10" class="text-center text-sm text-(--color-ink-muted)">
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
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
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

                    {{-- ⏳ ── খরচ ও রাউন্ডিংয়ের ঘর এখানে বসবে, কিন্তু আজ নয় ──

                         মালিকের ছবিতে মোটের কার্ডে দুইটা লেখার ঘর আছে —
                         **খরচ** ও **রাউন্ডিং (+/−)**। ঘর দুইটা বসানো
                         হয়েছিল, তারপর **সরিয়ে নেওয়া হয়েছে**, আর কারণটা
                         লিখে রাখা দরকার:

                         ⛔ **"মোট"-ই খতিয়ানে যায়।** `PurchaseBillService`
                         বিলের `total` থেকে সরবরাহকারীর দেনা বসায়। ঘর
                         দুইটা কেবল পর্দায় যোগ করলে পাতা বলত "মোট দেয়
                         ৳১,০০০" আর খতিয়ানে বসত ৳৯৮০ — **নীরবে, কোনো
                         ত্রুটি ছাড়া**, আর সরবরাহকারীর খাতা আমাদের খাতার
                         সাথে মিলত না।

                         ⚠️ **আর ওটা মৃত বোতামের চেয়েও খারাপ:** মৃত বোতাম
                         কিছুই করে না; এটা **একটা ভুল সংখ্যা দেখাত**, আর
                         মানুষ সেই সংখ্যা দেখে দর ঠিক করতেন।

                         **যা লাগবে, একসাথে:**
                         ```
                         pur_bills-এ expense ও rounding ঘর     মাইগ্রেশন
                         PurchaseBillService — মোটে যোগ
                         DirectPurchaseService — ঘর দুইটা পাস
                         এই কার্ডের দুইটা ইনপুট
                         ```
                         ⓘ চারটার তিনটা থাকলে সংখ্যাটা ভুল — তাই চারটাই
                         একসাথে, নয়তো একটাও নয়। --}}
                    <div class="flex justify-between border-t border-(--color-border) pt-1.5 font-semibold">
                        <dt>{{ __('purchase::field.net_payable') }}</dt>
                        <dd class="num" x-text="money(netPayable)"></dd>
                    </div>
                </dl>

                {{-- ── এখন পরিশোধ ────────────────────────────────────────
                     ডিপোতে অনেক সময় গাড়ির লোককেই টাকা ধরিয়ে দিতে হয়।
                     আলাদা পর্দায় পাঠালে বেশিরভাগ দিন সেটা লেখাই হত না,
                     আর সরবরাহকারীর খাতা ফুলে থাকত। --}}
                {{-- ── কে মালটা আনল ───────────────────────────────────

                     মালিকের কথা: *"পরিবহনকারী মানে মাল আনার খরচ — নেওয়ার
                     খরচও আছে এতে।"* ⓘ অর্থাৎ ঘরটা কেবল নাম নয়, খরচসহ।

                     ⛔ এতদিন ক্রয়ের দিকে এর একটাও ছিল না — মেপে দেখা
                     গেছে `app/Modules/Purchase`-এ `transport_cost` শব্দটা
                     শূন্যবার। তাই ভাড়াটা হয় কোথাও লেখাই হত না, নয়তো
                     আলাদা খরচ হয়ে বসত, আর **প্রতিটা পণ্যের লাভ ঠিক ভাড়ার
                     পরিমাণে বেশি দেখাত**।

                     ⚠️ আজ ঘরটা কেবল **রাখে** — ভাড়া ক্রয়মূল্যে ঢোকার
                     অংশটা আলাদা কাজ। ⓘ পর্দাতেও সেটা বলা আছে, নাহলে কেউ
                     ধরে নিতেন লাভের অঙ্কে ওটা ইতিমধ্যে ধরা হয়েছে। --}}
                <div class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    <div class="text-2xs font-medium text-(--color-ink-muted)">
                        {{ __('purchase::field.carrier') }}
                    </div>

                    {{-- ⚠️ তালিকা খালি থাকলে কারণটা বলা হয়, আর হাতে নাম
                         লেখার ঘরটা তখনো আছে — তাই পর্দাটা অচল হয় না।

                         ⓘ এই অবস্থাটা কল্পনা নয়: আজ কোনো সরবরাহকারী
                         TRANSPORT ধরনে নেই, তাই **প্রথম দিন থেকেই** তালিকা
                         খালি থাকবে। --}}
                    <select name="carrier_id" x-model="carrierId"
                            x-show="carriers.length > 0" x-cloak
                            class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
                        <option value="">—</option>
                        <template x-for="c in carriers" :key="c.id">
                            <option :value="c.id" x-text="c.label"></option>
                        </template>
                    </select>

                    <p x-show="carriers.length === 0" x-cloak
                       class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                              text-2xs text-(--color-badge-warning-ink)">
                        {{ __('purchase::message.no_carrier_party') }}
                    </p>

                    {{-- হাতে নাম — একবারের ভাড়া গাড়ির জন্য।

                         ⓘ তালিকা থেকে কেউ বাছা হলে ঘরটা লুকায়: দুইটা
                         একসাথে ভরলে কোনটা সত্যি তা কেউ বলতে পারত না। --}}
                    <input type="text" name="carrier_name" x-model="carrierName"
                           x-show="! carrierId" x-cloak
                           placeholder="{{ __('purchase::field.carrier_name') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                    <div class="flex gap-1">
                        <input type="number" step="0.01" inputmode="decimal"
                               name="transport_cost" x-model="transportCost"
                               placeholder="{{ __('purchase::field.transport_cost') }}"
                               class="num h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-end text-2xs">
                        <input type="text" name="vehicle_no" x-model="vehicleNo"
                               placeholder="{{ __('purchase::field.vehicle_no') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                      border border-(--color-border) bg-(--color-surface-card)
                                      px-2 text-2xs">
                    </div>

                    <input type="text" name="driver_name" x-model="driverName"
                           placeholder="{{ __('purchase::field.driver_name') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                    {{-- ⚠️ ভাড়া লিখলে কে আনল সেটা বলতেই হবে — নাহলে
                         টাকাটা কার খাতায় দেনা হবে কেউ জানে না। --}}
                    <p x-show="transportNeedsWho" x-cloak
                       class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                              text-2xs text-(--color-badge-warning-ink)">
                        {{ __('purchase::message.transport_needs_carrier') }}
                    </p>

                    {{-- ⭐ সৎ থাকা: সংখ্যাটা আজ ক্রয়মূল্যে যায় না, আর
                         পর্দা সেটাই বলে। ⓘ না বললে কেউ ধরে নিতেন লাভের
                         অঙ্কে ধরা হয়েছে, আর দর ঠিক করতেন তার উপর। --}}
                    <p x-show="Number(transportCost) > 0" x-cloak
                       class="text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.transport_not_in_cost_yet') }}
                    </p>
                </div>

                {{-- ── এখন পরিশোধ — একাধিক পথে ───────────────────────

                     ⚠️ আগে এখানে **একটা অঙ্ক আর একটা খাত** ছিল, অর্থাৎ
                     পর্দাটা ধরে নিত টাকা এক পথেই যায়। বাস্তবে যায় না:
                     কিছু নগদ, বাকিটা চেকে বা bKash-এ। ⛔ দ্বিতীয় পথটা
                     তখন কোথাও লেখাই হত না, আর সরবরাহকারীর খাতা ভুল
                     দেখাত।

                     ⓘ গড়নটা বিক্রয়ের জমার প্যানেলের হুবহু — সারি
                     খসড়ায় বসে, "যোগ" চাপলে তালিকায় ওঠে, আর লুকানো ঘর
                     হয়ে সার্ভারে যায়। দুইটা পর্দা এক রকম, তাই একবার
                     শিখলেই দুইটাই চলে। --}}
                <div class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                    <div class="text-2xs font-medium text-(--color-ink-muted)">
                        {{ __('purchase::field.paid_now') }}
                    </div>

                    {{-- যোগ হয়ে যাওয়া জমাগুলো --}}
                    <template x-for="(row, i) in deposits" :key="i">
                        <div class="flex items-center gap-1 rounded-(--radius-field)
                                    bg-(--color-surface-sunken) px-2 py-1 text-2xs">
                            <span class="min-w-0 flex-1 truncate">
                                <span x-text="methodName(row.methodId)"></span>
                                <span class="text-(--color-ink-muted)"
                                      x-show="row.reference"
                                      x-text="' · ' + row.reference"></span>
                            </span>
                            <span class="num font-medium" x-text="money(Number(row.amount))"></span>
                            <button type="button" @click="dropDeposit(i)"
                                    class="px-1 text-(--color-danger)"
                                    aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>

                            {{-- ⓘ সার্ভারে যা যায় — নামের ভিতরে সূচক, তাই
                                 PHP-তে সারিগুলো আলাদা থাকে। --}}
                            <input type="hidden" :name="`deposits[${i}][amount]`" :value="row.amount">
                            <input type="hidden" :name="`deposits[${i}][payment_method_id]`" :value="row.methodId">
                            <input type="hidden" :name="`deposits[${i}][account_id]`" :value="row.accountId">
                            <input type="hidden" :name="`deposits[${i}][reference]`" :value="row.reference">
                            <input type="hidden" :name="`deposits[${i}][ref_date]`" :value="row.refDate">
                        </div>
                    </template>

                    {{-- নতুন জমার খসড়া --}}
                    <div class="space-y-1">
                        <select x-model="depositDraft.methodId" @change="methodPicked()"
                                class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
                            <option value="">{{ __('purchase::field.paid_how') }}</option>
                            <template x-for="m in depositMethods" :key="m.id">
                                <option :value="m.id" x-text="m.label"></option>
                            </template>
                        </select>

                        {{-- ⚠️ খাতের তালিকা উপায় বাছার পরেই। উপায় না বেছে
                             খাত দেখালে কেউ নগদের খাতে চেকের টাকা বসিয়ে
                             দিতেন, আর মাস শেষে নগদ মিলত না। --}}
                        <select x-model="depositDraft.accountId" x-show="depositDraft.methodId" x-cloak
                                class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
                            <option value="">{{ __('purchase::field.paid_from') }}</option>
                            <template x-for="a in depositAccounts" :key="a.id">
                                <option :value="a.id" x-text="a.label"></option>
                            </template>
                        </select>

                        <div class="flex gap-1">
                            <input type="number" step="0.01" inputmode="decimal"
                                   x-model="depositDraft.amount"
                                   placeholder="{{ __('purchase::field.amount') }}"
                                   class="num h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field)
                                          border border-(--color-border) bg-(--color-surface-card)
                                          px-2 text-end text-2xs">
                            <button type="button" @click="addDeposit()" :disabled="! depositReady"
                                    class="rounded-(--radius-field) border border-(--color-border)
                                           px-2 text-2xs disabled:opacity-40">
                                {{ __('purchase::action.add_deposit') }}
                            </button>
                        </div>

                        {{-- রেফারেন্স — কেবল যে উপায়ে সেটা লাগে --}}
                        <input type="text" x-model="depositDraft.reference"
                               x-show="depositNeedsReference" x-cloak
                               placeholder="{{ __('purchase::field.reference') }}"
                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">

                        {{-- ── এই উপায়ের কোনো খাত নেই ───────────────────

                             ⚠️ ছাঁকনিটা ঠিকমতো কাজ করলে এই অবস্থাটা আসবেই:
                             যে কোম্পানির ব্যাংক হিসাব ছকে বসানো নেই, সে
                             "ব্যাংক ট্রান্সফার" বাছলে **একটাও খাত পাবে না**।

                             ⛔ বার্তাটা না থাকলে পর্দাটা চুপ করে থাকত —
                             খালি তালিকা, নিষ্ক্রিয় "যোগ" বোতাম, আর কোনো
                             কারণ নয়। মানুষটা ভাবতেন পর্দা নষ্ট, অথচ
                             অনুপস্থিত জিনিসটা তাঁর নিজের হিসাবের ছকে।

                             ⓘ পথটাও বলা আছে, কারণ "কোথায় গিয়ে ঠিক করব"
                             না জানলে বার্তাটা কেবল একটা অভিযোগ। --}}
                        <p x-show="depositDraft.methodId && depositAccounts.length === 0" x-cloak
                           class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                                  text-2xs text-(--color-badge-warning-ink)">
                            {{ __('purchase::message.no_account_for_method') }}
                        </p>
                    </div>

                    <div class="flex justify-between text-2xs">
                        <span class="text-(--color-ink-muted)">{{ __('purchase::field.paid_total') }}</span>
                        <span class="num" x-text="money(paidTotal)"></span>
                    </div>

                    {{-- ── তিনটা সারি, একটা নয় — মালিকের ছবির বিন্যাস ─────

                         ```
                         এই বিলে বাকি   ← এই কাগজটার হিসাব
                         আগের বকেয়া     ← সরবরাহকারীর পুরনো খাতা
                         ─────────────
                         মোট বকেয়া      ← দুইটার যোগফল
                         ```

                         ⚠️ **কেন তিনটা:** একটা সংখ্যায় মিশিয়ে দিলে "৳৫০,০০০
                         বাকি" পড়ে বোঝার উপায় থাকত না ওটা আজকের বিলের নাকি
                         ছয় মাসের জমা দেনা। ⓘ দুইজন মানুষ দুইটা অর্থ করতেন,
                         আর দরাদরির টেবিলে ওই ভুলের দাম টাকা।

                         ⓘ আগের সারিটার নাম ছিল "বাকি" — এখন **"এই বিলে
                         বাকি"**। অঙ্ক অপরিবর্তিত, কেবল নামটা সৎ হলো। --}}
                    <div class="flex justify-between text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('purchase::field.invoice_due') }}</span>
                        <span class="num" x-text="money(invoiceDue)"></span>
                    </div>

                    {{-- ⚠️ লেবেলটা বদলায় — অগ্রিম আর বকেয়া এক জিনিস নয়।

                         ⓘ বিক্রয়ের পর্দায় ঠিক এই নজিরটাই আছে, আর কারণটা
                         ওখানে লেখা: *"ব্যালেন্স ৫০০" পড়ে বোঝার উপায় ছিল না
                         তিনি ৫০০ পাবেন না দেবেন*। ⛔ এখানে উল্টো দিক —
                         আমরা দেব, নাকি আগেই বেশি দিয়ে রেখেছি। --}}
                    <div class="flex justify-between text-sm">
                        <span class="text-(--color-ink-muted)"
                              x-text="previousDue < 0
                                ? @js(__('purchase::field.previous_advance'))
                                : @js(__('purchase::field.previous_due'))"></span>
                        <span class="num"
                              x-text="previousDue ? money(Math.abs(previousDue)) : '—'"></span>
                    </div>

                    <div class="flex justify-between border-t border-(--color-border) pt-1.5 text-sm">
                        <span class="font-medium">{{ __('purchase::field.total_due') }}</span>
                        <span class="num font-semibold" x-text="money(totalDue)"></span>
                    </div>
                </div>
            </section>

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
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
            function directPurchase(catalogue, vatEnabled, lastRatesUrl) {
                return {
                    catalogue,
                    vatEnabled,
                    lastRatesUrl,
                    busy: false,
                    term: '',
                    picked: null,
                    entry: {},
                    lines: [],
                    paidNow: '',
                    nextKey: 1,

                    /* সরবরাহকারীর আগের বকেয়া — সার্ভার থেকে আসে বাছাইয়ের
                       মুহূর্তে ([[loadLastRates]])।

                       ⓘ ঋণাত্মক মানে অগ্রিম, আর তখন পর্দার লেবেলটাই
                       বদলে যায় — "আগের বকেয়া" নয়, "আগের অগ্রিম"। */
                    previousDue: 0,

                    /* ── জমা ─────────────────────────────────────────────
                       এক বিলের টাকা এক পথে যায় না — কিছু নগদ, বাকিটা চেকে
                       বা bKash-এ। প্রতিটা সারি নিজের উপায় ও নিজের খাত নিয়ে
                       বসে, আর সার্ভারে আলাদা পরিশোধ হয়। */
                    deposits: [],
                    depositMethods: @js($depositMethods),
                    /* ⚠️ খাতের ধরনটা `is_cash`/`is_bank` থেকে নেওয়া যায় না।

                       মেপে দেখা গেছে বিকাশের খাতটা দুইটার কোনোটাই নয়, তাই
                       ওই হিসাবে MFS-এর ছাঁকনি **একটাও খাত পেত না** আর
                       নীরবে সব খাত দেখাত — অর্থাৎ ছাঁকনিটা ছিল, কাজ করত না।

                       ⭐ আসল উত্তর মায়ের কোডে: ১১০১ নগদ · ১১০২ ব্যাংক ·
                       ১১০৫ মোবাইল মানি। বিক্রয়ের দিকেও এভাবেই করা। */
                    moneyAccounts: @js($moneyAccounts->map(fn ($a) => [
                        'id' => (string) $a->id,
                        'label' => $a->label(),
                        'parent' => (string) ($a->parent?->code ?? ''),
                    ])->values()),
                    depositDraft: { methodId: '', accountId: '', amount: '', reference: '', refDate: '' },

                    /* ── কে মালটা আনল ─────────────────────────────────
                       তালিকাটা পক্ষের ধরন ধরে ছাঁকা (TRANSPORT), তাই এখানে
                       কোনো প্রতিষ্ঠানের নাম লেখা নেই। */
                    carriers: @js($carriers),
                    carrierId: '',
                    carrierName: '',
                    transportCost: '',
                    vehicleNo: '',
                    driverName: '',

                    /* এই সরবরাহকারীর কাছ থেকে কোন পণ্য গতবার কত দরে —
                       পণ্যের আইডি ধরে। সরবরাহকারী বাছার সাথে সাথে একবারে
                       আসে; সারি ধরে ধরে নয়, নাহলে বিশ লাইনের কার্টে বিশটা
                       রাউন্ড-ট্রিপ হত আর কাউন্টারে সেটা টের পাওয়া যেত। */
                    lastRates: {},
                    supplierChosen: false,

                    /* ── খসড়া ────────────────────────────────────────────

                       গাড়ি গেটে দাঁড়ানো, বিশ লাইন টাইপ করা — পর্দা হারানো
                       মানে পুরোটা আবার। বিক্রয়ে এই ব্যবস্থা আগেই আছে, আর
                       এখানে সেটার গড়নই নেওয়া হয়েছে, ওদের শেখা ভুলগুলোসহ।

                       ⚠️ চাবিটা **ক্রয়ের নিজস্ব** — বিক্রয়েরটা থেকে আলাদা।
                       এক চাবি হলে বিক্রয়ের কার্ট ক্রয়ের পর্দায় খুলত, আর
                       কেউ না বুঝে সেটার উপরেই কিনতে বসতেন। */
                    draftKey: 'abos.direct-purchase.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',
                    draftFound: false,
                    draftAt: '',

                    /* সরবরাহকারী · গুদাম · বিল নম্বর — এগুলো Alpine-এর ঘরে
                       নেই, সাধারণ DOM ঘর। তাই খসড়ায় বসানোর সময় পর্দা থেকেই
                       পড়া হয়, আর ফেরানোর সময় পর্দাতেই লেখা হয়। */
                    /* ⚠️ `$root`, `$el` নয়।

                       `$el` মানে **যে এলিমেন্টের ভিতর থেকে ডাকা হয়েছে**।
                       `init()`-এ ওটা ফর্ম, কিন্তু `@click="restoreDraft()"`
                       থেকে ডাকলে ওটা **বোতামটা** — আর বোতামের ভিতরে
                       `[name=...]` কিছুই নেই।

                       ⛔ ফলটা নীরব ছিল: খসড়া ফিরত, সারিগুলোও ফিরত, কিন্তু
                       সরবরাহকারী আর বিল নম্বর **ফাঁকা** থেকে যেত — আর কেউ
                       ভুল সরবরাহকারীর নামে বিলটা নিশ্চিত করে ফেলতে পারতেন।
                       ⓘ ব্রাউজারে চাপ দিয়ে ধরা পড়েছে, কোড পড়ে নয়। */
                    box(name) {
                        return this.$root.querySelector('[name="' + name + '"]');
                    },

                    boxValue(name) {
                        const el = this.box(name);

                        return el ? el.value : '';
                    },

                    setBox(name, value) {
                        const el = this.box(name);

                        if (el) el.value = value ?? '';
                    },

                    /* ⚠️ কার্ট খালি হলে খসড়া মুছে যায়, রাখা হয় না — খালি
                       খসড়া ফেরানোর প্রস্তাব মানে প্রতিদিন একটা অর্থহীন
                       প্রশ্ন। */
                    saveDraft() {
                        /* ⚠️ প্রস্তাব পর্দায় থাকা অবস্থায় লেখা বা মোছা নয়।

                           এই লাইনটা ছাড়া বাগটা নীরব হত: পাতা খোলার সাথে
                           সাথেই `x-effect` একবার চলে, আর তখন কার্ট খালি —
                           অর্থাৎ খসড়াটা মুছে যেত ঠিক সেই মুহূর্তে যখন
                           ফেরানোর প্রস্তাব দেখানো হচ্ছে। বোতামটা থাকত,
                           চাপলে কিছুই ফিরত না। ⓘ বিক্রয়ে এটা শেখা হয়েছে। */
                        if (this.draftFound) return;

                        try {
                            if (this.lines.length === 0) {
                                localStorage.removeItem(this.draftKey);

                                return;
                            }

                            localStorage.setItem(this.draftKey, JSON.stringify({
                                at: new Date().toISOString(),
                                supplierId: this.boxValue('supplier_id'),
                                warehouseId: this.boxValue('warehouse_id'),
                                supplierBillNo: this.boxValue('supplier_bill_no'),
                                lines: this.lines,
                                paidNow: this.paidNow,
                                deposits: this.deposits,
                                carrierId: this.carrierId,
                                carrierName: this.carrierName,
                                transportCost: this.transportCost,
                                vehicleNo: this.vehicleNo,
                                driverName: this.driverName,
                                nextKey: this.nextKey,
                            }));
                        } catch (e) {
                            /* ⚠️ চুপ করে থাকা ইচ্ছাকৃত — localStorage বন্ধ
                               থাকতে পারে (ব্যক্তিগত উইন্ডো, সাইট-ডেটা বন্ধ
                               করা ব্রাউজার), আর তখন লেখা ব্যতিক্রম ছোঁড়ে।
                               কিন্তু ওটা ক্রয় থামানোর কারণ নয়। */
                        }
                    },

                    /** পাতা খোলার সময় — আছে কিনা দেখা, নিজে থেকে ফেরানো নয়। */
                    lookForDraft() {
                        try {
                            const parked = localStorage.getItem(this.draftKey + '.pending');

                            if (parked) {
                                localStorage.removeItem(this.draftKey + '.pending');

                                /* ⚠️ সার্ভার ফিরিয়ে দিয়েছে — তাই প্রশ্ন নয়,
                                   সরাসরি ফেরানো। ব্যবহারকারী "নতুন ক্রয়"
                                   চাননি, তিনি এইটাই পাঠিয়েছিলেন। */
                                if (@js($errors->any())) {
                                    this.applyDraft(parked);

                                    return;
                                }
                            }

                            const raw = localStorage.getItem(this.draftKey);

                            if (! raw) return;

                            const d = JSON.parse(raw);

                            if (! d || ! Array.isArray(d.lines) || d.lines.length === 0) return;

                            this.draftFound = true;
                            this.draftAt = d.at ? new Date(d.at).toLocaleString() : '';
                        } catch (e) {
                            localStorage.removeItem(this.draftKey);
                        }
                    },

                    /* ⚠️ ফেরানো **কেবল চাপ দিলে** — নিজে থেকে নয়।

                       নিজে থেকে ফেরালে সবচেয়ে বিপজ্জনক জিনিসটা ঘটত: কেউ নতুন
                       ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে,
                       আর তার উপরেই নতুন সারি যোগ করে নিশ্চিত করতেন। ⛔ **ভুল
                       সরবরাহকারীর নামে ভুল মাল, আর ভুল দেনা।** */
                    restoreDraft() {
                        this.applyDraft(localStorage.getItem(this.draftKey));
                        this.draftFound = false;
                    },

                    discardDraft() {
                        try {
                            localStorage.removeItem(this.draftKey);
                        } catch (e) {
                            // মুছতে না পারলেও প্রস্তাবটা সরিয়ে দেওয়াই যথেষ্ট
                        }

                        this.draftFound = false;
                    },

                    /** খসড়াটা পর্দায় বসানো — কোথা থেকে এল তা জানার দরকার নেই। */
                    applyDraft(raw) {
                        try {
                            const d = JSON.parse(raw || '{}');

                            this.setBox('supplier_id', d.supplierId);
                            this.setBox('warehouse_id', d.warehouseId);
                            this.setBox('supplier_bill_no', d.supplierBillNo);

                            this.lines = Array.isArray(d.lines) ? d.lines : [];
                            this.paidNow = d.paidNow ?? '';
                            this.deposits = Array.isArray(d.deposits) ? d.deposits : [];
                            this.carrierId = d.carrierId ?? '';
                            this.carrierName = d.carrierName ?? '';
                            this.transportCost = d.transportCost ?? '';
                            this.vehicleNo = d.vehicleNo ?? '';
                            this.driverName = d.driverName ?? '';
                            this.nextKey = d.nextKey ?? (this.lines.length + 1);

                            /* ⚠️ গতবারের দরগুলো আবার আনতে হয়। সরবরাহকারীর
                               ঘরটা কোড দিয়ে বসানো হয়েছে, তাই `change` ঘটে
                               না — আর তখন কার্টে সারি আছে অথচ "গতবার কত"
                               কলামটা ফাঁকা থাকত, ঠিক দরাদরির মুহূর্তে। */
                            if (d.supplierId) this.loadLastRates(d.supplierId);
                        } catch (e) {
                            // ভাঙা খসড়া — ফেরানোর চেয়ে বাদ দেওয়াই নিরাপদ
                        }
                    },

                    /* ── সাবমিটে খসড়া মোছা হয় না, সরিয়ে রাখা হয় ──────────

                       ⚠️ বিক্রয়ে এটা মালিকের অভিযোগে শেখা (৪ সেপ্টেম্বর
                       ২০২৬): *"এই warning-এ আমার সব entry হারিয়ে গেল"*।

                       সাবমিটে খসড়া মুছে দিলে, আর তার পরেই সার্ভার বিলটা
                       ফিরিয়ে দিলে (মজুদ কম, নম্বর নেওয়া, যাচাই — যা-ই হোক)
                       পাতাটা **খালি হয়ে ফিরত**: বিশ লাইনের কার্ট,
                       সরবরাহকারী, জমা — সব শেষ। ⓘ তাই খসড়াটা `.pending`-এ
                       সরে যায়, আর পাতাটা ভুলের বার্তাসহ ফিরলে ওটা নিজে
                       থেকেই ফিরে আসে, প্রশ্ন ছাড়াই। */
                    parkDraft() {
                        try {
                            const raw = localStorage.getItem(this.draftKey);

                            if (raw) {
                                localStorage.setItem(this.draftKey + '.pending', raw);
                                localStorage.removeItem(this.draftKey);
                            }
                        } catch (e) {
                            // সরাতে না পারলে খসড়াটা যেখানে আছে সেখানেই থাক
                        }
                    },

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

                    init() {
                        this.entry = this.blankEntry();

                        /* ⚠️ খসড়া খোঁজা **সবার আগে** — নাহলে নিচের
                           `loadLastRates` খসড়ার সরবরাহকারীকে নয়, পর্দার
                           পুরনো মানটাকে ধরে বসত। */
                        this.lookForDraft();

                        /* যাচাই ব্যর্থ হয়ে পাতাটা ফিরে এলে সরবরাহকারী আগে
                           থেকেই বাছা থাকে, অথচ `change` আর ঘটে না। তখন
                           গতবারের দরগুলো উধাও থাকত — ঠিক যখন মানুষটা ভুল
                           শুধরে আবার দেখছেন। */
                        const chosen = this.$root.querySelector('[name="supplier_id"]');

                        if (chosen && chosen.value) this.loadLastRates(chosen.value);
                    },

                    /**
                     * এই সরবরাহকারীর গতবারের দরগুলো আনা।
                     *
                     * ⚠️ ব্যর্থ হলে তালিকাটা **খালি** করা হয়, পুরনোটা রাখা
                     * হয় না। রেখে দিলে পর্দায় অন্য একজনের দর "এই
                     * সরবরাহকারীর গতবার" নামে বসে থাকত — চুপচাপ, আর ঠিক
                     * দরাদরির মুহূর্তে।
                     */
                    async loadLastRates(supplierId) {
                        const id = Number(supplierId) || 0;

                        this.supplierChosen = id > 0;
                        this.lastRates = {};

                        /* ⚠️ বকেয়াটাও এখানেই শূন্য হয়, দরগুলোর সাথে।
                           সরবরাহকারী বদলে পুরনো সংখ্যাটা রেখে দিলে পর্দায়
                           **অন্য একজনের বকেয়া** এই একজনের নামে বসে থাকত —
                           ঠিক যে ভুলটা দরের বেলায় উপরে ঠেকানো হয়েছে। */
                        this.previousDue = 0;

                        if (id <= 0) return;

                        try {
                            const res = await fetch(this.lastRatesUrl.replace(/0$/, String(id)), {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (! res.ok) return;

                            const payload = await res.json();

                            this.lastRates = payload.rates ?? {};
                            this.previousDue = Number(payload.due) || 0;
                        } catch (e) {
                            /* নীরবে ছেড়ে দেওয়া — সংখ্যাটা সুবিধার, বাধ্যতামূলক
                               নয়। ওটা না এলে ক্রয় থেমে যাওয়া অনেক বড় ক্ষতি। */
                        }
                    },

                    /** এই সারির পণ্যের গতবারের দর — না থাকলে null। */
                    lastRateFor(line) {
                        return this.lastRates[line.id] || null;
                    },

                    /** এই সারির সাথে একটা উপহার। */
                    addGift(line) {
                        if (! Array.isArray(line.gifts)) line.gifts = [];

                        line.gifts.push({
                            key: this.nextKey++,
                            product_id: '',
                            qty: '',
                            remarks: '',
                        });
                    },

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

                            /* উপহারের তালিকা সারির সাথেই জন্মায়, চাহিদামতো
                               নয় — `line.gifts` না থাকলে Alpine-এর x-for
                               undefined-এ হোঁচট খেত, আর হ্যান্ডলারটা মাঝপথে
                               থেমে যেত। */
                            gifts: [],
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

                        /* ⚠️ জমাগুলোও — নাহলে নতুন বিলে আগের বিলের টাকা
                           বসে থাকত, আর কেউ সেটা খেয়াল না করে নিশ্চিত করে
                           ফেলতেন। */
                        this.deposits = [];
                        this.depositDraft = {
                            methodId: '', accountId: '', amount: '', reference: '', refDate: '',
                        };

                        this.carrierId = '';
                        this.carrierName = '';
                        this.transportCost = '';
                        this.vehicleNo = '';
                        this.driverName = '';

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

                    /* ⏳ খরচ ও রাউন্ডিং এখানে যোগ হবে — কিন্তু সেবা ও
                       ডাটাবেসের ঘর বসার পরেই, একসাথে। কারণটা কার্ডের
                       কমেন্টে লেখা: পর্দায় যোগ করে খতিয়ানে না বসালে
                       সংখ্যাটা নীরবে মিথ্যা হয়। */
                    get netPayable() {
                        return this.subTotal + this.taxTotal;
                    },

                    /* এই বিলে কত বাকি — আগে এটার নাম ছিল `balanceDue`।
                       ⓘ নামটা বদলেছে কারণ পর্দায় এখন **দুইটা** বকেয়া:
                       এই বিলেরটা, আর সরবরাহকারীকে মোট। এক নামে দুই অর্থ
                       থাকলে দুইজন মানুষ দুইটা উত্তর পান। */
                    get invoiceDue() {
                        return this.balanceDue;
                    },

                    /*
                     * ⭐ সরবরাহকারীকে মোট কত — ছবির `DUE`।
                     *
                     * `DUE = এই বিলে বাকি + আগের বকেয়া`
                     *
                     * ⚠️ আগের বকেয়া ঋণাত্মক হতে পারে — অগ্রিম দেওয়া
                     * থাকলে। ⓘ তখন যোগফলটা এমনিতেই কমে, আর সেটাই ঠিক:
                     * অগ্রিম টাকাটা এই বিলের দায় মেটায়।
                     */
                    get totalDue() {
                        return this.invoiceDue + this.previousDue;
                    },


                    /* ⚠️ দুইটাই বাদ যায় — জমার সারিগুলো **আর** পুরনো একক
                       ঘরটা। ⓘ পর্দায় আজ কেবল সারিগুলোই ভরা হয়, কিন্তু
                       `paidNow` এখনো কোডে আছে (API ও ইমপোর্টের জন্য), আর
                       যোগফল থেকে বাদ না দিলে কোনো একদিন বকেয়া ভুল দেখাত। */
                    get balanceDue() {
                        const paid = this.paidTotal + (Number(this.paidNow) || 0);
                        const due = this.netPayable - paid;

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
                    /* ভাড়া আছে অথচ কে আনল বলা নেই — সার্ভারও এটাই আটকায়,
                       কিন্তু পর্দায় আগে বলাটাই ভদ্রতা: সাবমিটের পর ভুল
                       দেখানো মানে বিশ লাইন টাইপ করার পর জানা। */
                    get transportNeedsWho() {
                        return Number(this.transportCost) > 0
                            && ! this.carrierId
                            && this.carrierName.trim() === '';
                    },

                    /** বাছা উপায়টার সারি — id ধরে। */
                    get depositMethod() {
                        return this.depositMethods.find(
                            m => String(m.id) === String(this.depositDraft.methodId)
                        ) || null;
                    },

                    get depositNeedsReference() {
                        return !! this.depositMethod?.needsReference;
                    },

                    /* ── কোন উপায়ে কোন খাত ─────────────────────────────

                       ⚠️ ক্রয়ের চেক বিক্রয়ের চেকের উল্টো, আর নকল করলে ভুল
                       হত। বিক্রয়ে চেক **পাওয়া** যায়, তাই ওদিকে ১১০৪ (হাতে
                       আসা চেক) — ব্যাংক নয়, কারণ পাওয়া চেক এখনো টাকা নয়।
                       ক্রয়ে চেক **দেওয়া** হয়, আর তখন ব্যাংকের খাতাই কমে।

                       ⛔ হিসাবের দিক থেকে ইস্যু করা চেকের আসল ঘর `2115`
                       (ইস্যু করা চেক, একটা দায়) — পাশ হওয়া পর্যন্ত ব্যাংক
                       কমার কথা নয়। ⓘ কিন্তু আজকের [[PaymentService::confirm]]
                       যে খাত বাছা হয় সেটাই কমায়, `instrument` যা-ই হোক —
                       অর্থাৎ ফাঁকটা এই প্যানেলের আগেও ছিল, আর চেক-রেজিস্টার
                       ([[ChequeService]]) ক্রয়ের পরিশোধে যুক্ত হলে তবেই
                       সারবে। **এখানে লিখে রাখা হলো, যাতে দিনটা এলে জায়গাটা
                       খুঁজতে না হয়।**

                       ⚠️ উপায় না বাছা পর্যন্ত **একটাও খাত নয়** — খালি
                       তালিকা আর "সব খাত" দুইটা আলাদা অবস্থা। সব দেখালে কেউ
                       নগদের খাতে চেকের টাকা বসিয়ে দিতেন। */
                    depositKindParents: {
                        cash: @js(App\Modules\Accounts\Services\StandardChart::CASH_IN_HAND),
                        bank: @js(App\Modules\Accounts\Services\StandardChart::BANK),
                        mfs: @js(App\Modules\Accounts\Services\StandardChart::MOBILE_MONEY),
                        cheque: @js(App\Modules\Accounts\Services\StandardChart::BANK),
                    },

                    get depositAccounts() {
                        if (! this.depositDraft.methodId) return [];

                        const parent = this.depositKindParents[this.depositMethod?.kind];

                        /* ⓘ উপায়ের `kind` অচেনা হলে ছাঁকনিটা চুপ করে থাকে,
                           সব খাত দেখায় — ভুল কনফিগে পর্দাটা অচল হওয়ার চেয়ে
                           সেটা ভালো। */
                        if (! parent) return this.moneyAccounts;

                        return this.moneyAccounts.filter(a => a.parent === parent);
                    },

                    /** উপায় বাছার সাথে সাথে তার নিজের খাতটা বসে যায়। */
                    methodPicked() {
                        this.depositDraft.accountId = this.depositMethod?.accountId || '';
                    },

                    get depositReady() {
                        return this.depositDraft.methodId !== ''
                            && this.depositDraft.accountId !== ''
                            && Number(this.depositDraft.amount) > 0;
                    },

                    addDeposit() {
                        if (! this.depositReady) return;

                        this.deposits.push({ ...this.depositDraft });

                        this.depositDraft = {
                            methodId: '', accountId: '', amount: '', reference: '', refDate: '',
                        };
                    },

                    dropDeposit(index) {
                        this.deposits.splice(index, 1);
                    },

                    /** তালিকায় নাম দেখানোর জন্য — id নয়। */
                    methodName(id) {
                        return this.depositMethods.find(
                            m => String(m.id) === String(id)
                        )?.label || '';
                    },

                    /* ⓘ সার্ভারও এই যোগটা নিজে করে — পর্দার সংখ্যা বিশ্বাস
                       করে খাতায় কিছু বসানো হয় না। */
                    get paidTotal() {
                        return this.deposits.reduce(
                            (sum, row) => sum + (Number(row.amount) || 0), 0
                        );
                    },

                    guard(event) {
                        if (this.busy || this.lines.length === 0) {
                            event.preventDefault();

                            return;
                        }

                        const paid = this.paidTotal + (Number(this.paidNow) || 0);

                        if (paid > this.netPayable
                            && ! window.confirm(@js(__('purchase::message.paid_more_confirm')))) {
                            event.preventDefault();

                            return;
                        }

                        /* ⚠️ ভাড়া লিখে কে আনল না বললে সার্ভার ফিরিয়ে দেবে।
                           এখানে আগেই থামানো হয়, নাহলে পুরো ফর্মটা গিয়ে
                           ভুলসহ ফিরত — আর সেটা কাউন্টারে এক মিনিটের ক্ষতি। */
                        if (this.transportNeedsWho) {
                            event.preventDefault();

                            return;
                        }

                        /* ⚠️ মোছা নয়, সরিয়ে রাখা — সার্ভার ফিরিয়ে দিলে
                           পাতাটা যেন খালি হয়ে না ফেরে। */
                        this.parkDraft();

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
