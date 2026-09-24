{{--
    দরপত্রের তালিকা — জবাবের অপেক্ষায় থাকাগুলো উপরে।

    ⭐ এই পর্দার আসল প্রশ্ন *"কার জবাব আসেনি"*, আর সেটাই তাগাদার ভিত্তি।
    ⚠️ সাম্প্রতিক দিয়ে সাজালে পুরনো ঝুলন্ত অনুরোধগুলোই নিচে চাপা পড়ত —
    অথচ ওগুলোই সবচেয়ে বেশি দিন ধরে ঝুলছে।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('purchase::field.date'), 'width' => '7rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($r) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('purchase.rfq.show', $r) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($r->document_no) . '</a>')],
        ['key' => 'respond_by', 'label' => __('purchase::field.respond_by'), 'width' => '8rem',
         'render' => fn ($r) => $r->respond_by
             ? \App\Core\Support\DateFormat::format($r->respond_by)
             : '—'],
        ['key' => 'lines', 'label' => __('purchase::field.items'), 'numeric' => true, 'width' => '6rem',
         'render' => fn ($r) => $r->lines_count],

        /*
         * ⭐ দুইটা সংখ্যা পাশাপাশি: কাকে জিজ্ঞেস করা হয়েছে, আর কজন
         * জবাব দিয়েছেন।
         *
         * ⚠️ কেবল জবাবের সংখ্যা দেখালে "৩" পড়ে মনে হত সবাই জবাব
         * দিয়েছেন — ⛔ অথচ হয়তো সাতজনকে জিজ্ঞেস করা হয়েছিল।
         */
        ['key' => 'answers', 'label' => __('purchase::field.answers'), 'width' => '8rem',
         'render' => fn ($r) => $r->quotations_count . ' / ' . $r->suppliers_count],

        ['key' => 'status', 'label' => __('purchase::field.state'), 'width' => '9rem',
         'render' => fn ($r) => view('purchase::rfq.partials.status', ['rfq' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.rfqs') }}</x-slot:title>

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
            <x-ui.toolbar :title="__('purchase::menu.rfqs')"
                          :count="trans_choice('core.count.records', $rfqs->total(), ['count' => $rfqs->total()])"
                          :columns="$columns"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Purchase\Models\Rfq::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('purchase.rfq.create')">
                            {{ __('purchase::action.new_rfq') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('purchase::message.no_rfqs')"
            :rows="$rfqs"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$rfqs" />
    </div>
</x-layouts.app>
