{{--
    এক নজরে গুদাম।

    ── কেন এই চারটা প্রশ্ন, আর কেন এই ক্রমে ─────────────────────────────
    গুদামের লোকের দিনটা চারটা প্রশ্নে চলে, আর সেগুলোর একটা স্বাভাবিক
    ক্রম আছে:

      ১. কত মাল আছে, আর তার কতটা **সত্যিই বেচা যাবে**
      ২. কী **ফুরিয়ে আসছে** — নাহলে কাল বিক্রি আটকাবে
      ৩. মাসে মাসে কত **ঢুকছে আর বেরোচ্ছে**
      ৪. আজ কী কী **নড়ল**

    উল্টো ক্রমে সাজালে পাতাটা "আজ কী হলো" দিয়ে শুরু হত, অথচ সেটা
    দিনের শেষের প্রশ্ন।

    ── কোনো চার্ট-লাইব্রেরি নেই ─────────────────────────────────────────
    বার আর ডোনাট দুইটাই খাঁটি CSS। একটা লাইব্রেরি টানলে সেটা একটা
    নির্ভরতার সিদ্ধান্ত, আর ওটা মালিকের — দুইটা আকৃতির জন্য নেওয়ার মতো নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::overview.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('inventory::overview.title')"
            :subtitle="$warehouse?->name() ?? __('inventory::overview.all_warehouses')">
            <x-slot:actions>
                <x-ui.button :href="route('inventory.stock.index')">
                    {{ __('inventory::menu.stock') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- গুদাম বাছাই — GET, কারণ এটা প্রশ্ন, পরিবর্তন নয় --}}
    <form method="GET" action="{{ route('inventory.stock.overview') }}" class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('inventory.stock.overview') }}"
           @class([
               'inline-flex h-(--spacing-field-compact) items-center rounded-full border px-3 text-xs',
               'border-(--color-brand-500) bg-(--color-surface-hover) font-semibold text-(--color-brand-700)' => ! $warehouse,
               'border-(--color-border) text-(--color-ink-muted)' => (bool) $warehouse,
           ])>
            {{ __('inventory::overview.all_warehouses') }}
        </a>

        @foreach ($warehouses as $house)
            <a href="{{ route('inventory.stock.overview', ['warehouse' => $house->id]) }}"
               @class([
                   'inline-flex h-(--spacing-field-compact) items-center rounded-full border px-3 text-xs',
                   'border-(--color-brand-500) bg-(--color-surface-hover) font-semibold text-(--color-brand-700)' => $warehouse?->is($house),
                   'border-(--color-border) text-(--color-ink-muted)' => ! $warehouse?->is($house),
               ])>
                {{ $house->name() }}
            </a>
        @endforeach
    </form>

    {{-- ── ১ · চারটা সংখ্যা ───────────────────────────────────────── --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('inventory::overview.available'), 'value' => $states['available'],
             'hint' => __('inventory::overview.available_hint'), 'href' => null, 'tone' => 'ink'],
            ['label' => __('inventory::overview.below_reorder'), 'value' => (string) $belowReorder,
             'hint' => __('inventory::overview.below_reorder_hint'),
             'href' => route('inventory.stock.index', ['sort' => 'available']), 'tone' => 'warn'],
            ['label' => __('inventory::overview.out_of_stock'), 'value' => (string) $outOfStock,
             'hint' => __('inventory::overview.out_of_stock_hint'),
             'href' => route('inventory.stock.index', ['sort' => 'available']), 'tone' => 'bad'],
            ['label' => __('inventory::overview.stock_value'),
             'value' => $value === null ? '••••' : $value,
             'hint' => $value === null
                ? __('inventory::overview.value_hidden')
                : __('inventory::overview.stock_value_hint'),
             'href' => null, 'tone' => 'ink'],
        ] as $card)
            <{{ $card['href'] ? 'a' : 'div' }}
                @if ($card['href']) href="{{ $card['href'] }}" @endif
                data-boxed
                class="block rounded-(--radius-card) border border-(--color-border)
                       bg-(--color-surface-card) px-4 py-3">
                <div class="text-xs text-(--color-ink-muted)">{{ $card['label'] }}</div>
                <div @class([
                    'mt-1 text-2xl font-semibold tabular-nums',
                    'text-(--color-badge-warning-ink)' => $card['tone'] === 'warn',
                    'text-(--color-badge-danger-ink)' => $card['tone'] === 'bad',
                ])>{{ $card['value'] }}</div>
                <div class="mt-1 text-2xs text-(--color-ink-muted)">{{ $card['hint'] }}</div>
            </{{ $card['href'] ? 'a' : 'div' }}>
        @endforeach
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        {{-- ── ২ · মাসে মাসে ঢোকা ও বেরোনো ────────────────────────── --}}
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                               bg-(--color-surface-card) lg:col-span-2">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('inventory::overview.flow') }}
            </h2>

            @php
                /*
                 * সবচেয়ে বড় সংখ্যাটাই ১০০% — নাহলে সব বার সমান লম্বা
                 * হত আর চার্টটা কিছুই বলত না।
                 */
                $peak = collect($flow)->flatMap(fn ($m) => [(float) $m['in'], (float) $m['out']])->max() ?: 1;
            @endphp

            <div class="flex items-end gap-4 px-4 pt-8 pb-2" style="height: var(--spacing-chart)">
                @foreach ($flow as $month)
                    <div class="flex flex-1 items-end justify-center gap-1" style="height:100%">
                        <div class="w-1/2 rounded-t bg-(--color-brand-500)"
                             style="height:{{ max(2, (int) round((float) $month['in'] / $peak * 100)) }}%"
                             title="{{ __('inventory::overview.moved_in') }}: {{ $month['in'] }}"></div>
                        <div class="w-1/2 rounded-t bg-(--color-brand-700)/25"
                             style="height:{{ max(2, (int) round((float) $month['out'] / $peak * 100)) }}%"
                             title="{{ __('inventory::overview.moved_out') }}: {{ $month['out'] }}"></div>
                    </div>
                @endforeach
            </div>

            <div class="flex gap-4 px-4 pb-2">
                @foreach ($flow as $month)
                    <div class="flex-1 text-center text-2xs text-(--color-ink-muted)">{{ $month['month'] }}</div>
                @endforeach
            </div>

            <div class="flex items-center gap-4 border-t border-(--color-border) px-4 py-2 text-2xs
                        text-(--color-ink-muted)">
                <span class="flex items-center gap-1.5">
                    <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-500)"></span>
                    {{ __('inventory::overview.moved_in') }}
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-700)/25"></span>
                    {{ __('inventory::overview.moved_out') }}
                </span>
            </div>
        </div>

        {{-- ── মজুদের অবস্থা ───────────────────────────────────────────
             ABOS-এর নিজের সংখ্যা, আর ওটাই এই পাতার সবচেয়ে দামি অংশ:
             বাইরের কোনো ছবি এটা দেখায় না, কারণ বেশিরভাগ ব্যবস্থায়
             "মজুদ" একটাই সংখ্যা। --}}
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('inventory::overview.states') }}
            </h2>

            <div class="space-y-3 p-4">
                @foreach ([
                    ['available', $states['available'], 'bg-(--color-brand-500)'],
                    ['reserved', $states['reserved'], 'bg-(--color-brand-500)'],
                    ['hold', $states['hold'], 'bg-(--color-warning)'],
                    ['floor', $states['floor'], 'bg-(--color-ink-muted)'],
                ] as [$key, $qty, $colour])
                    @php
                        $floor = (float) $states['floor'] ?: 1;
                        $share = min(100, max(0, (int) round((float) $qty / $floor * 100)));
                    @endphp
                    <div>
                        <div class="mb-1 flex items-baseline justify-between text-xs">
                            <span class="text-(--color-ink-muted)">{{ __('inventory::overview.'.$key) }}</span>
                            <span class="font-semibold tabular-nums">{{ $qty }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-(--color-surface-hover)">
                            <div class="h-full {{ $colour }}" style="width:{{ $share }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="border-t border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                {{ __('inventory::overview.states_hint') }}
            </p>
        </div>
    </div>

    {{-- ── ৩ · ফুরিয়ে আসছে, আর আজ যা নড়ল ─────────────────────────── --}}
    <div class="mt-3 grid gap-3 lg:grid-cols-2">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                               bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('inventory::overview.below_reorder') }}
            </h2>
            <x-ui.table
                :empty="__('inventory::overview.nothing_low')"
                :rows="$lowStock"
                :columns="[
                    ['key' => 'name', 'label' => __('inventory::field.product'),
                     'render' => fn ($p) => $p->name()],
                    ['key' => 'available', 'label' => __('inventory::overview.available'), 'width' => '7rem',
                     'render' => fn ($p) => $p->available_qty],
                    ['key' => 'reorder', 'label' => __('inventory::overview.reorder_level'), 'width' => '7rem',
                     'render' => fn ($p) => $p->reorder_level],
                ]" />
        </div>

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                               bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('inventory::overview.recent') }}
                <span class="font-normal">· {{ __('inventory::overview.today_count', ['count' => $movementsToday]) }}</span>
            </h2>
            <x-ui.table
                :empty="__('inventory::overview.nothing_moved')"
                :rows="$recent"
                :columns="[
                    ['key' => 'product', 'label' => __('inventory::field.product'),
                     'render' => fn ($m) => $m->product?->name() ?? '—'],
                    ['key' => 'warehouse', 'label' => __('inventory::menu.warehouses'), 'width' => '9rem',
                     'render' => fn ($m) => $m->warehouse?->name() ?? '—'],
                    ['key' => 'qty', 'label' => __('inventory::overview.change'), 'width' => '7rem',
                     'render' => fn ($m) => $m->floor_change],
                ]" />
        </div>
    </div>
</x-layouts.app>
