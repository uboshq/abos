@props([
    'search' => true,
    'searchPlaceholder' => null,
    'filter' => true,
    'sort' => [],
    'view' => false,
    'density' => true,
    /**
     * The screen's columns, as the table declares them — [['key' => …, 'label' => …], …].
     *
     * Given, the Columns button appears and hides the ones unticked. Omitted,
     * it does not: a Columns menu that lists nothing is the dead button this
     * toolbar had six of.
     */
    'columns' => [],
    'export' => true,
    'share' => true,
    'print' => true,
    'refresh' => true,

    /*
     * ঠিকানার যে চাবিগুলো ছাঁকনি নয়, দৃশ্যের অবস্থা — যেমন ট্যাব (`tab`)।
     *
     * ── কেন লাগল, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
     * টুলবার অচেনা প্রতিটা চাবিকে সরানো-যায় এমন চিপ বানায় (নিচে
     * `$screenFilters`)। ⚠️ মূলধনের "মালিক ও বিনিয়োগকারী" ট্যাবে তাই
     * "owners" লেখা একটা চিপ উঠত, আর দাবির পাতায় "all" — কাঁচা শব্দ,
     * আর ক্লিক করলে ট্যাবটাই হারাত। ⓘ পাতা নিজে বলে দেয় কোনগুলো চুপ থাকবে।
     */
    'quiet' => [],

    /*
     * ঘরের ডিফল্ট মান — [চাবি => মান], যেমন গ্রাহকের তালিকায় ['status' => 'active']।
     *
     * ── কেন লাগল, ২৪ অক্টোবর ২০২৬ (মালিক, ছবিসহ) ────────────────────
     * খোঁজায় কেবল "kona" লিখলেন, আর পুরো ছাঁকনির প্যানেল খুলে গেল, সঙ্গে "অবস্থা: চালু" চিপ — *"শুধু kona লিখতে কী
     * হলো?"* খোঁজা পুরো ফর্ম জমা দেয়, আর তাতে ডিফল্ট মানে বসে থাকা ঘরও যায় (লুকানো `status=active`)। টুলবার সেটাকে
     * চালু ছাঁকনি ধরত। ⓘ ডিফল্ট মানে থাকা ঘর ছাঁকনি নয়: প্যানেল খোলে না, চিপ হয় না; ডিফল্ট ছাড়া অন্য মান হলে তবেই।
     * `quiet`-এর মতো চিরকাল চুপ নয় — "নিষ্ক্রিয়" বাছলে সেটা সত্যিকারের ছাঁকনি, প্যানেল খোলে।
     */
    'defaults' => [],

    /*
     * পর্দার শিরোনাম ও গোনা — এখন টুলবারের নিজের।
     *
     * ── কেন এগুলো এখানে এল ───────────────────────────────────────────
     * আগে শিরোনামটা থাকত `x-ui.page-header`-এ, টুলবারের **উপরে**, আর
     * সাথে পাতার নিজের প্যাডিং। ফলে তালিকায় পৌঁছাতে চারটা স্তর পার
     * হতে হত: ব্রেডক্রাম্ব → শিরোনাম → উপশিরোনাম → ছাঁকনির কার্ড →
     * টেবিলের হেডার। ছক শুরু হত উপর থেকে প্রায় ৫০০px-এ।
     *
     * D365-এ গ্রিড শুরু হয় ~১২০px-এ: কমান্ড বার আর দৃশ্যের সারি —
     * দুইটাই সরু, আর দুইটার পরেই তথ্য।
     *
     * শিরোনামটা টুলবারে আনায় স্তর দুইটা এক হলো, আর তালিকা অনেকটা
     * উপরে উঠে এল।
     */
    'title' => null,
    'subtitle' => null,
    // ⭐ পাতার ব্যাখ্যা — শিরোনামের পাশে ⓘ-এ ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত: শিরোনামের নিচে ব্যাখ্যার লাইন নয়)
    'info' => null,
    'count' => null,

    /**
     * এই পর্দার ছাঁকনির ঘরগুলোর মানুষের-ভাষার নাম — ['warehouse_id' => 'গুদাম', …]।
     *
     * ── কেন লাগল ────────────────────────────────────────────────────
     * সক্রিয় ছাঁকনির চিপে এতদিন উঠত query-র **কাঁচা মান**, আর এই রিপোর
     * সবচেয়ে সাধারণ ছাঁকনি একটা চেকবক্স — তাই চিপে লেখা থাকত "1"।
     * একটা চিপ যেটা বলে "1" তথ্য নয়, ধাঁধা।
     *
     * ⓘ ছয়টা সাধারণ নাম (cancelled · inactive · from · to · user · only)
     * টুলবার নিজেই চেনে ([[core.toolbar.filter_names]]) — পঞ্চাশটা ঘরের
     * সাঁইত্রিশটা ওতেই ঢাকা পড়ে। এই ঘরটা কেবল বাকিগুলোর জন্য।
     *
     * ⚠️ না দিলে আজকের আচরণ অটুট: নাম অজানা থাকলে চিপে মানটাই ওঠে।
     * ⛔ অজানা নাম থেকে একটা লেবেল **বানানো হয় না** — `warehouse_id`-কে
     * "Warehouse id" লিখলে বাংলা পর্দায় ইংরেজি শব্দ বসত, আর সেটা
     * মালিকের ভাষার নিয়ম ভাঙত। অনুবাদ অনুমান করা যায় না।
     */
    'filterLabels' => [],

    /*
     * ⭐ চিপে মানের নাম — চাবি → [মান → লেখা] (মালিক, ১ অক্টোবর ২০২৬)।
     * ⓘ এরিয়া বা পয়েন্টের ঠিকানায় আইডি থাকে; চিপে "এরিয়া: 12" কেউ পড়তে পারত না। না দিলে মানটাই ওঠে।
     */
    'filterValues' => [],

    /*
     * ছাপার আগে যে ঠিকানায় যেতে হবে — খতিয়ানের জন্য।
     *
     * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * *"Transactions dekhar somoy ajker date sobar upore … but print er
     * somoy ba printe dile bank er moto ledger dekhabe"* — পর্দায় নতুন
     * আগে, কাগজে পুরনো আগে।
     *
     * ⛔ সাধারণ ছাপার বোতামটা পর্দায় যা আছে তা-ই ছাপে, তাই খতিয়ানের
     * বেলায় সে **উল্টো ক্রমে** ছাপত। ⓘ ঠিকানা দিলে বোতামটা লিংক হয়ে
     * যায়: আগে সঠিক ক্রমে পাতাটা আসে, তারপর ছাপা শুরু হয়।
     *
     * ⚠️ না দিলে আচরণ আগের মতোই — পর্দারটাই ছাপে।
     */
    'printHref' => null,

    /*
     * ⭐ ছাঁকনি ইঞ্জিনের তালিকা — [[Panel::of()]]-এর তথ্য (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত"*)।
     * ⓘ দিলে "+ Filter" বোতামটা বাঁ পাশের প্যানেল খোলে/ভাঁজ করে ([[filter-layout]]), পাশে চালু ছাঁকনির সংখ্যা; চিপ আসে ইঞ্জিন
     *   থেকে (নামসহ, একাধিক টিক আলাদা চিপ)। না দিলে আগের মতো — পর্দার নিজের ঘর টুলবারের নিচে।
     */
    'facets' => null,

    /*
     * ⭐ রিপোর্টের টুলবার (মালিক, ২৩ নভেম্বর ২০২৬: *রিপোর্ট ঘর বদলালে বা তারিখ বাছলেই চলবে না — "দেখান" বা Enter*)।
     * ⓘ `true` হলে ঘর বদলালে জমা নেই (ছাঁকনির সারি আর সাজানো), টাইপের সাথে খোঁজা নেই, আর ছাঁকনির বোতাম "দেখান"।
     *   তালিকায় (`false`) আগের মতো — বদলালেই ছাঁকে।
     */
    'report' => false,
])

