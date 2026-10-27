{{--
    ⭐ অনুমোদনের ইনবক্স — এক পাতায় বাঁয়ে তালিকা, ডানে কাগজ (অনুমোদন পর্দা ভাগ ২; মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬;
    SAP Fiori "My Inbox", পরিকল্পনার পাতা ২)।

    বাঁয়ে: চার ট্যাব আর সংখ্যা (দফা ২) · কাজ, শাখা, অঙ্ক, দেরি আর সাজানো (দফা ৩) · কার্ড — পক্ষ, শেষ সময়, ধাপ (দফা ৫, ৭, ৮) ·
    নিচে এই পাতার মোট, সবগুলোর মোট আর পাতা বদল (দফা ৬)।
    ডানে: বেছে নেওয়া কাগজ ([[partials/detail]]) — অনুমোদন দিলে পরের কাগজ নিজে খোলে (দফা ১)।

    ⛔ আগে: ১১ কলামের টেবিল, ৫০-এ কাটা, মোট নেই; কাগজ খুলতে আলাদা পাতা, সিদ্ধান্তের পরে আবার তালিকায় ফিরে পরেরটা খুঁজতে হত।

    ⓘ ছোট পর্দায় (lg-এর নিচে) তালিকা আগে, কাগজ তার নিচে — ফোনের মাপে কার্ড-তালিকা, টেবিল নয় (দফা ২১-এর ওয়েব অংশ)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    @php
        $canPick = array_flip($bulkable);
        $keep = array_filter([
            'person' => $person ?: null,
            'tab' => $tab !== 'waiting' ? $tab : null,
        ]);
        $filterKeep = array_filter($keep + [
            'branch' => $branch ?: null,
            'amount' => $band !== '' ? $band : null,
            'sort' => $sort,
            'q' => request('q') ?: null,
        ]);
        $from = http_build_query(array_filter(request()->only(['tab', 'module', 'branch', 'amount', 'late', 'sort', 'q', 'page'])));
        $rows = $approvals->getCollection();
        $at = $opened !== null ? $rows->search(fn ($a) => (int) $a->id === (int) $opened->id) : false;
        $prevUrl = $at !== false && $at > 0 ? request()->fullUrlWithQuery(['open' => $rows[$at - 1]->id]) : null;
        $nextUrl = $at !== false && isset($rows[$at + 1]) ? request()->fullUrlWithQuery(['open' => $rows[$at + 1]->id]) : null;
        $summary = $tab === 'waiting'
            ? __('approval::message.inbox_summary', ['count' => $visibleTotal, 'late' => $lateCount, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)])
            : __('approval::message.tab_summary', ['count' => $visibleTotal, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)]);
    @endphp

    <x-slot:header>
        <x-ui.page-header :title="$person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox')"
                          :subtitle="$summary">
            <x-slot:actions>
                {{-- ⭐ একসাথে অনুমোদন — মালিকের সিদ্ধান্ত ৫; ⛔ টাকা নড়ে এমন কাগজে চেকবক্সই আঁকা হয় না। ⓘ আগে দেখার পাতা (দফা ১৩)
                     সংখ্যা, মোট আর বাদ পড়ার কারণ দেখায় — সেখান থেকেই আসল অনুমোদন। --}}
                @if ($bulkable !== [])
                    <x-ui.button type="submit" tone="secondary" form="bulk-form" data-bulk-button
                                 data-label="{{ __('approval::action.bulk_picked', ['count' => '__N__']) }}">
                        {{ __('approval::action.bulk_picked', ['count' => 0]) }}
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    {{-- ⓘ অন্যের ইনবক্স দেখার সময় কেবল "অপেক্ষায়" — বাকি তিনটা প্রশ্ন নিজের।
         ⚠️ ট্যাবের সারি দুই কলামের উপরে, পুরো চওড়ায় — 1366-এ মেনু খোলা রেখে তালিকার কলাম (20rem = 400px, মূল অক্ষর 20px) চারটা ট্যাব
         ধরে না, শেষেরটা কেটে পাশে সরতে হত (২৮ অক্টোবর ২০২৬-এর ছবি); পুরো চওড়ায় চারটাই এক সারিতে। --}}
    <x-ui.list-tabs :label="__('approval::field.tabs')" :tabs="collect($tabCounts)->map(fn ($count, $key) => [
        'key' => $key,
        'label' => __('approval::field.tab_'.$key),
        'count' => $count,
        'url' => route('approval.inbox.index', array_filter(['person' => $person ?: null, 'tab' => $key !== 'waiting' ? $key : null])),
        'active' => $tab === $key,
    ])->values()->all()" />

    <div class="grid gap-4 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:items-start">

        {{-- ── বাঁয়ে: ছাঁকনি, কার্ড, মোট ────────────────────────────────── --}}
        <div class="min-w-0" data-inbox-list>

            <form method="GET" action="{{ route('approval.inbox.index') }}" class="mb-3 space-y-2" data-inbox-filters>
                @foreach ($keep as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                @if ($selected !== '')
                    <input type="hidden" name="module" value="{{ $selected }}">
                @endif
                @if ($late)
                    <input type="hidden" name="late" value="1">
                @endif

                {{-- ⭐ কাজের চিপ, সংখ্যাসহ — §২.২; ⓘ যেখানে কিছু নেই সেই চিপ আঁকা হয় না, চিপের সংখ্যা সবসময় পুরো (ছাঁকা) তালিকার --}}
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ __('approval::field.module') }}">
                    @php
                        $chip = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors hover:bg-(--color-surface-hover)';
                        $on = 'border-(--color-brand-500) bg-(--color-surface-selected) text-(--color-ink)';
                        $off = 'border-(--color-border) bg-(--color-surface-card) text-(--color-ink-body)';
                    @endphp

                    <a href="{{ route('approval.inbox.index', $filterKeep) }}" @class([$chip, $selected === '' ? $on : $off])
                       @if ($selected === '') aria-current="true" @endif>
                        {{ __('approval::field.all_work') }} <span class="num text-(--color-ink-muted)">{{ $total }}</span>
                    </a>

                    @foreach ($modules as $code => $one)
                        <a href="{{ route('approval.inbox.index', $filterKeep + ['module' => $code]) }}"
                           @class([$chip, $selected === $code ? $on : $off])
                           @if ($selected === $code) aria-current="true" @endif>
                            {{ $one['label'] }} <span class="num text-(--color-ink-muted)">{{ $one['count'] }}</span>
                        </a>
                    @endforeach

                    {{-- ⭐ দেরির চিপ — সংখ্যা শূন্য হলে চিপটাই নেই ("দেরি ০" চেপে খালি তালিকা পাওয়ার চেয়ে খারাপ কিছু নেই) --}}
                    @if ($lateCount > 0)
                        <a href="{{ route('approval.inbox.index', $late ? $filterKeep + array_filter(['module' => $selected ?: null]) : $filterKeep + array_filter(['module' => $selected ?: null]) + ['late' => 1]) }}"
                           @class([$chip, 'border-(--color-badge-danger-ink) bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $late, $off => ! $late])
                           @if ($late) aria-current="true" @endif data-late-chip>
                            {{ __('approval::field.late') }} <span class="num">{{ $lateCount }}</span>
                        </a>
                    @endif
                </div>

                {{-- ⭐ শাখা · অঙ্ক · সাজানো — দফা ৩; ⓘ বদলালেই ফর্ম জমা পড়ে (`submit-form`), JS বন্ধ থাকলে নিচের বোতাম --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    @php $select = 'h-(--spacing-field-compact) rounded-full border border-(--color-border) bg-(--color-surface-card) px-2.5 text-xs'; @endphp

                    <label class="sr-only" for="inbox-branch">{{ __('approval::field.branch') }}</label>
                    <select id="inbox-branch" name="branch" data-action="submit-form" class="{{ $select }}">
                        <option value="">{{ __('approval::field.all_branches') }}</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}" @selected($branch === $id)>{{ $name }}</option>
                        @endforeach
                    </select>

                    <label class="sr-only" for="inbox-amount">{{ __('approval::field.amount_band') }}</label>
                    <select id="inbox-amount" name="amount" data-action="submit-form" class="{{ $select }}">
                        <option value="">{{ __('approval::field.any_amount') }}</option>
                        @foreach (array_keys(\App\Modules\Approval\Http\Controllers\ApprovalInboxController::AMOUNT_BANDS) as $key)
                            <option value="{{ $key }}" @selected($band === $key)>{{ __('approval::field.band_'.$key) }}</option>
                        @endforeach
                    </select>

                    <label class="sr-only" for="inbox-sort">{{ __('approval::field.sort') }}</label>
                    <select id="inbox-sort" name="sort" data-action="submit-form" class="{{ $select }}">
                        @foreach (\App\Modules\Approval\Http\Controllers\ApprovalInboxController::SORTS as $key)
                            <option value="{{ $key }}" @selected($sort === $key)>{{ __('approval::field.sort_'.$key) }}</option>
                        @endforeach
                    </select>

                    {{-- ⓘ কার ইনবক্স — কেবল `approval.report` আছে যাঁর; "সবাই" বিকল্প নেই, সেটা রিপোর্টের প্রশ্ন --}}
                    @if (count($signers) > 1)
                        <label class="sr-only" for="person">{{ __('approval::field.whose_inbox') }}</label>
                        <select id="person" name="person" data-action="submit-form" class="{{ $select }}">
                            <option value="">{{ __('approval::field.my_inbox') }}</option>
                            @foreach ($signers as $id => $name)
                                <option value="{{ $id }}" @selected($person === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <noscript><button type="submit" class="text-xs underline">{{ __('core.action.apply') }}</button></noscript>
                </div>

                {{-- ⭐ খোঁজা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ([[ApprovalInboxController::searched()]]) --}}
                <label class="block">
                    <span class="sr-only">{{ __('approval::field.search_hint') }}</span>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('approval::field.search_hint') }}"
                           class="h-(--spacing-field-compact) w-full rounded-full border border-(--color-border) bg-(--color-surface-card) px-3 text-xs">
                </label>
            </form>

            <form method="GET" action="{{ route('approval.inbox.bulk_review') }}" id="bulk-form">
                @if ($rows->isEmpty())
                    <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm text-(--color-ink-muted)">
                        {{ $tab === 'waiting' ? __('approval::message.nothing_waiting') : __('approval::message.nothing_in_tab') }}
                    </p>
                @else
                    <ul class="space-y-2" aria-label="{{ __('approval::field.papers_list') }}">
                        @foreach ($rows as $approval)
                            @include('approval::inbox.partials.card', ['approval' => $approval])
                        @endforeach
                    </ul>
                @endif
            </form>

            {{-- ⭐ দুই মোট আর পাতা বদল — দফা ৬ --}}
            <div class="mt-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-inbox-totals>
                <p class="flex justify-between gap-2 border-b border-(--color-border) px-3 py-2 text-sm font-semibold">
                    <span>{{ __('approval::field.page_total', ['count' => $rows->count()]) }}</span>
                    <span class="num" data-page-total>৳{{ \App\Core\Support\Money::format($sumPage) }}</span>
                </p>
                <p class="flex justify-between gap-2 px-3 py-2 text-sm text-(--color-ink-muted)">
                    <span>{{ __('approval::field.all_total', ['count' => $visibleTotal]) }}</span>
                    <span class="num" data-all-total>৳{{ \App\Core\Support\Money::format($sumAll) }}</span>
                </p>
                <x-ui.pager :rows="$approvals" />
            </div>

            @if ($bulkable !== [])
                <p class="mt-2 text-2xs text-(--color-ink-muted)">{{ __('approval::message.bulk_only_paper') }}</p>
            @endif

            {{-- ⭐ ছকের বাইরের অনুমোদন — সবসময় দ্বিতীয় একজনের ([[SignaturesWaitingElsewhere]]); অনুমোদন কাগজের নিজের পাতায় --}}
            @if (($elsewhere ?? []) !== [])
                <section data-boxed data-signatures-elsewhere
                         class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 class="font-semibold">{{ __('approval::message.elsewhere_title') }}</h2>
                    <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('approval::message.elsewhere_hint') }}</p>
                    <ul>
                        @foreach ($elsewhere as $item)
                            <li class="flex flex-wrap items-center justify-between gap-2 border-t border-(--color-border) py-2" data-elsewhere-kind="{{ $item['kind'] }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $item['title'] }}</p>
                                    <p class="text-2xs text-(--color-ink-muted)">
                                        {{ $item['detail'] }} · {{ $item['requested_by'] }}
                                        @if ($item['requested_at'])
                                            · {{ \App\Core\Support\DateFormat::format($item['requested_at']) }}
                                        @endif
                                    </p>
                                </div>
                                <x-ui.button tone="secondary" :href="$item['url']">{{ __('approval::message.elsewhere_open') }}</x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        {{-- ── ডানে: বেছে নেওয়া কাগজ ─────────────────────────────────────────── --}}
        <div class="min-w-0" data-inbox-detail>
            @if ($detail !== null)
                @include('approval::inbox.partials.detail', $detail + [
                    'nextId' => $nextId,
                    'from' => $from,
                    'prevUrl' => $prevUrl,
                    'nextUrl' => $nextUrl,
                ])
            @else
                <p class="rounded-(--radius-card) border border-dashed border-(--color-border) p-6 text-center text-sm text-(--color-ink-muted)">
                    {{ __('approval::message.pick_one') }}
                </p>
            @endif
        </div>
    </div>

    @include('approval::inbox.partials.keys')
</x-layouts.app>
