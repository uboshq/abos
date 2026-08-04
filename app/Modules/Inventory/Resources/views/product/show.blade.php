{{--
    একটা পণ্য — চারটা অবস্থা ও তার পেছনের চলাচল।

    চারটা সংখ্যা পাশাপাশি, আর নিচে সেই সারিগুলো যেগুলো যোগ হয়ে
    সংখ্যাগুলো হয়েছে (নিয়ম ১)। "৪৭ কেন" প্রশ্নের উত্তর এক ক্লিক দূরে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $product->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$product->name()" :subtitle="$product->code">
            <x-slot:actions>
                @can('update', $product)
                    <x-ui.button tone="secondary" :href="route('inventory.product.edit', $product)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('delete', $product)
                    @if ($product->is_active)
                        <form method="POST" action="{{ route('inventory.product.destroy', $product) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('inventory::action.deactivate') }}
                            </x-ui.button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('inventory.product.activate', $product) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('inventory::action.activate') }}
                            </x-ui.button>
                        </form>
                    @endif
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- চারটা অবস্থা — সব গুদাম মিলিয়ে --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'inventory::field.floor' => $states['floor'],
            'inventory::field.reserved' => $states['reserved'],
            'inventory::field.hold' => $states['hold'],
            'inventory::field.available' => $states['available'],
        ] as $label => $value)
            <div class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <p class="text-sm text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num mt-1 text-2xl font-semibold">{{ number_format((float) $value, 2) }}</p>
            </div>
        @endforeach
    </div>

    <p class="mt-2 max-w-(--spacing-prose-max) text-2xs text-(--color-ink-muted)">
        {{ __('inventory::message.stock_math') }}
    </p>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4 lg:col-span-2">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('inventory::section.identity') }}</h2>
                @include('inventory::partials.state-badge', ['record' => $product])
            </div>

            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                @foreach ([
                    'inventory::field.barcode' => $product->barcode,
                    'inventory::field.brand' => $product->brand,
                    'inventory::field.category' => $product->category,
                    'inventory::field.unit' => $product->unit?->name(),
                    'inventory::field.tax' => $product->tax?->name(),
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.pricing') }}</h2>

            <dl class="space-y-2">
                @foreach ([
                    'inventory::field.purchase_price' => $product->purchase_price,
                    'inventory::field.sale_price' => $product->sale_price,
                    'inventory::field.reorder_level' => $product->reorder_level,
                ] as $label => $value)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="num font-medium">{{ number_format((float) $value, 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    {{-- চলাচল — সংখ্যাগুলো কোথা থেকে এল (নিয়ম ১) --}}
    <section id="movements"
             class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
            {{ __('inventory::section.movements') }}
        </h2>

        <x-ui.table
            :empty="__('inventory::message.no_movements')"
            :rows="$movements"
            :columns="[
                ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
                 'render' => fn ($m) => $m->trx_date?->format('d/m/Y')],
                ['key' => 'document', 'label' => __('core.table.document'),
                 'render' => fn ($m) => view('inventory::partials.movement-source', ['movement' => $m])],
                ['key' => 'warehouse_id', 'label' => __('inventory::field.warehouse'),
                 'render' => fn ($m) => $m->warehouse?->name()],
                ['key' => 'reason_code_id', 'label' => __('inventory::field.reason'),
                 'render' => fn ($m) => $m->reasonCode?->name()],
                ['key' => 'floor_change', 'label' => __('inventory::field.floor'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($m) => (float) $m->floor_change ? number_format((float) $m->floor_change, 2) : ''],
                ['key' => 'hold_change', 'label' => __('inventory::field.hold'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($m) => (float) $m->hold_change ? number_format((float) $m->hold_change, 2) : ''],
            ]" />

        @if ($movements->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $movements->links() }}</div>
        @endif
    </section>
</x-layouts.app>
