{{--
    ভাউচারের তালিকা — সাতটা ট্যাব, এক পর্দা (১৯ সেপ্টেম্বর ২০২৬)।

    মালিকের নির্দেশে, আর ট্যাবের ক্রমও তাঁর। কোন ভাউচার কোন ট্যাবে
    পড়ে, আর কেন কোনোটা দুইবার আসে না — [[VoucherListController]]।

    ⓘ কলামগুলো এক-ধরনের তালিকার ([[voucher/index]]) মতোই, যাতে দুই
    পর্দায় একই ভাউচার একই চেহারায় দেখায়। "অন্যান্য" ট্যাবে একটা কলাম
    বেশি — ভাউচারটা কোথা থেকে এল, কারণ ওখানে সেটাই প্রথম প্রশ্ন।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($v) => view('accounts::voucher.partials.number', ['voucher' => $v])],
    ];

    if ($tab === \App\Modules\Accounts\Http\Controllers\VoucherListController::OTHERS) {
        $columns[] = ['key' => 'type', 'label' => __('accounts::field.voucher_type'), 'width' => '9rem',
            'render' => fn ($v) => __('accounts::menu.' . $v->type)];
        $columns[] = ['key' => 'against_type', 'label' => __('accounts::field.came_from'), 'width' => '11rem',
            'render' => fn ($v) => \Illuminate\Support\Str::headline((string) $v->against_type)];
    }

    $columns[] = ['key' => 'narration', 'label' => __('core.table.narration')];
    $columns[] = ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
        'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)];
    $columns[] = ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
        'render' => fn ($v) => view('accounts::voucher.partials.status', ['voucher' => $v])];
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

            <x-ui.toolbar :title="__('accounts::voucher.tab.' . $tab)"
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

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_vouchers')"
            :rows="$vouchers"
            :columns="$columns" />

        <x-ui.pager :rows="$vouchers" />
    </div>
</x-layouts.app>
