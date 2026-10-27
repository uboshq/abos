{{--
    ভাউচারের তালিকা — সাতটা ট্যাব, এক পর্দা (১৯ সেপ্টেম্বর ২০২৬)।

    মালিকের নির্দেশে, আর ট্যাবের ক্রমও তাঁর। কোন ভাউচার কোন ট্যাবে
    পড়ে, আর কেন কোনোটা দুইবার আসে না — [[VoucherListController]]।

    ⓘ কলামগুলো এক-ধরনের তালিকার ([[voucher/index]]) মতোই, যাতে দুই
    পর্দায় একই ভাউচার একই চেহারায় দেখায়। "অন্যান্য" ট্যাবে একটা কলাম
    বেশি — ভাউচারটা কোথা থেকে এল, কারণ ওখানে সেটাই প্রথম প্রশ্ন।
--}}
{{-- ⭐ ক্রয় আর বিক্রয় ট্যাব — ভাউচার নয়, খাতায় বসা বিল (১৯ সেপ্টেম্বর ২০২৬)।
     ⓘ সারিটা খাতার গোছানো যোগ, তাই ভাউচারের অবস্থার কলাম নেই; নম্বরটা
     কোরের `<x-ui.drill>` দিয়ে বিলের নিজের পাতায় যায়। কেন ভাউচার নয় —
     [[VoucherListController::PURCHASE]]। --}}
@php
    $isDocumentTab = isset(\App\Modules\Accounts\Http\Controllers\VoucherListController::DOCUMENT_TABS[$tab]);
@endphp

@php
    $columns = $isDocumentTab ? [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '20rem',
         'render' => fn ($d) => view('accounts::voucher.partials.document-link', [
             'source' => $d->source_type, 'id' => $d->source_id,
             // ⭐ নম্বরের পাশে পক্ষ (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে কন্ট্রোলার, পাতার একবারে
             'party' => ($partyNames ?? [])[$d->source_type.':'.$d->source_id] ?? null,
         ])],
        ['key' => 'narration', 'label' => __('core.table.narration')],
        ['key' => 'amount', 'total' => 'money', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($d) => \App\Core\Support\Money::format($d->amount)],
    ] : [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '20rem',
         'render' => fn ($v) => view('accounts::voucher.partials.number', [
             'voucher' => $v,
             // ⭐ নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট", নইলে প্রাপকের নাম (মালিক, ১৯ অক্টোবর ২০২৬; [[VoucherParties]])
             'party' => ($partyNames ?? [])[$v->id] ?? null,
         ])],
    ];

    if (! $isDocumentTab && $tab === \App\Modules\Accounts\Http\Controllers\VoucherListController::OTHERS) {
        $columns[] = ['key' => 'type', 'label' => __('accounts::field.voucher_type'), 'width' => '9rem',
            'render' => fn ($v) => __('accounts::menu.' . $v->type)];
        $columns[] = ['key' => 'against_type', 'label' => __('accounts::field.came_from'), 'width' => '11rem',
            'render' => fn ($v) => \Illuminate\Support\Str::headline((string) $v->against_type)];
    }

    /*
     * ⭐ খসড়া বাছাই — একসাথে পোস্ট বা অনুমোদনে পাঠানো (অনুমোদন পর্দা ভাগ ২, দফা ১৪; [[VoucherBulkPostController]])।
     * ⓘ ঘরটা কেবল খসড়ায়, আর কেবল যাঁর পোস্টের চাবি আছে; ⛔ তবু সার্ভার প্রতিটা আবার দেখে — লুকানো ঘর হাতে বানিয়ে পাঠানো যায়।
     * ⓘ চেকবক্স টেবিলের বাইরের ফর্মে (`form="voucher-bulk"`) — টেবিলের ভেতরে `<form>` বসালে HTML ওটাকে বাইরে ঠেলে দিত।
     */
    $canBulk = ! $isDocumentTab && auth()->user()?->can('accounts.voucher.update');

    if ($canBulk) {
        array_unshift($columns, ['key' => 'pick', 'label' => __('accounts::field.pick'), 'width' => '3rem',
            'render' => fn ($v) => $v->isDraft()
                ? new \Illuminate\Support\HtmlString('<label class="flex min-h-(--spacing-touch) items-center justify-center">'
                    .'<input type="checkbox" name="ids[]" value="'.(int) $v->id.'" form="voucher-bulk" class="size-4" data-voucher-pick>'
                    .'<span class="sr-only">'.e(__('accounts::field.pick').' '.$v->document_no).'</span></label>')
                : '']);
    }

    if (! $isDocumentTab) {
        $columns[] = ['key' => 'narration', 'label' => __('core.table.narration')];
        $columns[] = ['key' => 'amount', 'total' => 'money', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
            // ⓘ মোটে কেবল পাকা ভাউচার — বাতিল আর খসড়া শূন্য ([[Voucher::countedAmount()]])
            'raw' => fn ($v) => $v->countedAmount(),
            'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)];
        $columns[] = ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
            'render' => fn ($v) => view('accounts::voucher.partials.status', ['voucher' => $v])];
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.voucher_list') }}</x-slot:title>

    {{-- ট্যাবের সারি — ছাঁকনি (তারিখ, খোঁজ) ট্যাব বদলালেও থেকে যায় --}}
    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('accounts::menu.voucher_list') }}">
        @foreach ($tabs as $each)
            <a href="{{ route('accounts.voucher.list', [...request()->except(['tab', 'page']), 'tab' => $each]) }}"
               @if ($tab === $each) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $tab === $each
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ __('accounts::voucher.tab.' . $each) }}
                <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $counts[$each] }}
                </span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">

            {{-- ⓘ ট্যাব ছাঁকনির চিপ নয় — উপরের ট্যাবগুলোই দেখায় (১৯ সেপ্টেম্বর ২০২৬) --}}
            <x-ui.toolbar :title="__('accounts::voucher.tab.' . $tab)" :quiet="['tab']"
                :count="trans_choice('accounts::message.voucher_count', $vouchers->total(), ['count' => $vouchers->total()])"
                :sort="$sortOptions"
                :columns="$columns">
                <x-ui.date name="from"
                           value="{{ request('from') }}"
                           aria-label="{{ __('accounts::field.from_date') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                <x-ui.date name="to"
                           value="{{ request('to') }}"
                           aria-label="{{ __('accounts::field.to_date') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
            </x-ui.toolbar>
        </form>

        {{-- ⭐ একসাথে পোস্ট বা অনুমোদনে পাঠানো — আগে দেখার পাতা (দফা ১৪); ⓘ খসড়া না থাকলে বোতামটাই নেই --}}
        @if ($canBulk && $vouchers->getCollection()->contains(fn ($v) => $v->isDraft()))
            <form method="GET" action="{{ route('accounts.voucher.bulk_review') }}" id="voucher-bulk"
                  class="flex flex-wrap items-center gap-3 border-b border-(--color-border) px-3 py-2">
                <input type="hidden" name="back" value="{{ request()->fullUrl() }}">
                <x-ui.button type="submit" tone="secondary" data-voucher-bulk>{{ __('accounts::action.bulk_post_review') }}</x-ui.button>
                <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::message.bulk_post_hint') }}</span>
            </form>
        @endif

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_vouchers')"
            :rows="$vouchers"
            :grand="$grand ?? []"
            :columns="$columns" />

        <x-ui.pager :rows="$vouchers" />
        <x-ui.list-totals :rows="$vouchers" :grand="$grand ?? []" :columns="$columns" />
    </div>
</x-layouts.app>