{{--
    One Toolbar Standard — সেকশন ১৫.২৪।

    সব গ্রিড ও রিপোর্টে হুবহু একই টুলবার, একই ক্রমে। প্রতিটা স্ক্রিনে আলাদা
    করে বানালে একটায় Sort বাঁয়ে আর অন্যটায় ডানে চলে যেত, আর ব্যবহারকারীকে
    প্রতিটা স্ক্রিন আলাদা করে শিখতে হত।

    যে বোতাম এই স্ক্রিনে অর্থহীন সেটা বাদ দেওয়া যায় (:print="false"), কিন্তু
    নতুন বোতাম এখানেই যোগ করতে হবে — স্ক্রিনে নয়।

    ক্রমটা ব্যবহারকারীর দেওয়া নমুনা অনুযায়ী: বাঁয়ে Filter By, তারপর খোঁজার
    ঘর, তারপর Sort by, আর ডান প্রান্তে View।

    ── আগে এখানে ছয়টা মৃত বোতাম ছিল ────────────────────────────────────
    Filter · Columns · Density · Export · Print · Refresh — ছয়টাই ছিল খালি
    <button>, কোনো আচরণ ছাড়া। দেখে মনে হত কাজ করে, ক্লিক করলে কিছুই হত না।
    মেনুর মৃত সারিগুলোর মতোই এটা সবচেয়ে খারাপ ধরনের স্টাব: কাজটা আছে বলে
    দেখায়।

    এখন যা আছে তার প্রতিটাই সত্যিই কিছু করে। Columns ও Export সরানো হয়েছে —
    ওগুলো এখনো তৈরি হয়নি, আর তৈরি না হওয়া জিনিস দেখানোর চেয়ে না দেখানোই
    সৎ। যেদিন তৈরি হবে সেদিন এখানেই ফিরবে।
--}}
@php
    /*
        রপ্তানি বন্ধ মানে সত্যিই বন্ধ।

        বোতামটা লুকানো যথেষ্ট নয় — ঠিকানায় `?export=csv` লাগিয়ে দিলে
        ফাইল ঠিকই নামত। সুইচ যদি কেবল আড়াল হয়, তবে সেটা না থাকার
        চেয়ে খারাপ: পর্দা দেখে সবাই ধরে নেয় পথটা বন্ধ।
    */
    if (! $export) {
        app(\App\Core\Services\ListExport::class)->refuse();
    }
@endphp

