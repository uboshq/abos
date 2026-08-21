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
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
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
                                {{ $bill->lines }} · <span class="num">{{ \App\Core\Support\Money::format($bill->total) }}</span>
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
    <div x-data="pos({{ Illuminate\Support\Js::from($products) }}, {{ $walkinId }}, {{ Illuminate\Support\Js::from($resumed) }}, {{ Illuminate\Support\Js::from($discountOn) }}, {{ Illuminate\Support\Js::from($methods) }})"
         {{--
             কি-বোর্ডের সারি — কাউন্টারে হাত মাউসে যায় না।

             ── কেন এতগুলো ─────────────────────────────────────────────
             ব্যস্ত কাউন্টারে প্রতিটা মাউস-নাগাল এক-দুই সেকেন্ড, আর দিনে
             তিনশো বিক্রয়ে সেটা ঘণ্টার হিসাব। যিনি রোজ চালান তিনি
             পর্দাটার দিকে তাকানও না — আঙুল জানে কোথায় কী।

             নম্বরগুলো কাউন্টারে চেনা ছকেই: F2 টাকা, F4 ধরে রাখা,
             F8 খোঁজা — এই তিনটা আগেই ছিল, তাই বদলানো হয়নি। কারও
             অভ্যাস ভাঙা মানে তাঁকে আবার পর্দার দিকে তাকাতে বাধ্য করা।

             Esc দুই ধাপে: আগে খোঁজার লেখা, তারপর খোলা প্যানেল। একবারে
             সব বন্ধ করলে ভুল করে চাপলে ফেরতের টাইপ করা সবটা যেত।
         --}}
         @keydown.window.escape="term ? (term = '') : closePanels()"
         @keydown.window.f1.prevent="helping = !helping"
         @keydown.window.f2.prevent="$refs.paid?.focus()"
         @keydown.window.f3.prevent="openReturn()"
         @keydown.window.f4.prevent="$refs.hold?.click()"
         @keydown.window.f6.prevent="startSplit()"
         @keydown.window.f7.prevent="document.querySelector('[name=customer_id]')?.focus()"
         @keydown.window.f8.prevent="$refs.search?.focus()"
         @keydown.window.f9.prevent="lines.length && (paid = total.toFixed(2))"
         @keydown.window.f10.prevent="lines.length && $refs.checkout?.click()"
         class="grid gap-4 lg:grid-cols-[1fr_22rem]">

        {{-- ── বাঁ দিক: খোঁজা ও পণ্য ───────────────────────────────── --}}
        <section class="space-y-3">
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <label class="block">
                    <span class="sr-only">{{ __('sales::message.pos_search') }}</span>
                    <input type="search"
                           x-model="term"
                           x-ref="search"
                           x-init="$nextTick(() => $refs.search.focus())"
                           @keydown.enter.prevent="takeFirst()"
                           placeholder="{{ __('sales::message.pos_search') }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
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

            {{--
                তোলা বিলটার নম্বর — "সম্পূর্ণ" চাপলে ওটাই শেষ হবে।

                না পাঠালে কাউন্টার নতুন একটা খসড়া বানাত, আর তোলা
                বিলটা মরা কাগজ হয়ে খাতায় পড়ে থাকত — নম্বরসহ।
            --}}
            @if ($resumedId)
                <input type="hidden" name="resumed_invoice_id" value="{{ $resumedId }}">
            @endif

            <div data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border)
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
                                       class="num h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 text-end text-sm">

                                <button type="button" @click="line.qty = Number(line.qty) + 1"
                                        :aria-label="'+'"
                                        class="size-8 rounded-(--radius-field) border border-(--color-border)">+</button>

                                <input type="number" step="0.01" min="0"
                                       x-model="line.rate"
                                       :name="`lines[${i}][rate]`"
                                       class="num ms-auto h-(--spacing-field-dense) w-24 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">

                                <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.id">
                            </div>

                            {{--
                                লাইনে ছাড় — কাউন্টারে যেভাবে দেওয়া হয়।

                                ── কেন বিলের গায়ে নয়, লাইনে ─────────────────
                                ক্যাশিয়ার বলেন "এই আইটেমে ৫০ টাকা কম", পুরো
                                বিলে নয়। বিলের গায়ে বসালে সেটা লাইনগুলোয়
                                ভাগ করতে হত, আর তখন কোন পণ্যে কত ছাড় গেল তা
                                হারিয়ে যেত — মুনাফার রিপোর্টে ওই সংখ্যাটাই
                                লাগে।

                                ── অনুমোদন আপনাআপনি ─────────────────────────
                                ছাড়ের সীমা কোম্পানির অনুমোদনের ছকে; বিল
                                নিশ্চিত করার সময় `SalesInvoiceService` নিজেই
                                অনুরোধ পাঠায়। এখানে আলাদা কিছু করতে হয় না,
                                আর সেটাই ঠিক — দুই জায়গায় দুইটা সীমা থাকলে
                                একদিন তারা আলাদা হত।
                            --}}
                            <div x-show="discountOn" x-cloak class="mt-1 flex items-center gap-1">
                                <label class="text-2xs text-(--color-ink-muted)"
                                       :for="`discount-${i}`">{{ __('sales::field.discount') }}</label>

                                <input type="number" step="0.01" min="0" :id="`discount-${i}`"
                                       x-model="line.discount"
                                       :max="lineBase(line)"
                                       :name="`lines[${i}][discount]`"
                                       class="num ms-auto h-(--spacing-field-dense) w-24 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
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
                           class="num h-(--spacing-counter) w-full rounded-(--radius-field) border border-(--color-border)
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

                    {{--
                        ভাগ করে দেওয়া — ২,০০০ বিকাশে, বাকিটা নগদে।

                        ── কেন এটা লাগে ─────────────────────────────────
                        বাংলাদেশে রোজকার ঘটনা, কারণ বিকাশের ব্যালেন্স গোল
                        অঙ্কে থাকে। এক উপায়ে বাধ্য করলে ক্যাশিয়ার পুরোটা
                        "নগদ" লিখে দিতেন, আর দিনশেষে ড্রয়ারে ২,০০০ কম
                        পড়ত — ঠিক সেই মিথ্যা ঘাটতি, যেটা সারাতেই উপায়ের
                        তালিকাটা বানানো হয়েছিল।

                        ── কেন লুকানো থাকে ──────────────────────────────
                        বেশিরভাগ বিক্রয় এক উপায়েই। ঘরগুলো সবসময় খোলা
                        রাখলে প্রতিটা নগদ বিক্রয়ে দুইটা বাড়তি ট্যাব চাপতে
                        হত, আর কাউন্টারে ওইটুকুই গতি নষ্ট করার জন্য যথেষ্ট।
                    --}}
                    @if ($methods->isNotEmpty())
                        <button type="button" x-show="!splitting" x-cloak @click="startSplit()"
                                class="mt-2 w-full rounded-(--radius-field) border border-(--color-border)
                                       py-1.5 text-2xs text-(--color-ink-muted)
                                       transition-colors hover:bg-(--color-surface-hover)">
                            {{ __('sales::action.split_payment') }}
                        </button>

                        <div x-show="splitting" x-cloak class="mt-2 space-y-1">
                            <template x-for="(part, p) in payments" :key="p">
                                <div class="flex items-center gap-1">
                                    <select :name="`payments[${p}][payment_method_id]`"
                                            x-model="part.method_id"
                                            class="h-(--spacing-field-compact) min-w-0 flex-1 rounded-(--radius-field) border
                                                   border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                        <option value="">{{ __('sales::field.cash') }}</option>
                                        @foreach ($methods as $method)
                                            <option value="{{ $method->id }}">{{ $method->name() }}</option>
                                        @endforeach
                                    </select>

                                    <input type="number" step="0.01" min="0"
                                           x-model="part.amount"
                                           :name="`payments[${p}][amount]`"
                                           class="num h-(--spacing-field-compact) w-24 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-app)
                                                  px-2 text-end text-sm">

                                    {{-- নম্বর কেবল যে উপায়ে লাগে — নগদে TrxID নেই --}}
                                    <input type="text" x-show="needsReference(part)" x-cloak
                                           x-model="part.reference"
                                           :name="`payments[${p}][reference]`"
                                           :placeholder="'{{ __('sales::field.instrument_no') }}'"
                                           class="h-(--spacing-field-compact) w-28 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-app) px-2 text-sm">

                                    <button type="button" @click="payments.splice(p, 1)"
                                            x-show="payments.length > 1"
                                            :aria-label="'{{ __('sales::action.remove_line') }}'"
                                            class="rounded-(--radius-field) px-1.5
                                                   text-(--color-ink-muted)">&times;</button>
                                </div>
                            </template>

                            <div class="flex items-center justify-between gap-2">
                                <button type="button" @click="payments.push({method_id: '', amount: '', reference: ''})"
                                        class="rounded-(--radius-field) border border-(--color-border)
                                               px-2 py-1 text-2xs">
                                    {{ __('sales::action.add_payment_row') }}
                                </button>

                                {{-- ভাগগুলোর যোগফল — না মিললে সেবাটাই আটকায়,
                                     কিন্তু ততক্ষণে ক্রেতা দাঁড়িয়ে আছেন --}}
                                <span class="num text-2xs"
                                      :class="Math.abs(splitTotal - Number(paid || 0)) < 0.005
                                              ? 'text-(--color-ink-muted)'
                                              : 'text-(--color-danger)'"
                                      x-text="money(splitTotal)"></span>
                            </div>
                        </div>
                    @endif

                    <div class="mt-2 flex items-baseline justify-between text-sm"
                         x-show="Number(paid) > total" x-cloak>
                        <span class="text-(--color-ink-muted)">{{ __('sales::field.change') }}</span>
                        <span class="num font-semibold" x-text="money(Number(paid) - total)"></span>
                    </div>
                </div>

                {{--
                    ছাড়ের অনুমোদন — ম্যানেজার কাউন্টারে দাঁড়িয়েই দেন।

                    ── কেন নিজের লগইন, ভাগ করা PIN নয় ──────────────────
                    ভাগ করা PIN দুইদিনেই সবাই জেনে যায়। তখন অডিটে
                    ম্যানেজারের নাম বসে অথচ কাজটা কর্মীর — আর যে
                    জিনিসটার জন্য পুরো অনুমোদন ব্যবস্থাটা, সেটাই তখন
                    মিথ্যা সাক্ষী হয়ে দাঁড়ায়।

                    ঘরটা কেবল তখনই আসে যখন সত্যিই একটা ছাড় সিদ্ধান্তের
                    অপেক্ষায়। রোজ চোখের সামনে থাকলে ক্যাশিয়ার একদিন
                    ম্যানেজারের পাসওয়ার্ডটা মুখস্থ করে ফেলতেন।
                --}}
                @if ($awaitingApproval)
                    <div class="space-y-2 rounded-(--radius-field) border
                                border-(--color-badge-warning-ink)/30
                                bg-(--color-badge-warning-bg) p-3">
                        <p class="text-2xs text-(--color-badge-warning-ink)">
                            {{ __('sales::message.pos_needs_approval') }}
                        </p>

                        <input type="email" name="approver_email" autocomplete="off"
                               placeholder="{{ __('sales::field.approver_email') }}"
                               value="{{ old('approver_email') }}"
                               class="h-(--spacing-counter-sm) w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-3 text-sm">

                        {{--
                            পাসওয়ার্ডে `old()` নেই — ইচ্ছাকৃত।

                            ব্যর্থ হলে পুরনো ইনপুট ফেরত আসে, আর এখানে
                            লিখলে ম্যানেজারের পাসওয়ার্ড পর্দায় বসানো
                            থাকত পরের ক্যাশিয়ারের জন্যও। সার্ভারেও
                            ঘরটা ফ্ল্যাশ হয় না (bootstrap/app.php)।
                        --}}
                        <input type="password" name="approver_password" autocomplete="new-password"
                               placeholder="{{ __('sales::field.approver_password') }}"
                               class="h-(--spacing-counter-sm) w-full rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-3 text-sm">
                    </div>
                @endif

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
                    <span class="num">{{ \App\Core\Support\Money::format($todaysTotal) }}</span>
                </p>
            </div>
        </form>

        {{--
            কি-বোর্ডের তালিকা — F1।

            দশটা শর্টকাট থাকা আর না থাকা সমান, যদি কেউ না জানে কোনটা কী
            করে। নতুন ক্যাশিয়ার প্রথম দিনে একবার দেখেন, তারপর আর লাগে না।
        --}}
        <div x-show="helping" x-cloak @click.self="helping = false"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-sm rounded-(--radius-card) bg-(--color-surface-card) p-4 shadow-lg">
                <h2 class="mb-3 font-medium">{{ __('sales::message.pos_keys') }}</h2>

                <dl class="space-y-1 text-sm">
                    @foreach ([
                        'F1' => 'sales::message.key_help',
                        'F2' => 'sales::message.key_paid',
                        'F3' => 'sales::message.key_return',
                        'F4' => 'sales::message.key_hold',
                        'F6' => 'sales::message.key_split',
                        'F7' => 'sales::message.key_customer',
                        'F8' => 'sales::message.key_search',
                        'F9' => 'sales::message.key_exact',
                        'F10' => 'sales::message.key_checkout',
                        'Esc' => 'sales::message.key_close',
                    ] as $key => $label)
                        <div class="flex justify-between gap-4">
                            <dt class="num font-medium">{{ $key }}</dt>
                            <dd class="text-(--color-ink-muted)">{{ __($label) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        {{--
            কাউন্টার থেকেই ফেরত — F3।

            ── কেন এখানে, অফিসের পর্দায় নয় ─────────────────────────────
            ক্রেতা দোকানে দাঁড়িয়ে আছেন, হাতে বিল আর মাল। অন্য পর্দায়
            যেতে বললে ক্যাশিয়ার হয় লাইন থামান, নয় কাগজে টুকে রাখেন —
            আর ওই কাগজটা রাতে হারায়। মালটা তখন গুদামে ফেরে না, খাতায়
            বিক্রয়ই থেকে যায়।
        --}}
        <div x-show="returning" x-cloak @click.self="returning = false"
             class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form method="POST" action="{{ route('sales.pos.return') }}"
                  class="mt-8 w-full max-w-lg rounded-(--radius-card) bg-(--color-surface-card) p-4 shadow-lg">
                @csrf
                <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">

                <h2 class="mb-3 font-medium">{{ __('sales::message.pos_return') }}</h2>

                <div class="flex gap-2">
                    <input type="text" x-model="billNo" name="document_no"
                           x-ref="billNo"
                           @keydown.enter.prevent="findBill()"
                           :placeholder="'{{ __('sales::field.inv_number') }}'"
                           class="h-(--spacing-counter-sm) min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-3 text-sm">

                    <button type="button" @click="findBill()"
                            class="rounded-(--radius-field) border border-(--color-border) px-3 text-sm">
                        {{ __('core.action.search') }}
                    </button>
                </div>

                <p x-show="billError" x-cloak class="mt-2 text-2xs text-(--color-danger)" x-text="billError"></p>

                <template x-if="bill">
                    <div class="mt-3">
                        <p class="text-2xs text-(--color-ink-muted)">
                            <span x-text="bill.customer"></span> · <span class="num" x-text="bill.date"></span>
                        </p>

                        <div class="mt-2 space-y-1">
                            <template x-for="(line, i) in bill.lines" :key="line.id">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="min-w-0 flex-1 truncate" x-text="line.name"></span>

                                    {{-- আর কতটুকু ফেরত নেওয়া যায় — দ্বিতীয়বার
                                         ফেরতের সময় এটা না জানালে ক্যাশিয়ার
                                         পুরোটা টাইপ করতেন আর সেবাটা আটকাত --}}
                                    <span class="num text-2xs text-(--color-ink-muted)"
                                          x-text="`${roomOn(line)} / ${line.qty}`"></span>

                                    <input type="number" step="0.01" min="0" :max="roomOn(line)"
                                           x-model="line.take"
                                           :name="`lines[${i}][qty]`"
                                           class="num h-(--spacing-field-compact) w-20 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-app)
                                                  px-2 text-end text-sm">

                                    <input type="hidden" :name="`lines[${i}][product_id]`" :value="line.product_id">
                                    <input type="hidden" :name="`lines[${i}][sales_invoice_line_id]`" :value="line.id">
                                </div>
                            </template>
                        </div>

                        {{-- টাকা ফেরত ঐচ্ছিক: বাকিতে কেনা মাল ফেরত এলে
                             টাকা যায় না, কেবল পাওনা কমে --}}
                        <label class="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="refund" value="1" x-model="refunding">
                            {{ __('sales::message.pos_refund_cash') }}
                        </label>

                        <div class="mt-3 flex gap-2">
                            <button type="submit"
                                    class="min-h-(--spacing-touch) flex-1 rounded-(--radius-field)
                                           bg-(--color-brand-600) text-sm font-medium text-white">
                                {{ __('sales::action.take_back') }}
                            </button>

                            <button type="button" @click="returning = false"
                                    class="min-h-(--spacing-touch) rounded-(--radius-field) border
                                           border-(--color-border) px-4 text-sm">
                                {{ __('core.action.cancel') }}
                            </button>
                        </div>
                    </div>
                </template>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            function pos(catalogue, walkinId, resumed, discountOn, methods) {
                return {
                    catalogue,
                    walkinId,
                    discountOn,
                    methods,

                    /*
                     * ভাগ করে পরিশোধ — শুরুতে বন্ধ।
                     *
                     * বেশিরভাগ বিক্রয় এক উপায়েই; ঘরগুলো খোলা রাখলে
                     * প্রতিটা নগদ বিক্রয়ে বাড়তি ট্যাব চাপতে হত।
                     */
                    splitting: false,
                    payments: [],

                    /*
                     * কি-বোর্ডের সাহায্য — F1।
                     *
                     * ── কেন এটা ছাড়া বাকি কি-গুলোর মানে নেই ─────────
                     * দশটা শর্টকাট থাকা আর না থাকা সমান, যদি কেউ না
                     * জানে কোনটা কী করে। নতুন ক্যাশিয়ার প্রথম দিনেই
                     * F1 চেপে তালিকাটা দেখতে পান, আর তারপর আর লাগে না।
                     */
                    helping: false,

                    closePanels() {
                        this.helping = false;
                        this.returning = false;
                    },

                    // ── কাউন্টার থেকেই ফেরত ──────────────────────────
                    returning: false,
                    billNo: '',
                    bill: null,
                    billError: '',
                    refunding: true,

                    openReturn() {
                        this.returning = true;
                        this.$nextTick(() => this.$refs.billNo?.focus());
                    },

                    /*
                     * নম্বর ধরে বিলটা আনা।
                     *
                     * নেট গেলে কাউন্টার থামে না: ব্যর্থ হলে কেবল একটা
                     * বার্তা দেখায়, ঝুড়ি ও বিক্রয় আগের মতোই চলে।
                     */
                    async findBill() {
                        this.bill = null;
                        this.billError = '';

                        const no = String(this.billNo || '').trim();

                        if (no === '') {
                            return;
                        }

                        try {
                            const response = await fetch(
                                `{{ route('sales.pos.bill') }}?no=${encodeURIComponent(no)}`,
                                {headers: {Accept: 'application/json'}},
                            );

                            if (!response.ok) {
                                this.billError = '{{ __('sales::message.pos_bill_not_found') }}';

                                return;
                            }

                            const bill = await response.json();

                            // প্রতিটা সারিতে "কতটুকু ফেরত নিচ্ছি" — শুরুতে
                            // খালি, কারণ বেশিরভাগ ফেরতে এক-দুইটা পণ্যই আসে
                            bill.lines.forEach((l) => { l.take = ''; });

                            this.bill = bill;
                        } catch (e) {
                            this.billError = '{{ __('sales::message.pos_bill_not_found') }}';
                        }
                    },

                    /** এই লাইনে আর কতটুকু ফেরত নেওয়া যায়। */
                    roomOn(line) {
                        return Math.max(0, Number(line.qty) - Number(line.returned || 0));
                    },

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
                        discount: l.discount || '',
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

                                // ছাড় শুরুতে খালি — শূন্য লিখলে ঘরটায়
                                // "0" বসে থাকত আর টাইপ করতে আগে মুছতে হত
                                discount: '',

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

                    /*
                     * ভাগ করে দেওয়া শুরু — প্রথম সারিতে পুরো টাকাটাই।
                     *
                     * খালি সারি দিয়ে শুরু করলে ক্যাশিয়ারকে দুইবার টাইপ
                     * করতে হত: একবার মোট, একবার প্রথম ভাগ। পুরোটা বসিয়ে
                     * দিলে তিনি কেবল দ্বিতীয় সারিতে যতটা সরাতে চান
                     * ততটুকুই লেখেন।
                     */
                    startSplit() {
                        this.splitting = true;

                        if (this.payments.length === 0) {
                            this.payments = [
                                {method_id: '', amount: (this.paid || this.total.toFixed(2)), reference: ''},
                                {method_id: '', amount: '', reference: ''},
                            ];
                        }
                    },

                    get splitTotal() {
                        return this.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                    },

                    needsReference(part) {
                        const found = this.methods.find((m) => String(m.id) === String(part.method_id));

                        return Boolean(found && found.needs_reference);
                    },

                    lineBase(line) {
                        return (Number(line.qty) || 0) * (Number(line.rate) || 0);
                    },

                    /*
                     * ছাড় বাদ দিয়ে লাইনের টাকা।
                     *
                     * ছাড় লাইনের চেয়ে বেশি হতে পারে না — সেবাটাও সেটা
                     * আটকায়, কিন্তু পর্দায় ঋণাত্মক সংখ্যা দেখানো মানে
                     * ক্রেতাকে ভুল মোট দেখানো, আর সেটা সংশোধনের আগেই
                     * বলা হয়ে যায়।
                     */
                    lineTotal(line) {
                        const base = this.lineBase(line);
                        const off = Math.min(Number(line.discount) || 0, base);

                        return base - off;
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
