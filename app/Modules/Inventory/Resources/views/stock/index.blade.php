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
    $available = fn ($p) => bcsub(
        bcsub((string) $p->floor_total, (string) $p->reserved_total, 4),
        (string) $p->hold_total,
        4,
    );
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
        [
            'key' => 'code',
            'label' => __('inventory::field.product'),
            'width' => '22rem',
            'render' => fn ($p) => view('inventory::partials.product-link', ['product' => $p]),
        ],
        [
            'key' => 'unit_id',
            'label' => __('inventory::field.unit'),
            'width' => '6rem',
            'render' => fn ($p) => $p->unit?->name(),
        ],
        [
            'key' => 'floor',
            'label' => __('inventory::field.floor'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->floor_total,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'reserved',
            'label' => __('inventory::field.reserved'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->reserved_total,
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
        [
            'key' => 'hold',
            'label' => __('inventory::field.hold'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $p->hold_total,
                'href' => route('inventory.report.show', 'inventory.hold'),
            ]),
        ],
        [
            'key' => 'available',
            'label' => __('inventory::field.available'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($p) => view('ui.amount-link', [
                'value' => $available($p),
                'href' => route('inventory.product.show', $p).'#movements',
            ]),
        ],
    ];

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

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.none_yet')"
            :rows="$products"
            :compact="request()->boolean('compact')"
            :columns="$columns"
            :totals="$totals" />

        <x-ui.pager :rows="$products" />
    </div>
</x-layouts.app>
