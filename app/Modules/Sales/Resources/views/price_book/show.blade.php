{{--
    একটা দর তালিকা — তার দরগুলো, আর একসাথে অনেক দর বসানোর গ্রিড ([[PriceBookController::show()]], ৫ অক্টোবর ২০২৬)।

    ⓘ গ্রিডে প্রতিটা চালু পণ্যের একটা সারি; যার দর লেখা হয় কেবল সেটাই বসে (Excel লাগে না — মালিক)।
    ⓘ শুরুর আর শেষের তারিখ গোটা গ্রিডের জন্য একটা; একই পণ্য-একক-তারিখের দর থাকলে সেটাই বদলায়, অডিটে আগে-পরে।
    ⓘ JavaScript লাগে না — সাধারণ ফর্ম।
--}}
@php
    use App\Core\Support\Money;

    $columns = [
        ['key' => 'product', 'label' => __('sales::price_book.product'),
            'render' => fn ($i) => ($i->product?->code ? $i->product->code.' — ' : '').($i->product?->name() ?? '—')],
        ['key' => 'unit', 'label' => __('sales::price_book.unit'), 'width' => '7rem',
            'render' => fn ($i) => $i->unit?->name() ?? $i->product?->unit?->name() ?? '—'],
        ['key' => 'price', 'label' => __('sales::price_book.price'), 'numeric' => true, 'width' => '8rem',
            'render' => fn ($i) => Money::format((string) $i->price)],
        ['key' => 'from', 'label' => __('sales::price_book.valid_from'), 'width' => '7rem',
            'render' => fn ($i) => $i->valid_from?->format('d-m-Y')],
        ['key' => 'to', 'label' => __('sales::price_book.valid_to'), 'width' => '7rem',
            'render' => fn ($i) => $i->valid_to?->format('d-m-Y') ?? __('sales::price_book.open_ended')],
        ['key' => 'actions', 'label' => '', 'width' => '12rem',
            'render' => fn ($i) => view('sales::price_book.partials.item-actions', ['list' => $list, 'item' => $i, 'canManage' => $canManage])],
    ];

    $state = $list->is_active ? __('sales::price_book.active') : __('sales::price_book.inactive');
    $subtitle = __('sales::price_book.title_'.$target).' · '.__('sales::price_book.for').': '.($aim ?? '—').' · '.$state;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $list->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$list->name()" :subtitle="$subtitle">
            <x-slot:actions>
                <x-ui.button :href="route('sales.price_book.index', ['target' => $target])" tone="secondary">{{ __('sales::price_book.back') }}</x-ui.button>
                @if ($canManage)
                    <x-ui.button :href="route('sales.price_book.edit', $list)" tone="secondary">{{ __('sales::price_book.edit') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <x-ui.errors />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$items" :columns="$columns" :empty="__('sales::price_book.no_items')" />
    </div>
    <x-ui.pager :rows="$items" />
    <x-ui.list-totals :rows="$items" />

    @if ($canManage)
        <form method="POST" action="{{ route('sales.price_book.items.store', $list) }}" data-price-grid
              class="mt-4 space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            <h2 class="font-semibold">{{ __('sales::price_book.grid_title') }}</h2>
            <p class="text-xs text-(--color-ink-muted)">{{ __('sales::price_book.grid_hint') }}</p>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.field name="valid_from" type="date" :label="__('sales::price_book.valid_from')"
                            :value="old('valid_from', now()->toDateString())" required />
                <x-ui.field name="valid_to" type="date" :label="__('sales::price_book.valid_to')" :value="old('valid_to')" />
            </div>

            <div class="table-responsive max-h-[60vh] overflow-y-auto">
                <table class="ui-lines w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('sales::price_book.product') }}</th>
                            <th class="text-start">{{ __('sales::price_book.unit') }}</th>
                            <th class="text-end">{{ __('sales::price_book.price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $i => $product)
                            <tr>
                                <td>
                                    <input type="hidden" name="rows[{{ $i }}][product_id]" value="{{ $product->id }}">
                                    {{ $product->code }} — {{ $product->name() }}
                                    <span class="text-xs text-(--color-ink-muted)">({{ Money::format((string) $product->sale_price) }})</span>
                                </td>
                                <td>
                                    @if (! empty($packs[$product->id]))
                                        <select name="rows[{{ $i }}][unit_id]" aria-label="{{ __('sales::price_book.unit') }}"
                                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2">
                                            <option value="">{{ $product->unit?->name() ?? '-' }}</option>
                                            @foreach ($packs[$product->id] as $pack)
                                                @if ((int) $pack['id'] !== (int) $product->unit_id)
                                                    <option value="{{ $pack['id'] }}" @selected((string) old('rows.'.$i.'.unit_id') === (string) $pack['id'])>{{ $pack['label'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    @else
                                        {{ $product->unit?->name() ?? '—' }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <input type="number" step="0.0001" min="0" inputmode="decimal" name="rows[{{ $i }}][price]"
                                           value="{{ old('rows.'.$i.'.price') }}" aria-label="{{ __('sales::price_book.price') }}"
                                           class="num h-(--spacing-field-compact) w-32 rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-card) px-2 text-end">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" tone="primary">{{ __('sales::price_book.grid_save') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