@php
    // টুলবার সবসময় একটা GET ফর্মের ভেতরে বসে, তাই প্রতিটা নিয়ন্ত্রণ
    // ফর্মটা জমা দিলেই কাজ করে — আলাদা JavaScript লাগে না।
    /*
     * ⛔ ঠিকানার মান অ্যারেও হতে পারে (`?q[]=x`, `?sort[]=x`) — তখন ঘরে ছাপতে গিয়ে পাতা ৫০০ দিত। লেখা না হলে খালি
     * (১১ অক্টোবর ২০২৬; রুট-তালিকা ভাগের টুলবারে আসার পর [[ARouteHadDealersButNoBooksTest]] ধরল)।
     */
    $searched = is_string(request('q')) ? request('q') : '';
    $currentSort = is_string(request('sort')) ? request('sort') : '';
    $currentView = request('view') === 'grid' ? 'grid' : 'list';
    $isCompact = \App\Core\Support\Ui::compact();
    $hasFilters = $filter && trim($slot->toHtml()) !== '';

    /*
     * ছাঁকনির পটিটা শুরুতেই খোলা থাকবে কি না।
     *
     * ── কেন এটা রূপের সিদ্ধান্ত ──────────────────────────────────────
     * Fiori-র তালিকার পর্দায় ছাঁকনি লুকানো থাকে না — উপরে একটা স্থায়ী
     * পটি, তার নিচে ছক। ব্যবহারকারী আগে ছাঁকনি ভরেন, তারপর তালিকা
     * দেখেন। ওই ক্রমটাই SAP চেনার সবচেয়ে বড় সূত্র, আর সেটা রং দিয়ে
     * আনা যায় না — ওটা Alpine-এর প্রাথমিক অবস্থার কথা।
     *
     * বাকি ন'টা রূপে ছাঁকনি বোতামে খোলে, কারণ ওখানে জায়গাটা তালিকার।
     *
     * ── আগে থেকে চালু ছাঁকনি থাকলে সব রূপেই খোলা ─────────────────────
     * নিচের শর্তটা `||` — কেউ ছাঁকনি দেওয়া একটা লিংক খুললে প্যানেলটা
     * এমনিতেই খোলা থাকে, নাহলে "সারিগুলো কম কেন" প্রশ্নের উত্তর পর্দায়
     * থাকত না। রূপের নিয়মটা ওটার বদলে বসে না, সাথে যোগ হয়।
     */
    $shellLook = \App\Core\Support\LookRegistry::lookFor(
        \App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)
    );

    $filterMode = \App\Core\Support\Ui::filters($shellLook);

    $filtersAlwaysOpen = $filterMode === 'bar';

    /*
     * চিপে কী লেখা উঠবে।
     *
     * ── তিনটা আলাদা ঘটনা, তিন রকম লেখা ──────────────────────────────
     *   চেকবক্স (`cancelled=1`)   → শুধু নাম          "বাতিলসহ"
     *   বাছাই/তারিখ               → নাম **ও** মান     "থেকে: ০১/০৯/২০২৬"
     *   নাম অজানা                 → শুধু মান          (আজকের আচরণ)
     *
     * ⚠️ দ্বিতীয়টায় মানটা বাদ দেওয়া যায় না। "অবস্থা" লিখলে ব্যবহারকারী
     * জানেন একটা অবস্থার ছাঁকনি চালু, কিন্তু **কোনটা** তা জানেন না — একটা
     * ধাঁধার বদলে আরেকটা ধাঁধা।
     *
     * ⚠️ তারিখটা কোম্পানির নিজের ছকে যায় ([[DateFormat::format]]), কারণ
     * `2026-09-01` কাঁচা মানটা কেউ পড়ে না — ওই সহায়কটাই ১১০ জায়গায়
     * ব্যবহৃত, আর এটা ১১১তম।
     */
    $chipText = function (string $key, $value) use ($filterLabels, $filterValues): string {
        $value = is_array($value) ? implode(', ', $value) : (string) $value;
        $value = (string) ($filterValues[$key][$value] ?? $value);

        $names = (array) __('core.toolbar.filter_names');
        $name = $filterLabels[$key] ?? ($names[$key] ?? null);

        if ($name === null) {
            return $value;
        }

        // চালু চেকবক্স — মানটা ("1") দেখানোর কিছু নেই
        if ($value === '1') {
            return $name;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            $value = \App\Core\Support\DateFormat::format($value);
        }

        return $name.': '.$value;
    };

    /*
     * সংরক্ষিত দৃশ্যের নিয়ন্ত্রণটা কোথায় বসে।
     *
     * `dropdown` — শিরোনামটাই বোতাম (D365)। `title` — শিরোনাম কেবল
     * শিরোনাম, আর বোতামটা ডানের নিয়ন্ত্রণগুলোর সাথে।
     *
     * জিনিসটা দুই ক্ষেত্রেই এক (`x-ui.view-menu`); কেবল জায়গা আলাদা —
     * ঠিক যেভাবে `stage-strip` D365-এ শেভরন আর Fiori-তে টালি হয়।
     */
    $viewMode = \App\Core\Support\Ui::views($shellLook);

    /*
     * Which columns are showing.
     *
     * In the query string, not in a cookie or a table of preferences: it
     * travels with the link. Somebody who hides four columns and sends the
     * page to a colleague sends what they are looking at, which is the whole
     * reason they sent it. A saved preference would have shown the colleague
     * their own columns instead, and the two would have argued about a
     * screenshot.
     *
     * Nothing chosen means everything, so a fresh link is the full table.
     */
    $columnKeys = collect($columns)->pluck('key')->filter()->values();
    $hiddenColumns = collect(explode(',', (string) request('hide')))
        ->map(fn ($k) => trim($k))->filter()->values();

    /* The current query, minus the page number — every menu below builds a
       link from it, and carrying page 3 into a CSV of the whole list is how
       an export quietly comes back short. */
    $params = collect(request()->query())->except('page')->all();
    $shareUrl = url()->current().'?'.http_build_query($params);

    /*
     * প্যানেলটা খোলা থাকবে কি না।
     *
     * আগে এখানে ঘরের নামের একটা তালিকা ছিল — from, to, branch_id, status।
     * ফলে যে পর্দা অন্য নামের ছাঁকনি ব্যবহার করত (অডিটে user, action,
     * module) সেখানে ছাঁকনি দেওয়ার পরেও প্যানেলটা বন্ধ হয়ে ফিরত, আর
     * ব্যবহারকারী দেখতেন তালিকা ছোট হয়ে গেছে কিন্তু কেন তা দেখতে পেতেন
     * না — ছাঁকনিটা তুলবেন কী করে সেটাও নয়।
     *
     * তাই নাম ধরে নয়, বাদ দিয়ে: টুলবারের নিজের ঘরগুলো (খোঁজা, সাজানো,
     * দৃশ্য, ঘনত্ব, পাতা, কলাম) ছাড়া ঠিকানায় আর কিছু থাকলেই সেটা
     * স্ক্রিনের ছাঁকনি, আর প্যানেলটা খোলা থাকে।
     */
    $ownKeys = ['q', 'sort', 'view', 'compact', 'page', 'hide', 'show', 'export', ...$quiet];
    $screenFilters = collect(request()->query())
        ->except($ownKeys)
        ->filter(fn ($value) => $value !== '' && $value !== null)
        // ⓘ ডিফল্ট মানে থাকা ঘর ছাঁকনি নয় (`defaults`-এর মন্তব্য দেখুন)
        ->reject(fn ($value, $key) => array_key_exists($key, $defaults) && ! is_array($value)
            && (string) $value === (string) $defaults[$key]);

    /*
     * ⭐ কাগজের মাথা — তালিকার নাম আর "যা দেখানো হচ্ছে" (চালু ছাঁকনি, খোঁজা, বারের তারিখ) ([[ListPaper]], ১৬ নভেম্বর ২০২৬)।
     * ⓘ কেবল কাগজ চাইলে; রিপোর্ট নিজের নাম আর সময়কাল পরে বসিয়ে দেয় ([[ReportExport::capture()]])।
     */
    $exporter = app(\App\Core\Services\ListExport::class);
    if ($exporter->isPaper()) {
        $facts = $facets !== null
            ? array_column($facets['chips'], 'text')
            : $screenFilters->map(fn ($value, $key) => $chipText((string) $key, $value))->values()->all();
        if (($facets['date']['text'] ?? null) !== null) {
            array_unshift($facts, $facets['date']['label'].': '.$facets['date']['text']);
        }
        if ($searched !== '') {
            $facts[] = __('core.action.search').': '.$searched;
        }
        $exporter->describe(['title' => is_string($title) ? $title : null, 'facts' => $facts]);
    }
@endphp

{{-- flex-col — দুইটা সারির ক্রম CSS ঠিক করে, তাই `order-1` ও `order-2`
     কাজ করে (নিচের ব্যাখ্যা দেখুন) --}}
{{-- দুইটা `class` অ্যাট্রিবিউট লেখা যাবে না — ব্রাউজার প্রথমটাই নেয়
     আর দ্বিতীয়টা চুপচাপ ফেলে দেয়। একবার ঠিক এভাবেই টুলবারের জমিন
     উধাও হয়েছিল, আর পর্দায় সেটা ধরা পড়েনি; computed value পড়ে ধরা
     পড়েছে। নামটা তাই merge-এর ভেতরেই। --}}
