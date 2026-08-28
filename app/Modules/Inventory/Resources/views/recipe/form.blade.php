{{--
    রেসিপির ফর্ম — খাবার, ধরন, ফলন, আর উপকরণের সারি।

    ── কেন ফলনের ঘরটা এত জোর দিয়ে ব্যাখ্যা করা ──────────────────────────
    এই ফর্মের সবচেয়ে সহজ ভুলটা হলো ফলন আর পরিমাণ গুলিয়ে ফেলা। "৫০ প্লেটের
    হাঁড়িতে ১০ কেজি চাল" লিখতে গিয়ে কেউ যদি ফলন ১ রেখে চাল ১০ লেখেন, তবে
    প্রতি প্লেটে ১০ কেজি চাল কমবে — এক দিনেই গুদাম শূন্য।

    ভুলটা নীরব: কোথাও লাল দেখায় না, বিল ছাপে, আর স্টক নামতে থাকে। তাই
    ঘরটার নিচে উদাহরণসহ কথাটা লেখা থাকে।

    ── কেন অপচয় আলাদা ঘর ────────────────────────────────────────────────
    এক কেজি আলুর খোসা ছাড়ালে ৮৫০ গ্রাম থাকে। রাঁধুনি জানেন "রান্নায় ৮৫০
    গ্রাম যায়", ক্রেতা জানেন "১ কেজি কিনতে হয়"। দুইটা আলাদা সংখ্যা, আর
    দুইটাই সত্যি — গুদাম থেকে বেরোয় বড়টা।
--}}
@php
    /*
     * সারিগুলোর শুরুর অবস্থা — এখানেই হিসাব করা, `@json()`-এর ভেতরে নয়।
     *
     * প্রথম খসড়ায় গোটা `map(fn ($l) => [...])` টা `@json()`-এর যুক্তি
     * হিসেবে লেখা হয়েছিল, আর Blade ওটার বন্ধনী গুনতে পারেনি — সংকলিত
     * ফাইলে `ParseError: Unclosed '['`, আর পাতাটা ৫০০।
     *
     * ব্লেডের নির্দেশগুলো যুক্তিটা রেগেক্স দিয়ে কাটে, তাই ভেতরে
     * arrow function বা বাসা-বাঁধা বন্ধনী থাকলে ভাঙে। জটিল কিছু
     * `@php`-তে হিসাব করে নাম দিয়ে পাঠানোই নিরাপদ।
     */
    $lines = old('lines', $recipe->exists
        ? $recipe->lines->map(function ($line) {
            return [
                'product_id' => (string) $line->product_id,
                'qty' => (string) $line->qty,
                'waste_pct' => (string) $line->waste_pct,
            ];
        })->values()->all()
        : []);

    $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    // পণ্যের তালিকা — `x-ui.select` যে আকারে চায়: [আইডি => নাম]
    $productOptions = $products->mapWithKeys(function ($product) {
        return [$product->id => $product->name()];
    })->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:header>
        <x-ui.page-header
            :title="$recipe->exists ? $recipe->product?->name() : __('inventory::action.new_recipe')"
            :subtitle="__('inventory::message.recipe_subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ভুলের তালিকা — প্রতিবেশী ফর্মগুলোর মতোই হাতে লেখা।

         প্রথম খসড়ায় `<x-ui.errors />` লেখা হয়েছিল, আর ওরকম কোনো
         কম্পোনেন্ট নেই — পাতাটা ৫০০ দিয়েছিল। এই প্রকল্পে ভুলের ব্লকটা
         প্রতিটা ফর্ম নিজে লেখে, আর সেটাই এখানকার রীতি। --}}
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

    <form method="POST"
          action="{{ $recipe->exists ? route('inventory.recipe.update', $recipe) : route('inventory.recipe.store') }}"
          x-data="recipeForm()">
        @csrf
        @if ($recipe->exists) @method('PUT') @endif

        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.recipe_head') }}</h2>

            {{-- `x-ui.select` ও `x-ui.field` স্লট নেয় না, **তথ্য** নেয়:
                 নাম, লেবেল, বিকল্পের অ্যারে, আর বাছাই করা মান। প্রথম
                 খসড়ায় ভেতরে `<option>` লেখা হয়েছিল আর পাতাটা ৫০০
                 দিয়েছিল ("Undefined variable $label")। --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="product_id"
                             :label="__('inventory::field.dish')"
                             :options="$productOptions"
                             :selected="old('product_id', $recipe->product_id)"
                             placeholder="—"
                             required />

                <x-ui.select name="kind"
                             :label="__('inventory::field.recipe_kind')"
                             :options="$kinds"
                             :selected="old('kind', $recipe->kind)"
                             :hint="__('inventory::field.recipe_kind_hint')"
                             required />

                <x-ui.field name="yield_qty"
                            :label="__('inventory::field.yield')"
                            type="number"
                            numeric
                            :value="old('yield_qty', $recipe->yield_qty ?? '1')"
                            :hint="__('inventory::field.yield_hint')"
                            required />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $recipe->is_active ?? true))
                           class="size-4 rounded-(--radius-field) border-(--color-border)">
                    {{ __('inventory::field.recipe_active_hint') }}
                </label>
            </div>
        </section>

        <section data-boxed
                 class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('inventory::section.recipe_lines') }}</h2>

                <x-ui.button type="button" tone="secondary" x-on:click="addLine()">
                    {{ __('inventory::action.add_ingredient') }}
                </x-ui.button>
            </div>

            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.recipe_lines_note') }}
            </p>

            <div class="overflow-x-auto">
                {{-- `ui-lines` — লাইন-এডিটরের নাম, আর ওটা বাধ্যতামূলক।

                     ছকের নাম না থাকলে থিম তার মাপ-ধার-ঘনত্ব কিছুই বদলাতে
                     পারে না, আর মোবাইলে কার্ড-চেহারাটাও হারায়। প্রথম
                     খসড়ায় নামটা ছিল না, আর
                     [[EveryScreenObeysTheThemeTest]] ঠিকই ধরেছে।

                     ঘরের প্যাডিং এখানে লেখা হয় না — ওটা টোকেন থেকে আসে
                     (`--lines-pad*`), তাই রূপ বদলালে ঘনত্বও বদলায়। --}}
                <table class="ui-lines table-cards w-full text-sm">
                    <thead>
                        <tr class="text-2xs uppercase text-(--color-ink-muted)">
                            <th class="text-start">{{ __('inventory::field.ingredient') }}</th>
                            <th class="text-end">{{ __('inventory::field.qty_used') }}</th>
                            <th class="text-end">{{ __('inventory::field.waste_pct') }}</th>
                            <th class="text-end">{{ __('inventory::field.qty_from_store') }}</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        <template x-for="(line, i) in lines" :key="line.key">
                            <tr>
                                <td>
                                    <select :name="`lines[${i}][product_id]`" x-model="line.product_id" required
                                            class="min-h-(--spacing-field) w-full min-w-[12rem]
                                                   rounded-(--radius-field) border border-(--color-border)
                                                   bg-(--color-surface-app) px-2 text-sm">
                                        <option value="">—</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name() }}</option>
                                        @endforeach
                                    </select>
                                </td>

                                <td>
                                    <input type="number" :name="`lines[${i}][qty]`" x-model="line.qty"
                                           step="0.0001" min="0.0001" required
                                           class="min-h-(--spacing-field) w-28 rounded-(--radius-field)
                                                  border border-(--color-border) bg-(--color-surface-app)
                                                  px-2 text-end text-sm">
                                </td>

                                <td>
                                    <input type="number" :name="`lines[${i}][waste_pct]`" x-model="line.waste_pct"
                                           step="0.01" min="0" max="99.99"
                                           class="min-h-(--spacing-field) w-24 rounded-(--radius-field)
                                                  border border-(--color-border) bg-(--color-surface-app)
                                                  px-2 text-end text-sm">
                                </td>

                                {{-- গুদাম থেকে যতটা সত্যিই বেরোবে।

                                     ── কেন এটা পর্দায় দেখানো হয় ──────────
                                     অপচয়ের অঙ্কটা ভাগ, গুণ নয় — ৮৫০ গ্রাম
                                     ১৫% অপচয়ে দাঁড়ায় ১০০০, ৯৭৭.৫ নয়।
                                     সংখ্যাটা চোখের সামনে থাকলে ভুল বসানো
                                     অপচয় সাথে সাথেই ধরা পড়ে। --}}
                                <td class="num text-end text-(--color-ink-muted)"
                                    x-text="gross(line)"></td>

                                <td class="text-end">
                                    <button type="button" x-on:click="lines.splice(i, 1)"
                                            :aria-label="'{{ __('inventory::action.remove_ingredient') }}'"
                                            class="grid size-8 place-items-center rounded-(--radius-field)
                                                   text-(--color-ink-muted) hover:bg-(--color-surface-hover)
                                                   hover:text-(--color-danger)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
                                            <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        </template>

                        {{-- একটাও উপকরণ না থাকলে সেটা ভুল, আর কথাটা এখানেই
                             বলা — সংরক্ষণ চেপে জানার আগেই। --}}
                        <tr x-show="lines.length === 0">
                            <td colspan="5" class="text-center text-sm text-(--color-danger)">
                                {{ __('inventory::validation.recipe_needs_lines') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button :href="route('inventory.recipe.index')" tone="secondary">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>

    @push('scripts')
        <script>
            function recipeForm() {
                return {
                    lines: {!! $linesJson !!},

                    init() {
                        /* প্রতিটা সারির একটা স্থায়ী চাবি — নাহলে একটা
                           সারি মুছলে Alpine বাকিগুলো নতুন করে আঁকত আর
                           বাছাই করা পণ্যগুলো এক ঘর সরে যেত। */
                        this.lines = this.lines.map((l) => ({ ...l, key: this.nextKey() }));

                        if (this.lines.length === 0) this.addLine();
                    },

                    nextKey() {
                        this._key = (this._key || 0) + 1;
                        return this._key;
                    },

                    addLine() {
                        this.lines.push({ product_id: '', qty: '', waste_pct: '0', key: this.nextKey() });
                    },

                    /* গুদাম থেকে যতটা বেরোবে — ভাগ, গুণ নয়। */
                    gross(line) {
                        const qty = parseFloat(line.qty);
                        const waste = parseFloat(line.waste_pct);

                        if (!isFinite(qty) || qty <= 0) return '—';
                        if (!isFinite(waste) || waste <= 0 || waste >= 100) return qty.toFixed(4);

                        return (qty / ((100 - waste) / 100)).toFixed(4);
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
