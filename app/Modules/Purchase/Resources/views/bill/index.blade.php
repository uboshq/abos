{{-- ক্রয় বিল — তালিকা। --}}
@php
    $columns = [
        [
            'key' => 'trx_date',
            'label' => __('purchase::field.date'),
            'width' => '7rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->trx_date),
        ],
        [
            'key' => 'document_no',
            // ⭐ মালিকের ক্রম, ১ অক্টোবর ২০২৬: Date | INV Number | Supp INV No. | Supplier | Branch | Warehouse | Items | Due on | Total | Paid | Due | State | Created by
            'label' => __('purchase::field.inv_number'),
            'width' => '12rem',
            'render' => fn ($d) => view('purchase::components.doc-link', [
                'document' => $d,
                'route' => 'purchase.bill.show',
            ]),
        ],
        /*
         * ⭐ সরবরাহকারীর নিজের বিল নম্বর ("Supp INV No.") — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"Purchase Bills e Purchase
         * reference no er ekta colam koro"*। ⓘ ঘরটা আগে থেকেই ছিল (`supplier_bill_no`), খোঁজেও ধরা পড়ত, কিন্তু তালিকায় দেখা যেত না —
         * সরবরাহকারীর কাগজ হাতে নিয়ে মেলাতে প্রতিটা বিল খুলতে হত। ফাঁকা হলে "—"।
         */
        [
            'key' => 'supplier_bill_no',
            'label' => __('purchase::field.supp_inv_no'),
            'width' => '9rem',
            'render' => fn ($d) => filled($d->supplier_bill_no) ? $d->supplier_bill_no : '—',
        ],
        [
            'key' => 'supplier_id',
            'label' => __('purchase::field.supplier'),
            'render' => fn ($d) => $d->supplier?->name(),
        ],
        /*
         * ⭐ বাকি কলামগুলো — মালিক, ১ অক্টোবর ২০২৬: Branch | Warehouse | Items … Paid | Due … Created by।
         * ⓘ সবগুলোর তথ্য কন্ট্রোলার একসাথে আনে (eager-load, `withCount`, `withPaid`) — এখানে কোনো কোয়েরি নয়।
         * ⓘ "শাখা" কেবল হেডারে "সব শাখা" থাকলে ($showBranch) — এক শাখা বাছলে সব সারি একই শাখার।
         */
        ...(($showBranch ?? true) ? [[
            'key' => 'branch_id',
            'label' => __('purchase::field.branch'),
            'render' => fn ($d) => $d->branch?->name() ?? '—',
        ]] : []),
        [
            'key' => 'warehouse_id',
            'label' => __('purchase::field.warehouse'),
            'render' => fn ($d) => $d->warehouse?->name() ?? '—',
        ],
        [
            'key' => 'lines_count',
            'label' => __('purchase::field.items'),
            'numeric' => true,
            'width' => '5rem',
            'render' => fn ($d) => (string) $d->lines_count,
        ],
        [
            'key' => 'due_on',
            'label' => __('purchase::field.due_on'),
            'width' => '8rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->due_on),
        ],

        [
            'key' => 'total',
            'total' => 'money',
            'label' => __('purchase::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('purchase.bill.show', $d),
            ]),
        ],
        [
            'key' => 'paid_total',
            'total' => 'money',
            'raw' => fn ($d) => $d->paidAmount(),
            'label' => __('purchase::field.bill_paid'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($d) => \App\Core\Support\Money::format($d->paidAmount()),
        ],
        [
            'key' => 'due',
            'total' => 'money',
            'raw' => fn ($d) => $d->dueAmount(),
            'label' => __('purchase::field.bill_due'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($d) => \App\Core\Support\Money::format($d->dueAmount()),
        ],
        [
            'key' => 'status',
            'label' => __('purchase::field.state'),
            'width' => '8rem',
            'render' => fn ($d) => view('purchase::components.status-badge', ['document' => $d]),
        ],
        [
            'key' => 'created_by',
            'label' => __('purchase::field.created_by'),
            'render' => fn ($d) => $d->creator?->name ?? '—',
        ],
    ];
@endphp

<x-layouts.app :menu="$menu" :process-band="$processBand ?? []">
    <x-slot:title>{{ __('purchase::menu.bills') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('purchase::menu.bills')" :count="__('purchase::message.bill_note')"
                :columns="$columns" :search-placeholder="__('purchase::message.bill_search')"
                          :sort="$sortOptions">
        {{-- ⭐ "নতুন বিল" নেই — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬: বিল জন্মায়
             মাল গ্রহণে আর সরাসরি ক্রয়ে, আপনা থেকে। এই পাতা কেবল তালিকা; বিল
             খুলে সম্পাদনা করা যায়। ⓘ আদেশের পাতার "এই আদেশের বিল করুন" থাকল —
             যে ডিপো মাল গ্রহণের পর্দা বন্ধ রাখে, তার একমাত্র পথ ওটাই। --}}
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand ?? []"
            :view-url="fn ($d) => route('purchase.bill.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_bills')"
            :rows="$bills"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$bills" />
        {{-- তালিকার যোগফল — পর্দা কেবল **ঘোষণা করে**।

             ⓘ দেখানো হবে কি না সেটা রূপ ঠিক করে ([[Ui::listFoot]]), তাই
             এখানে কোনো রূপের নাম লেখা নেই। --}}
        {{-- ⭐ মোট · পরিশোধিত · বাকি — টেবিলের সর্বমোটের একই সংখ্যা, গোটা ছাঁকনির (মালিক, ৫ অক্টোবর ২০২৬) --}}
        <x-ui.list-totals :rows="$totals['rows']" :grand="$grand ?? []" :columns="$columns" />
    </div>
</x-layouts.app>
