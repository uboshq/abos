{{--
    দর তালিকা — এক স্তরের তালিকাগুলো (গ্রাহক · ধরন · এলাকা · সবার), ট্যাবে ([[PriceBookController::index()]])।

    ⭐ মালিক, ৫ অক্টোবর ২০২৬: "মূল্য নির্ধারণ"-এর চারটা "তৈরি হচ্ছে" পাতার জায়গায় আসল তালিকা।
    ⓘ তালিকার নামটাই লিংক (নিয়ম ১) — খুললে তার দরগুলো আর একসাথে দর বসানোর গ্রিড।
--}}
@php
    $tabs = collect(\App\Modules\Sales\Services\PriceBookService::TARGETS)->map(fn ($t) => [
        'key' => $t,
        'label' => __('sales::price_book.tab_'.$t),
        'url' => route('sales.price_book.index', ['target' => $t]),
        'active' => $t === $target,
    ])->all();

    $columns = [
        ['key' => 'code', 'label' => __('sales::price_book.code'), 'width' => '8rem', 'render' => fn ($l) => $l->code],
        ['key' => 'name', 'label' => __('sales::price_book.title'),
            'render' => fn ($l) => new \Illuminate\Support\HtmlString('<a href="'.e(route('sales.price_book.show', $l)).'"'
                .' class="text-(--color-brand-500) underline-offset-2 hover:underline">'.e($l->name()).'</a>')],
        ['key' => 'for', 'label' => __('sales::price_book.for'), 'render' => fn ($l) => $aims[$l->id] ?? '—'],
        ['key' => 'rows', 'label' => __('sales::price_book.rows'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($l) => (string) ($counts[$l->id] ?? 0)],
        ['key' => 'state', 'label' => '', 'width' => '6rem',
            'render' => fn ($l) => $l->is_active ? __('sales::price_book.active') : __('sales::price_book.inactive')],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::price_book.title_'.$target) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::price_book.title_'.$target)" :subtitle="__('sales::price_book.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <input type="hidden" name="target" value="{{ $target }}">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::price_book.search') }}</span>
                        <input type="search" name="q" value="{{ $q }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                    @if ($canManage)
                        <x-ui.button :href="route('sales.price_book.create', ['target' => $target])" tone="primary" data-new-price-list>
                            {{ __('sales::price_book.new') }}
                        </x-ui.button>
                    @endif
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <x-ui.list-tabs :tabs="$tabs" :label="__('sales::price_book.title')" />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('sales::price_book.title_'.$target)" :search="false" :filter="false" :columns="$columns" />

        <x-ui.table :rows="$lists" :columns="$columns" :empty="__('sales::price_book.no_lists')" />
    </div>

    <x-ui.pager :rows="$lists" />
    <x-ui.list-totals :rows="$lists" />
</x-layouts.app>
