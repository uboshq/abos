{{-- ⓘ the inbox body — drawn inside the coloured money head ([[money.page]]) in every look, and without it on the board
     (rose, navy, blue — owner, 24 Nov 2026: no big orange ribbon). Variables come from [[index.blade.php]]. --}}
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
    @if (($board ?? false) && in_array($tab, ['sent', 'done'], true))
        {{-- ⓘ the history tabs have their own toolbar below — the board's head row is the title and the tabs alone --}}
        <div class="inbox-board-headrow" data-inbox-headrow>@include('approval::inbox.partials.board-head')</div>
    @elseif (! ($board ?? false))
    <x-ui.list-tabs :label="__('approval::field.tabs')" :tabs="collect($tabCounts)->map(fn ($count, $key) => [
        'key' => $key,
        'label' => __('approval::field.tab_'.$key),
        'count' => $count,
        'url' => route('approval.inbox.index', array_filter(['person' => $person ?: null, 'tab' => $key !== 'waiting' ? $key : null])),
        'active' => $tab === $key,
    ])->values()->all()" />
    @endif

    {{-- ⭐ "আমার পাঠানো" আর "আমি অনুমোদন দিয়েছি" — হিসাবের জায়গা, কাজের নয়: পুরো চওড়া তালিকা, অন্য সব তালিকার একই টুলবার
         (মালিক, ৭ নভেম্বর ২০২৬, নমুনা ২ "ok"; SAP-এর Approval History)। "অপেক্ষায়" আর "অন্যের হয়ে" আগের মতো বাঁয়ে-ডানে। --}}
    @if (in_array($tab, ['sent', 'done'], true))
        @include('approval::inbox.partials.history')
    @else
    {{-- ⭐ অপেক্ষার ট্যাবেও অন্য সব তালিকার একই এক সারির টুলবার — পুরো চওড়ায়, তালিকা আর কাগজের উপরে (মালিক, ৭ নভেম্বর ২০২৬,
         নমুনা ৪ "ok"): + ছাঁকনি · খুঁজুন · সাজাও · ঘনত্ব · কলাম · রপ্তানি · শেয়ার · প্রিন্ট · নতুন করে আনো।
         ⓘ কাজ, দেরি, শাখা, অঙ্ক আর কার ইনবক্স "+ ছাঁকনি"-র ভেতরে; ঠিকানার নাম আগের মতোই (module, late, branch, amount, person, q, sort)।
         ⛔ আগে বাঁয়ের তালিকার মাথায় চিপ, তিনটা ড্রপডাউন আর খোঁজার আলাদা বাক্স — অন্য কোনো তালিকায় এমন নেই। --}}
    @php
        $inboxSorts = collect(\App\Modules\Approval\Http\Controllers\ApprovalInboxController::SORTS)
            ->mapWithKeys(fn ($key) => [$key => __('approval::field.sort_'.$key)])->all();
        $bandNames = collect(array_keys(\App\Modules\Approval\Http\Controllers\ApprovalInboxController::AMOUNT_BANDS))
            ->mapWithKeys(fn ($key) => [$key => __('approval::field.band_'.$key)])->all();
        $field = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
    @endphp
    @if ($board ?? false)
        {{-- ⭐ one head row on the board (owner, 24 Nov 2026: tabs inside the filter bar, no big orange ribbon — "স্ক্রিনের
             জায়গাটা বাড়বে"): coloured title · tabs with counts · the same toolbar as every list. --}}
        <div class="inbox-board-headrow" data-inbox-headrow>
            @include('approval::inbox.partials.board-head')
    @endif
    <form method="GET" action="{{ route('approval.inbox.index') }}" data-inbox-filters
          @class([
              'block overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)',
              'mb-3' => ! ($board ?? false),
              'inbox-board-filters' => $board ?? false,
          ])>
        @if ($tab !== 'waiting')
            <input type="hidden" name="tab" value="{{ $tab }}">
        @endif

        {{-- ⓘ শিরোনাম নেই — ঠিক উপরে ট্যাবের নামই সেটা বলে; দুবার লিখলে তালিকা এক সারি নিচে নামে --}}
        <x-ui.toolbar :quiet="['tab', 'open']"
            :search-placeholder="__('approval::field.search_hint')"
            :sort="$inboxSorts"
            :filter-labels="[
                'module' => __('approval::field.work'),
                'late' => __('approval::field.late'),
                'branch' => __('approval::field.branch'),
                'amount' => __('approval::field.amount_band'),
                'person' => __('approval::field.whose_inbox'),
            ]"
            :filter-values="[
                'module' => collect($modules)->map(fn ($one) => $one['label'])->all(),
                'late' => ['1' => __('approval::field.late')],
                'branch' => collect($branches)->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all(),
                'amount' => $bandNames,
                'person' => collect($signers)->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all(),
            ]">
            {{-- ⓘ কাজ — সংখ্যাসহ, সংখ্যা সবসময় পুরো (ছাঁকা) তালিকার --}}
            <select name="module" aria-label="{{ __('approval::field.work') }}" data-action="submit-form" class="{{ $field }}">
                <option value="">{{ __('approval::field.all_work') }} ({{ $total }})</option>
                @foreach ($modules as $code => $one)
                    <option value="{{ $code }}" @selected($selected === $code)>{{ $one['label'] }} ({{ $one['count'] }})</option>
                @endforeach
            </select>

            {{-- ⓘ দেরি — সংখ্যা শূন্য হলে ঘরটাই নেই, যদি না এখনই বাছা থাকে --}}
            @if ($lateCount > 0 || $late)
                <label class="inline-flex items-center gap-1.5 text-sm" data-late-chip>
                    <input type="checkbox" name="late" value="1" data-action="submit-form" @checked($late)>
                    {{ __('approval::field.late') }} <span class="num text-(--color-ink-muted)">({{ $lateCount }})</span>
                </label>
            @endif

            @if (count($branches) > 1)
                <select id="inbox-branch" name="branch" aria-label="{{ __('approval::field.branch') }}" data-action="submit-form" class="{{ $field }}">
                    <option value="">{{ __('approval::field.all_branches') }}</option>
                    @foreach ($branches as $id => $name)
                        <option value="{{ $id }}" @selected($branch === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            <select id="inbox-amount" name="amount" aria-label="{{ __('approval::field.amount_band') }}" data-action="submit-form" class="{{ $field }}">
                <option value="">{{ __('approval::field.any_amount') }}</option>
                @foreach ($bandNames as $key => $name)
                    <option value="{{ $key }}" @selected($band === $key)>{{ $name }}</option>
                @endforeach
            </select>

            {{-- ⓘ কার ইনবক্স — কেবল `approval.report` আছে যাঁর; "সবাই" বিকল্প নেই, সেটা রিপোর্টের প্রশ্ন --}}
            @if (count($signers) > 1)
                <select id="person" name="person" aria-label="{{ __('approval::field.whose_inbox') }}" data-action="submit-form" class="{{ $field }}">
                    <option value="">{{ __('approval::field.my_inbox') }}</option>
                    @foreach ($signers as $id => $name)
                        <option value="{{ $id }}" @selected($person === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @elseif ($person)
                <input type="hidden" name="person" value="{{ $person }}">
            @endif

            <noscript><button type="submit" class="text-xs underline">{{ __('core.action.apply') }}</button></noscript>
        </x-ui.toolbar>
    </form>
    @if ($board ?? false)
        </div>
    @endif

    {{-- ⭐ the board (rose, navy, blue — [[ApprovalInboxController::BOARD_LOOKS]]): kinds in coloured boxes on the left, the
         paper on the right, each side scrolling on its own (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5). Every other look
         keeps the cards exactly as before. --}}
    <div @class([
            'grid gap-4',
            'lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:items-start' => ! ($board ?? false),
            'inbox-board lg:grid-cols-[minmax(0,1.08fr)_minmax(0,1fr)]' => $board ?? false,
        ]) @if ($board ?? false) data-inbox-board @endif>

        {{-- ── বাঁয়ে: কার্ড, মোট ────────────────────────────────── --}}
        <div @class(['min-w-0', 'max-lg:hidden' => $picked, 'inbox-board-left' => $board ?? false]) data-inbox-list>

            <form method="GET" action="{{ route('approval.inbox.bulk_review') }}" id="bulk-form">
                @if ($rows->isEmpty())
                    <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm text-(--color-ink-muted)">
                        {{ $tab === 'waiting' ? __('approval::message.nothing_waiting') : __('approval::message.nothing_in_tab') }}
                    </p>
                @elseif ($board ?? false)
                    @include('approval::inbox.partials.board-list')
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
        <div @class(['min-w-0', 'max-lg:hidden' => ! $picked, 'inbox-board-right' => $board ?? false]) data-inbox-detail>
            @if ($picked)
                <a href="{{ $backUrl }}" data-inbox-back
                   class="mb-2 inline-flex min-h-(--spacing-touch) items-center gap-1 text-sm text-(--color-link) lg:hidden">
                    <span aria-hidden="true">&lsaquo;</span> {{ __('approval::menu.inbox') }}@if ($at !== false) · <span class="num">{{ $at + 1 }} / {{ $rows->count() }}</span>@endif
                </a>
            @endif
            @if ($detail !== null)
                @include(($board ?? false) ? 'approval::inbox.partials.board-detail' : 'approval::inbox.partials.detail', $detail + [
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
    @endif
