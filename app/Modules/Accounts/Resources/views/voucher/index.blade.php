{{--
    এক ধরনের ভাউচারের তালিকা।

    সবচেয়ে নতুনটা উপরে — ভাউচারের তালিকায় লোকে আজকের কাজ দেখতে আসে।
    অবস্থাটা কলাম হিসেবে আছে, কারণ খসড়া আর পোস্ট করা ভাউচারের মধ্যে
    পার্থক্যটা মৌলিক: একটা হিসাবে আছে, অন্যটা নেই।
--}}
@php
    /*
     * ⭐ পক্ষ · কী বাবদ · কোথায় · মাধ্যম — ১৯ সেপ্টেম্বর ২০২৬, মালিকের "koro"।
     *
     * ⓘ তারিখ-নম্বর-বিবরণ-অঙ্ক দেখে বোঝা যেত না টাকাটা কার, কীসের, কোথায়
     * গেল — প্রতিটা খুলে দেখতে হত। ⭐ দাখিলার লাইন থেকেই পড়া হয়: যে খাতটা
     * নগদ/ব্যাংক/মোবাইল (বা পথের টাকা, হাতে চেক) সেটা "কোথায়", বাকিটা
     * "কী বাবদ"। শিরোনাম ধরন অনুযায়ী — আদায়ে "কোথায় জমা", পরিশোধে
     * "কোথা থেকে", জাবেদায় ডেবিট / ক্রেডিট।
     */
    $isMoney = fn ($a) => $a !== null && ($a->money_kind !== null
        || in_array((string) $a->code, [\App\Modules\Accounts\Services\StandardChart::CASH_IN_TRANSIT,
            \App\Modules\Accounts\Services\StandardChart::CHEQUES_IN_HAND], true));

    $names = fn ($lines) => $lines->map(fn ($l) => $l->account?->name())->filter()->unique()->implode(', ') ?: '—';

    $side = fn ($v, string $dc, ?bool $money) => $v->lines
        ->filter(fn ($l) => bccomp((string) $l->{$dc}, '0', 4) > 0)
        ->filter(fn ($l) => $money === null || $isMoney($l->account) === $money);

    [$forLabel, $forSide, $whereLabel, $whereSide] = match ($type) {
        \App\Modules\Accounts\Models\Voucher::RECEIPT => [__('accounts::field.list_for'), ['credit', false], __('accounts::field.paid_into'), ['debit', true]],
        \App\Modules\Accounts\Models\Voucher::PAYMENT => [__('accounts::field.list_for'), ['debit', false], __('accounts::field.paid_from'), ['credit', true]],
        \App\Modules\Accounts\Models\Voucher::EXPENSE => [__('accounts::field.expense_head'), ['debit', false], __('accounts::field.paid_from'), ['credit', null]],
        \App\Modules\Accounts\Models\Voucher::CONTRA => [__('accounts::field.moved_from'), ['credit', null], __('accounts::field.moved_to'), ['debit', null]],
        default => [__('accounts::field.debit'), ['debit', null], __('accounts::field.credit'), ['credit', null]],
    };

    /* ⓘ ঘরটার পুরো নিয়ম এখন [[Voucher::wayInWords()]]-এ।

       ⛔ আগে দুই জায়গায় দুইটা তালিকা ছিল — মডেলে কোডবদ্ধ মান,
       আর এখানে টাকার খাতের ধরন। ⚠️ দুইটা তালিকা একদিন আলাদা হয়।

       ⚠️ মন্তব্যটা PHP-র রীতিতে, কারণ এই ঘরটা একটা PHP ব্লকের
       ভিতরে — ওখানে ব্লেডের মন্তব্য কাঁচা PHP হয়ে পাতাটা ৫০০ দেয়।
       ⓘ একবার দিয়েছেও। */
    $method = fn ($v) => $v->wayInWords() ?? '—';

    $flowColumns = array_values(array_filter([
        $type === \App\Modules\Accounts\Models\Voucher::CONTRA ? null : [
            'key' => 'party',
            'label' => $type === \App\Modules\Accounts\Models\Voucher::RECEIPT ? __('accounts::field.received_from') : __('accounts::field.party'),
            'render' => fn ($v) => ($partyNames ?? [])[$v->party_type.':'.$v->party_id] ?? ($v->payee_name ?: '—'),
        ],
        ['key' => 'for', 'label' => $forLabel, 'render' => fn ($v) => $names($side($v, ...$forSide))],
        ['key' => 'where', 'label' => $whereLabel, 'render' => fn ($v) => $names($side($v, ...$whereSide))],
        in_array($type, [\App\Modules\Accounts\Models\Voucher::JOURNAL], true) ? null
            : ['key' => 'method', 'label' => __('accounts::field.list_method'), 'width' => '8rem', 'render' => $method],
    ]));

    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($v) => view('accounts::voucher.partials.number', ['voucher' => $v])],
        ...$flowColumns,
        ['key' => 'narration', 'label' => __('core.table.narration')],
        ['key' => 'amount', 'total' => 'money', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         // ⓘ মোটে কেবল পাকা ভাউচার — বাতিল আর খসড়া শূন্য ([[Voucher::countedAmount()]])
         'raw' => fn ($v) => $v->countedAmount(),
         'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)],
        ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
         'render' => fn ($v) => view('accounts::voucher.partials.status', [
             'voucher' => $v,
             'awaiting' => in_array((int) $v->id, $awaitingIds ?? [], true),
             'revised' => in_array((int) $v->id, $revisedIds ?? [], true),
         ])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.' . $type) }}</x-slot:title>

    {{-- ⭐ রঙিন মাথা — নাম, কয়টা, "নতুন" ([[money.page]]; মালিক, ৬ নভেম্বর ২০২৬: নমুনা "ok", নিচের লেখা নয় — "fita ta ro cikon hoy") --}}
    <x-money.page :kind="$type" :title="__('accounts::voucher.' . $type)" :icon="['receipt' => 'inbox', 'payment' => 'outbox', 'expense' => 'wallet', 'journal' => 'edit', 'contra' => 'swap'][$type] ?? 'wallet'"
        :count="trans_choice('accounts::message.voucher_count', $vouchers->total(), ['count' => $vouchers->total()])">
        <x-slot:actions>
            @can('accounts.voucher.create')
                <x-ui.button tone="primary" icon="plus" :href="route('accounts.voucher.create', $type)">
                    {{ __('accounts::action.new_voucher') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :sort="$sortOptions"
                :columns="$columns"
                :filter-labels="$filterLabels ?? []"
                :filter-values="$filterValues ?? []">
                {{-- তারিখের পরিসর — ভাউচারের তালিকায় এটাই সবচেয়ে বেশি
                     ব্যবহৃত ফিল্টার, তাই লুকানো নয় --}}
                <x-ui.date name="from"
                           value="{{ $filters['from'] ?? '' }}"
                           aria-label="{{ __('accounts::field.from_date') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                <x-ui.date name="to"
                           value="{{ $filters['to'] ?? '' }}"
                           aria-label="{{ __('accounts::field.to_date') }}"
                           :submit-on-change="true"
                           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />

                {{--
                    ⭐ বাকি ছাঁকনি — পক্ষ (খোঁজাসহ), খাত, পদ্ধতি, অবস্থা, টাকার পরিসর, কে বানালেন, শাখা (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬;
                    [[VoucherListFilters]])। ⓘ প্যানেল যেকোনো ঘর বদলালেই জমা দেয় (`data-action="submit-form"`), বাছা ছাঁকনি চিপ হয়ে
                    ✕-সহ ওঠে ([[x-ui.toolbar]]), আর সব ঠিকানায় — share আর ছাপা একই তালিকা।
                --}}
                @php
                    $compact = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
                    $pick = fn (string $key, array $options, string $all) => ['key' => $key, 'options' => $options, 'all' => $all];
                @endphp
                {{-- ⓘ পক্ষের ঘর খালি থাকলে কেবল "—" দেখায় — নামটা পাশে, বাকি ঘরের "নাম: সব"-এর মতো চেনা যায় --}}
                <div class="flex min-w-64 items-center gap-2" data-filter="party">
                    <span class="shrink-0 text-xs text-(--color-ink-muted)">{{ $filterLabels['party'] ?? '' }}</span>
                    <x-ui.party-search name="party" :groups="$filterOptions['parties'] ?? []" :selected="$filters['party'] ?? null"
                                       clearable :aria-label="$filterLabels['party'] ?? ''" />
                </div>
                @foreach ([
                    $pick('account', $filterOptions['accounts'] ?? [], $filterLabels['account'] ?? ''),
                    $pick('method', $filterOptions['methods'] ?? [], $filterLabels['method'] ?? ''),
                    $pick('state', $filterOptions['states'] ?? [], $filterLabels['state'] ?? ''),
                    $pick('by', $filterOptions['makers'] ?? [], $filterLabels['by'] ?? ''),
                    $pick('branch_id', $filterOptions['branches'] ?? [], $filterLabels['branch_id'] ?? ''),
                ] as $f)
                    {{-- ⓘ এক শাখা হলে শাখার ঘরের কিছু বলার নেই --}}
                    @continue($f['key'] === 'branch_id' && count($f['options']) < 2)
                    <select name="{{ $f['key'] }}" aria-label="{{ $f['all'] }}" class="{{ $compact }}" data-filter="{{ $f['key'] }}">
                        <option value="">{{ $f['all'] }}: {{ __('accounts::voucher.filter.all') }}</option>
                        @foreach ($f['options'] as $value => $text)
                            <option value="{{ $value }}" @selected((string) ($filters[$f['key']] ?? '') === (string) $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                @endforeach
                <input type="text" inputmode="decimal" name="min" value="{{ $filters['min'] ?? '' }}" data-filter="min"
                       placeholder="{{ $filterLabels['min'] ?? '' }}" aria-label="{{ $filterLabels['min'] ?? '' }}" class="{{ $compact }} w-28 text-right">
                <input type="text" inputmode="decimal" name="max" value="{{ $filters['max'] ?? '' }}" data-filter="max"
                       placeholder="{{ $filterLabels['max'] ?? '' }}" aria-label="{{ $filterLabels['max'] ?? '' }}" class="{{ $compact }} w-28 text-right">
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
        {{-- ⭐ কেবল মাসশেষের সমন্বয় — জাবেদার তালিকায় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬) --}}
        @if ($type === \App\Modules\Accounts\Models\Voucher::JOURNAL)
            <div class="flex flex-wrap items-center gap-2 border-t border-(--color-border) px-3 py-2" data-adjusting-filter>
                @if ($adjusting ?? false)
                    <a href="{{ route('accounts.voucher.index', $type) }}"
                       class="rounded-full border border-(--color-border) px-3 py-1 text-xs">
                        {{ __('accounts::action.show_all') }}
                    </a>
                    <span class="text-xs text-(--color-ink-muted)">{{ __('accounts::voucher.adjusting_only') }}</span>
                @else
                    <a href="{{ route('accounts.voucher.index', [$type, 'adjusting' => 1]) }}"
                       class="rounded-full border border-(--color-border) px-3 py-1 text-xs">
                        {{ __('accounts::voucher.adjusting_filter') }}
                    </a>
                @endif
            </div>
        @endif

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
            :grand="$grand ?? []"
            :row-menu="fn ($d) => view('accounts::voucher.partials.row-menu', ['voucher' => $d, 'awaiting' => in_array((int) $d->id, $awaitingIds ?? [], true)])"
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_vouchers')"
            :rows="$vouchers"
            :columns="$columns" />

        <x-ui.pager :rows="$vouchers" />
        <x-ui.list-totals :rows="$vouchers" :grand="$grand ?? []" :columns="$columns" />
    </div>
    </x-money.page>
</x-layouts.app>
