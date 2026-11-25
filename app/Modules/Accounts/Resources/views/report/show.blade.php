{{--
    আটটা রিপোর্টের একটাই পর্দা।

    কলাম, ফিল্টার ও যোগফল সবই সংজ্ঞা থেকে আসে, তাই নবম রিপোর্ট যোগ
    করতে এই ফাইলটা ছুঁতে হয় না — সেটাই Report engine থাকার কারণ
    (সেকশন ২.২)।

    যোগফল পুরো ফলের উপর, এই পাতার নয়। পাতাভিত্তিক যোগফল ভুল উত্তর দেয়,
    আর সেটা ভুল বলে চেনাও যায় না — তাই engine আলাদা কোয়েরিতে গোনে।
--}}
@php
    $money = \App\Core\Engines\Report\ReportColumn::MONEY;
    $date = \App\Core\Engines\Report\ReportColumn::DATE;
    $document = \App\Core\Engines\Report\ReportColumn::DOCUMENT;

    $filters = $result->filters;

    /*
        ⭐ কোন সময়ের সংখ্যা — শিরোনামের পাশে, সবসময় চোখে (পাতা-ঝাড়ু ধাপ ০, ১০ অক্টোবর ২০২৬)। ⛔ তারিখের ঘর "+ ছাঁকনি"-র
        ভেতরে লুকোনো, আর ডিফল্ট এই মাস: আগের মাসের তথ্য থাকলেও রিপোর্ট খালি দেখাত, আর কোন সময়ের তা কোথাও লেখা ছিল না।
    */
    $period = null;
    if ($report->hasFilter('date_range') && ! empty($filters['to'])) {
        $toText = \App\Core\Support\DateFormat::format($filters['to']);
        $period = match (true) {
            $report->isAsOfDate() => __('accounts::field.as_on').': '.$toText,
            ! empty($filters['all_time']) => __('core.report.all_time').' – '.$toText,
            default => \App\Core\Support\DateFormat::format($filters['from']).' – '.$toText,
        };
    }

    /*
        ⛔ শাখায় আটকানো মানুষের তালিকায় কেবল নাগালের শাখা — ইঞ্জিন বাকিগুলো
        ফেরায় (অডিট ২৭ সেপ্টেম্বর, §৩), তাই দেখানো মানে এমন একটা পছন্দ দেওয়া
        যেটা বাছলেই লাল। ⓘ তালিকাটা ইঞ্জিনের নিজের উত্তর (`branch_ids`) থেকে,
        আটটা কন্ট্রোলারে আলাদা করে নয়।
    */
    if (($filters['branch_ids'] ?? null) !== null) {
        $branches = $branches->whereIn('id', $filters['branch_ids'])->values();
    }

    /*
        ⛔ প্রকল্প বাছার তালিকা — পাঠায় কেবল Accounts-এর কন্ট্রোলার।

        ⚠️ ২০ সেপ্টেম্বর ২০২৬: `$centres` যোগ হয়েছিল কেবল ঐ এক
        কন্ট্রোলারে, অথচ এই পর্দাটা **আটটা** কন্ট্রোলার দেখায় —
        Approval, Customer, Inventory, Purchase, Restaurant, Sales,
        Supplier আর Accounts। ফলে লাইভে ৫০টা রিপোর্ট পাতা একসাথে
        ৫০০ দিয়েছিল।

        ⭐ ডিফল্টটা এখানেই, কন্ট্রোলারে নয় — নবম কন্ট্রোলার যোগ হলে
        তাকেও মনে রাখতে হত, আর সাতবার ভুল হওয়া জিনিস অষ্টমবারেও হবে।
    */
    $centres = $centres ?? collect();

    $previous = \App\Core\Engines\Report\ReportEngine::COMPARE_PREVIOUS;
    $lastYear = \App\Core\Engines\Report\ReportEngine::COMPARE_LAST_YEAR;

    /*
        কলাম আসে ব্যবহারকারী ধরে, সংজ্ঞা থেকে সরাসরি নয়।

        ক্রয়মূল্য ও মুনাফার কলামগুলো অনুমতির পেছনে (`permission` ঘরে
        লেখা)। এখানে একবার ছেঁকে নিলে পর্দা, রপ্তানি ও ছাপা তিনটাই একই
        তালিকা পায় — রপ্তানি এই টেবিলটার কলামই ধরে নেয়। তিন জায়গায়
        আলাদা করে লিখলে একদিন একটায় লেখা হত আর বাকি দুইটায় নয়।
    */
    $columns = $result->columnsFor(auth()->user());

    /*
        কলাম লুকানো-দেখানো — তালিকার পর্দাগুলোর মতোই, ১৯ সেপ্টেম্বর ২০২৬।

        ⓘ তালিকায় কাজটা `x-ui.table` করে; এই পর্দা নিজের টেবিল আঁকে, তাই
        একই নিয়ম এখানে: ঠিকানার `?hide=` কলামগুলো বাদ, আর সব বাদ গেলে সবই
        দেখানো (কলামহীন টেবিলের চেয়ে সব কলাম ভালো)। ⚠️ ছাঁকা তালিকাটাই
        রপ্তানিতে যায় — পর্দায় যা, ফাইলেও তা।

        মেনুর জন্য টুলবার পায় পুরো তালিকা (লুকানোগুলোসহ), নাহলে একবার
        লুকানো কলাম আর ফেরানো যেত না।
    */
    $menuColumns = collect($columns)
        ->map(fn ($column) => ['key' => $column->key, 'label' => __($column->label)])
        ->all();

    $hiddenKeys = collect(explode(',', (string) request('hide')))
        ->map(fn ($key) => trim($key))
        ->filter()
        ->all();

    if ($hiddenKeys !== []) {
        $kept = array_values(array_filter($columns, fn ($column) => ! in_array($column->key, $hiddenKeys, true)));

        if ($kept !== []) {
            $columns = $kept;
        }
    }

    /*
        রপ্তানি — ঠিক এই কলামগুলো নিয়েই।

        তালিকার পর্দাগুলোয় কাজটা x-ui.table নিজে করে; এই পর্দাটা নিজের
        টেবিল আঁকে, তাই জমাটা এখানে হাতে দিতে হয়। এতদিন হয়নি, আর
        "Export CSV"-তে ক্লিক করলে একই HTML পাতাই ফিরে আসত।

        উপরের ছাঁকা তালিকাটাই পাঠানো হয়, নতুন করে বাছা হয় না — নাহলে
        পর্দায় ঢাকা একটা সংখ্যা ফাইলে বেরিয়ে যেত।
    */
    \App\Core\Engines\Report\ReportExport::capture($result, $columns);

    /*
        ⭐ সংখ্যা সবসময় ডানে — টাকা, পরিমাণ, শতাংশ, জের, আর যে লেখার কলামের প্রতিটা মান কেবল সংখ্যা ("কয়দিন", "কয়জন")
        (মালিকের অডিট, ২৫ নভেম্বর ২০২৬)। ⓘ কোড, নম্বর, ফোন, বছর সংখ্যা হলেও লেখা — বাঁয়ে।
    */
    $numKeys = \App\Core\Engines\Report\ReportResult::rightAligned($columns, $result->isSplitByBranch()
        ? array_merge([], ...array_map(fn ($s) => $s->rows, $result->sections)) : $result->rows);

    // ⭐ মাথা সবার আগে — ABOS-এর তিন রূপে মোটের ফিতা আর কথার বাক্স মাথার বারের নিচে, নোটিশ ⓘ-তে (মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();

    /*
        ⭐ ডান পাশের ছাঁকনির প্যানেল — রিপোর্ট যা ঘোষণা করে আর পাতা আগে যা আঁকত, ঠিক সেগুলোই ([[ReportPanel]])।
        ⓘ শাখার তালিকা উপরে নাগালে ছাঁটা — তার পরে, তাই প্যানেলেও কেবল নাগালের শাখা।
    */
    $panel = \App\Core\Engines\Report\ReportPanel::build(
        $report, $filters, $accounts, $centres, $branches,
        $partyFilter ?? null, $parties ?? [], $partyTypes, $ownFilters ?? [], request(), $extraFacets ?? [],
    );
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($report->title) }}</x-slot:title>

    {{-- ⭐ যে মডিউল চায় তার রঙে (`pageKind`) — অনুমোদনের রিপোর্ট (মালিক, ৭ নভেম্বর ২০২৬, নমুনা "ok"); বাকি রিপোর্ট আগের মতো --}}
    @isset($pageKind)
        <div data-page-kind="{{ $pageKind }}">
    @endisset

    {{--
        রিপোর্টের নিজের একটা কথা — কেবল যে রিপোর্ট পাঠায়।

        ── কেন এই ঘরটা লাগল ────────────────────────────────────────────
        বেশিরভাগ রিপোর্টের প্রতিটা সারি একটা কাগজ, তাই সারিটা ক্লিকযোগ্য।
        কিছু রিপোর্ট গণনা করে ("রহিম ১২টা অনুমোদন"), আর সেখানে ক্লিক করার
        কিছু নেই। ⚠️ মানুষ তবু ক্লিক করবেন, আর কিছু না হলে ভাববেন পাতাটা
        ভাঙা — **ভাঙা মনে হওয়াটা ভাঙার প্রায় সমান খরচের**।

        তাই সীমাটা লুকানো হয় না, লেখা হয়, আর সাথে কাগজগুলো কোথায় আছে
        সেটাও। ⓘ `@isset` বলে বাকি ছয়টা মডিউলের কিছুই বদলায় না — যে
        পাঠায় না, তার পাতায় ঘরটাই আঁকা হয় না।
    --}}
    {{-- ⭐ এক মডিউলের রিপোর্টগুলো ট্যাবে — যে মডিউল পাঠায় কেবল তার (অনুমোদন: মেনুর ছয় সারি এক সারিতে, অনুমোদন পর্দা ভাগ ২, দফা ২) --}}
    @isset($reportTabs)
        <x-ui.list-tabs :tabs="$reportTabs" :label="__($report->title)" />
    @endisset

    @if (isset($notice) && ! $abosHead)
        <div role="note"
             class="mb-3 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app)
                    px-3 py-2 text-sm text-(--color-ink-muted)">
            {{ $notice }}
        </div>
    @endif

    {{-- ⭐ অঙ্কটা কীভাবে এল — কমিশনের সূত্র, রিপোর্টের নিজের মানে (প্রিন্সিপালের কমিশনের drill; মালিক, ২৪ অক্টোবর ২০২৬) --}}
    @if (! empty($explain) && ! $abosHead)
        <div role="note" data-report-explain
             class="mb-3 space-y-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 py-2 text-sm">
            @foreach ($explain as $line)
                <p class="num">{{ $line }}</p>
            @endforeach
        </div>
    @endif

    {{--
        ফলটা এক লাইনে, সারিগুলোর উপরে — ৭ সেপ্টেম্বর ২০২৬।

        ── ⛔ কী ছিল না ────────────────────────────────────────────────
        লাভ-ক্ষতির পর্দা খাত ধরে ধরে সব দেখাত, আর নিচে লিখত "সর্বমোট
        ২৭,০০০"। ⓘ লাভ হয়েছে না ক্ষতি — কোথাও একটা শব্দও ছিল না।

        ⚠️ অথচ মালিকের কাছে ওটাই একমাত্র প্রশ্ন। ⛔ যে লাভ-ক্ষতি হিসাব
        লাভ কত বলে না, সে হিসাব নয় — সে একটা তালিকা।

        ── কেন উপরে ───────────────────────────────────────────────────
        স্থিতিপত্রেও একই সিদ্ধান্ত, আর কারণটা ওখানে লেখা: *"পুরনো পর্দায়
        সংখ্যাটা নিচে পড়ে থাকত আর কেউ দেখত না।"* ⓘ একই যুক্তি এখানেও —
        একশো সারির নিচে চাপা পড়া উত্তর কোনো উত্তর নয়।

        ⭐ `@isset` বলে বাকি ৩৬টা রিপোর্টের কিছুই বদলায় না।
    --}}
    @if (isset($summary) && ! $abosHead)
        <section data-boxed
                 @class([
                     'mb-4 flex flex-wrap items-center gap-3 rounded-(--radius-card) border px-4 py-3',
                     'border-(--color-border) bg-(--color-badge-success-bg)' => $summary['good'],
                     'border-(--color-danger) bg-(--color-badge-danger-bg)' => ! $summary['good'],
                 ])>
            <span @class(['font-semibold',
                'text-(--color-badge-success-ink)' => $summary['good'],
                'text-(--color-badge-danger-ink)' => ! $summary['good']])>
                {{ $summary['label'] }}
            </span>

            <span class="flex-1"></span>

            {{-- ⭐ বাড়তি অঙ্ক — লাভ-ক্ষতিতে মোট মুনাফা (পুনঃঅডিট, ৯ অক্টোবর ২০২৬; [[CoreReports::profitAndLoss()]]) --}}
            @foreach ($summary['lines'] ?? [] as $line)
                <span class="text-sm" data-summary-line>{{ $line['label'] }}: <span class="num tabular-nums">{{ \App\Core\Support\Money::format($line['value']) }}</span></span>
            @endforeach

            <strong class="num text-lg tabular-nums">
                {{-- ⓘ `text` দিলে সেটাই — খাতার জের "(Dr) 250.79" ([[PartyLedgerReports]]) --}}
                {{ $summary['text'] ?? \App\Core\Support\Money::format($summary['value']) }}
            </strong>
        </section>
    @endif

    {{-- ⭐ ডান পাশের ছাঁকনি — সব তালিকার একই ছাঁচ ([[filter-layout]]); রিপোর্টে "দেখান" চাপলে চলে --}}
    <x-ui.filter-layout :filters="$panel" form="list-form" :report="true">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents" data-report>
            {{-- ⛔ নাগালের বাইরের শাখা চাইলে ইঞ্জিন ফেরায় — কারণটা এখানে দেখা যায়, নীরব ফেরত নয় (অডিট ২৭ সেপ্টেম্বর, §৩)। --}}
            <x-ui.errors />

            {{--
                ⭐ খোঁজার ঘর — ২১ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশ
                *"report e search bar diba"*।

                ⛔ এতদিন এখানে `:search="false"` লেখা ছিল, অর্থাৎ ঘরটা
                ইচ্ছা করে বন্ধ করা ছিল। ⓘ চারশো সারির একটা রিপোর্টে একটা
                নাম খুঁজতে হলে পাতা ধরে ধরে নামা ছাড়া উপায় ছিল না।

                ⚠️ খোঁজাটা পাতার উপর নয়, পুরো ফলের উপর — [[ReportEngine]]
                শব্দটা কোয়েরিতে বসায়, তাই নিচের যোগফল আর সারির সংখ্যাও
                খোঁজা ফলেরই হয়। পাতায় ছাঁকলে দশটা সারির পাশে চারশোর
                যোগফল বসত।
            --}}
            {{-- ⭐ ২৩ নভেম্বর ২০২৬ থেকে "প্রিন্ট ভিউ" = কেন্দ্রীয় কাগজ (`?export=print`, [[ListPaper]]) নতুন ট্যাবে, সব পাতা — পর্দার পাতা
                 নয়; লেজারে লেজারের কাগজ। ⓘ নিচের পুরনো `?print=1` পথ ইঞ্জিনে থাকল (ঠিকানা জানা কেউ এলে সব সারি আঁকে)। --}}
            {{-- ⭐ ছাপা গোটা পরিসর — পর্দায় এক পাতার বেশি সারি থাকলে "ছাপুন" `?print=1`-এ যায়, যেখানে ইঞ্জিন সব সারি আঁকে
                 আর পাতাটা নিজেই ছাপা শুরু করে (মালিক, ৩ অক্টোবর ২০২৬: কাস্টমার লেজার পাতা ধরে ধরে ছাপতে হত;
                 [[ReportEngine::wholeDocumentWanted()]])। ⓘ পুরো পরিসর এক পাতায় থাকলে আগের মতো পর্দাটাই ছাপে। --}}
            <x-ui.toolbar :title="__($report->title)" :report="true" :count="isset($pl) ? null : trans_choice('accounts::message.row_count', $result->totalRows, ['count' => $result->totalRows]).($period !== null && ! ($abosHead && ($panel['date']['text'] ?? null) === $period) ? ' · '.$period : '')"
                          :search="$report->searchableColumns() !== []"
                          :print-href="\App\Modules\Accounts\Reports\PartyLedgerReports::statementHref($report->key, $result->filters)"
                          :print-view="true"
                          :info="$abosHead ? ($notice ?? null) : null"
                          :columns="$menuColumns" :facets="$panel">
                {{--
                    ⭐ ঘরগুলো এখন ডান পাশের ছাঁকনির প্যানেলে (মালিক, ২৩ নভেম্বর ২০২৬; [[ReportPanel]]) — তারিখ, হিসাব, প্রকল্প,
                    রিপোর্টের সাধারণ ছাঁকনি, শাখা, পক্ষ, পক্ষের ধরন, তুলনা, উপরের কয়টা; একই চাবিতে, তাই পুরোনো লিংক অটুট।
                    ⓘ এখানে কেবল রিপোর্টের নিজের বাড়তি অংশ (ট্যাব, মাস) — যে রিপোর্ট পাঠায়।
                --}}
                @isset($extraFilters) @include($extraFilters) @endisset
            </x-ui.toolbar>
        </form>

    @if (! empty($explain) && $abosHead)
        <div role="note" data-report-explain
             class="space-y-1 border-b border-(--color-border) px-4 py-2 text-sm">
            @foreach ($explain as $line)
                <p class="num">{{ $line }}</p>
            @endforeach
        </div>
    @endif
    {{-- ⓘ লাভ-ক্ষতির বিবরণীতে নিট আর মোট মুনাফা ছকেই — একই অঙ্ক দুবার নয় --}}
    @if (isset($summary) && $abosHead && ! isset($pl))
        <section data-report-summary
                 @class([
                     'flex flex-wrap items-center gap-3 border-b px-4 py-2',
                     'border-(--color-border) bg-(--color-badge-success-bg)' => $summary['good'],
                     'border-(--color-danger) bg-(--color-badge-danger-bg)' => ! $summary['good'],
                 ])>
            <span @class(['font-semibold',
                'text-(--color-badge-success-ink)' => $summary['good'],
                'text-(--color-badge-danger-ink)' => ! $summary['good']])>
                {{ $summary['label'] }}
            </span>

            <span class="flex-1"></span>

            {{-- ⭐ বাড়তি অঙ্ক — লাভ-ক্ষতিতে মোট মুনাফা (পুনঃঅডিট, ৯ অক্টোবর ২০২৬; [[CoreReports::profitAndLoss()]]) --}}
            @foreach ($summary['lines'] ?? [] as $line)
                <span class="text-sm" data-summary-line>{{ $line['label'] }}: <span class="num tabular-nums">{{ \App\Core\Support\Money::format($line['value']) }}</span></span>
            @endforeach

            <strong class="num text-lg tabular-nums">
                {{-- ⓘ `text` দিলে সেটাই — খাতার জের "(Dr) 250.79" ([[PartyLedgerReports]]) --}}
                {{ $summary['text'] ?? \App\Core\Support\Money::format($summary['value']) }}
            </strong>
        </section>
    @endif

        {{--
            কতগুলো সারি বাদ পড়ল, সেটা লেখা থাকে।

            চুপচাপ দশটা দেখালে তালিকাটা পড়ে মনে হত ওইটুকুই সব — আর
            "২৪০-এর মধ্যে ১০" আর "১০টা সারি" দুইটা সম্পূর্ণ আলাদা কথা।
            যোগফলের সারিটা কিন্তু পুরো ২৪০-এরই, তাই না লিখলে সংখ্যা দুইটা
            মেলাতে গিয়ে কেউ ভাবত হিসাব ভুল।
        --}}
        @if ($result->isTopOnly())
            <p class="border-b border-(--color-border) bg-(--color-surface-app) px-4 py-2 text-2xs
                      text-(--color-ink-muted)">
                {{ __('core.report.showing_top', [
                    'count' => $result->totalRows,
                    'total' => $result->fullRowCount,
                ]) }}
            </p>
        @endif

        {{--
            ⭐ গ্রাফ — যে রিপোর্ট চায় ([[ReportDefinition::$chart]]), টেবিলের একই সারি থেকে (মালিক, ২৩ নভেম্বর ২০২৬; [[ReportChart]])।
            ⛔ কেবল "ABOS" রূপে (হোমের বোর্ডের একই ছাঁচ, যার নকশা ওই রূপেই) আর কেবল পর্দায় — Excel, কাগজ আর অন্য রূপ হুবহু আগের মতো।
        --}}
        @php
            $reportChart = ! app(\App\Core\Services\ListExport::class)->wanted()
                && \App\Core\Support\Ui::home(\App\Core\Support\LookRegistry::lookFor(\App\Core\Support\LookPreview::orChosen(auth()->user()?->ui))) === 'board'
                ? \App\Core\Engines\Report\ReportChart::for($result, $columns) : null;
        @endphp
        @if ($reportChart !== null)
            <div class="border-b border-(--color-border) p-3" data-report-chart>
                <x-dashboard.board-chart :chart="$reportChart" />
            </div>
        @endif

        {{-- ⭐ যে রিপোর্ট নিজের বিবরণী-টেবিল পাঠায় (রেওয়ামিল: দল, উপমোট, অবস্থার সারি; ১৭ নভেম্বর ২০২৬) — সব সারি এক পাতায়; বাকিরা আগের মতো --}}
        @if (isset($statementTable) && $result->rows !== [])
            @include($statementTable)
        @elseif ($report->transpose && $result->rows !== [])
            {{-- ⭐ পাশ ফেরানো রিপোর্ট — সারিগুলো কলাম ("শাখা ধরে বিক্রয়"; [[ReportDefinition::$transpose]]); বাকিরা আগের মতো --}}
            @include('accounts::report.partials.transposed')
        @elseif ($result->rows === [])
            {{-- ⓘ খালি হলে কোন সময়টা খালি আর কী করতে হবে, বার্তাতেই (১২ অক্টোবর ২০২৬, পাতা-সাজানো ধাপ ০) --}}
            <x-ui.empty-state :message="$period !== null ? __('accounts::message.nothing_in_period', ['period' => $period]) : __($report->hasFilter('date_range') ? 'accounts::message.nothing_in_range' : 'accounts::message.nothing_to_show')" />
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col"
                                    @class([
                                        'num' => isset($numKeys[$column->key]),
                                        'whitespace-nowrap' => $column->type === $date,
                                    ])
                                    @if ($column->width) style="width: {{ $column->width }}" @endif>
                                    {{ __($column->label) }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    {{--
                        ⭐ "সব শাখা"-তে শাখা ধরে ভাগ — মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬:
                        *"সব রিপোর্ট শাখাভিত্তিক আলাদা করে দেখাবে … সাথে Grand Total"*।
                        ⓘ প্রতিটা শাখার মোট ইঞ্জিনের নিজের কোয়েরিতে ([[BranchSection]]),
                        আর নিচের সর্বমোট পুরো নাগালের — যোগ করে বানানো নয়।
                    --}}
                    @if ($result->isSplitByBranch())
                        @foreach ($result->sections as $section)
                            <tbody>
                                <tr>
                                    <th scope="colgroup" colspan="{{ count($columns) }}"
                                        class="bg-(--color-surface-hover) text-start text-sm font-semibold">
                                        {{ $section->branchName }}
                                    </th>
                                </tr>
                                @foreach ($section->rows as $row)
                                    <tr class="transition-colors hover:bg-(--color-surface-hover)" data-report-row>
                                        @foreach ($columns as $column)
                                            <td @class([
                                                'align-middle',
                                                'num' => isset($numKeys[$column->key]),
                                                'whitespace-nowrap' => $column->type === $date,
                                            ])>
                                                @include('accounts::report.partials.cell', [
                                                    'column' => $column,
                                                    'row' => $row,
                                                    'filters' => $filters,
                                                ])
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                @if ($section->rowCount > count($section->rows))
                                    <tr>
                                        <td colspan="{{ count($columns) }}" class="text-sm text-(--color-ink-muted)">
                                            {{ __('core.report.more_in_branch', ['count' => $section->rowCount - count($section->rows)]) }}
                                        </td>
                                    </tr>
                                @endif
                                {{-- ⓘ মোটের লেখা সামনের অঙ্কহীন ঘরগুলো জুড়ে — সরু প্রথম কলামে তিন লাইনে ভাঙত --}}
                                @php
                                    $lead = 0;
                                    foreach ($columns as $column) {
                                        if ($column->total && isset($section->totals[$column->key])) {
                                            break;
                                        }
                                        $lead++;
                                    }
                                @endphp
                                <tr class="font-semibold">
                                    @foreach ($columns as $index => $column)
                                        @continue($index > 0 && $index < $lead)
                                        <td @if ($index === 0 && $lead > 1) colspan="{{ $lead }}" @endif @class(['num' => isset($numKeys[$column->key])])>
                                            @if ($index === 0)
                                                {{-- ⓘ ভাগের নিজের মোটের লেখা থাকলে সেটাই ("দল মোট — করিম"; মালিক, ২৩ নভেম্বর ২০২৬) --}}
                                                @if ($section->totalLabel !== null)
                                                    {{ $section->totalLabel }}
                                                @else
                                                    {{ __($result->report->sections === null ? 'core.report.branch_total' : 'core.report.section_total') }} — {{ $section->branchName }}
                                                @endif
                                            @elseif ($column->total && isset($section->totals[$column->key]))
                                                {{-- ⓘ পরিমাণের মোটও দশমিক ছাড়া ([[ReportResult::number()]]) --}}
                                                {{ \App\Core\Engines\Report\ReportResult::number($section->totals[$column->key], $column) }}{{ $column->type === \App\Core\Engines\Report\ReportColumn::PERCENT ? '%' : '' }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            </tbody>
                        @endforeach
                    @else
                        <tbody>
                            @foreach ($result->rows as $row)
                                <tr class="transition-colors hover:bg-(--color-surface-hover)" data-report-row>
                                    @foreach ($columns as $column)
                                        <td @class([
                                            'align-middle',
                                            'num' => isset($numKeys[$column->key]),
                                            'whitespace-nowrap' => $column->type === $date,
                                        ])>
                                            @include('accounts::report.partials.cell', [
                                                'column' => $column,
                                                'row' => $row,
                                                'filters' => $filters,
                                            ])
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endif

                    <tfoot>
                        <tr>
                            @foreach ($columns as $index => $column)
                                <td @class(['num' => isset($numKeys[$column->key])])>
                                    @if ($index === 0)
                                        {{ $result->isSplitByBranch() ? __('core.report.grand_total') : __('core.print.total') }}
                                    @elseif ($column->total && isset($result->totals[$column->key]))
                                        {{-- ⭐ মোটের অঙ্কও খোলে — সব সারি মিলিয়ে ([[ReportColumn::drillTotalHref()]]; মালিক, ২৪ অক্টোবর ২০২৬) --}}
                                        @php
                                            $grandShown = \App\Core\Engines\Report\ReportResult::number($result->totals[$column->key], $column)
                                                .($column->type === \App\Core\Engines\Report\ReportColumn::PERCENT ? '%' : '');
                                            $grandHref = $column->drillTotalHref($filters);
                                        @endphp
                                        @if ($grandHref !== null)
                                            <a href="{{ $grandHref }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)"
                                               aria-label="{{ $column->drillLabel($grandShown) }}" data-report-drill-total="{{ $column->key }}">{{ $grandShown }}</a>
                                        @else
                                            {{ $grandShown }}
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- ⓘ কাগজে শেষ জের শেষেও — উপরের বাক্সটা কেবল প্রথম পাতায় পড়ে (মালিক, ৩ অক্টোবর ২০২৬) --}}
            @isset($summary)
                <p class="hidden print:block px-4 py-2 text-end font-semibold" data-summary-end>
                    {{ $summary['label'] }}: {{ $summary['text'] ?? \App\Core\Support\Money::format($summary['value']) }}
                </p>
            @endisset

            {{-- ⓘ শাখা ধরে ভাগে প্রতিটা শাখার প্রথম পাতা — পাতা-ভাগ তখন অর্থহীন --}}
            @if (! $result->isSplitByBranch() && $result->totalRows > $result->perPage)
                {{-- পাতা ভাগ করা হলে সেটা বলা দরকার, নাহলে ব্যবহারকারী
                     ভাবত যোগফলটাও শুধু এই পাতার — অথচ ওটা পুরো ফলের --}}
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-(--color-border)
                            px-3 py-2 text-sm">
                    <span class="text-(--color-ink-muted)">
                        {{ __('accounts::message.page_of', [
                            'page' => $result->page,
                            'pages' => (int) ceil($result->totalRows / $result->perPage),
                        ]) }}
                    </span>

                    <span class="flex gap-2">
                        @if ($result->page > 1)
                            <a href="{{ request()->fullUrlWithQuery(['page' => $result->page - 1]) }}"
                               class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                ← {{ __('core.action.more') }}
                            </a>
                        @endif

                        @if ($result->page * $result->perPage < $result->totalRows)
                            <a href="{{ request()->fullUrlWithQuery(['page' => $result->page + 1]) }}"
                               class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                {{ __('core.action.more') }} →
                            </a>
                        @endif
                    </span>
                </div>
            @endif
        @endif
    </div>
    </x-ui.filter-layout>
    @isset($pageKind)
        </div>
    @endisset
</x-layouts.app>