<div x-data="{ filtersOpen: {{ $hasFilters && ($filtersAlwaysOpen || $screenFilters->isNotEmpty()) ? 'true' : 'false' }} }"
     {{ $attributes->merge(['class' => 'toolbar-view flex flex-col border-b border-(--color-border) bg-(--color-toolbar)']) }}>

    {{--
        দৃশ্যের সারি — শিরোনাম · গোনা · ছাঁকনি · খোঁজা · সাজানো।

        ⓘ ১৯ সেপ্টেম্বর ২০২৬ থেকে শিরোনাম নিজের লাইনে, আর বাকিটা কমান্ড
        বারের সাথে এক লাইনে — নিচের ⭐ মন্তব্য। নিচের ইতিহাসটা থাকল, কারণ
        "HTML নয়, CSS-এর ক্রম" যুক্তিটা এখনো একই।

        `order-2` — এই ব্লকটা লেখা আছে আগে, কিন্তু দেখা যায় পরে।

        ── কেন CSS-এর ক্রম, HTML-এর নয় ────────────────────────────────
        ডানের সরঞ্জামগুলো (দৃশ্য, ঘনত্ব, কলাম, রপ্তানি, শেয়ার, ছাপা,
        রিফ্রেশ) প্রায় আড়াইশো লাইনের একটা ব্লক, আর ওগুলোর জায়গা
        কমান্ড বারে — এই সারির আগে। ব্লকটা হাতে তুলে উপরে বসাতে গেলে
        ভেতরের Alpine স্টেট, x-cloak আর ড্রপডাউনের সম্পর্কগুলো ছুঁতে
        হত, আর তার একটাও ভাঙলে বোতাম দেখতে ঠিকই থাকত অথচ কাজ করত না।

        `order` দিয়ে সরালে HTML অটুট থাকে, কেবল দেখার ক্রম বদলায় —
        আর ট্যাব-অর্ডার HTML-এর ক্রমই মানে, তাই কীবোর্ডে শিরোনাম-ঘর
        আগে আসে, যা ঠিকই আছে।
    --}}
    {{--
        ⭐ দুই লাইন — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬।

        *"Invoice List — The invoice posts income… এগুলো প্রথমে দিয়ে, নিচে
        বক্সের ভিতরে সব এক লাইনে দাও। + Filter প্রথম — শিরোনামের পর ২য়
        লাইনে প্রথম।"*

        ⓘ তাই: ১ম লাইনে শিরোনাম ও বর্ণনা, আর ডানে পাতার বোতাম ("নতুন …" —
        মালিক: *"শিরোনামের ডানে create বোতাম"*); ২য় লাইনে Filter → চালু
        ছাঁকনি → খোঁজা → Sort → ডানে দৃশ্য · ঘনত্ব · কলাম · রপ্তানি · শেয়ার ·
        ছাপা · রিফ্রেশ। ছোট পর্দায় লাইনটা ভেঙে নিচে নামে (flex-wrap)।

        ⚠️ নিচের দুইটা পুরনো সারি HTML-এ যেখানে ছিল সেখানেই — `contents`
        হয়ে এক লাইনের ধারকের ভেতরে গলে যায়, আর ক্রমটা প্রতিটা জিনিসের নিজের
        `order`। ⓘ কারণটা নিচের পুরনো মন্তব্যেরই: ডানের সরঞ্জামের ব্লকে
        Alpine-এর অবস্থা আর ড্রপডাউন — হাতে সরালে বোতাম দেখতে ঠিক থাকত
        অথচ কাজ করত না।
    --}}
    @if ($title || isset($actions))
    <div class="order-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-3 pt-2 pb-1.5">

        {{-- দৃশ্যের শিরোনাম — নাম, আর পাশে কত সারি।

             ── এখানে একটা মৃত বোতাম ছিল ────────────────────────────────
             আগে শিরোনামের পাশে একটা `▾` চিহ্ন আঁকা হত, আর পাশের মন্তব্যে
             লেখা ছিল "চিহ্নটা বলে এটা একটা দৃশ্য"। কিন্তু ওর পেছনে কোনো
             মেনু ছিল না — দশটা রূপের একটাতেও ক্লিক করে কিছু হত না।

             এখন D365-এ শিরোনামটাই সত্যিকারের ড্রপডাউন (`x-ui.view-menu`),
             আর বাকি ন'টায় চিহ্নটা একেবারেই নেই — শিরোনাম কেবল শিরোনাম,
             আর দৃশ্য বাছার বোতামটা নিচে ডান দিকের নিয়ন্ত্রণগুলোর সাথে। --}}
        @if ($title)
            {{-- ⓘ পাতায় প্রথম শিরোনাম h1, পরেরটা h2 — page-header আগে থাকলে এটা h2 ([[PageHeading]]) --}}
            {{-- ⛔ এক-লাইনের রূপ নয়, পুরো ব্লক — Blade আগে সব "php … endphp" জোড়া তুলে রাখে, আর এক-লাইনেরটা থেকে শুরু করে
                 পরের endphp পর্যন্ত গিলে ফেলত (মাঝের সব if সহ): টুলবারওয়ালা প্রতিটা পাতা ৫০০ (cbws2-এর Mac দৌড়, ২০ অক্টোবর ২০২৬) --}}
            @php
                $headingTag = \App\Core\Support\PageHeading::tag();
            @endphp
            @if ($viewMode === 'dropdown')
                <{{ $headingTag }} class="contents">
                    <x-ui.view-menu :label="$title" mode="heading" /><x-ui.page-info :text="$info" />
                </{{ $headingTag }}>
            @else
                <{{ $headingTag }} data-view-selector
                    class="shrink-0 truncate text-lg font-semibold text-(--color-ink)">
                    {{ $title }}<x-ui.page-info :text="$info" />
                </{{ $headingTag }}>
            @endif

            @if ($count !== null || $subtitle)
                {{-- ⛔ `shrink-0` নয় — কিছু পাতা গোনার জায়গায় পুরো বাক্য পাঠায় ("কোন কাজে কে কত টাকা থেকে … · ৫টা নিয়ম চালু"), আর না-ভাঙা
                     বাক্য ফোনে পাতাকেই পাশে ঠেলত (অনুমোদনের নিয়মের পাতা ৩৯০-এ ৪৪৩px চওড়া, ৩০ অক্টোবর ২০২৬, দফা ২১)। ⓘ সারিটা
                     flex-wrap, তাই ছোট সংখ্যা জায়গা না পেলে আগে পরের লাইনে নামে, ভাঙে না। --}}
                <span data-record-count data-list-part="count"
                      class="tabular min-w-0 border-s border-(--color-border) ps-3 text-sm
                             text-(--color-ink-muted)">{{ $count ?? $subtitle }}</span>
            @endif
        @endif

        {{--
            ⭐ পাতার বোতাম ("নতুন আদেশ", "নতুন ডিলার"…) — শিরোনামের ডানে।

            মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬: *"যেখানে create বোতাম আছে,
            সেখানে শিরোনামের ডানে create বোতাম রাখো।"* ⓘ আগে এটা কমান্ড
            বারের বাঁ প্রান্তে, শিরোনামের উপরের সারিতে বসত।

            cmd-actions — এখানকার বোতামগুলো ৩২px, ফর্মের ৪৮px নয়।
            নিয়মটা app.css-এ, আর কেন স্তরের বাইরে তা ওখানে লেখা।
        --}}
        @isset($actions)
            {{-- ⓘ ms-auto নয় — বোতাম শিরোনামের **পাশেই**, দূরে ডান প্রান্তে নয় (মালিক
                 চান বোতাম আর শিরোনাম একসাথে — abos-8b-এর কাছে পাঠানো দাগানো ছবিতেও) --}}
            <div data-command-bar class="cmd-actions flex flex-wrap items-center gap-1">
                {{ $actions }}
            </div>
        @endisset
    </div>
    @endif

    {{-- ২য় লাইন — বক্সের ভেতরে, সব এক লাইনে (উপরের মন্তব্য)। --}}
    <div class="order-2 flex flex-wrap items-center gap-2 border-t border-(--color-border) px-2 py-1.5">
    <div class="contents">

        {{--
            সক্রিয় ছাঁকনিগুলো — সরানো-যায় এমন পিল।

            ── কেন এটা সব রূপেই থাকে, কেবল Odoo-তে নয় ──────────────────
            ওডুর চিহ্ন হল এই চিপগুলো খোঁজার ঘরের ভেতরে বসে। কিন্তু
            "কোন ছাঁকনি এখন চালু আছে" — এটা সাজসজ্জা নয়, তথ্য।

            আগে ছাঁকনি চালু থাকলে তালিকা ছোট হয়ে যেত আর পর্দায় কোথাও
            লেখা থাকত না কেন। মানুষ তখন ভাবতেন সারিগুলো হারিয়ে গেছে।
            ওই বিভ্রান্তিটা বাকি সাত রূপেও একইভাবে ঘটত, তাই চিপগুলো
            সবার জন্য — **চেহারাটা** রূপ ধরে বদলায়, থাকা-না-থাকা নয়।

            ফুটারের ব্যাকআপ-সতর্কতার মতোই যুক্তি: যেটা ব্যবসার দরকার,
            সেটা নকলের জন্য বাদ যায় না।
        --}}
        @if ($facets !== null)
            {{-- ⓘ ছাঁকনির বোতাম আর চিপ — টুলবার ছাড়া পাতার সরু বারেও একই ([[filter-bar]]) --}}
            @include('components.ui.filter.toggle', ['facets' => $facets, 'filterMode' => $filterMode])
        @elseif ($screenFilters->isNotEmpty())
            <span class="order-2 flex flex-wrap items-center gap-1">
                @foreach ($screenFilters as $key => $value)
                    <a data-facet
                       href="{{ url()->current().'?'.http_build_query(collect(request()->query())->except([$key, 'page'])->all()) }}"
                       class="inline-flex items-center gap-1.5 rounded-(--radius-pill)
                              bg-(--color-surface-selected) px-2.5 py-0.5 text-2xs font-medium
                              text-(--color-ink-body) transition-colors
                              hover:bg-(--color-surface-hover)"
                       title="{{ __('core.toolbar.remove_filter') }}">
                        {{--
                            ⚠️ এই লাইনটা **দশটা সাজেই** বদলেছে (৪ সেপ্টেম্বর ২০২৬),
                            আর সেটা ইচ্ছাকৃত।

                            "ন'টা সাজ ছোঁয়া যাবে না" নিয়মটা **চেহারার** কথা বলে,
                            বাগের নয়। কেউ Odoo-র রূপ বেছে নিলে তাঁর চিপে "1" ওঠা
                            Odoo-র চেহারা নয় — ওটা আমাদের বাগ, তাঁর রূপের গায়ে।

                            ⓘ চিপগুলো এই ফাইলে সব রূপেই আছে, আর তার কারণও উপরে
                            লেখা: "চেহারাটা রূপ ধরে বদলায়, থাকা-না-থাকা নয়।"
                            লেখাটা পড়া না গেলে থাকা-না-থাকার প্রশ্নই ওঠে না।
                        --}}
                        <span class="truncate">{{ $chipText($key, $value) }}</span>
                        <svg viewBox="0 0 24 24" class="size-3 shrink-0 fill-current opacity-60"
                             aria-hidden="true">
                            <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                        </svg>
                    </a>
                @endforeach
            </span>
        @endif

        {{-- Filter By — লেখা সহ, বাঁ প্রান্তে।

             স্ক্রিনের নিজস্ব ফিল্টার না থাকলে বোতামটাও থাকে না: যে বোতাম
             খালি প্যানেল খোলে সেটাও একটা মৃত বোতাম। --}}
        @if ($hasFilters)
            <button type="button"
                    @click="filtersOpen = ! filtersOpen"
                    :aria-expanded="filtersOpen ? 'true' : 'false'"
                    aria-controls="toolbar-filters"

                    {{-- গার্ডের চিহ্ন — ক্লাসের নাম নয়, ঘোষণার চাবি ধরে।

                         ⓘ [[TheOtherNineLooksStillDrawTheSame]] এটা খুঁজে দেখে
                         chips মোডের গড়নটা সত্যিই বসেছে কিনা। ⚠️ ক্লাসের নাম
                         ধরলে একদিন একটা Tailwind ক্লাস বদলাত আর পাহারাটা
                         নীরবে অন্ধ হয়ে যেত। --}}
                    @if ($filterMode === 'chips') data-look-chip @endif

                    @class([
                        // ⓘ order-1 — ২য় লাইনের একেবারে শুরুতে (মালিক: "+ Filter প্রথম")
                        'order-1 flex items-center gap-1.5 transition-colors hover:bg-(--color-surface-hover)',

                        /*
                         * chips মোড — বোতামটা চিপের চেহারা নেয়।
                         *
                         * ⓘ কাজটা এক (ছাঁকনির প্যানেল খোলা), কেবল চেহারা
                         * আলাদা: সক্রিয় ছাঁকনিগুলো যেখানে গোল পিল হয়ে
                         * বসে, সেখানে একটা চৌকো বোতাম বেমানান — চোখ
                         * ওটাকে অন্য জাতের জিনিস পড়ে।
                         *
                         * ⚠️ মোডটা কম্পোনেন্ট নিজে জিজ্ঞেস করে
                         * (`Ui::filters()`), পর্দা বলে দেয় না — নাহলে
                         * ছত্রিশটা পর্দাকে সাজের নাম জানতে হত।
                         */
                        'rounded-(--radius-pill) border border-dashed border-(--color-border)
                         px-2.5 py-0.5 text-2xs font-medium text-(--color-ink-muted)'
                            => $filterMode === 'chips',

                        'min-h-(--spacing-touch) rounded-(--radius-field)
                         border border-(--color-border) px-3 text-sm'
                            => $filterMode !== 'chips',
                    ])>
                @if ($filterMode !== 'chips')
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                        <path d="M3 5h18v2l-7 7v5l-4 2v-7L3 7V5Z"/>
                    </svg>
                @endif
                {{ $filterMode === 'chips' ? __('core.toolbar.add_filter') : __('core.toolbar.filter') }}
            </button>
        @endif

        @if ($search)
            {{-- min-w-48 না দিলে ছোট পর্দায় ঘরটা শূন্যে মিলিয়ে যায়।

                 flex-1 জায়গা ছেড়ে দিতে রাজি, আর Sort by-র লেবেল ও ড্রপডাউন
                 মিলে প্রায় পুরো সারিটা নিয়ে নেয় — ফলে ৩৭৫ পিক্সেলে খোঁজার
                 ঘরটা এক আঙুলের চেয়েও সরু হয়ে যেত, শুধু ম্যাগনিফায়ারটা
                 দেখা যেত। একটা সর্বনিম্ন চওড়া ধরে রাখলে ওটা মিলিয়ে যাওয়ার
                 বদলে Sort by পরের লাইনে নেমে যায়। --}}
            {{-- ⓘ ছাঁকনি ইঞ্জিনের তালিকায় খোঁজার ঘর ফাঁকা জায়গা ভরে (অনুমোদিত নমুনা, ১৪ নভেম্বর ২০২৬); বাকি পাতায় আগের মতো --}}
            <label @class(['relative order-3 min-w-48 flex-1', 'sm:max-w-sm' => $facets === null])>
                <span class="sr-only">{{ __('core.action.search') }}</span>
                {{-- placeholder-এ কী কী দিয়ে খোঁজা যায় তা লেখা থাকে।
                     শুধু "খুঁজুন" লিখলে ব্যবহারকারী নাম দিয়েই খোঁজে, আর
                     মোবাইল নম্বর দিয়েও যে খোঁজা যায় তা কখনো জানে না। --}}
                {{-- ⭐ টাইপ করতেই খোঁজ (মালিক, ১ অক্টোবর ২০২৬) — `data-live-search` ধরে রাখে এখন কী খোঁজা আছে,
                     আর খোঁজের পরে ফেরা পাতায় `autofocus` কার্সর ঘরেই রাখে; নিয়মগুলো `search-as-you-type.js`-এ। --}}
                <input type="search" name="q" value="{{ $searched }}"
                       placeholder="{{ $searchPlaceholder ?? __('core.action.search') }}"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) ps-8 pe-3 text-sm" data-quick-find
                       @unless ($report) data-live-search="{{ $searched }}" @endunless @if (request()->has('q')) autofocus @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"
                     class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2
                            fill-(--color-ink-muted)">
                    <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                </svg>

                {{--
                    ⛔ খোঁজার ঘরে Enter চাপলে ঘনত্ব বদলে যেত — ২১ সেপ্টেম্বর ২০২৬।

                    ⚠️ মালিকের অভিযোগ: *"+ ছাঁকনি search box kaj kore na"*। ⓘ আর
                    কারণটা HTML-এর একটা নিয়ম, JavaScript নয়: ঘরে Enter চাপলে
                    ব্রাউজার ফর্মের **প্রথম** সাবমিট বোতামটা চালায়। এই ফর্মের
                    প্রথমটা ছিল `name="compact" value="1"` — অর্থাৎ **ঘনত্ব**।

                    ⛔ ফল: খুঁজতে গিয়ে পাতা ঘন হয়ে যেত, আর খোঁজাটা হত না।
                    কিছুই ভাঙত না, কোনো ত্রুটি আসত না — ব্রাউজার ঠিক যা করার
                    কথা তাই করত।

                    ⭐ তাই ঘরের **ঠিক পরেই** একটা সাধারণ সাবমিট, নাম-মান ছাড়া।
                    এখন সে-ই প্রথম, আর Enter মানে কেবল খোঁজা। ⓘ চোখে দেখা যায়
                    না (`sr-only`), কারণ পাশে খোঁজার আইকনটা আগে থেকেই আছে —
                    কিন্তু কিবোর্ড ও স্ক্রিন-রিডারের কাছে সে আসল বোতাম।

                    ⚠️ নতুন কোনো সাবমিট বোতাম এর **আগে** বসালে বাগটা ফিরে
                    আসবে। পাহারা: [[EnterInTheSearchBoxOnlySearchesTest]]।
                --}}
                <button type="submit" class="sr-only" aria-label="{{ __('core.action.search') }}">{{ __('core.action.search') }}</button>
            </label>
        @endif

        {{-- ⭐ 📅 তারিখ ▾ — লেনদেনের তালিকায়, খোঁজার পরে (পরিকল্পনা §৩.১: *"ছাঁকনি · খোঁজা · 📅 তারিখ · সাজান ▾ …"*)।
             ⓘ চটজলদি নাম এক চাপে (আজ … গত ৯০ দিন, যেকোনো তারিখ) — প্রতিটা সাধারণ লিংক, বাকি ছাঁকনি অটুট ([[FilterSet::presets()]]);
             নিজে শুরু-শেষ বাছতে শেষ ঘরটা ছাঁকনির প্যানেল খোলে। বোতামে এখন যা বাছা তা-ই লেখা ("এই মাস", বা দুই তারিখ)। --}}
        @if (($facets['date'] ?? null) !== null)
            @include('components.ui.filter.date-bar', ['dateBar' => $facets['date']])
        @endif

        {{-- Sort by — ডিফল্টটা ব্যবসায়িক অর্থবহ হতে হবে ("সবচেয়ে বেশি
             বকেয়া আগে"), নাহলে ব্যবহারকারীকে প্রতিবার নিজে সাজাতে হয়,
             আর তালিকা খুলেই কাজের সারিগুলো চোখে পড়ে না। --}}
        @if ($sort !== [])
            {{-- ⚠️ order-3, খোঁজার সমান — নতুন `order-4` নয়। বানানো CSS-এ কেবল
                 order-1..3 আছে, আর অচেনা ক্লাস ০ ধরে Sort-কে লাইনের শুরুতে
                 তুলে দিয়েছিল (মালিক ধরেছেন, ১৯ সেপ্টেম্বর ২০২৬: *"+ Filter &
                 Search, Sort by-এর আগে থাকার কথা"*)। ⓘ সমান order-এ HTML-এর ক্রম
                 খাটে: খোঁজা → Sort → সরঞ্জাম। --}}
            {{-- ⭐ ইঞ্জিনের তালিকায় নমুনার মতো এক বোতাম "⇅ বেশি বকেয়া আগে ▾" — আলাদা "সাজাও" লেখা নেই (পর্দা-পাঠকের জন্য থাকে) --}}
            <label class="relative order-3 flex items-center gap-2 text-sm" data-sort>
                <span @class(['whitespace-nowrap text-(--color-ink-muted)', 'sr-only' => $facets !== null])>{{ __('core.toolbar.sort_by') }}</span>
                @if ($facets !== null)
                    <span aria-hidden="true" class="pointer-events-none absolute start-2.5 text-(--color-ink-muted)">⇅</span>
                @endif
                <select name="sort" @unless ($report) data-action="submit-form" @endunless
                        @class(['h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm',
                                'ps-7' => $facets !== null])>
                    @foreach ($sort as $value => $label)
                        <option value="{{ $value }}" @selected($currentSort === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

    </div>

    {{--
        কমান্ড বার — একটাই সারি, আর এই পর্দার সব কাজ ওখানেই।

        ── কেন বোতাম আর শিরোনামের পাশে নয় ─────────────────────────────
        আগে "নতুন ডিলার" বসত শিরোনামের ডানে (page-header-এ), আর রপ্তানি
        ও ছাপা বসত টুলবারের ডানে — একই ধরনের জিনিস দুই জায়গায়, দুই
        চেহারায়। ফলে প্রতিটা পর্দায় বোতাম কোথায় তা আলাদা করে খুঁজতে হত।

        এখন একটাই ক্রম, সব পর্দায়: নতুন — তারপর এই কাগজে যা করা যায় —
        তারপর ডানে দৃশ্য, ঘনত্ব, কলাম, রপ্তানি, শেয়ার, ছাপা, রিফ্রেশ।

        ফলে মানুষ **জায়গাটা** শেখে, বোতামটা নয়।
    --}}
    {{-- ⓘ `contents` — এই সারিটাও ২য় লাইনে গলে যায় (উপরের ⭐ মন্তব্য, ১৯ সেপ্টেম্বর ২০২৬)।
         পাতার বোতাম ("নতুন …") আর এখানে নেই — শিরোনামের ডানে, ১ম লাইনে। --}}
    <div class="contents">
        {{-- ⓘ order-3 — Sort-এর পরে HTML-এ, তাই সমান order-এ তার পরেই; ms-auto ডানে ঠেলে --}}
        <div class="print-hide order-3 ms-auto flex items-center gap-1">

            {{-- ⭐ পরিকল্পনা §৩.১ (মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো") — বারে সাতটা জিনিস:
                 ছাঁকনি · খোঁজা · তারিখ · সাজান ▾ · ★ দৃশ্য ▾ · কলাম · নামান ▾।
                 ⓘ "দেখাও" (টেবিল না কার্ড) পর্দার মাপ দেখে নিজে বদলায় — ফোনে কার্ড, কম্পিউটারে টেবিল; ঘনত্ব ব্যবহারকারীর নিজের
                 সেটিংসে ([[Ui::compact()]]); শেয়ার দৃশ্যের মেনুতে "লিংক কপি"; রিফ্রেশ নেই — পাতা নিজেই নতুন তথ্য আনে। --}}
            {{-- সংরক্ষিত দৃশ্য — ন'টা রূপে এখানে; D365-এ শিরোনামেই বসে (উপরে), তাই সেখানে দুইবার নয় --}}
            @if ($viewMode !== 'dropdown')
                <x-ui.view-menu mode="button" />
            @endif

            {{-- Columns — কোন কলামগুলো দেখা যাবে।

                 টিকগুলো ফর্মের ভেতরেই, তাই "প্রয়োগ" চাপলে বাকি সব
                 (খোঁজা, সাজানো, ফিল্টার) অক্ষত রেখে পাতা ফিরে আসে। আলাদা
                 JavaScript নেই — যে টুলবার ফর্ম জমা দিয়ে চলে, তার কলাম
                 বাছাইও ফর্মেই থাকা উচিত। --}}
            @if ($columnKeys->isNotEmpty())
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = ! open" @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            :aria-expanded="open.toString()"
                            aria-label="{{ __('core.toolbar.columns') }}"
                            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                                   text-sm text-(--color-ink-muted) transition-colors
                                   hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                            <path d="M3 4h4v16H3V4Zm6 0h6v16H9V4Zm8 0h4v16h-4V4Z"/>
                        </svg>
                        <span class="hidden xl:inline">{{ __('core.toolbar.columns') }}</span>
                        @if ($hiddenColumns->isNotEmpty())
                            <span class="rounded-full bg-(--color-brand-500) px-1.5 text-[10px] font-semibold text-white">
                                {{ $columnKeys->count() - $hiddenColumns->count() }}/{{ $columnKeys->count() }}
                            </span>
                        @endif
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute end-0 z-30 mt-1 w-60 rounded-(--radius-card) border
                                border-(--color-border) bg-(--color-surface-card) p-2 shadow-lg">
                        @foreach ($columns as $column)
                            @php $key = $column['key']; @endphp
                            <label class="flex min-h-(--spacing-touch) cursor-pointer items-center gap-2
                                          rounded-(--radius-field) px-2 text-sm hover:bg-(--color-surface-hover)">
                                <input type="checkbox" name="show[]" value="{{ $key }}"
                                       @checked(! $hiddenColumns->contains($key))
                                       class="size-4 shrink-0">
                                <span class="truncate">{{ $column['label'] }}</span>
                            </label>
                        @endforeach

                        {{-- একটাও না রাখলে খালি টেবিল — সেটা কেউ চায় না, আর
                             সার্ভার তখন সবগুলোই দেখায়। এখানে বলে দেওয়া হয়
                             যাতে "কাজ করেনি" মনে না হয়। --}}
                        <p class="px-2 pt-1 text-2xs text-(--color-ink-muted)">
                            {{ __('core.toolbar.columns_note') }}
                        </p>

                        <x-ui.button type="submit" tone="secondary" class="mt-2 w-full">
                            {{ __('core.action.apply') }}
                        </x-ui.button>
                    </div>
                </div>
            @endif

            {{-- ⭐ নামান ▾ — Excel, CSV, PDF আর প্রিন্ট এক মেনুতে (পরিকল্পনা §৩.১)। যা দেখছেন আর যে কলাম দেখানো, ঠিক সেটাই নামে।
                 ⛔ নতুন চাবি "তালিকা নামানো" (`system_admin.list.export`): যার নেই তার কাছে মেনুই দেখায় না, আর ঠিকানায় `?export=`
                 লিখলেও ৪০৩ ([[ExportListing]]); কে কখন কী নামাল তা নিরীক্ষার খাতায় ([[ExportJournal]])। --}}
            @php $canDownload = auth()->user()?->can('system_admin.list.export') ?? false; @endphp
            {{-- ⛔ শেয়ারও নামানোর চাবির পেছনে — চাবি নেই তো মেনুই নেই ([[TheListBarFollowsThePlanTest]]; Mac রান, ১৮ নভেম্বর ২০২৬) --}}
            @if ($canDownload && ($export || $print || $share))
                <div x-data="{ open: false }" class="relative" data-list-download>
                    <button type="button" @click="open = ! open" @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            :aria-expanded="open.toString()"
                            aria-label="{{ __('core.toolbar.download') }}"
                            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                                   text-sm text-(--color-ink-muted) transition-colors
                                   hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                            <path d="M12 3v10l3.5-3.5L17 11l-5 5-5-5 1.5-1.5L12 13V3ZM5 18h14v2H5v-2Z"/>
                        </svg>
                        <span class="hidden xl:inline">{{ __('core.toolbar.download') }}</span>
                        <span aria-hidden="true">▾</span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute end-0 z-30 mt-1 w-56 overflow-hidden rounded-(--radius-card)
                                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        @if ($canDownload && $export)
                            {{-- data-no-prefetch — মাউস ছুঁলেই ফাইল বানানো শুরু হয় না --}}
                            <a href="{{ url()->current().'?'.http_build_query(request()->query() + ['export' => 'xlsx']) }}"
                               data-no-prefetch data-download="xlsx"
                               class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                                {{ __('core.toolbar.export_xlsx') }}
                                <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.toolbar.export_xlsx_note') }}</span>
                            </a>
                            <a href="{{ url()->current().'?'.http_build_query(request()->query() + ['export' => 'csv']) }}"
                               data-no-prefetch data-download="csv"
                               class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                                {{ __('core.toolbar.export_csv') }}
                                <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.toolbar.export_csv_note') }}</span>
                            </a>
                            {{-- ⭐ PDF — লেজারের ছাঁচের কাগজ, সব পাতা, ফাইল হয়ে নামে ([[ListPaper]]; আগে ছিল "ছাপুন, তারপর PDF হিসেবে সেভ") --}}
                            <a href="{{ url()->current().'?'.http_build_query(request()->query() + ['export' => 'pdf']) }}"
                               data-no-prefetch data-download="pdf" aria-label="{{ __('core.toolbar.export_pdf') }}"
                               class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                                {{ __('core.toolbar.export_pdf') }}
                                <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.toolbar.export_pdf_note') }}</span>
                            </a>
                        @endif
                        @if ($canDownload && $print && $printHref)
                            {{-- ⓘ ছাপার নিজের ঠিকানা (যেমন লেজার, পুরো পরিসর) — `<a>`, ফর্ম জমা নয় --}}
                            <a href="{{ $printHref }}" data-download="print" aria-label="{{ __('core.action.print') }}"
                               class="block border-t border-(--color-border) px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                                {{ __('core.action.print') }}
                            </a>
                        @elseif ($canDownload && $print)
                            {{-- ⭐ প্রিন্ট ভিউ — নতুন ট্যাবে কাগজটা, সব পাতা; সেখানেই দেখা, ছাপা, সেভ (মালিক, ১৬ নভেম্বর ২০২৬) --}}
                            <a href="{{ url()->current().'?'.http_build_query(request()->query() + ['export' => 'print']) }}"
                               target="_blank" rel="noopener" data-no-prefetch data-download="print" aria-label="{{ __('core.action.print') }}"
                               class="block border-t border-(--color-border) px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                                {{ __('core.action.print') }}
                                <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.toolbar.print_view_note') }}</span>
                            </a>
                        @endif
                        {{--
                            ⛔ শেয়ার — এই ছাঁকনিসহ তালিকার লিংক (হোয়াটসঅ্যাপ, ইমেইল, কপি)। বারের নতুন "নামান" মেনুতে (41d7b56ce) এটা বাদ পড়েছিল —
                               পুরনো সুবিধা হারানো চলবে না; Mac রানে AVoucherListFiltersByEveryFieldTest ধরেছে (১৭ নভেম্বর ২০২৬)।
                        --}}
                        @if ($share)
                            <div x-data="shareMenu({ url: @js($shareUrl) })" class="border-t border-(--color-border)" data-list-share>
                                <a href="https://wa.me/?text={{ urlencode($shareUrl) }}" target="_blank" rel="noopener"
                                   class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">WhatsApp</a>
                                <a href="mailto:?body={{ urlencode($shareUrl) }}"
                                   class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">{{ __('core.toolbar.share_email') }}</a>
                                <button type="button" @click="copy()" aria-label="{{ __('core.toolbar.share_copy') }}"
                                        class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">
                                    <span x-show="! copied">{{ __('core.toolbar.share_copy') }}</span>
                                    <span x-show="copied" x-cloak class="text-(--color-badge-success-ink)">{{ __('core.toolbar.share_copied') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($print && request()->boolean('print'))
                {{--
                    ⓘ ছাপার লিংক থেকে এসেছি — এখন নিজে থেকেই ছাপা শুরু হোক।

                    ⛔ এই ঘরটা না থাকলে জিনিসটা **আধখানা** হত: মানুষ সঠিক
                    ক্রমের পাতায় পৌঁছাতেন, তারপর নিজে ছাপার বোতাম খুঁজতেন,
                    আর সেটা আবার পর্দারটাই ছাপত। ⚠️ ABOS-এর সবচেয়ে সাধারণ
                    রোগ ঠিক এটাই — কাজটা হয়েছে, শেষ জোড়াটা লাগানো হয়নি।
                --}}
                <span data-print-on-load hidden></span>
            @endif

        </div>
    </div>
    </div>{{-- ২য় লাইনের ধারক --}}

    {{-- স্ক্রিনের নিজস্ব ফিল্টার — একটাই সারিতে, টেবিলের উপরে
         (সেকশন ১৫.৮), Filter By বোতামের নিচে। --}}
    @if ($hasFilters)
        {{-- order-3 — বাকি দুইটা সারির নিচে।

             ফ্লেক্সে যার `order` লেখা নেই সে ০ ধরে, আর ০ সবসময় ১-এর
             আগে। ক্লাসটা না দিলে ছাঁকনির প্যানেলটা কমান্ড বারেরও উপরে
             উঠে যেত — অর্থাৎ ছাঁকনি খুললেই পর্দাটা উল্টে যেত। --}}
        {{-- ⭐ মালিক, ১ অক্টোবর ২০২৬: *"নিষ্ক্রিয়রাও দেখাও filter hoy na"* — টিক দিয়ে তারপর
             "খুঁজুন" চাপতে হত, আর কেউ তা বুঝত না। ⓘ `data-action="submit-form"` ধারকে: বদলের
             ঘটনা ভেতরের ঘর থেকে উঠে এখানে ধরা পড়ে ([[actions.js]] `closest`), তাই ২৪টা তালিকার
             চেকবক্স আর বাছাই একসাথে — প্রতিটা পাতায় আলাদা করে নয়। বোতামটা থাকছে Enter-এর জন্য। --}}
        {{-- ⭐ রিপোর্টে (`report`) বদলের জমা নেই — কেবল "দেখান" বা Enter (মালিক, ২৩ নভেম্বর ২০২৬) --}}
        <div id="toolbar-filters"
             @if ($report) data-report-filters @else data-action="submit-form" @endif
             x-show="filtersOpen"
             x-cloak
             class="order-3 flex flex-wrap items-center gap-2 border-t border-(--color-border)
                    bg-(--color-surface-app) px-3 py-2">
            {{ $slot }}

            <x-ui.button type="submit" tone="secondary" :data-report-show="$report ? '' : null">{{ $report ? __('core.action.show') : __('core.action.search') }}</x-ui.button>
        </div>
    @endif
</div>
