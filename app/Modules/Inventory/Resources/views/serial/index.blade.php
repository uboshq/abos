{{--
    সিরিয়াল নম্বরের তালিকা — আর সবচেয়ে উপরে খোঁজার ঘর।

    ⭐ এই পর্দার আসল প্রশ্ন একটাই: *"এই নম্বরের পিসটা কোথায়, আর তার
    ওয়ারেন্টি আছে কি না"* — আর সেটা জিজ্ঞেস করেন কাউন্টারে দাঁড়ানো একজন
    ক্রেতা, হাতে একটা নষ্ট জিনিস নিয়ে। ⚠️ তাই ঘরটা তালিকার মাথায়, আর
    বড়-ছোট হরফ মেলানো হয় না ([[SerialNumber::scopeNumbered()]])।
--}}
@php
    $columns = [
        ['key' => 'serial_no', 'label' => __('inventory::field.serial_no'), 'width' => '14rem',
         'render' => fn ($s) => $s->serial_no],
        ['key' => 'product', 'label' => __('inventory::field.product'),
         'render' => fn ($s) => $s->product?->name() ?? '—'],
        ['key' => 'batch', 'label' => __('inventory::field.batch_no'), 'width' => '9rem',
         'render' => fn ($s) => $s->batch?->batch_no ?? '—'],
        ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'),
         'render' => fn ($s) => $s->warehouse?->name() ?? '—'],
        ['key' => 'status', 'label' => __('inventory::field.state'), 'width' => '8rem',
         'render' => fn ($s) => view('inventory::serial.partials.status', ['serial' => $s])],

        /*
         * ⭐ ওয়ারেন্টির কলামটা তারিখ নয়, **উত্তর**।
         *
         * ⚠️ একটা তারিখ দেখে মানুষকে মাথায় হিসাব করতে বলা মানে ভুল
         * হওয়ার সুযোগ দেওয়া, আর এখানে ভুলের দাম একটা প্রত্যাখ্যাত
         * দাবি। ⓘ তাই "আছে / নেই" লেখা হয়, তারিখটা পাশে ছোট করে।
         */
        ['key' => 'warranty', 'label' => __('inventory::field.warranty'), 'width' => '11rem',
         'render' => fn ($s) => new \Illuminate\Support\HtmlString(
             ($s->underWarranty()
                 ? '<span class="font-medium">' . e(__('inventory::status.under_warranty')) . '</span>'
                 : '<span class="text-(--color-ink-muted)">' . e(__('inventory::status.no_warranty')) . '</span>')
             . ($s->warranty_to
                 ? '<span class="block text-2xs text-(--color-ink-muted)">'
                   . e(\App\Core\Support\DateFormat::format($s->warranty_to)) . '</span>'
                 : ''))],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.serials') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.serials')"
                          :count="trans_choice('core.count.records', $serials->total(), ['count' => $serials->total()])"
                          :columns="$columns"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('inventory.serial.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('inventory.serial.create')">
                            {{ __('inventory::action.add_serials') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                {{-- ⓘ নিজের ঘর, সাধারণ খোঁজার ঘর নয় — ⚠️ এখানে প্রশ্নটা
                     "নামে কিছু আছে কি না" নয়, "এই নম্বরটা কোথায়"। --}}
                <label class="block">
                    <span class="sr-only">{{ __('inventory::field.serial_no') }}</span>
                    <input type="search" name="serial" value="{{ $serial }}"
                           placeholder="{{ __('inventory::message.serial_search') }}"
                           class="h-(--spacing-field) w-full max-w-64 rounded-(--radius-field)
                                  border border-(--color-border) bg-(--color-surface-card) px-3">
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$serial !== '' ? __('inventory::message.serial_not_found') : __('inventory::message.no_serials')"
            :rows="$serials"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$serials" />
    </div>
</x-layouts.app>
