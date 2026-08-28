{{--
    লেবেল ছাপা — কোন পণ্যের, কয়টা করে।

    ── কেন এক পাতায় গোটা তালিকা, খোঁজার ঘর নয় ─────────────────────────
    লেবেল ছাপা হয় মাল ঢোকার দিনে, আর তখন হাতে ডেলিভারির কাগজ থাকে —
    মানুষ ওটা ধরে নিচে নামেন আর টিক দেন। প্রতিটার জন্য আলাদা করে খুঁজতে
    বললে দশটা পণ্যে দশবার থামতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::label.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::label.title')"
                          :subtitle="__('inventory::label.subtitle')" />
    </x-slot:header>

    <form method="GET" action="{{ route('inventory.label.print') }}" target="_blank"
          x-data="{ chosen: 0 }"
          class="space-y-4">

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.field name="copies" type="number" min="1" max="200"
                            :label="__('inventory::label.copies')" value="1"
                            :hint="__('inventory::label.copies_hint')" />

                <x-ui.select name="paper" :label="__('core.print.choose_paper')"
                             :options="[
                                 'a4' => __('core.print.paper.a4'),
                                 '80mm' => __('core.print.paper.80_mm'),
                                 '58mm' => __('core.print.paper.58_mm'),
                             ]"
                             selected="a4" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 self-end text-sm">
                    <input type="checkbox" name="price" value="1" class="size-4">
                    {{ __('inventory::label.with_price') }}
                </label>
            </div>

            {{-- ডিলারের গুদামে দাম গ্রাহকভেদে আলাদা, তাই ঘরটা ডিফল্টে
                 বন্ধ — দোকানের তাকের লেবেলে টিক দিলেই চলে। --}}
            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('inventory::label.price_note') }}</p>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-(--color-border) px-4 py-2">
                <h2 class="font-semibold">{{ __('inventory::label.which_products') }}</h2>
                <span class="tabular text-xs text-(--color-ink-muted)"
                      x-text="chosen + '/' + {{ $products->count() }}"></span>
            </div>

            <div class="max-h-96 overflow-y-auto">
                @foreach ($products as $product)
                    <label class="flex min-h-(--spacing-touch) items-center gap-3 border-b border-(--color-border)
                                  px-4 py-2 text-sm last:border-b-0 hover:bg-(--color-surface-hover)">
                        <input type="checkbox" name="products[]" value="{{ $product->id }}" class="size-4"
                               @change="chosen += $event.target.checked ? 1 : -1">

                        <span class="w-32 shrink-0 font-medium">{{ $product->code }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ $product->name() }}</span>

                        {{-- গায়ে ছাপা নম্বরটা দেখানো হয়, কারণ ওটা থাকলে
                             দাগে ওটাই বসে — কোডটা নয়। --}}
                        <span class="w-40 shrink-0 text-end text-xs text-(--color-ink-muted)">
                            {{ $product->barcode ?: __('inventory::label.own_code') }}
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        {{--
            বাছাই চলাকালীন ছাপার বোতামটা সাথে সাথে থাকে।

            ── কেন, ২৯ আগস্ট ২০২৬ ──────────────────────────────────────
            চেহারার পাতার "দুই ধাপ" সারানোর পর গোটা পণ্যে একই রোগ খুঁজে
            দেখা হয়েছিল। ১২৭টা পর্দার মধ্যে দুইটা ধরা পড়ে, আর এটা তার
            একটা: এখানে ৬১টা পণ্যের ঘর, আর বাছতে বাছতে ছাপার বোতামটা
            পর্দা থেকে অনেক দূরে চলে যায়।

            তাই বোতামটা নিচে সাঁটা থাকে, আর সাথে কয়টা বাছা হয়েছে সেটাও
            — নাহলে একশোটা ঘরের মাঝখানে গুনে রাখা যায় না।

            কিছু বাছা না থাকলে পটিটা নেই: ছাপার মতো কিছু না থাকলে
            "ছাপুন" লেখা একটা পটি ভেসে থাকার কোনো কারণ নেই।
        --}}
        <div x-show="chosen > 0" x-cloak
             class="fixed inset-x-0 bottom-(--spacing-bottom-nav) z-40 border-t border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3 shadow-lg md:bottom-0">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3">
                <span class="text-sm">
                    <span class="num font-semibold" x-text="chosen"></span>
                    {{ __('inventory::label.chosen') }}
                </span>

                <span class="flex-1"></span>

                <span class="text-xs text-(--color-ink-muted)">
                    {{ __('inventory::label.opens_in_new_tab') }}
                </span>

                <x-ui.button type="submit" tone="primary" icon="printer">
                    {{ __('core.print.print') }}
                </x-ui.button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 pb-16">
            <x-ui.button type="submit" tone="primary" icon="printer" ::disabled="chosen === 0">
                {{ __('core.print.print') }}
            </x-ui.button>

            <span class="text-xs text-(--color-ink-muted)">{{ __('inventory::label.opens_in_new_tab') }}</span>
        </div>
    </form>
</x-layouts.app>
