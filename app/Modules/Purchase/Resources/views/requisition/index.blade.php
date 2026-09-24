{{--
    চাহিদার তালিকা — যেগুলোর সিদ্ধান্ত বাকি, উপরে।

    ⭐ এই পর্দার আসল প্রশ্ন *"কার চাওয়াটা ঝুলে আছে"*। ⚠️ সাম্প্রতিক দিয়ে
    সাজালে পুরনো ঝুলন্ত চাহিদাগুলোই নিচে চাপা পড়ত — অথচ ওগুলোই সবচেয়ে
    বেশি দিন অপেক্ষা করছে, আর ওখানেই কেউ একজন কাজ থামিয়ে বসে আছেন।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('purchase::field.date'), 'width' => '7rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($r) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('purchase.requisition.show', $r) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($r->document_no) . '</a>')],
        ['key' => 'requester', 'label' => __('purchase::field.requested_by'),
         'render' => fn ($r) => $r->requester?->name ?? '—'],
        ['key' => 'department', 'label' => __('purchase::field.department'),
         'render' => fn ($r) => $r->department ?: '—'],

        /*
         * ⭐ কবে লাগবে — আর এই কলামটাই তালিকার ক্রম ঠিক করে।
         *
         * ⚠️ তারিখ ছাড়া প্রতিটা চাহিদা সমান জরুরি দেখায়, আর তখন ক্রম
         * ঠিক হয় কে বেশি বার তাগাদা দিল তা দেখে — ⛔ ঐ ক্রমটা
         * প্রতিষ্ঠানের নয়, স্বরের।
         */
        ['key' => 'needed_by', 'label' => __('purchase::field.needed_by'), 'width' => '8rem',
         'render' => fn ($r) => $r->needed_by
             ? \App\Core\Support\DateFormat::format($r->needed_by)
             : '—'],

        ['key' => 'lines', 'label' => __('purchase::field.items'), 'numeric' => true, 'width' => '6rem',
         'render' => fn ($r) => $r->lines_count],
        ['key' => 'status', 'label' => __('purchase::field.state'), 'width' => '9rem',
         'render' => fn ($r) => view('purchase::requisition.partials.status', ['requisition' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.requisitions') }}</x-slot:title>

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
            <x-ui.toolbar :title="__('purchase::menu.requisitions')"
                          :count="trans_choice('core.count.records', $requisitions->total(), ['count' => $requisitions->total()])"
                          :columns="$columns"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Purchase\Models\PurchaseRequisition::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('purchase.requisition.create')">
                            {{ __('purchase::action.new_requisition') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('purchase::message.no_requisitions')"
            :rows="$requisitions"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$requisitions" />
    </div>
</x-layouts.app>
