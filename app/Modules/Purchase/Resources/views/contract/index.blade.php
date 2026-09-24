{{--
    চুক্তির তালিকা — মেয়াদ শেষ হয়ে আসছে যেগুলোর, উপরে।

    ⭐ এই পর্দার আসল প্রশ্ন *"কোন চুক্তিগুলোর মেয়াদ শেষ হয়ে আসছে"*।
    ⚠️ সাম্প্রতিক দিয়ে সাজালে ঠিক ঐ চুক্তিগুলোই নিচে চাপা পড়ত যেগুলো
    নিয়ে আজ কিছু করার আছে — নবায়ন, বা নতুন দর।
--}}
@php
    $columns = [
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('purchase.contract.show', $c) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($c->document_no) . '</a>')],
        ['key' => 'supplier', 'label' => __('purchase::field.supplier'),
         'render' => fn ($c) => $c->supplier?->name() ?? '—'],
        ['key' => 'starts_on', 'label' => __('purchase::field.starts_on'), 'width' => '8rem',
         'render' => fn ($c) => \App\Core\Support\DateFormat::format($c->starts_on)],
        ['key' => 'ends_on', 'label' => __('purchase::field.ends_on'), 'width' => '8rem',
         'render' => fn ($c) => \App\Core\Support\DateFormat::format($c->ends_on)],

        /*
         * ⭐ কত দিন বাকি — তারিখটার পাশে, কারণ তারিখ দেখে মানুষ মাথায়
         * হিসাব করেন না।
         *
         * ⚠️ পেরিয়ে গেলে সংখ্যাটা ঋণাত্মক, আর তখন সেটা লাল — ⓘ
         * *"শেষ হয়ে গেছে"* কথাটা সংখ্যার চেয়ে জোরে বলা দরকার।
         */
        ['key' => 'days_left', 'label' => __('purchase::field.days_left'), 'width' => '8rem',
         'numeric' => true,
         'render' => function ($c) {
             $left = $c->daysLeft();

             return new \Illuminate\Support\HtmlString(
                 $left < 0
                     ? '<span class="text-(--color-badge-danger-ink)">'
                       . e(__('purchase::field.contract_over')) . '</span>'
                     : e((string) $left));
         }],

        ['key' => 'lines', 'label' => __('purchase::field.items'), 'numeric' => true, 'width' => '6rem',
         'render' => fn ($c) => $c->lines_count],
        ['key' => 'status', 'label' => __('purchase::field.state'), 'width' => '9rem',
         'render' => fn ($c) => view('purchase::contract.partials.status', ['contract' => $c])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.contracts') }}</x-slot:title>

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
            <x-ui.toolbar :title="__('purchase::menu.contracts')"
                          :count="trans_choice('core.count.records', $contracts->total(), ['count' => $contracts->total()])"
                          :columns="$columns">
                <x-slot:actions>
                    @can('create', \App\Modules\Purchase\Models\PurchaseContract::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('purchase.contract.create')">
                            {{ __('purchase::action.new_contract') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('purchase::message.no_contracts')"
            :rows="$contracts"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$contracts" />
    </div>
</x-layouts.app>
