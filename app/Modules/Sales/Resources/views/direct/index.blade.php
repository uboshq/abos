{{--
    সরাসরি বিক্রয় — নমুনার হুবহু বিন্যাস।

        উপরে সরু স্ট্রিপ   তারিখ · বিল নম্বর · মেয়াদ · DO · গুদাম
        এন্ট্রি এলাকা       বাঁয়ে ঘরগুলো, মাঝে "এই লাইন", ডানে পণ্যের ছবি
        কার্ট              SL# থেকে টাকা পর্যন্ত ন'টা কলাম
        ডান পাশের প্যানেল   ক্রেতা, এই চালান, দিতে হবে, পার্টির বকেয়া, গোনা

    ── কেন ডান পাশটা আলাদা কলাম, নিচে নয় ────────────────────────────────
    টাকার অঙ্কগুলো সবসময় চোখের সামনে থাকতে হয়, কার্ট যত লম্বাই হোক।
    নিচে রাখলে দশ লাইনের বিলে Confirm বোতামটা ভাঁজের নিচে চলে যেত, আর
    কাউন্টারের লোককে স্ক্রল করে খুঁজতে হত — যে কাজটা করতে তিনি এসেছেন।

    ── "এই লাইন" প্যানেলটা কেন ────────────────────────────────────────
    কার্টে যোগ করার আগেই লাইনটার টাকা কত হচ্ছে সেটা দেখা যায়। না দেখালে
    ভুল দর বা ভুল ছাড় ধরা পড়ত কার্টে যোগ করার পরে, আর তখন সারিটা মুছে
    আবার লিখতে হত।
--}}
@php
    $vatEnabled = $show['vat'] ?? true;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.direct') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

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

    <form method="POST" action="{{ route('sales.direct.store') }}"
          x-data="directSale({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($customerTerms) }}, {{ $walkinId }}, {{ $vatEnabled ? 'true' : 'false' }})"
          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="min-w-0 space-y-3">

            {{-- ── ডকুমেন্ট স্ট্রিপ ──────────────────────────────────── --}}
            <section data-boxed class="rounded-(--radius-card) border-t-2 border-(--color-success)
                            border-x border-b border-(--color-border)
                            bg-(--color-surface-card) p-3">
                {{-- পাঁচটা ঘরই এক সারিতে, আর ঘরগুলো সরু।

                     আগে xl:grid-cols-5 দেওয়া ছিল, কিন্তু ডান পাশের প্যানেল
                     জায়গা নিয়ে নেওয়ায় বাঁ দিকটা আর "xl" হত না — ফলে পাঁচটা
                     ঘর দুই সারিতে ভেঙে যেত আর স্ট্রিপটা দ্বিগুণ উঁচু দেখাত।
                     এখন মাপটা ধরা হয়েছে কনটেইনারের নিজের প্রস্থে (@container),
                     পর্দার প্রস্থে নয় — তাই ঘরগুলো যেখানে বসছে সেখানকার
                     জায়গা দেখেই সিদ্ধান্ত হয়। --}}
                <div class="@container">
                <div class="grid gap-2 grid-cols-2 @xl:grid-cols-5">
                    <label class="block">
                        <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                     text-(--color-ink-muted)">{{ __('sales::field.challan_date') }}</span>
                        <x-ui.date name="trx_date"
                                   :value="old('trx_date', now()->toDateString())"
                                   class="w-full text-sm" />
                                   </label>

                    {{-- বিলের নম্বর নিশ্চিত করার সময় বসে — সিরিজ থেকে।

                         আগে থেকে দেখালে খসড়া বাতিল হলে ওই নম্বরটা খরচ হয়ে
                         সিরিজে একটা ফাঁক থেকে যেত, আর নিরীক্ষায় "৪৭ নম্বর
                         বিলটা কোথায়" প্রশ্নের উত্তর থাকত না। --}}
                    <label class="block">
                        <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                     text-(--color-ink-muted)">{{ __('sales::field.inv_number') }}</span>
                        <input type="text" disabled value="{{ __('sales::field.on_confirm') }}"
                               class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                     text-(--color-ink-muted)">{{ __('sales::field.credit_period') }}</span>
                        <input type="number" min="0" max="365" name="credit_period_days" x-model="creditDays"
                               placeholder="{{ __('sales::field.days') }}"
                               class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-sm">
                    </label>

                    @if ($show['do_no'])
                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('sales::field.do_no') }}</span>
                            <input type="text" name="do_no" placeholder="{{ __('sales::field.optional') }}"
                                   class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 text-sm">
                        </label>
                    @endif

                    @if ($show['warehouse_select'])
                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('sales::field.warehouse') }}</span>
                            <select name="warehouse_id"
                                    class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-app) px-2 text-sm">
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected($warehouse?->id === $w->id)>
                                        {{ $w->name() }} ({{ $w->code }})
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        {{-- এক গুদামের প্রতিষ্ঠানে বাছার কিছু নেই — ঘরটা
                             লুকানো, কিন্তু গুদামটা যায়ই, নাহলে মাল কোথা
                             থেকে বেরোল তা লেখা থাকত না --}}
                        <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">
                    @endif
                </div>
                </div>
            </section>

            {{-- ── এন্ট্রি এলাকা ─────────────────────────────────────── --}}
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                <div class="grid gap-3 lg:grid-cols-[1fr_13rem_9rem]">

                    {{-- বাঁ: খোঁজা ও ঘরগুলো।

                         @container — মাপটা এই কলামের নিজের প্রস্থে, পর্দার
                         নয়। ভিউপোর্ট ধরে মাপলে ডান পাশের প্যানেল জায়গা
                         নেওয়ায় পাঁচটা ঘর তিন-দুইয়ে ভেঙে চার সারি হয়ে যেত। --}}
                    <div class="@container min-w-0">
                        <label class="relative block">
                            <span class="sr-only">{{ __('sales::message.type_or_pick') }}</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"
                                 class="pointer-events-none absolute start-2 top-1/2 size-5 -translate-y-1/2
                                        fill-(--color-brand-500)">
                                <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                            </svg>
                            <input type="search" x-model="term" x-ref="search"
                                   x-init="$nextTick(() => $refs.search.focus())"
                                   @keydown.enter.prevent="pickFirst()"
                                   placeholder="{{ __('sales::message.type_or_pick') }}"
                                   class="h-(--spacing-counter) w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) ps-9 pe-3 text-lg">
                        </label>

                        {{-- বাছাই করা পণ্যের মজুদ — নমুনা দাবি করে এটা পণ্য
                             বাছার সাথে সাথেই দেখা যাবে --}}
                        <p class="mt-1 text-2xs text-(--color-ink-muted)" x-show="! picked" x-cloak>
                            {{ __('sales::message.pick_item_to_see_stock') }}
                        </p>

                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-2xs" x-show="picked" x-cloak>
                            @foreach ([
                                'main' => 'sales::field.main_stock',
                                'reserved' => 'sales::field.reserved_short',
                                'available' => 'sales::field.available_short',
                                'free' => 'sales::field.free_stock',
                                'free_available' => 'sales::field.free_available',
                            ] as $key => $label)
                                <span>
                                    <span class="text-(--color-ink-muted)">{{ __($label) }}</span>
                                    <span class="num font-semibold" x-text="qty(picked?.{{ $key }})"></span>
                                </span>
                            @endforeach
                        </div>

                        {{-- খোঁজার ফল --}}
                        <div class="mt-2 max-h-40 space-y-0.5 overflow-y-auto" x-show="term.trim() !== ''" x-cloak>
                            <template x-for="p in visible" :key="p.id">
                                <button type="button" @click="pick(p)"
                                        class="flex w-full items-baseline justify-between gap-2 rounded-(--radius-field)
                                               px-2 py-1 text-start text-sm transition-colors
                                               hover:bg-(--color-surface-hover)">
                                    <span class="min-w-0 truncate" x-text="p.name"></span>
                                    <span class="num shrink-0 text-2xs text-(--color-ink-muted)"
                                          x-text="qty(p.available)"></span>
                                </button>
                            </template>
                        </div>

                        {{-- প্রথম সারি: পরিমাণ · একক · ফ্রি · একক · মোট --}}
                        <div class="mt-3 grid gap-2 grid-cols-2 @xl:grid-cols-5">
                            <x-sales::entry-field label="sales::field.qty">
                                <input type="number" step="0.01" min="0" x-model="entry.qty"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            <x-sales::entry-field label="sales::field.uom">
                                <input type="text" readonly :value="picked?.unit || ''"
                                       class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                            </x-sales::entry-field>

                            @if ($show['free_qty'])
                                <x-sales::entry-field label="sales::field.free_qty">
                                    <input type="number" step="0.01" min="0" x-model="entry.freeQty"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>

                                <x-sales::entry-field label="sales::field.uom">
                                    <input type="text" readonly :value="picked?.unit || ''"
                                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-sm text-(--color-ink-muted)">
                                </x-sales::entry-field>
                            @endif

                            {{-- মোট পরিমাণ নিজে থেকেই — বিক্রয় + ফ্রি।

                                 হাতে লিখতে দিলে কেউ ভুল যোগ করত, আর গুদাম
                                 থেকে ভুল সংখ্যক মাল বেরোত। --}}
                            <x-sales::entry-field label="sales::field.total_qty">
                                <input type="text" readonly :value="qty(entryTotalQty)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm font-semibold">
                            </x-sales::entry-field>
                        </div>

                        {{-- দ্বিতীয় সারি: দর · মোট টাকা · ছাড় · ভ্যাট · নিট --}}
                        <div class="mt-2 grid gap-2 grid-cols-2 @xl:grid-cols-5">
                            <x-sales::entry-field label="sales::field.sales_rate">
                                <input type="number" step="0.0001" min="0" x-model="entry.rate"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            <x-sales::entry-field label="sales::field.total_amount">
                                <input type="text" readonly :value="money(entryBase)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">
                            </x-sales::entry-field>

                            @if ($show['line_discount'])
                                <x-sales::entry-field label="sales::field.discount_pct">
                                    <input type="number" step="0.01" min="0" max="100" x-model="entry.discountPercent"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>
                            @endif

                            @if ($vatEnabled)
                                <x-sales::entry-field label="sales::field.vat">
                                    <input type="text" readonly :value="money(entryVat)"
                                           class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 text-end text-sm">
                                </x-sales::entry-field>
                            @endif

                            <x-sales::entry-field label="sales::field.net_value">
                                <input type="text" readonly :value="money(entryNet)"
                                       class="num h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm font-semibold">
                            </x-sales::entry-field>
                        </div>
                    </div>

                    {{-- মাঝ: এই লাইন --}}
                    <div class="rounded-(--radius-card) bg-(--color-badge-success-bg) p-3">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-badge-success-ink)">
                            {{ __('sales::field.this_line') }}
                        </p>
                        <p class="num mt-1 text-2xl font-bold text-(--color-badge-success-ink)"
                           x-text="'৳' + money(entryNet)"></p>

                        <dl class="mt-2 space-y-0.5 text-2xs">
                            @foreach ([
                                'sales::field.net_value' => 'entryAfterDiscount',
                                'sales::field.vat' => 'entryVat',
                                'sales::field.total_qty' => 'entryTotalQty',
                            ] as $label => $expr)
                                <div class="flex justify-between gap-2">
                                    <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                                    <dd class="num" x-text="money({{ $expr }})"></dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-2 grid grid-cols-2 gap-1">
                            @if ($show['gift'])
                                <button type="button" @click="addGift()"
                                        class="rounded-(--radius-field) border border-(--color-badge-pending-ink)/30
                                               bg-(--color-badge-pending-bg) px-2 py-1 text-2xs font-medium
                                               text-(--color-badge-pending-ink)">
                                    {{ __('sales::field.gift') }}
                                </button>
                            @endif

                            {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
                                 জন্য নয়। তাই আলাদা বোতামের পেছনে: চোখে পড়ে
                                 না, কিন্তু দরকার হলে এক চাপ দূরে। --}}
                            <button type="button" @click="showCosting = ! showCosting"
                                    class="rounded-(--radius-field) border border-(--color-border)
                                           px-2 py-1 text-2xs font-medium">
                                {{ __('sales::field.costing') }}
                            </button>
                        </div>

                        <p x-show="showCosting" x-cloak class="num mt-1 text-2xs text-(--color-ink-muted)"
                           x-text="picked ? money(picked.cost) : ''"></p>

                        <div class="mt-2 grid grid-cols-2 gap-1">
                            <button type="button" @click="addToCart()" :disabled="! picked"
                                    class="rounded-(--radius-field) bg-(--color-success) px-2 py-2 text-2xs
                                           font-semibold text-white disabled:opacity-50">
                                {{ __('sales::action.add_to_cart') }}
                            </button>
                            <button type="button" @click="clearEntry()"
                                    class="rounded-(--radius-field) bg-(--color-danger) px-2 py-2 text-2xs
                                           font-semibold text-white">
                                {{ __('sales::action.clear_data') }}
                            </button>
                        </div>

                        <div class="mt-2 border-t border-(--color-badge-success-ink)/20 pt-1 text-2xs">
                            <div class="flex justify-between">
                                <span class="text-(--color-ink-muted)">{{ __('sales::field.in_cart') }}</span>
                                <span class="num" x-text="lines.length + ' ' + @js(__('sales::field.items'))"></span>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <span>{{ __('sales::field.running_total') }}</span>
                                <span class="num" x-text="'৳' + money(subTotal)"></span>
                            </div>
                        </div>
                    </div>

                    {{-- ডান: পণ্যের ছবির জায়গা --}}
                    <div class="hidden items-center justify-center rounded-(--radius-card)
                                border border-(--color-border) p-3 lg:flex">
                        <div class="text-center">
                            <svg viewBox="0 0 24 24" aria-hidden="true"
                                 class="mx-auto size-12 fill-(--color-ink-muted)/40">
                                <path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.2 6.6 3.3L12 10.8 5.4 7.5 12 4.2ZM5 9.3l6 3v7.4l-6-3V9.3Zm8 10.4v-7.4l6-3v7.4l-6 3Z"/>
                            </svg>
                            <p class="mt-1 text-2xs text-(--color-ink-muted)"
                               x-text="picked ? picked.name : @js(__('sales::message.pick_an_item'))"></p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ── কার্ট ────────────────────────────────────────────── --}}
            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('sales::field.sl') }}</th>
                                <th class="text-start">{{ __('sales::field.item_name') }}</th>
                                <th class="text-end">{{ __('sales::field.unit_price') }}</th>
                                <th class="text-end">{{ __('sales::field.quantity') }}</th>
                                @if ($show['free_qty'])
                                    <th class="text-end">{{ __('sales::field.free_unit') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.total_qty') }}</th>
                                @if ($show['line_discount'])
                                    <th class="text-end">{{ __('sales::field.dis') }}</th>
                                @endif
                                @if ($vatEnabled)
                                    <th class="text-end">{{ __('sales::field.vat') }}</th>
                                @endif
                                <th class="text-end">{{ __('sales::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(line, i) in lines" :key="line.key">
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell" x-text="i + 1"></td>

                                    <td class="cell" data-label="{{ __('sales::field.item_name') }}">
                                        <span x-text="line.name"></span>
                                        <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.unit_price') }}">
                                        <input type="number" step="0.0001" min="0" x-model="line.rate"
                                               :name="`lines[${i}][rate]`"
                                               class="num h-(--spacing-field-dense) w-full sm:w-24 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    <td class="cell-input text-end" data-label="{{ __('sales::field.quantity') }}">
                                        <input type="number" step="0.01" min="0.01" x-model="line.qty"
                                               :name="`lines[${i}][qty]`"
                                               class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                    </td>

                                    @if ($show['free_qty'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.free_unit') }}">
                                            <input type="number" step="0.01" min="0" x-model="line.freeQty"
                                                   :name="`lines[${i}][free_qty]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    <td class="num cell" data-label="{{ __('sales::field.total_qty') }}"
                                        x-text="qty(Number(line.qty || 0) + Number(line.freeQty || 0))"></td>

                                    @if ($show['line_discount'])
                                        <td class="cell-input text-end" data-label="{{ __('sales::field.dis') }}">
                                            <input type="number" step="0.01" min="0" max="100"
                                                   x-model="line.discountPercent"
                                                   :name="`lines[${i}][discount_percent]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>
                                    @endif

                                    @if ($vatEnabled)
                                        <td class="num cell" data-label="{{ __('sales::field.vat') }}"
                                            x-text="money(lineVat(line))"></td>
                                    @endif

                                    <td class="num cell font-medium" data-label="{{ __('sales::field.amount') }}"
                                        x-text="money(lineNet(line))"></td>

                                    <td class="cell-input text-end">
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
                   class="p-8 text-center text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.nothing_added') }}
                </p>
            </section>

            {{-- ── উপহার ────────────────────────────────────────────── --}}
            @if ($show['gift'])
                <section x-show="gifts.length > 0" x-cloak
                         class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card)">
                    <h2 class="flex items-center justify-between border-b border-(--color-border) px-3 py-2">
                        <span class="text-2xs font-semibold uppercase tracking-wide">
                            {{ __('sales::field.gift_item') }}
                        </span>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('sales::message.not_for_sales') }}</span>
                    </h2>

                    <div class="table-responsive">
                        <table class="ui-lines table-cards w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ __('sales::field.sl') }}</th>
                                    <th class="text-start">{{ __('sales::field.gift_for') }}</th>
                                    <th class="text-start">{{ __('sales::field.item_name') }}</th>
                                    <th class="text-end">{{ __('sales::field.quantity') }}</th>
                                    <th class="text-start">{{ __('sales::field.remarks') }}</th>
                                    <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(gift, i) in gifts" :key="gift.key">
                                    <tr class="border-b border-(--color-border)">
                                        <td class="cell" x-text="i + 1"></td>

                                        <td class="cell-input" data-label="{{ __('sales::field.gift_for') }}">
                                            <select x-model="gift.againstProductId"
                                                    :name="`gifts[${i}][against_product_id]`"
                                                    class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                           border-(--color-border) bg-(--color-surface-app) px-2">
                                                <option value="">-</option>
                                                <template x-for="line in lines" :key="line.key">
                                                    <option :value="line.id" x-text="line.name"></option>
                                                </template>
                                            </select>
                                        </td>

                                        <td class="cell-input" data-label="{{ __('sales::field.item_name') }}">
                                            {{-- পণ্যতালিকা এখানে সার্ভার থেকে আবার আঁকা হয় না।

                                                 উপরে ওই একই তালিকা JSON হিসেবে চলে গেছে
                                                 (directSale-এর catalogue), তাই দ্বিতীয়বার
                                                 <option> হিসেবে পাঠানো মানে একই ডেটা দুইবার।
                                                 ছয়টা পণ্যে সেটা চোখে পড়ে না, কিন্তু দুই
                                                 হাজার পণ্যের গুদামে এটাই পাতাটাকে ভারী করে
                                                 তুলত — আর কাউন্টারের পাতা দিনে কয়েকশো বার
                                                 খোলা হয়। --}}
                                            <select x-model="gift.productId" :name="`gifts[${i}][product_id]`"
                                                    class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                           border-(--color-border) bg-(--color-surface-app) px-2">
                                                <option value="">-</option>
                                                <template x-for="p in catalogue" :key="p.id">
                                                    <option :value="p.id" x-text="p.name"></option>
                                                </template>
                                            </select>
                                        </td>

                                        <td class="cell-input text-end" data-label="{{ __('sales::field.quantity') }}">
                                            <input type="number" step="0.01" min="0" x-model="gift.qty"
                                                   :name="`gifts[${i}][qty]`"
                                                   class="num h-(--spacing-field-dense) w-full sm:w-20 rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                                        </td>

                                        <td class="cell-input" data-label="{{ __('sales::field.remarks') }}">
                                            <input type="text" x-model="gift.remarks" :name="`gifts[${i}][remarks]`"
                                                   class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border
                                                          border-(--color-border) bg-(--color-surface-app) px-2">
                                        </td>

                                        <td class="cell-input text-end">
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
                </section>
            @endif
        </div>

        {{-- ══ ডান পাশের প্যানেল ═══════════════════════════════════════ --}}
        <aside class="flex flex-col self-start rounded-(--radius-card) border
                      border-(--color-border) bg-(--color-surface-card)
                      xl:sticky xl:top-3 xl:max-h-[calc(100dvh-5.5rem)]">

            <div class="min-h-0 flex-1 overflow-y-auto">

            {{-- ক্রেতা --}}
            <div class="border-b border-(--color-border) p-3">
                <label class="relative block">
                    <span class="sr-only">{{ __('sales::message.search_customer') }}</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"
                         class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2
                                fill-(--color-brand-500)">
                        <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                    </svg>
                    <select name="customer_id" x-model="customerId"
                            class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) ps-8 pe-2 text-sm">
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected($customer->id === $walkinId)>
                                {{ $customer->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                @if ($show['credit_limit'])
                    <p class="mt-1 flex justify-between text-2xs">
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.credit_limit') }}</span>
                        <span class="num" x-text="customer.limit > 0 ? money(customer.limit) : '—'"></span>
                    </p>
                @endif
            </div>

            {{-- এই চালান --}}
            <x-sales::panel-heading>{{ __('sales::field.this_challan') }}</x-sales::panel-heading>

            <div class="space-y-1 p-3 text-2xs">
                <x-sales::panel-row :label="__('sales::field.invoice_total')" strong>
                    <span class="num" x-text="'৳' + money(grossTotal)"></span>
                </x-sales::panel-row>

                @if ($show['sub_total'])
                    <x-sales::panel-row :label="__('sales::field.sub_total_no_vat')">
                        <span class="num" x-text="'৳' + money(subTotal)"></span>
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.discount_amount')">
                    <input type="number" step="0.01" min="0" name="discount_amount" x-model="discountAmount"
                           placeholder="{{ __('sales::field.amount_or_pct') }}"
                           class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-1 text-end">
                </x-sales::panel-row>

                @if ($vatEnabled)
                    <x-sales::panel-row :label="__('sales::field.vat')">
                        <span class="num" x-text="'৳' + money(vatTotal)"></span>
                    </x-sales::panel-row>
                @endif

                @if ($show['expense'])
                    <x-sales::panel-row :label="__('sales::field.expense')">
                        <input type="number" step="0.01" min="0" name="expense_amount" x-model="expenseAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif

                @if ($show['rounding'])
                    <x-sales::panel-row :label="__('sales::field.rounding')">
                        <input type="number" step="0.01" min="0" name="rounding_amount" x-model="roundingAmount"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif
            </div>

            {{-- দিতে হবে --}}
            <x-sales::panel-heading tone="success">
                {{ __('sales::field.to_pay_on_this') }}
            </x-sales::panel-heading>

            <div class="space-y-1 bg-(--color-badge-success-bg)/40 p-3 text-2xs">
                <x-sales::panel-row :label="__('sales::field.net_payable')" strong>
                    <span class="num text-sm" x-text="'৳' + money(netPayable)"></span>
                </x-sales::panel-row>

                @if ($show['deposit'])
                    <x-sales::panel-row :label="__('sales::field.received_deposit')">
                        <input type="number" step="0.01" min="0" name="deposit" x-model="deposit"
                               class="num h-(--spacing-inline) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-1 text-end">
                    </x-sales::panel-row>
                @endif

                <x-sales::panel-row :label="__('sales::field.invoice_due')" strong>
                    <span class="num" x-text="'৳' + money(invoiceDue)"></span>
                </x-sales::panel-row>
            </div>

            {{-- পার্টির বকেয়া --}}
            <x-sales::panel-heading tone="pending">
                {{ __('sales::field.what_party_owes') }}
            </x-sales::panel-heading>

            <div class="space-y-1 bg-(--color-badge-pending-bg)/40 p-3 text-2xs">
                <x-sales::panel-row :label="__('sales::field.previous_balance')">
                    <span class="num" x-text="customer.due > 0 ? money(customer.due) : '—'"></span>
                </x-sales::panel-row>

                <x-sales::panel-row :label="__('sales::field.outstanding')" strong>
                    <span class="num" x-text="outstanding > 0 ? money(outstanding) : '—'"></span>
                </x-sales::panel-row>
            </div>

            {{-- গোনা --}}
            <x-sales::panel-heading>{{ __('sales::field.quantities') }}</x-sales::panel-heading>

            <div class="space-y-1 p-3 text-2xs">
                @foreach ([
                    ['label' => 'sales::field.total_item', 'expr' => 'counts.totalItem', 'on' => 'total_item'],
                    ['label' => 'sales::field.total_sales_qty', 'expr' => 'counts.totalSalesQty', 'on' => 'sales_qty'],
                    ['label' => 'sales::field.total_free_qty', 'expr' => 'counts.totalFreeQty', 'on' => 'free_qty_total'],
                    ['label' => 'sales::field.total_free_plus_sales', 'expr' => 'counts.totalQty', 'on' => 'total_qty'],
                ] as $row)
                    @if ($show[$row['on']])
                        <x-sales::panel-row :label="__($row['label'])">
                            <span class="num" x-text="{{ $row['expr'] }} || '—'"></span>
                        </x-sales::panel-row>
                    @endif
                @endforeach
            </div>

            </div>

            {{-- নমুনার ছয়টা বোতাম।

                 ── কেন এগুলো এখানে, যদিও সব কাজ এখনো তৈরি হয়নি ──────────
                 জায়গাটা ধরে রাখা: কাজগুলো এলে কোথায় বসবে তা আগেই ঠিক থাকে,
                 আর ব্যবহারকারী দুই পণ্যের মাঝে গিয়ে একই বিন্যাস পান।

                 তবে চুপচাপ কিছু-না-করা নয়। যে বোতাম চাপা যায় অথচ কিছুই হয়
                 না, সেটাই সবচেয়ে খারাপ স্টাব — মানুষ ভাবে সিস্টেম নষ্ট।
                 তাই চাপলে পরিষ্কার করে বলা হয় জিনিসটা আসছে।

                 খরচ ও জমা দুইটা সত্যিই কাজ করে — ওগুলোর ঘর এই প্যানেলেই
                 আছে, তাই বোতামটা সেখানেই নিয়ে যায়। --}}
            <div class="grid grid-cols-2 gap-1 border-t border-(--color-border) p-3">
                @foreach (array_filter([
                    ['key' => 'chart_bulk_do', 'action' => null, 'show' => true],
                    ['key' => 'expense', 'action' => 'expense_amount', 'show' => $show['expense']],
                    ['key' => 'transportation', 'action' => null, 'show' => true],
                    ['key' => 'shipment', 'action' => null, 'show' => true],
                    ['key' => 'add_deposit', 'action' => 'deposit', 'show' => $show['deposit']],
                    ['key' => 'add_note', 'action' => null, 'show' => true],
                ], fn (array $b) => $b['show']) as $button)
                    <button type="button"
                            @if ($button['action'])
                                @click="focusField('{{ $button['action'] }}')"
                            @else
                                @click="upcoming = @js(__('sales::action.'.$button['key']))"
                            @endif
                            @class([
                                'rounded-(--radius-field) border border-(--color-border) px-2 py-1.5 text-2xs',
                                'transition-colors hover:bg-(--color-surface-hover)',
                                'text-(--color-ink-muted)' => $button['action'] === null,
                            ])>
                        {{ __('sales::action.'.$button['key']) }}
                    </button>
                @endforeach
            </div>

            <p x-show="upcoming" x-cloak
               class="mx-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-2 py-1 text-2xs
                      text-(--color-badge-pending-ink)">
                <span x-text="upcoming"></span> — {{ __('sales::message.upcoming') }}
            </p>

            {{-- বোতাম --}}
            <div class="space-y-2 p-3">
                <x-ui.button type="submit" tone="primary" class="w-full"
                             ::disabled="lines.length === 0">
                    {{ __('sales::action.confirm') }}
                </x-ui.button>

                <button type="button" @click="clearAll()"
                        class="w-full rounded-(--radius-field) bg-(--color-danger)/10 px-3 py-2 text-2xs
                               font-medium text-(--color-danger)">
                    {{ __('sales::action.clear_full') }}
                </button>

                <a href="{{ route('sales.challan.index') }}"
                   class="block text-center text-2xs text-(--color-ink-muted) hover:underline">
                    ← {{ __('core.action.cancel') }}
                </a>
            </div>
        </aside>
    </form>

    @push('scripts')
        <script>
            function directSale(catalogue, customers, walkinId, vatEnabled) {
                return {
                    catalogue,
                    customers,
                    vatEnabled,
                    term: '',
                    picked: null,
                    showCosting: false,
                    entry: { qty: '', freeQty: '', rate: '', discountPercent: '' },
                    lines: [],
                    gifts: [],
                    customerId: String(walkinId || ''),
                    creditDays: '',
                    discountAmount: '',
                    expenseAmount: '',
                    roundingAmount: '',
                    deposit: '',
                    upcoming: '',
                    nextKey: 1,

                    get visible() {
                        const t = this.term.trim().toLowerCase();
                        if (t === '') return [];

                        return this.catalogue.filter(p =>
                            p.name.toLowerCase().includes(t)
                            || p.code.toLowerCase().includes(t)
                            || (p.barcode || '').toLowerCase().includes(t)
                        ).slice(0, 30);
                    },

                    get customer() {
                        return this.customers[this.customerId] || { limit: 0, due: 0, days: 0 };
                    },

                    pick(product) {
                        this.picked = product;
                        this.entry.rate = product.rate;
                        this.entry.qty = this.entry.qty || '1';
                        this.term = '';
                    },

                    pickFirst() {
                        const first = this.visible[0];
                        if (first) this.pick(first);
                    },

                    // ── চলতি লাইনের অঙ্ক ────────────────────────────────
                    get entryBase() {
                        return (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
                    },

                    get entryAfterDiscount() {
                        return this.entryBase
                            - this.entryBase * (Number(this.entry.discountPercent) || 0) / 100;
                    },

                    get entryVat() {
                        if (! this.vatEnabled || ! this.picked) return 0;
                        return this.entryAfterDiscount * Number(this.picked.vatRate || 0) / 100;
                    },

                    get entryNet() {
                        return this.entryAfterDiscount + this.entryVat;
                    },

                    /* বিক্রয় + ফ্রি — গুদাম থেকে মোট যতটা বেরোবে।
                       নমুনায় ঘরটা নিজে থেকেই ভরে, হাতে লেখা যায় না। */
                    get entryTotalQty() {
                        return (Number(this.entry.qty) || 0) + (Number(this.entry.freeQty) || 0);
                    },

                    addToCart() {
                        if (! this.picked) return;

                        this.lines.push({
                            key: this.nextKey++,
                            id: this.picked.id,
                            name: this.picked.name,
                            unit: this.picked.unit,
                            vatRate: this.picked.vatRate || 0,
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
                        this.entry = { qty: '', freeQty: '', rate: '', discountPercent: '' };
                        this.term = '';
                        this.showCosting = false;
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
                            againstProductId: this.picked ? String(this.picked.id) : '',
                            qty: '',
                            remarks: @js(__('sales::message.not_for_sales')),
                        });
                    },

                    // ── কার্টের অঙ্ক ────────────────────────────────────
                    lineBase(line) {
                        return (Number(line.qty) || 0) * (Number(line.rate) || 0);
                    },

                    lineAfterDiscount(line) {
                        return this.lineBase(line)
                            - this.lineBase(line) * (Number(line.discountPercent) || 0) / 100;
                    },

                    lineVat(line) {
                        if (! this.vatEnabled) return 0;
                        return this.lineAfterDiscount(line) * Number(line.vatRate || 0) / 100;
                    },

                    lineNet(line) {
                        return this.lineAfterDiscount(line) + this.lineVat(line);
                    },

                    get subTotal() {
                        return this.lines.reduce((s, l) => s + this.lineAfterDiscount(l), 0);
                    },

                    get vatTotal() {
                        return this.lines.reduce((s, l) => s + this.lineVat(l), 0);
                    },

                    get grossTotal() {
                        return this.subTotal + this.vatTotal;
                    },

                    get netPayable() {
                        return this.grossTotal
                            - (Number(this.discountAmount) || 0)
                            + (Number(this.expenseAmount) || 0)
                            + (Number(this.roundingAmount) || 0);
                    },

                    get invoiceDue() {
                        const due = this.netPayable - (Number(this.deposit) || 0);
                        return due > 0 ? due : 0;
                    },

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

                    /* যে বোতামের কাজটা এই পাতাতেই আছে, সেটা ওই ঘরে নিয়ে
                       যায় — নতুন কোনো পপ-আপ নয়। খরচ বসাতে গিয়ে একটা জানালা
                       খুলে আবার বন্ধ করা কাউন্টারে দুইটা বাড়তি চাপ। */
                    /* $root, $el নয়: বোতাম থেকে ডাকা হলে $el হয় ওই
                       বোতামটাই, আর বোতামের ভেতরে ঘরটা থাকে না — তখন
                       কিছুই ফোকাস হত না, নীরবে। জাবেদা ও নগদ গণনার
                       পর্দায় এই একই ভুল দুইটা ফিচার মেরে রেখেছিল। */
                    focusField(name) {
                        this.upcoming = '';
                        const el = this.$root.querySelector(`[name="${name}"]`);
                        if (el) { el.focus(); el.select?.(); }
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
