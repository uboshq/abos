{{--
    কাউন্টার — এক পর্দা, এক হাত।

    ── কেন এটা অন্য পর্দাগুলোর মতো নয় ──────────────────────────────────
    বাকি সব পর্দা ভরা হয় বসে, সময় নিয়ে। এটা ভরা হয় দাঁড়িয়ে, পেছনে লাইন
    নিয়ে। তাই এখানকার একমাত্র মাপকাঠি: একটা বিক্রি শেষ করতে কতগুলো চাপ
    লাগে।

    খোঁজার ঘরটা নিজে থেকেই ফোকাস নেয় এবং বিক্রির পরেও ফিরে আসে — বারকোড
    স্ক্যানার আসলে একটা কীবোর্ড, সে যেখানে ফোকাস সেখানেই লিখে Enter চাপে।
    ফোকাস অন্য কোথাও থাকলে স্ক্যান করা বারকোডটা কোনো একটা টাকার ঘরে ঢুকে
    যেত, আর সেটা কেউ খেয়াল না করলে বিলটাই ভুল হত।

    ── কেন গ্রাহক বাছা বাধ্যতামূলক নয় ─────────────────────────────────
    নগদ বিক্রিতে গ্রাহকের নাম কেউ জিজ্ঞেস করে না। সেটিংসে বসানো "নগদ
    গ্রাহক" নিজেই বসে যায়; যিনি নাম দিতে চান কেবল তার বেলায় বাছতে হয়।
    আলাদা POS-গ্রাহক তালিকা নেই — একই মাস্টার, নাহলে একই দোকানের হিসাব
    দুই জায়গায় ভাগ হয়ে যেত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.pos') }}</x-slot:title>

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

    <div x-data="pos({{ Illuminate\Support\Js::from($products) }}, {{ $walkinId }})"
         @keydown.window.escape="term = ''"
         class="grid gap-4 lg:grid-cols-[1fr_22rem]">

        {{-- ── বাঁ দিক: খোঁজা ও পণ্য ───────────────────────────────── --}}
        <section class="space-y-3">
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <label class="block">
                    <span class="sr-only">{{ __('sales::message.pos_search') }}</span>
                    <input type="search"
                           x-model="term"
                           x-ref="search"
                           x-init="$nextTick(() => $refs.search.focus())"
                           @keydown.enter.prevent="takeFirst()"
                           placeholder="{{ __('sales::message.pos_search') }}"
                           class="h-12 w-full rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-4 text-base">
                </label>

                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.pos_hint') }}
                </p>
            </div>

            {{-- পণ্যের কার্ড — আঙুলে চাপার মতো বড়, আর বিক্রয়যোগ্য সংখ্যা
                 প্রতিটায় লেখা, যাতে নেই এমন মাল বেচার আগেই দেখা যায় --}}
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="p in visible" :key="p.id">
                    <button type="button"
                            @click="add(p)"
                            class="rounded-(--radius-card) border border-(--color-border)
                                   bg-(--color-surface-card) p-3 text-start transition-colors
                                   hover:bg-(--color-surface-hover)">
                        <div class="text-sm font-medium" x-text="p.name"></div>
                        <div class="mt-0.5 text-2xs text-(--color-ink-muted)" x-text="p.code"></div>
                        <div class="mt-1 flex items-baseline justify-between">
                            <span class="num text-base font-semibold" x-text="money(p.rate)"></span>
                            <span class="text-2xs"
                                  :class="Number(p.available) > 0
                                      ? 'text-(--color-ink-muted)'
                                      : 'text-(--color-danger)'">
                                {{ __('sales::field.available_short') }}
                                <span class="num" x-text="qty(p.available)"></span>
                                <span x-text="p.unit"></span>
                            </span>
                        </div>
                    </button>
                </template>
            </div>

            <p x-show="visible.length === 0" x-cloak class="p-6 text-center text-sm text-(--color-ink-muted)">
                {{ __('core.empty.no_results') }}
            </p>
        </section>

        {{-- ── ডান দিক: ঝুড়ি ও টাকা ──────────────────────────────── --}}
        <form method="POST" action="{{ route('sales.pos.checkout') }}"
              class="lg:sticky lg:top-4 lg:h-fit">
            @csrf

            <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">

            <div class="space-y-3 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-3">

                <x-ui.select name="customer_id" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="$walkinId ?: null" />

                {{-- ঝুড়ি --}}
                <div class="max-h-[45vh] overflow-y-auto">
                    <template x-for="(line, i) in lines" :key="line.id">
                        <div class="border-b border-(--color-border) py-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm" x-text="line.name"></div>
                                    <div class="num text-2xs text-(--color-ink-muted)"
                                         x-text="money(line.rate) + ' × ' + qty(line.qty)"></div>
                                </div>

                                <span class="num text-sm font-medium" x-text="money(lineTotal(line))"></span>

                                <button type="button" @click="remove(i)"
                                        :aria-label="'{{ __('sales::action.remove_line') }}'"
                                        class="rounded-(--radius-field) px-1.5 text-(--color-ink-muted)
                                               hover:bg-(--color-surface-hover)">&times;</button>
                            </div>

                            <div class="mt-1 flex items-center gap-1">
                                <button type="button" @click="line.qty = Math.max(1, Number(line.qty) - 1)"
                                        :aria-label="'−'"
                                        class="size-8 rounded-(--radius-field) border border-(--color-border)">−</button>

                                <input type="number" step="0.01" min="0.01"
                                       x-model="line.qty"
                                       :name="`lines[${i}][qty]`"
                                       class="num h-8 w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">

                                <button type="button" @click="line.qty = Number(line.qty) + 1"
                                        :aria-label="'+'"
                                        class="size-8 rounded-(--radius-field) border border-(--color-border)">+</button>

                                <input type="number" step="0.01" min="0"
                                       x-model="line.rate"
                                       :name="`lines[${i}][rate]`"
                                       class="num ms-auto h-8 w-24 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">

                                <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
                            </div>
                        </div>
                    </template>

                    <p x-show="lines.length === 0" x-cloak
                       class="py-8 text-center text-sm text-(--color-ink-muted)">
                        {{ __('sales::message.pos_empty_cart') }}
                    </p>
                </div>

                {{-- মোট --}}
                <div class="border-t border-(--color-border) pt-2">
                    <div class="flex items-baseline justify-between">
                        <span class="font-semibold">{{ __('sales::field.total') }}</span>
                        <span class="num text-2xl font-bold" x-text="money(total)"></span>
                    </div>
                </div>

                {{-- টাকা --}}
                <div>
                    <label class="mb-1 block text-sm font-medium" for="paid">
                        {{ __('sales::field.paid') }}
                    </label>
                    <input id="paid" name="paid" type="number" step="0.01" min="0"
                           x-model="paid"
                           class="num h-11 w-full rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-3 text-end text-lg">

                    {{-- দ্রুত টাকার বোতাম — কাউন্টারে সবচেয়ে বেশি যা দেওয়া হয় --}}
                    <div class="mt-2 flex flex-wrap gap-1">
                        <button type="button" @click="paid = total.toFixed(2)"
                                class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs">
                            {{ __('sales::action.exact') }}
                        </button>
                        <template x-for="note in [100, 500, 1000]" :key="note">
                            <button type="button" @click="paid = String(note)"
                                    class="num rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs"
                                    x-text="note"></button>
                        </template>
                    </div>

                    <div class="mt-2 flex items-baseline justify-between text-sm"
                         x-show="Number(paid) > total" x-cloak>
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.change') }}</span>
                        <span class="num font-semibold" x-text="money(Number(paid) - total)"></span>
                    </div>
                </div>

                <x-ui.button type="submit" tone="primary" class="w-full"
                             ::disabled="lines.length === 0">
                    {{ __('sales::action.checkout') }}
                </x-ui.button>

                <p class="text-center text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.pos_today') }}:
                    <span class="num">{{ number_format((float) $todaysTotal, 2) }}</span>
                </p>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function pos(catalogue, walkinId) {
                return {
                    catalogue,
                    walkinId,
                    term: '',
                    lines: [],
                    paid: '',

                    get visible() {
                        const t = this.term.trim().toLowerCase();

                        // খালি ঘরে সব পণ্য — কাউন্টারে বেশিরভাগ সময় লোকে
                        // খোঁজে না, চোখে দেখে বেছে নেয়
                        if (t === '') return this.catalogue.slice(0, 60);

                        return this.catalogue.filter(p =>
                            p.name.toLowerCase().includes(t)
                            || p.code.toLowerCase().includes(t)
                            || (p.barcode || '').toLowerCase().includes(t)
                        ).slice(0, 60);
                    },

                    /*
                     * Enter চাপলে প্রথমটা ঝুড়িতে।
                     *
                     * বারকোড স্ক্যানার শেষে Enter পাঠায়, আর বারকোডে
                     * সাধারণত একটাই পণ্য মেলে — তাই স্ক্যান করলেই সরাসরি
                     * ঝুড়িতে চলে যায়, কোনো ক্লিক ছাড়াই।
                     */
                    takeFirst() {
                        const first = this.visible[0];
                        if (first) this.add(first);
                    },

                    add(product) {
                        const existing = this.lines.find(l => l.id === product.id);

                        // একই পণ্য দ্বিতীয়বার স্ক্যান করলে নতুন সারি নয়,
                        // পরিমাণ এক বাড়ে — রসিদে একই জিনিস দুই সারিতে
                        // দেখলে গ্রাহক ভাবেন দুইবার ধরা হয়েছে
                        if (existing) {
                            existing.qty = Number(existing.qty) + 1;
                        } else {
                            this.lines.push({
                                id: product.id,
                                name: product.name,
                                rate: product.rate,
                                qty: 1,
                            });
                        }

                        this.term = '';
                        this.$nextTick(() => this.$refs.search.focus());
                    },

                    remove(index) {
                        this.lines.splice(index, 1);
                    },

                    lineTotal(line) {
                        return (Number(line.qty) || 0) * (Number(line.rate) || 0);
                    },

                    get total() {
                        return this.lines.reduce((sum, l) => sum + this.lineTotal(l), 0);
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
