{{--
    মজুদ — চারটা অবস্থা এক টেবিলে।

    এই একটা পাতাই ব্যবহারকারীর আসল প্রশ্নের উত্তর: "কী কত আছে, আর তার
    কতটা বেচা যাবে"। চারটা আলাদা পাতায় ভাগ করলে তিনটা খুলে মনে মনে
    বিয়োগ করতে হত, আর সেটাই ভুলের জায়গা।

    গুদামের ফিল্টার আছে, কারণ "কত আছে" প্রশ্নের উত্তর গুদামভেদে আলাদা —
    নেত্রকোনার মাল ময়মনসিংহে বেচা যায় না।
--}}
@php
    // সংখ্যাগুলো কোয়েরি থেকেই আসে (floor_total ইত্যাদি); এখানে শুধু
    // বিয়োগটা, আর সেটা bcmath-এ — টাকার মতো পরিমাণেও ভাসমান সংখ্যা নয়
    // ⓘ বিক্রয়যোগ্য কোয়েরি থেকেই, একটাই সূত্রে — মেয়াদ পেরোনো লট বাদ (StockService::availableSql(), মজুদ M27)
    $available = fn ($p) => (string) $p->available_total;

    /*
     * ⭐ ফ্রি মালের বিক্রয়যোগ্য অংশ — ১৮ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ `hold` বাদ যায় না, আর সেটা ইচ্ছাকৃত: আটকানো মাল **কেনা
     * মালের** খোপে থাকে, ফ্রি-র নয়। ⓘ দুইবার বিয়োগ করলে একই মাল
     * দুই জায়গায় কমত, আর সংখ্যাটা বাস্তবের চেয়ে কম দেখাত।
     */
    $freeAvailable = fn ($p) => bcsub(
        (string) $p->free_total,
        (string) $p->free_reserved_total,
        4,
    );

    /*
     * ⭐ দামের দুইটা ঘর — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ হাতে থাকা মাল কোনগুলো, আর কেন ─────────────────────────────
     * উপরের হিসাব দুইটাই বলে দেয় খোপগুলো কীভাবে বাসা বাঁধে:
     * `available = floor − reserved − hold`, অর্থাৎ **`floor`-এর ভিতরেই
     * `reserved` আর `hold` আছে**; আর `free_available = free −
     * free_reserved`, অর্থাৎ `free`-র ভিতরে `free_reserved`।
     *
     * ⛔ তাই যোগ করা হয় কেবল চারটা: `floor + unplaced + free +
     * unplaced_free`। ⚠️ সাতটা যোগ করলে একই মাল দুইবার গোনা হত, আর
     * মজুদের মূল্য বাস্তবের চেয়ে বেশি দেখাত।
     * ⓘ এটা কোড পড়ে মাপা, ধরে নেওয়া নয়।
     */
    $onHand = fn ($p) => bcadd(
        bcadd((string) $p->floor_total, (string) $p->unplaced_total, 4),
        bcadd((string) $p->free_total, (string) $p->unplaced_free_total, 4),
        4,
    );

    /*
     * ⓘ গড় ক্রয়মূল্য — খরচের স্তরে যা পড়ে আছে তার মোট মূল্য ÷ পরিমাণ,
     * ঠিক [[StockCountService::averageCost()]]-এর সংজ্ঞাতেই।
     *
     * ⛔ স্তর শূন্য হলে `null` — শূন্য নয়। ⚠️ শূন্য লিখলে পর্দা বলত
     * *"এই মাল বিনামূল্যে এসেছে"*, অথচ সত্যিটা হলো **দাম জানা নেই**,
     * আর ধরে-নেওয়া দরই এই পুরো ইঞ্জিনটার শত্রু।
     */
    /*
     * ⭐ মূল্যের পরিমাণ — কেবল কেনা মাল (গ১৯, Inventory অডিট, ৪ অক্টোবর ২০২৬)।
     *
     * ⛔ সরবরাহকারীর ফ্রি মালের খরচ শূন্য: মাল গ্রহণে খরচের স্তর বসে কেবল কেনা পরিমাণে, ফ্রি যায় আলাদা খোপে স্তর ছাড়া
     * ([[PurchaseReceiptService]])। ⚠️ আগে `$onHand` (ফ্রিসহ) × গড় দর গোনা হত — ১০০টা ৮০ টাকায় আর ২০টা ফ্রি হলে
     * খাতায় ৮,০০০, তালিকায় ৯,৬০০। ⓘ পরিমাণের ঘরগুলো বদলায় না — ফ্রি মাল তাকে আছে, কেবল তার দাম নেই।
     */
    $paidOnHand = fn ($p) => bcadd((string) $p->floor_total, (string) $p->unplaced_total, 4);

    $unitCost = function ($p) {
        $qty = (string) ($p->layer_qty_total ?? '0');

        return bccomp($qty, '0', 4) > 0
            ? bcdiv((string) $p->layer_value_total, $qty, 4)
            : null;
    };
