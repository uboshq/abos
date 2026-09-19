{{--
    এক ধরনের ভাউচারের তালিকা।

    সবচেয়ে নতুনটা উপরে — ভাউচারের তালিকায় লোকে আজকের কাজ দেখতে আসে।
    অবস্থাটা কলাম হিসেবে আছে, কারণ খসড়া আর পোস্ট করা ভাউচারের মধ্যে
    পার্থক্যটা মৌলিক: একটা হিসাবে আছে, অন্যটা নেই।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($v) => view('accounts::voucher.partials.number', ['voucher' => $v])],
        ['key' => 'narration', 'label' => __('core.table.narration')],
        ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)],
        ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
         'render' => fn ($v) => view('accounts::voucher.partials.status', [
             'voucher' => $v,
             'awaiting' => in_array((int) $v->id, $awaitingIds ?? [], true),
         ])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.' . $type) }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::voucher.' . $type)" :count="trans_choice('accounts::message.voucher_count', $vouchers->total(), ['count' => $vouchers->total()])"
                :sort="$sortOptions"
                :columns="$columns">
        <x-slot:actions>
            @can('accounts.voucher.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.voucher.create', $type)">
                        {{ __('accounts::action.new_voucher') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                {{-- তারিখের পরিসর — ভাউচারের তালিকায় এটাই সবচেয়ে বেশি
                     ব্যবহৃত ফিল্টার, তাই লুকানো নয় --}}
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

        {{--
            ⭐ অনুমোদনের অপেক্ষায় কয়টা — ১৮ সেপ্টেম্বর ২০২৬।

            ⓘ ঝুলে থাকা কাগজ খাতায় বসেনি, তাই মাসের কোনো যোগফলে ওরা
            নেই। ⚠️ সংখ্যাটা না দেখালে ম্যানেজার কম খরচ ধরে এগোতেন,
            আর মাস শেষে অনুমোদন হয়ে গেলে হঠাৎ বেড়ে যেত।

            ⛔ শূন্য হলে সারিটাই আঁকা হয় না — "০টি অপেক্ষায়" লেখা
            একটা পট্টি রোজ জায়গা নিত আর কিছুই বলত না।
        --}}
        @if (($awaitingCount ?? 0) > 0 || ($awaiting ?? false))
            <div class="flex flex-wrap items-center gap-2 border-t border-(--color-border) px-3 py-2">
                @if ($awaiting ?? false)
                    <a href="{{ route('accounts.voucher.index', $type) }}"
                       class="rounded-full border border-(--color-border) px-3 py-1 text-xs">
                        {{ __('accounts::action.show_all') }}
                    </a>
                    <span class="text-xs text-(--color-ink-muted)">
                        {{ __('accounts::message.awaiting_only') }}
                    </span>
                @else
                    <a href="{{ route('accounts.voucher.index', [$type, 'awaiting' => 1]) }}"
                       class="rounded-full border border-(--color-badge-pending-ink)
                              bg-(--color-badge-pending-bg) px-3 py-1 text-xs
                              font-medium text-(--color-badge-pending-ink)">
                        {{ trans_choice('accounts::message.awaiting_approval', $awaitingCount, ['count' => $awaitingCount]) }}
                    </a>
                    <span class="text-xs text-(--color-ink-muted)">
                        {{ __('accounts::message.awaiting_not_in_books') }}
                    </span>
                @endif
            </div>
        @endif

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_vouchers')"
            :rows="$vouchers"
            :columns="$columns" />

        <x-ui.pager :rows="$vouchers" />
    </div>
</x-layouts.app>
