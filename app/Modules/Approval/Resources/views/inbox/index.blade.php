{{--
    ⭐ অনুমোদনের ইনবক্স — এক পাতায় বাঁয়ে তালিকা, ডানে কাগজ (অনুমোদন পর্দা ভাগ ২; মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬;
    SAP Fiori "My Inbox", পরিকল্পনার পাতা ২)।

    বাঁয়ে: চার ট্যাব আর সংখ্যা (দফা ২) · কাজ, শাখা, অঙ্ক, দেরি আর সাজানো (দফা ৩) · কার্ড — পক্ষ, শেষ সময়, ধাপ (দফা ৫, ৭, ৮) ·
    নিচে এই পাতার মোট, সবগুলোর মোট আর পাতা বদল (দফা ৬)।
    ডানে: বেছে নেওয়া কাগজ ([[partials/detail]]) — অনুমোদন দিলে পরের কাগজ নিজে খোলে (দফা ১)।

    ⛔ আগে: ১১ কলামের টেবিল, ৫০-এ কাটা, মোট নেই; কাগজ খুলতে আলাদা পাতা, সিদ্ধান্তের পরে আবার তালিকায় ফিরে পরেরটা খুঁজতে হত।

    ⭐ ছোট পর্দায় (lg-এর নিচে) আগে তালিকা, কার্ড ছুঁলে কাগজ — একবারে একটা (দফা ১ আর ২১: "ফোনে আগে তালিকা, তারপর বিস্তারিত";
    পরিকল্পনার পাতা ৬)। কাগজের মাথায় "‹ ইনবক্স · ২ / ৩" তালিকায় ফেরায়; অনুমোদনের পরে পরের কাগজ নিজে খোলে, তাই ফোনেও।
    ⛔ আগে ফোনে তালিকার নিচে প্রথম কাগজটা পুরো আঁকা থাকত — কার্ড ছুঁলে পাতা নতুন করে খুলত আর কাগজ খুঁজতে নিচে নামতে হত।
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
        // ⓘ ফোনে কাগজ কেবল তখন, যখন কেউ বেছে খুলেছেন (`?open=`); নিজে বেছে নেওয়া প্রথম কাগজটা বড় পর্দার ডান দিকের জন্য
        $picked = request()->filled('open') && $detail !== null;
        $backUrl = request()->fullUrlWithoutQuery(['open']);
        $summary = $tab === 'waiting'
            ? __('approval::message.inbox_summary', ['count' => $visibleTotal, 'late' => $lateCount, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)])
            : __('approval::message.tab_summary', ['count' => $visibleTotal, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)]);
    @endphp

    {{-- ⭐ রঙিন মাথা — লাইভের মাথাটাই (নাম, এক লাইনের হিসাব, একসাথে অনুমোদন), অনুমোদনের রঙে ([[money.page]]; মালিক, ৭ নভেম্বর ২০২৬:
         "অনুমোদনের ইনবক্স ekhon zemon live e ache temoi thakbe setake just colorfull koro", নমুনা "ok")। --}}
    <x-money.page kind="approval" :title="$person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox')"
        icon="approval" :count="$summary">
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
    <form method="GET" action="{{ route('approval.inbox.index') }}" data-inbox-filters
          class="mb-3 block overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
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

    <div class="grid gap-4 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:items-start">

        {{-- ── বাঁয়ে: কার্ড, মোট ────────────────────────────────── --}}
        <div @class(['min-w-0', 'max-lg:hidden' => $picked]) data-inbox-list>

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
        <div @class(['min-w-0', 'max-lg:hidden' => ! $picked]) data-inbox-detail>
            @if ($picked)
                <a href="{{ $backUrl }}" data-inbox-back
                   class="mb-2 inline-flex min-h-(--spacing-touch) items-center gap-1 text-sm text-(--color-link) lg:hidden">
                    <span aria-hidden="true">&lsaquo;</span> {{ __('approval::menu.inbox') }}@if ($at !== false) · <span class="num">{{ $at + 1 }} / {{ $rows->count() }}</span>@endif
                </a>
            @endif
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
    @endif
    </x-money.page>
</x-layouts.app>