@endphp

{{--
    চারটা সংখ্যাই ক্লিকযোগ্য — নিয়ম ১।

    তাকে ১৬০ দেখে থেমে যাওয়ার কোনো কারণ নেই; কোন চালানে এল সেটাও এক ক্লিক
    দূরে থাকা উচিত। আটকানো সংখ্যাটা আটকানো মালের রিপোর্টে যায়, কারণ ওখানেই
    কারণগুলো আলাদা করে দেখা যায় — ক্ষতিগ্রস্ত কতটা, আর দাম বাড়ার অপেক্ষায়
    কতটা।

    ব্যাখ্যাটা এখানে, :columns অ্যাট্রিবিউটের ভেতরে নয়। ওখানে মন্তব্যে একটা
    উদ্ধৃতিচিহ্ন থাকলেই অ্যাট্রিবিউটটা ওখানেই শেষ হয়ে যায়, আর পুরো কলাম-অ্যারে
    পাতায় কাঁচা লেখা হয়ে ছাপা হয়। এই ভুলটা এই বিল্ডে দুইবার হয়েছে।
--}}

@php
    $columns = [
        /*
         * ⭐ ক্রম · কোড · নাম — আলাদা তিনটা কলাম, ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিকের নিজের লেখা তালিকা: *"SL#, Code, Product Name, Unit,
         * Stock On floor, …"*। ⚠️ আগে কোড আর নাম একটাই কলামে জোড়া ছিল
         * ("PRD-0001 - Cosmos 40gm"), তাই **কোড ধরে সাজানো বা চোখ বুলিয়ে
         * খোঁজা** যেত না — গুদামে মানুষ কোড ধরেই খোঁজেন।
         *
         * ⓘ ক্রমটা পাতা ধরে গোনা নয়, **গোটা তালিকার**: `firstItem()`
         * ধরে শুরু। ⛔ নাহলে দ্বিতীয় পাতাতেও ১ থেকে শুরু হত, আর
         * "৫২ নম্বর সারিটা দেখুন" বলার কোনো উপায় থাকত না।
         */
        [
            'key' => 'sl',
            'label' => __('core.table.serial'),
            'numeric' => true,
            'width' => '4rem',
            'render' => fn ($p, $i) => (string) (($products->firstItem() ?? 1) + $i),
        ],
        [
            'key' => 'code',
            'label' => __('inventory::field.code'),
            'width' => '9rem',
            'render' => fn ($p) => view('inventory::partials.product-link', [
                'product' => $p,
                'show' => 'code',
            ]),
        ],
        [
            'key' => 'name',
            'label' => __('inventory::field.product'),
            'width' => '18rem',
            'render' => fn ($p) => view('inventory::partials.product-link', [
                'product' => $p,
                'show' => 'name',
            ]),
        ],
        [
            'key' => 'unit_id',
            'label' => __('inventory::field.unit'),
            'width' => '6rem',
            'render' => fn ($p) => $p->unit?->name(),
        ],
        [
            'key' => 'floor',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->floor_total,
            'label' => __('inventory::field.floor'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($p) => view('inventory::partials.qty-in-packs', [
                'qty' => $p->floor_total,
                'dashOnZero' => true,
                'quantity' => true,
                'ladder' => $ladders[$p->id] ?? [],
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'reserved',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->reserved_total,
            'label' => __('inventory::field.reserved'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->reserved_total,
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'hold',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->hold_total,
            'label' => __('inventory::field.hold'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->hold_total,
                'dashOnZero' => true,
                'quantity' => true,
                /*
                 * ⛔ এখানে ছিল `'inventory.hold'` — ওটা রিপোর্টের **চাবি**,
                 * ঠিকানার স্লাগ নয়, তাই লিংকটা ৪০৪ দিত (২১ সেপ্টেম্বর
                 * ২০২৬, অডিটে ধরা)। ⓘ স্লাগ `hold`, আর জোড়াটা
                 * [[StockReportController::SLUGS]]-এ।
                 *
                 * ⚠️ মন্তব্যটা PHP-র, ব্লেডের নয়: `{{-- --}}` কেবল
                 * টেমপ্লেটের জায়গায় চলে, PHP এক্সপ্রেশনের ভিতরে নয় —
                 * ওখানে বসালে পুরো পাতাটা parse error দেয়।
                 */
                'href' => route('inventory.report.show', ['slug' => 'hold']),
            ]),
        ],
        [
            'key' => 'available',
            'total' => 'quantity',
            'raw' => $available,
            'label' => __('inventory::field.available'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $available($p),
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'free',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->free_total,
            'label' => __('inventory::field.free'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->free_total,
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'free_available',
            'total' => 'quantity',
            'raw' => $freeAvailable,
            'label' => __('inventory::field.free_available'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $freeAvailable($p),
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'unplaced',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->unplaced_total,
            'label' => __('inventory::field.unplaced'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->unplaced_total,
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.stock.placement'),
            ]),
        ],
        [
            'key' => 'unplaced_free',
            'total' => 'quantity',
            'raw' => fn ($p) => $p->unplaced_free_total,
            'label' => __('inventory::field.unplaced_free'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->unplaced_free_total,
                'dashOnZero' => true,
                'quantity' => true,
                'href' => route('inventory.stock.placement'),
            ]),
        ],
    ];

    /*
     * ⭐ দামের ঘর দুইটা শেষে বসে, আর কেবল চাবি থাকলে ও দেখতে চাইলে।
     *
     * ⛔ `$showCost` কন্ট্রোলারে হিসাব হয় আর অনুমতিকে কখনো ছাড়ায় না;
     * চাবি না থাকলে সংখ্যাগুলো কোয়েরিতেই আসে না, কেবল লুকানো হয় না।
     *
     * ⚠️ মূল্যটা **সারির নিজের পরিমাণ** × গড় ক্রয়মূল্য, স্তরের মোট মূল্য
     * সরাসরি নয়। ⓘ কারণ গুদামের ছাঁকনি চালু থাকলে সারির পরিমাণ ঐ
     * গুদামের, অথচ স্তর কোম্পানির — দুইটা পাশাপাশি বসালে সারিটা নিজের
     * সাথেই মিলত না। ⭐ ছাঁকনি ছাড়া দুইটা এক জায়গায় পড়ে।
     */
    /*
     * ⛔ চাবিটার নাম এই ফাইলেই লেখা, আর সেটা ইচ্ছাকৃত — দুইটা কারণে।
     *
     * ⓘ এক, পাহারা ([[NoSensitiveFieldIsPrintedInTheOpenTest]]) দাবি করে
     * যে ঘরটা যে ফাইল ছাপে, সেই ফাইলেই চাবির নাম থাকবে। ⭐ আর দাবিটা
     * ন্যায্য: কন্ট্রোলারে লুকানো একটা `$showCost` দেখে পাঠক বুঝবেন না
     * ঘরটা আদৌ পাহারা দেওয়া কি না।
     *
     * ⓘ দুই, দুইটা পাহারা দুইটা আলাদা কাজ করে, তাই একটা বাদ দিলেও অন্যটা
     * ধরে: কন্ট্রোলারের শর্তটা সংখ্যাটাকে **কোয়েরিতেই** আসতে দেয় না,
     * আর এখানকার শর্তটা **পাতায়** আসতে দেয় না।
     */
    if ($showCost && request()->user()?->can('inventory.cost.view')) {
        $columns[] = [
            'key' => 'avg_cost',
            'label' => __('inventory::field.purchase_price'),
            'numeric' => true,
            'width' => '9rem',
            /*
             * ⓘ `ui.amount` সরাসরি ডাকা যায় না — ওটা একটা কম্পোনেন্ট, আর
             * ছকের `render` ক্লোজার একটা **ভিউ** চায়। ⭐ মোড়কটা হলো
             * `ui.amount-link`, আর `href` ছাড়া সে সাধারণ অঙ্কই ছাপে।
             *
             * ⛔ দাম জানা না থাকলে `—`, শূন্য নয় — উপরের টীকা দেখুন।
             */
            'render' => fn ($p) => ($c = $unitCost($p)) === null
                ? '—'
                : view('ui.amount-link', [
                    'value' => $c,
                    'href' => route('inventory.product.show', $p),
                ]),
        ];

        $columns[] = [
            'key' => 'stock_value',
            'total' => 'money',
            'raw' => fn ($p) => ($c = $unitCost($p)) === null ? '0' : bcmul($paidOnHand($p), $c, 4),
            'label' => __('inventory::field.stock_value'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($p) => ($c = $unitCost($p)) === null
                ? '—'
                : view('ui.amount-link', [
                    'value' => bcmul($paidOnHand($p), $c, 4),
                    'href' => route('inventory.product.show', $p).'#movements',
                ]),
        ];
    }

    /*
     * এই পাতার যোগ — গোটা তালিকার নয়।
     *
     * ── কেন যোগফলটা দরকার ────────────────────────────────────────────
     * গুদামে মিলিয়ে নেওয়ার সময় প্রশ্নটা হয় "সব মিলিয়ে কত ধরা আছে",
     * আর এতদিন সেটা চোখে গুনতে হত। ছয়টা সারিতে সহজ, ত্রিশটায় নয়।
     *
     * ── কেন পাতা ধরে, মোট নয় ─────────────────────────────────────────
     * `$products` একটা paginator — হাতে যা আছে তা কেবল এই পাতার সারি।
     * গোটা তালিকার যোগ চাইলে আলাদা একটা কোয়েরি লাগত, আর সেটা এখান
     * থেকে করলে প্রতিটা পাতায় একটা বাড়তি গোনা হত। শিরোনামে "এই পাতায়"
     * লেখা থাকে, তাই সংখ্যাটা কী বলছে তা নিয়ে সন্দেহ থাকে না।
     */
    $sum = fn (callable $pick) => \App\Core\Support\Money::format(
        collect($products->items())->reduce(
            fn (string $carry, $p) => bcadd($carry, (string) $pick($p), 4),
            '0',
        ),
    );

    $totals = [
        'floor' => $sum(fn ($p) => $p->floor_total),
        'reserved' => $sum(fn ($p) => $p->reserved_total),
        'hold' => $sum(fn ($p) => $p->hold_total),
        'available' => $sum(fn ($p) => $available($p)),
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.stock') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.stock')" :count="__('inventory::message.stock_math')"
                :columns="$columns" :search-placeholder="__('inventory::message.search_placeholder')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('inventory.stock.adjust')
                    <x-ui.button tone="secondary" :href="route('inventory.stock.adjust')">
                        {{ __('inventory::menu.adjust') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                {{-- ⭐ শূন্য মজুদের পণ্য তালিকায় আসে না — মালিকের নির্দেশ,
                     ২৮ সেপ্টেম্বর ২০২৬। ⓘ ছাঁকনিটা গুদামের ঘরের হুবহু একই
                     চেহারায়, কারণ দুইটা একই কাজ করে আর একই টুলবারে বসে। --}}
                <label class="flex items-center gap-2 text-sm">
                    <span class="sr-only">{{ __('inventory::field.stock_filter') }}</span>
                    <select name="stock"
                            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2 text-sm">
                        <option value="holding" @selected($stock === 'holding')>{{ __('inventory::message.stock_holding') }}</option>
                        <option value="zero" @selected($stock === 'zero')>{{ __('inventory::message.stock_zero') }}</option>
                        <option value="all" @selected($stock === 'all')>{{ __('inventory::message.stock_all') }}</option>
                    </select>
                </label>

                {{-- ⓘ ঘরটা দেখা যায় কেবল চাবি থাকলে। ⛔ চাবি ছাড়া ছাঁকনিটা
                     দেখানো মানে জানিয়ে দেওয়া যে একটা লুকানো কলাম আছে, আর
                     সেটা নিজেই একটা তথ্য। --}}
                @if ($maySeeCost)
                    <label class="flex items-center gap-2 text-sm">
                        <span class="sr-only">{{ __('inventory::field.purchase_price') }}</span>
                        <select name="cost"
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="show" @selected($showCost)>{{ __('inventory::message.cost_show') }}</option>
                            <option value="hide" @selected(! $showCost)>{{ __('inventory::message.cost_hide') }}</option>
                        </select>
                    </label>
                @endif

                <label class="flex items-center gap-2 text-sm">
                    <span class="sr-only">{{ __('inventory::field.warehouse') }}</span>
                    <select name="warehouse_id"
                            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2 text-sm">
                        <option value="">{{ __('inventory::field.warehouse') }}</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected($warehouse?->id === $w->id)>{{ $w->name() }}</option>
                        @endforeach
                    </select>
                </label>
            </x-ui.toolbar>
        </form>

        {{-- ⓘ খালি তালিকার কারণটা এখন তিনটা হতে পারে, আর তিনটার উত্তরও
             আলাদা: খোঁজায় কিছু মেলেনি · কারও গায়ে মাল নেই (তখন ছাঁকনির
             কথা বলে দেওয়া হয়) · গুদামে এখনো কিছুই আসেনি।
             ⚠️ লেখাটা নিচের PHP ব্লকে হিসাব করা, অ্যাট্রিবিউটের ভিতরে নয় —
             একটা `"` অ্যাট্রিবিউট আগেই শেষ করে দেয় আর Blade পুরো ট্যাগটা
             লেখা হিসেবে ছেপে দেয়, কোনো ত্রুটি ছাড়াই।

             ⛔ আর এই মন্তব্যেই দ্বিতীয় ফাঁদটা ধরা পড়েছিল: প্রথম লেখায়
             এখানে ডিরেক্টিভটার নাম উদ্ধৃত করা ছিল। ⓘ Blade মন্তব্যের
             ভিতরেও ওটাকে সত্যিকারের PHP ট্যাগ বানিয়ে ফেলে, আর তাতে নিচের
             আসল ব্লকটা আর চলেই না — পাতাটা ৫০০ দেয়, অথচ `php -l` আর
             `view:cache` দুইটাই সবুজ বলে। ⚠️ তাই মন্তব্যে কোনো Blade
             ডিরেক্টিভের নাম লেখা যাবে না। --}}
        @php
            $emptyLine = match (true) {
                filled($q) => __('core.empty.no_results'),
                $stock === 'holding' => __('inventory::message.none_holding_stock'),
                default => __('inventory::message.none_yet'),
            };
        @endphp

        <x-ui.table
            :empty="$emptyLine"
            :rows="$products"
            :compact="request()->boolean('compact')"
            :columns="$columns"
            :grand="$grand ?? []"
            :view-url="fn ($p) => route('inventory.product.show', $p).'#movements'"
            :totals="isset($grand) ? [] : $totals" />

        <x-ui.pager :rows="$products" />

        {{-- ⭐ যোগফলের পট্টি — সারি · তাকে · বিক্রয়যোগ্য, আর মূল্য কেবল যিনি দর দেখতে পারেন (কলামটা তখনই থাকে)।
             ⓘ টেবিলের সর্বমোটের একই সংখ্যা, গোটা ছাঁকনির; ⛔ আটটা পরিমাণের সবকটা নয় — পট্টি এক লাইনে থাকে। --}}
        @php
            $barTotals = [
                ['label' => __('inventory::field.floor'), 'value' => \App\View\Components\Ui\Table::format((string) ($grand['floor'] ?? '0'), 'quantity')],
                ['label' => __('inventory::field.available'), 'value' => \App\View\Components\Ui\Table::format((string) ($grand['available'] ?? '0'), 'quantity')],
            ];

            if (isset($grand['stock_value']) && collect($columns)->contains('key', 'stock_value')) {
                $barTotals[] = ['label' => __('inventory::field.stock_value'), 'value' => \App\Core\Support\Money::format((string) $grand['stock_value'])];

                // ⓘ চালান হয়েছে, বিল হয়নি — গোটা কোম্পানির দৃশ্যেই আসে; মূল্য আর এটা মিলে খাতার মজুদ খাত (মজুদ ⚠️৪)
                if (isset($grand['not_billed_value'])) {
                    $barTotals[] = ['label' => __('inventory::field.not_billed_value'), 'value' => \App\Core\Support\Money::format((string) $grand['not_billed_value'])];
                }
            }
        @endphp
        <x-ui.list-totals :rows="$products" :totals="$barTotals" />
    </div>
</x-layouts.app>
