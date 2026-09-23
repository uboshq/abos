{{--
    গোনার শিট — গুদামের প্রতিটা পণ্য এক পাতায়।

    ── ⚠️ কেন লাইন যোগ করার বোতাম নেই ───────────────────────────────────
    স্থানান্তরের ফর্মে সারি যোগ করা হয়, কারণ ওখানে প্রশ্নটা *"কী কী
    পাঠাব"*। ⛔ গোনায় প্রশ্নটা উল্টো: *"তাকে কী কী আছে"*, আর তার উত্তর
    দিতে হলে **প্রতিটা পণ্য আগে থেকেই পাতায় থাকতে হয়** — নাহলে যে পণ্যটা
    খাতায় আছে অথচ তাকে নেই, সেটা কেউ কোনোদিন লিখতই না।

    ⓘ তাই সারিগুলো সার্ভার থেকেই আসে, আর কোনো Alpine নেই — একশো সারির
    পাতায় একশোটা প্রতিক্রিয়াশীল ঘর ফোনে ধীর হত।

    ── ⛔ আর সবচেয়ে বিপজ্জনক নিয়মটা এই পাতার নিজের ───────────────────────
    **খালি ঘর ≠ শূন্য।** ⚠️ যে সারিতে কিছু লেখা হয়নি সেটা *"গোনা হয়নি"*,
    আর ওই পণ্যের খাতা অক্ষত থাকে। ⛔ খালিকে শূন্য ধরলে একটা অর্ধেক-ভরা
    শিট সংরক্ষণ করামাত্র বাকি পুরো গুদাম শূন্য হয়ে যেত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::action.new_count') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::action.new_count')"
                          :subtitle="__('inventory::message.count_note')" />
    </x-slot:header>

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

    {{-- ── কোন গুদাম, আর খাতার সংখ্যা দেখা যাবে কি না ──────────────────
         ⓘ এটা আলাদা একটা GET ফর্ম, কারণ গুদাম বদলালে **পুরো শিটটাই**
         নতুন করে আসতে হয় — খাতার সংখ্যাগুলো গুদাম ধরে ধরে আলাদা। --}}
    <form method="GET" data-boxed
          class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border
                 border-(--color-border) bg-(--color-surface-card) p-4">
        <x-ui.select name="warehouse" :label="__('inventory::field.warehouse')"
                     :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                     :selected="$warehouse?->id" />

        {{-- ⭐ অন্ধ গণনা — ⚠️ খাতার সংখ্যা চোখের সামনে থাকলে গণনাকারী
             প্রায়ই ওটাই লিখে দেন, আর তখন গোনাটা কেবল খাতার প্রতিধ্বনি।
             ⓘ ডিফল্টে বন্ধ: ছোট দোকানে একজনই গোনেন ও মেলান। --}}
        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
            <input type="checkbox" name="blind" value="1" @checked($blind) class="size-4">
            {{ __('inventory::action.blind_count') }}
        </label>

        <x-ui.button type="submit" tone="secondary">{{ __('inventory::action.load_sheet') }}</x-ui.button>
    </form>

    @if ($warehouse === null)
        <x-ui.empty-state :message="__('inventory::message.count_needs_warehouse')" />
    @else
        <form method="POST" action="{{ route('inventory.count.store') }}" class="space-y-4">
            @csrf

            <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-ui.field name="count_date" type="date" :label="__('inventory::field.date')"
                                :value="old('count_date', now()->toDateString())" required />

                    <x-ui.field name="narration" :label="__('inventory::field.narration')"
                                :value="old('narration')" />
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ $warehouse->name() }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">
                    {{ __('inventory::message.count_blank_is_not_zero') }}
                </p>

                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('inventory::field.product') }}</th>

                                @unless ($blind)
                                    <th class="text-end">{{ __('inventory::field.book_qty') }}</th>
                                @endunless

                                <th class="text-end">{{ __('inventory::field.counted_qty') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($products as $i => $product)
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell-input" data-label="{{ __('inventory::field.product') }}">
                                        {{ $product->name() }}
                                        <input type="hidden" name="lines[{{ $i }}][product_id]"
                                               value="{{ $product->id }}">
                                    </td>

                                    @unless ($blind)
                                        <td class="cell-input num text-end"
                                            data-label="{{ __('inventory::field.book_qty') }}">
                                            {{ $bookQty[$product->id] ?? '0' }}
                                        </td>
                                    @endunless

                                    <td class="cell-input" data-label="{{ __('inventory::field.counted_qty') }}">
                                        {{-- ⛔ খালি রাখাই "গোনা হয়নি" — তাই কোনো
                                             ডিফল্ট মান বসানো হয় না, শূন্যও নয়। --}}
                                        <input type="number" step="0.01" min="0" inputmode="decimal"
                                               name="lines[{{ $i }}][counted_qty]"
                                               value="{{ old('lines.'.$i.'.counted_qty') }}"
                                               class="num h-(--spacing-field-compact) w-full rounded-(--radius-field)
                                                      border border-(--color-border) bg-(--color-surface-card)
                                                      px-2 text-end sm:w-28">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('inventory.count.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
