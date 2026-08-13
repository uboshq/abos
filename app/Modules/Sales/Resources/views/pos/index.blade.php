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

    {{--
        কাউন্টারে অপেক্ষমাণ বিল — দুই কলামের উপরে, দুইটা ফর্মের বাইরে।

        ভেতরে রাখা যেত না: প্রতিটা সারিতে "তুলুন" বোতামের নিজের ফর্ম
        লাগে, আর ফর্মের ভেতরে ফর্ম HTML-এ চলে না — ব্রাউজার ভেতরেরটা
        ফেলে দেয়, তাই বোতামটা নীরবে কিছুই করত না।

        কিছু ঝুলে না থাকলে পট্টিটাই আসে না; খালি একটা বাক্স কাউন্টারের
        জায়গা নেয় আর কিছু বলে না।
    --}}
    @if ($parked->isNotEmpty())
        <div class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-3">
            <h2 class="mb-2 text-sm font-semibold">{{ __('sales::message.pos_parked_bills') }}</h2>

            <div class="flex flex-wrap gap-2">
                @foreach ($parked as $bill)
                    <form method="POST" action="{{ route('sales.pos.resume', ['invoice' => $bill->id]) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-(--radius-field) border border-(--color-border)
                                       px-3 py-2 text-start text-xs transition-colors
                                       hover:bg-(--color-surface-hover)">
                            <span class="block font-medium">{{ $bill->no }}</span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ $bill->lines }} · <span class="num">{{ number_format((float) $bill->total, 2) }}</span>
                                · {{ $bill->since }}
                            </span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    {{--
        ── পুরো বিক্রয় মাউস না ছুঁয়ে ────────────────────────────────────
        প্ল্যানের শেষ হওয়ার শর্তগুলোর একটা। কাউন্টারে হাত দুইটাই ব্যস্ত
        থাকে — একটায় স্ক্যানার, একটায় মাল — তাই মাউস ধরতে গেলে প্রতিটা
        বিক্রয়ে দুই সেকেন্ড যায়, আর লাইনে দশজন থাকলে সেটাই ভিড়।

        স্ক্যানার নিজে Enter পাঠায়, তাই Enter খোঁজার ঘরেই আটকে থাকে
        (প্রথম পণ্যটা ঝুড়িতে যায়)। বাকি কাজগুলো F-কি-তে, কারণ ওগুলোয়
        বারকোডের কোনো অক্ষর নেই — অক্ষরের কি ব্যবহার করলে স্ক্যান করা
        কোডের মাঝখানে একটা শর্টকাট চলে যেত।

            F2   টাকার ঘরে যাও
            F4   বিল ধরে রাখো
            F8   খোঁজার ঘরে ফেরো
            Esc  খোঁজা মুছে ফেলো
            টাকার ঘরে Enter  → বিক্রয় সম্পূর্ণ

        preventDefault দরকার: F2 ব্রাউজারের নিজের কাজ নয়, কিন্তু কিছু
        ব্রাউজারে F4/F8 ঠিকানার বার বা ডিবাগারে যায়।
    --}}
    <div x-data="pos({{ Illuminate\Support\Js::from($products) }}, {{ $walkinId }}, {{ Illuminate\Support\Js::from($resumed) }})"
         @keydown.window.escape="term = ''"
         @keydown.window.f2.prevent="$refs.paid?.focus()"
         @keydown.window.f4.prevent="$refs.hold?.click()"
         @keydown.window.f8.prevent="$refs.search?.focus()"
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

                {{-- কি-বোর্ডের মানচিত্র — মাউস না ছোঁয়ার শর্তটা তখনই
                     কাজে লাগে যখন কি-গুলো কোথাও লেখা থাকে --}}
                <p class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.pos_keys') }}
                </p>

                {{-- খুঁজে পাওয়া যায়নি — নীরবতা নয় --}}
                <p x-show="notFound" x-cloak
                   class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-1
                          text-2xs text-(--color-badge-danger-ink)">
                    {{ __('sales::message.pos_not_found') }}
                    <span class="num" x-text="notFound"></span>
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

                                    {{-- স্ক্যানে পাওয়া লট ও মেয়াদ — মিলিয়ে
                                         দেখার জন্য। স্ক্যান না হলে কিছুই আসে
                                         না, তাই ডিপোর সারি আগের মতোই। --}}
                                    <div x-show="line.batch || line.expiry" x-cloak
                                         class="text-2xs text-(--color-ink-muted)">
                                        <span x-text="line.batch"></span>
                                        <span x-show="line.batch && line.expiry"> · </span>
                                        <span class="num" x-text="line.expiry"></span>
                                    </div>
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
                    {{--
                        টাকার ঘরে Enter মানে বিক্রয় সম্পূর্ণ।

                        ফর্মে একাধিক ঘর থাকলে ব্রাউজার Enter-এ প্রথম
                        submit বোতামটা চাপে — এখানে সেটাই "সম্পূর্ণ",
                        তাই আচরণটা এমনিতেই ঠিক। তবু স্পষ্ট করে লেখা,
                        কারণ পরে কেউ "ধরে রাখুন" বোতামটা উপরে সরালে
                        Enter নীরবে বিল ধরে রাখা শুরু করত।
                    --}}
                    <input id="paid" name="paid" type="number" step="0.01" min="0"
                           x-model="paid"
                           x-ref="paid"
                           @keydown.enter.prevent="lines.length > 0 && $el.form.requestSubmit($refs.checkout)"
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
                             ::disabled="lines.length === 0"
                             x-ref="checkout">
                    {{ __('sales::action.checkout') }}
                </x-ui.button>

                {{--
                    ধরে রাখা — একই ফর্ম, শুধু গন্তব্য আলাদা (formaction)।

                    আলাদা ফর্ম বানালে ঝুড়ির প্রতিটা সারি দুইবার লিখতে হত,
                    আর একদিন একটা ঘর একটায় যোগ হয়ে অন্যটায় বাদ পড়ত।
                    JavaScript-এও করা যেত, কিন্তু formaction HTML-এরই
                    জিনিস — স্ক্রিপ্ট না চললেও কাজ করে।
                --}}
                <button type="submit"
                        formaction="{{ route('sales.pos.park') }}"
                        x-ref="hold"
                        x-show="lines.length > 0" x-cloak
                        class="min-h-(--spacing-touch) w-full rounded-(--radius-field)
                               border border-(--color-border) text-sm
                               transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('sales::message.pos_park') }}
                </button>

                <p class="text-center text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.pos_today') }}:
                    <span class="num">{{ number_format((float) $todaysTotal, 2) }}</span>
                </p>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function pos(catalogue, walkinId, resumed) {
                return {
                    catalogue,
                    walkinId,

                    /*
                     * তোলা বিলের সারিগুলো নিয়েই পর্দা খোলে।
                     *
                     * সার্ভার সারিগুলো পাঠায় লাইনের চেহারায় (product_id),
                     * আর কার্ট চেনে id নামে — তাই এখানেই বদলে নেওয়া হয়।
                     * না বদলালে সারিগুলো দেখা যেত, কিন্তু একই পণ্য আবার
                     * চাপলে নতুন সারি হত আর রসিদে জিনিসটা দুইবার থাকত।
                     */
                    lines: (resumed || []).map(l => ({
                        id: l.product_id,
                        name: l.name,
                        rate: l.rate,
                        qty: Number(l.qty),
                    })),

                    term: '',
                    paid: '',

                    /*
                     * শেষ যে কোডটা খুঁজে পাওয়া যায়নি।
                     *
                     * নীরবে কিছু না করলে ক্যাশিয়ার দ্বিতীয়বার, তৃতীয়বার
                     * স্ক্যান করতেন আর ভাবতেন স্ক্যানারটা নষ্ট। কোডটা
                     * দেখিয়ে দিলে তিনি অন্তত জানেন যন্ত্র পড়েছে,
                     * ব্যবস্থা চেনেনি।
                     */
                    notFound: '',

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

                        if (first) {
                            this.add(first);
                            return;
                        }

                        /*
                         * পাতার তালিকায় নেই — সার্ভারকে জিজ্ঞেস করা হয়।
                         *
                         * ── ওষুধের কার্টনে এটাই একমাত্র পথ ─────────────
                         * GS1 DataMatrix স্ক্যান করলে স্ক্যানার পাঠায় গোটা
                         * element string (পণ্য + লট + মেয়াদ একসাথে)। ওই
                         * লেখাটা পাতার তালিকার নাম/কোড/বারকোডের কোনোটার
                         * সাথেই মেলে না, তাই স্থানীয় খোঁজা সবসময় খালি
                         * ফেরে — আর ক্যাশিয়ার দেখেন "পণ্য নেই", অথচ
                         * প্যাকেটটা তাঁর হাতেই।
                         *
                         * সার্ভারের lookup বারকোডটা ভেঙে GTIN বের করে,
                         * আর সাথে লট ও মেয়াদও ফেরত দেয়।
                         */
                        const scanned = this.term.trim();

                        if (scanned === '') return;

                        this.askServer(scanned);
                    },

                    async askServer(code) {
                        try {
                            const response = await fetch(
                                '{{ route('sales.pos.lookup') }}?code=' + encodeURIComponent(code),
                                { headers: { 'Accept': 'application/json' } },
                            );

                            if (!response.ok) {
                                this.notFound = code;
                                return;
                            }

                            const product = await response.json();

                            this.add({
                                id: product.id,
                                name: product.name,
                                rate: product.rate,
                                batch: product.scanned_batch,
                                expiry: product.scanned_expiry,
                            });
                        } catch (e) {
                            /*
                             * নেট গেলে কাউন্টার থামে না।
                             *
                             * খোঁজাটা ব্যর্থ হলে কেবল "পাওয়া গেল না"
                             * দেখায়; ঝুড়ি, মোট আর ছাপা সবই আগের মতো
                             * চলে। ব্যতিক্রম ছড়াতে দিলে Alpine-এর পুরো
                             * অংশটা থেমে যেত।
                             */
                            this.notFound = code;
                        }
                    },

                    add(product) {
                        this.notFound = '';

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

                                /*
                                 * স্ক্যানে পাওয়া লট ও মেয়াদ — কেবল
                                 * দেখানোর জন্য, কার্টে পাঠানোর জন্য নয়।
                                 *
                                 * কোন লট বেরোবে সেটা FEFO ঠিক করে মাল
                                 * বেরোনোর মুহূর্তে। এখানে সংখ্যাগুলো
                                 * থাকে যাতে ক্যাশিয়ার হাতের প্যাকেটের
                                 * সাথে মিলিয়ে নিতে পারেন — বিশেষত
                                 * মেয়াদটা, যেটা ছোট ছাপায় পড়া কঠিন।
                                 */
                                batch: product.batch ?? null,
                                expiry: product.expiry ?? null,
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
