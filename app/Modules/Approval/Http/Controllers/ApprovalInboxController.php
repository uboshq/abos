<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Contracts\ApprovalBundles;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Engines\Approval\AuthorityService;
use App\Core\Engines\Approval\BulkApproval;
use App\Core\Panels\FactRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * অনুমোদনের পর্দা — "আমার সিদ্ধান্তের অপেক্ষায়" আর "আমার অনুরোধ"।
 *
 * ── কেন দুইটা তালিকা, একটা নয় ───────────────────────────────────────
 * দুইজন মানুষ, দুইটা প্রশ্ন। ম্যানেজার জানতে চান "আমাকে কী দেখতে হবে",
 * আর যিনি ছাড় চেয়েছেন তিনি জানতে চান "আমারটার কী হলো"। একটা তালিকায়
 * মেশালে দুইজনেরই নিজেরটা খুঁজে বের করতে হত।
 */
class ApprovalInboxController extends Controller implements HasMiddleware
{
    /**
     * ইনবক্সে সর্বোচ্চ কয়টা সারি দেখানো হয়।
     *
     * ── কেন সীমা, আর কেন পাতা ভাগ নয় ────────────────────────────────
     * তালিকাটার সীমা ছিল না, আর সেটা নিরীহ মনে হত — অপেক্ষমাণ অনুরোধ
     * তো হাতেগোনা। কিন্তু "হাতেগোনা" ধরে নেওয়াটাই ভুল: ইনবক্স লম্বা
     * হয় ঠিক তখনই যখন কেউ অনুমোদন করছেন না, আর তখনই পর্দাটা খোলা
     * সবচেয়ে জরুরি।
     *
     * ⛔ পাতা ভাগ এখানে চলে না, আর কারণটা উপরের চিপগুলো: ওরা **পুরো
     * তালিকার** সংখ্যা দেখায় (নিচের মন্তব্যে কেন তা লেখা)। পাতা ভাগ
     * বসালে "পাতা ২-এ যান" আর "ক্রয় ১৩৭" — দুইটা আলাদা গল্প একসাথে
     * বলতে হত। বদলে [[ExpenseController]]-এর ছাঁচ: সীমা + আলাদা
     * কোয়েরিতে মোট + পর্দায় স্পষ্ট লেখা কতটা দেখা যাচ্ছে।
     *
     * পঞ্চাশ — বাকি তালিকাগুলোর পাতার মাপের সমান, যাতে "এক পর্দা কত"
     * সংখ্যাটা পুরো সিস্টেমে একটাই থাকে।
     */
    private const INBOX_LIMIT = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalEngine $engine,
        private readonly ApprovalFlowService $flows,
        private readonly ApprovalFacts $facts,
        private readonly ApprovalSla $sla,
        private readonly BulkApproval $bulk,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:approval.view', only: ['mine']),

            /*
             * ⚠️ `index` এখানে নেই, আর কারণটা `show`-এর মতোই।
             *
             * ইনবক্স খোলেন **দুই ধরনের** মানুষ: যিনি সই দেন
             * (`approval.decide`), আর যিনি দেখেন কার সইয়ে কী আটকে আছে
             * (`approval.report` — ম্যানেজার, নিরীক্ষক, মালিক)। দ্বিতীয়
             * দলটার নিজের ইনবক্স খালি, কিন্তু তাঁদেরই ব্যক্তি-ছাঁকনিটা
             * দরকার।
             *
             * ⓘ `can:approval.decide` বসিয়ে রাখলে **যিনি কোনো ছকে নেই
             * তিনি পাতাটাই খুলতে পারতেন না** — অর্থাৎ ছাঁকনিটা ঠিক
             * তাঁদের জন্যই অদৃশ্য হত যাঁদের জন্য বানানো।
             *
             * ⓘ `forward`-ও এই একই চাবিতে: যিনি সই দিতে পারেন কেবল
             * তিনিই সেটা অন্যকে দিতে পারেন। ⛔ আলাদা চাবি দিলে এমন কেউ
             * কাগজ পাঠাতে পারতেন যিনি নিজে ওটায় সই দিতেই পারতেন না।
             */
            /*
             * ⛔ `bulkApprove` এই তালিকায় না থাকায় রুটটা খোলা ছিল।
             *
             * ⚠️ মেনুতে বোতামটা লুকানো থাকলেও লগইন করা যে কেউ
             * সরাসরি POST করতে পারতেন — আর একসাথে একশোটা কাগজে
             * সই দেওয়ার চেয়ে খারাপ আর কিছু নেই।
             *
             * ⓘ [[BulkApproval]] প্রতিটা সারি আলাদা করে `canDecide()`
             * দিয়ে যেত, তাই কাগজ পাশ হত না — তবু দরজাটা খোলা
             * থাকা আর দরজাটা বন্ধ থাকা এক কথা নয়।
             *
             * ⭐ ধরা পড়েছে [[EveryRouteIsGuardedTest]] লাল হয়ে — আমার
             * নিজের কোনো দাবি এটা ধরত না, কারণ প্রতিটাতেই মানুষটার
             * কোনো না কোনো চাবি ছিল।
             */
            new Middleware('can:approval.decide', only: ['approve', 'reject', 'sendBack', 'forward', 'bulkApprove', 'bulkReview', 'confirmAll']),

            /*
             * ⚠️ `show` এখানে নেই, আর সেটা ইচ্ছাকৃত।
             *
             * পাতাটা **তিন ধরনের** মানুষ খোলেন: যিনি অনুরোধ করেছেন,
             * যিনি সিদ্ধান্ত দেবেন, আর যিনি নিরীক্ষা করেন
             * (`approval.report` — রিপোর্টের সারি থেকে এসে)। একটামাত্র
             * `can:` দিয়ে "এটা বা ওটা" বলা যায় না, আর `approval.view`
             * বসিয়ে রাখলে **রিপোর্টের প্রতিটা সারি নিরীক্ষকের কাছে
             * ৪০৩ দিত** — দেখতে জীবন্ত, চাপলে বন্ধ।
             *
             * তাই সীমাটা `show()`-এর ভেতরে, যেখানে তিনটা প্রশ্নের
             * উত্তর আলাদাভাবে দেওয়া যায়।
             */
            new Middleware('can:approval.view', only: ['withdraw']),
        ];
    }

    /**
     * ⭐ ইনবক্সের চারটা ট্যাব — অনুমোদন পর্দা ভাগ ২, দফা ২ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; SAP Fiori "My Inbox")।
     *
     *   waiting  আমার অপেক্ষায় — [[ApprovalEngine::pendingQueryFor()]]
     *   sent     আমার পাঠানো — আমার যে অনুরোধ এখনো খোলা (অপেক্ষায় বা সংশোধনে ফেরত); পুরো ইতিহাস "আমার অনুরোধ" পাতায়
     *   done     আমি অনুমোদন দিয়েছি — যেগুলোয় আমার অনুমোদনের সারি আছে
     *   behalf   অন্যের হয়ে — দায়িত্ব হস্তান্তরে আমার কাছে আসা অপেক্ষার কাগজ ([[ApprovalEngine::standInQueryFor()]])
     */
    public const TABS = ['waiting', 'sent', 'done', 'behalf'];

    /** ⭐ সাজানো — দফা ৩: দেরি আগে (অপেক্ষার ডিফল্ট), নতুন আগে, অঙ্ক ধরে (বড় আগে) */
    public const SORTS = ['late', 'new', 'amount'];

    /**
     * ⭐ অঙ্কের ছাঁকনি — দফা ৩। [নিচের সীমা, ওপরের সীমা) — নিচেরটা ধরে, ওপরেরটা নয়, তাই কোনো অঙ্ক দুই ধাপে পড়ে না।
     * ⓘ ধাপগুলো অনুমোদনের নিয়মের চেনা সীমায় (১০ হাজার, ১ লাখ, ৫ লাখ) — "৫ লাখ বা বেশি" এক রকম (পরিকল্পনার চতুর্থ যাচাই)।
     */
    public const AMOUNT_BANDS = [
        'below_10k' => [null, '10000'],
        '10k_1l' => ['10000', '100000'],
        '1l_5l' => ['100000', '500000'],
        '5l_up' => ['500000', null],
    ];

    /** ⭐ এক পাতায় কয়টা — দফা ৬: আগে ৫০-এ কাটা পড়ত, এখন পাতা বদল আর দুই মোট */
    public const PER_PAGE = 25;

    /**
     * ⭐ অনুমোদনের ইনবক্স — এক পাতায় বাঁয়ে তালিকা, ডানে বেছে নেওয়া কাগজ (অনুমোদন পর্দা ভাগ ২, দফা ১, ২, ৩, ৬, ৭, ৮)।
     *
     * ⓘ ডানের কাগজ [[detailFor()]]-এর — কাগজের নিজের পাতা ([[show()]]) ঠিক একই তথ্য দেখায়, তাই দুই জায়গা কখনো দুই কথা বলে না।
     * ⓘ অনুমোদন দিলে পরের কাগজ নিজে খোলে: ফর্মের সাথে তালিকার পরের সারিটা যায় ([[nextAfterDecision()]])।
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * ⛔ পাতাটা খোলার চাবি — আর এটা ফসকে গিয়েছিল।
         *
         * রুট থেকে `can:approval.decide` তোলা হয়েছিল কারণ নিরীক্ষককেও
         * ঢুকতে দিতে হবে, **কিন্তু ভেতরে কিছু বসানো হয়নি** — অর্থাৎ
         * কয়েক ঘণ্টা লগইন করা যে কেউ গোটা কোম্পানির অপেক্ষমাণ তালিকা
         * খুলতে পারতেন, অঙ্ক ও অনুরোধকারীসহ।
         *
         * ⚠️ ধরা পড়েছে `EveryRouteIsGuarded` লাল হয়ে — আর ওটাই প্রমাণ
         * করে কেন গার্ডটা আছে: আমার নিজের কোনো টেস্ট এটা ধরত না, কারণ
         * প্রতিটাতেই মানুষটার কোনো না কোনো চাবি ছিল।
         */
        /*
         * ⭐ ছকের বাইরের সই — সবসময় দ্বিতীয় একজনের (নকশা দফা #৬, ২১ অক্টোবর ২০২৬; [[SignaturesWaitingElsewhere]])।
         * ⓘ যাঁর জন্য এমন সই অপেক্ষায়, তিনি `approval.decide` ছাড়াও বাক্সটা খোলেন — কেবল নিজের সারি দেখতে; সারিগুলো মডিউল
         * নিজে ছাঁকে (চাবি, নাগাল, যিনি চেয়েছেন তিনি নন)।
         */
        $elsewhere = app(\App\Core\Contracts\SignaturesWaitingElsewhere::class)->waitingFor($user);

        abort_unless($user->can('approval.decide') || $user->can('approval.report') || $elsewhere !== [], 403);

        /*
         * কার ইনবক্স — নিজের, নাকি অন্য কারো?
         *
         * ── কেন এটা `approval.report`-এর পেছনে ──────────────────────
         * "রহিমের অপেক্ষায় কী কী" জানা মানে **রহিমের কাজের চাপ জানা** —
         * ম্যানেজার, নিরীক্ষক ও মালিকের প্রশ্ন, সহকর্মীর নয়।
         *
         * ⚠️ অনুমতি না থাকলে প্যারামিটারটা চুপচাপ উপেক্ষা করা হয় না —
         * ওটা থাকলে ৪০৩। নাহলে কেউ ঠিকানায় `?person=7` বসিয়ে দেখতেন
         * নিজেরই তালিকা, আর ভাবতেন রহিমেরটা দেখছেন।
         */
        $subject = $user;
        $signers = [];

        if ($user->can('approval.report')) {
            $signers = $this->theSigners();

            $chosen = (int) $request->query('person', 0);

            if ($chosen > 0 && $chosen !== $user->id) {
                abort_unless(isset($signers[$chosen]), 404);

                $subject = User::query()->findOrFail($chosen);
            }
        } elseif ($request->query('person') !== null) {
            abort(403);
        }

        $own = $subject->is($user);

        /*
         * ⭐ ট্যাবের প্রশ্নগুলো — অন্যের ইনবক্স দেখার সময় কেবল "অপেক্ষায়"। ⓘ রহিমের পাঠানো বা রহিমের দেওয়া অনুমোদন জানার
         * জায়গা রিপোর্ট; ইনবক্সে সেটা দেখালে নিরীক্ষক আর সহকর্মীর প্রশ্ন গুলিয়ে যেত।
         */
        $tabs = $this->tabQueries($subject, $own);
        $tab = (string) $request->query('tab', 'waiting');
        $tab = isset($tabs[$tab]) ? $tab : 'waiting';

        $branch = (int) $request->query('branch', 0);
        $band = (string) $request->query('amount', '');
        $band = isset(self::AMOUNT_BANDS[$band]) ? $band : '';
        $sort = (string) $request->query('sort', '');
        $sort = in_array($sort, self::SORTS, true) ? $sort : (in_array($tab, ['waiting', 'behalf'], true) ? 'late' : 'new');

        /*
         * ⓘ খোঁজা, শাখা আর অঙ্ক — পুরো তালিকা ছাঁকে, কাজের চিপের সংখ্যাও তার পরে গোনা। ⚠️ কাজ আর দেরি বাছে, চিপের সংখ্যা কমায় না
         * (নিচে), নাহলে "ক্রয়" বাছার পর বাকি চিপ শূন্য দেখাত।
         */
        $filtered = $this->narrowed(
            self::searched(clone $tabs[$tab], (string) $request->query('q', '')),
            $branch,
            $band,
        );

        /*
         * কাজ ধরে চিপ — §২.২।
         *
         * ⓘ গোনাটা ডাটাবেজে, group-by দিয়ে — সারি না তুলেই পুরো তালিকার হিসাব (১২ সেপ্টেম্বর ২০২৬)।
         * ⛔ `reorder()` — গোনার কোয়েরিতে ক্রমের কোনো মানে নেই, আর ক্রম ধরে সাজানো একটা group-by কড়া MySQL সরাসরি খারিজ করে
         * (ONLY_FULL_GROUP_BY)।
         */
        $counts = (clone $filtered)
            ->reorder()
            ->withoutEagerLoads()
            ->select('module')
            ->selectRaw('COUNT(*) as tally')
            ->groupBy('module')
            ->pluck('tally', 'module');

        $selected = trim((string) $request->query('module', ''));

        $modules = [];

        foreach ($this->flows->choices() as $code => $entry) {
            if ($counts->has($code) || $code === $selected) {
                $modules[$code] = ['label' => $entry['label'], 'count' => (int) $counts->get($code, 0)];
            }
        }

        /*
         * ⭐ দেরির ছাঁকনি — ২৪ সেপ্টেম্বর ২০২৬। ⓘ শর্তটা একটাই জায়গায় ([[lateOnes]]): চিপের সংখ্যা, সারি আর মোট — তিনটাই।
         */
        $late = $request->boolean('late');

        $lateCount = self::lateOnes((clone $filtered)->reorder()->withoutEagerLoads())->count();

        $list = self::lateOnes(
            (clone $filtered)->when($selected !== '', fn ($q) => $q->where('module', $selected)),
            $late,
        );

        /*
         * ⭐ দুই মোট — দফা ৬: নিচে "এই পাতার মোট" আর "সবগুলোর মোট"। ⓘ সবগুলোর মোট ডাটাবেজে (DECIMAL-এর যোগ, কোনো ভাসমান সংখ্যা
         * নেই), পাতার মোট পাতার সারি থেকে bcmath-এ ([[Money::sumOf()]])। ⛔ দুইটা একই সংখ্যা হলে, অর্থাৎ এক পাতাতেই সব, তাও দুই
         * লাইনেই লেখা — "সব Nটার মোট" দেখে পাঠক জানেন আর কিছু বাকি নেই।
         */
        $visibleTotal = (clone $list)->reorder()->withoutEagerLoads()->count();
        $sumAll = Money::round((string) ((clone $list)->reorder()->withoutEagerLoads()->sum('amount') ?? '0'));

        $approvals = self::sorted($list, $sort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = $approvals->getCollection();
        $sumPage = Money::sumOf($rows, fn (Approval $a) => (string) ($a->amount ?? '0'));

        /*
         * ⭐ ডানের কাগজ — বেছে নেওয়াটা (`?open=`), নাহলে তালিকার প্রথমটা (দফা ১)। ⓘ বেছে নেওয়া কাগজ এই পাতায় না থাকলেও খোলে, যদি
         * পাঠকের দেখার অধিকার থাকে ([[detailFor()]]-এর একই তিন প্রশ্ন); না থাকলে প্রথমটা — খালি ৪০৩ নয়, কারণ ঠিকানাটা ইনবক্সের।
         */
        $openId = (int) $request->query('open', 0);
        $opened = $openId > 0
            ? ($rows->firstWhere('id', $openId) ?? Approval::query()->with('requester')->find($openId))
            : null;
        $detail = $opened !== null ? $this->detailFor($opened, $user) : null;

        if ($detail === null && $rows->isNotEmpty()) {
            $opened = $rows->first();
            $detail = $this->detailFor($opened, $user);
        }

        return view('approval::inbox.index', [
            'menu' => $this->menu->forUser($user),
            'approvals' => $approvals,

            /*
             * ⭐ কার · কী বাবদ · কোথায় — ২০ সেপ্টেম্বর ২০২৬। কাগজগুলো ধরন ধরে একবারে তোলা হয় ([[ApprovalFacts]]), সারি ধরে নয়।
             * ⓘ দেয়াল তোলা কেবল নিজের সারিতে — অন্যের সারি দেখা রিপোর্ট-পাঠক নিজের দেয়ালেই ([[ApprovalFacts::VIEW_WALLS]])
             */
            'facts' => $this->facts->of($rows, $own),
            // ⭐ ছকের বাইরের সই — কেবল নিজের বাক্সে, অন্যের বাক্স দেখার সময় নয়
            'elsewhere' => $own ? $elsewhere : [],
            'visibleTotal' => $visibleTotal,
            'sumAll' => $sumAll,
            'sumPage' => $sumPage,
            'labels' => $this->flows->labels(),
            'modules' => $modules,
            'selected' => $selected,
            // "সব" চিপের সংখ্যা — সবসময় পুরো (ছাঁকা) তালিকার, কাজের চিপ নির্বিশেষে
            'total' => (int) $counts->sum(),

            'tab' => $tab,
            'tabCounts' => collect($tabs)->map(fn ($q) => (clone $q)->reorder()->withoutEagerLoads()->count())->all(),
            'branch' => $branch,
            'branches' => $this->branchChoices(),
            'band' => $band,
            'sort' => $sort,

            /*
             * ব্যক্তির তালিকা — কেবল যাঁর অনুমতি আছে তাঁর জন্য। ⚠️ "সবাই" বলে কোনো বিকল্প নেই: "সবার অপেক্ষমাণ" প্রশ্নটার উত্তর
             * **রিপোর্টের**, ইনবক্সের নয় — দুই জায়গায় রাখলে সংখ্যা দুইটা একদিন আলাদা হত।
             */
            'signers' => $signers,
            'person' => $own ? 0 : $subject->id,
            'personName' => $subject->name,

            /*
             * ⭐ প্রতিটা সারির ঘড়ির অবস্থা আর শেষ সময় — পর্দা নিজে হিসাব করে না ([[ApprovalSla]]); ⓘ ধাপ "২ / ৩ · ম্যানেজার"
             * ইঞ্জিনের ধাপ থেকে ([[stepsOf()]]), দফা ৭ আর ৮।
             */
            'sla' => $rows->mapWithKeys(fn (Approval $a) => [$a->id => $this->sla->stateOf($a)])->all(),
            'steps' => $this->stepsOf($rows),
            'standIns' => $this->standInsOf($rows, $user, $own),
            'deadlines' => $rows->mapWithKeys(fn (Approval $a) => [$a->id => $this->deadlineOf($a)])->all(),

            'late' => $late,
            'lateCount' => $lateCount,

            /*
             * ⭐ কোন সারিগুলো একসাথে অনুমোদন দেওয়া যায় — মালিকের সিদ্ধান্ত ৫। ⓘ কেবল নিজের অপেক্ষার তালিকায়; তবু
             * [[BulkApproval]] সার্ভারেও আলাদা করে দেখে — লুকানো একটা চেকবক্স হাতে বানিয়ে পাঠানো যায়।
             */
            'bulkable' => $own && in_array($tab, ['waiting', 'behalf'], true)
                ? $rows->reject(fn (Approval $a) => $this->bulk->movesMoney($a))->pluck('id')->all()
                : [],

            'opened' => $opened,
            'detail' => $detail,
            'nextId' => $opened !== null ? self::nextAfterDecision($rows, (int) $opened->id) : null,
        ]);
    }

    /**
     * ⭐ চার ট্যাবের প্রশ্ন — কোয়েরি হিসেবে, চালানোর আগে (গোনা আর সারি আলাদা চলে)।
     *
     * @return array<string, Builder<Approval>>
     */
    private function tabQueries(User $subject, bool $own): array
    {
        $tabs = ['waiting' => $this->engine->pendingQueryFor($subject)];

        if (! $own) {
            return $tabs;
        }

        $tabs['sent'] = Approval::query()
            ->with('requester')
            ->where('requested_by', $subject->id)
            ->whereIn('status', [Approval::PENDING, Approval::RETURNED]);

        $tabs['done'] = Approval::query()
            ->with('requester')
            ->whereExists(fn ($signed) => $signed->selectRaw('1')
                ->from('approval_decisions')
                ->whereColumn('approval_decisions.approval_id', 'approvals.id')
                ->where('approval_decisions.user_id', $subject->id)
                ->where('approval_decisions.decision', ApprovalDecision::APPROVED));

        $tabs['behalf'] = $this->engine->standInQueryFor($subject);

        return $tabs;
    }

    /**
     * ⭐ শাখা আর অঙ্ক — দফা ৩।
     *
     * ── ⓘ শাখা কাগজের নিজের, অনুমোদনের সারির নয় ─────────────────────
     * `approvals` সারিতে শাখা নেই — কাগজটা কোন শাখার, তা কাগজের নিজের `branch_id`-তে (ইনবক্সের "শাখা" লেখাও সেখান থেকে,
     * [[ApprovalFacts]])। ⚠️ তাই ধরন প্রতি একটা উপ-প্রশ্ন: যে ধরনের সারণিতে `branch_id` নেই (বেতনের রান — গোটা কোম্পানির), শাখা
     * বাছলে সেটা আসে না; আর সেটাই সৎ — ওই কাগজ কোনো এক শাখার নয়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private function narrowed($query, int $branch, string $band)
    {
        if ($band !== '') {
            [$from, $below] = self::AMOUNT_BANDS[$band];
            $query->whereNotNull('amount')
                ->when($from !== null, fn ($q) => $q->where('amount', '>=', $from))
                ->when($below !== null, fn ($q) => $q->where('amount', '<', $below));
        }

        if ($branch <= 0) {
            return $query;
        }

        $types = (clone $query)->reorder()->withoutEagerLoads()->distinct()->pluck('approvable_type')->all();

        return $query->where(function (Builder $any) use ($types, $branch): void {
            $any->whereRaw('1 = 0');

            foreach ($types as $type) {
                if (! is_string($type) || ! is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }

                $table = (new $type)->getTable();

                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'branch_id')) {
                    continue;
                }

                $any->orWhere(fn (Builder $one) => $one
                    ->where('approvable_type', $type)
                    ->whereIn('approvable_id', fn ($ids) => $ids->select('id')->from($table)->where('branch_id', $branch)));
            }
        });
    }

    /**
     * ⭐ সাজানো — দফা ৩।
     *
     * ⓘ "দেরি আগে": যেটার শেষ সময় পেরিয়েছে আগে, তারপর যেটার শেষ সময় কাছে, তারপর ঘড়িহীন — প্রতিটা দলে পুরনোটা আগে।
     * ⓘ শেষে সবসময় `id` — একই মুহূর্তের দুই কাগজ পাতা বদলালে জায়গা অদলবদল করে না (পাতা ভাগে একটা কাগজ দুইবার বা একবারও না)।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function sorted($query, string $sort)
    {
        $query->reorder();

        return match ($sort) {
            'new' => $query->orderByDesc('requested_at')->orderByDesc('id'),
            'amount' => $query->orderByRaw('amount IS NULL')->orderByDesc('amount')->orderBy('id'),
            default => $query
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 2 WHEN due_at < ? THEN 0 ELSE 1 END', [now()])
                ->orderBy('due_at')
                ->orderBy('requested_at')
                ->orderBy('id'),
        };
    }

    /**
     * ⭐ অনুমোদন দেওয়ার পরে কোন কাগজ খুলবে — দফা ১: তালিকায় ঠিক পরেরটা, শেষেরটা হলে আগেরটা, একটাই থাকলে কিছুই নয়।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $rows
     */
    private static function nextAfterDecision($rows, int $id): ?int
    {
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        $at = array_search($id, $ids, true);

        if ($at === false) {
            return $ids[0] ?? null;
        }

        return $ids[$at + 1] ?? $ids[$at - 1] ?? null;
    }

    /**
     * ⭐ সারি ধরে ধাপ — "ধাপ ২ / ৩ · ম্যানেজার" (দফা ৮)। ⓘ ধাপের সংখ্যা ইঞ্জিনের ছক থেকে, স্তর ধরে (একই স্তরে তিনজন = এক ধাপ);
     * নাম থাকলে নাম। ছক এক কাজে একবারই পড়া হয়।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $rows
     * @return array<int, array{at: int, of: int, name: ?string}>
     */
    private function stepsOf($rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $approval) {
            $key = $approval->module.'|'.$approval->action.'|'.$approval->approvable_type;
            $seen[$key] ??= [
                'levels' => $this->engine->stepsFor($approval)->pluck('level')->map(fn ($l) => (int) $l)->unique()->sort()->values()->all(),
                'names' => $this->engine->stepNamesFor($approval),
            ];

            $levels = $seen[$key]['levels'];
            $at = (int) $approval->current_level;

            $out[(int) $approval->id] = [
                'at' => $at,
                'of' => max(count($levels), $at),
                'name' => $seen[$key]['names'][$at] ?? null,
            ];
        }

        return $out;
    }

    /**
     * ⭐ কার হয়ে — "অন্যের হয়ে" ট্যাব আর সারির চিহ্ন (দফা ২)। ⓘ কেবল নিজের ইনবক্সে; প্রশ্নটা ইঞ্জিনের ([[ApprovalEngine::standInFor()]]),
     * যেটা সিদ্ধান্তের `on_behalf_of`-এর একই উত্তর দেয়।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $rows
     * @return array<int, string>
     */
    private function standInsOf($rows, User $user, bool $own): array
    {
        if (! $own) {
            return [];
        }

        $out = [];

        foreach ($rows as $approval) {
            if ($approval->status !== Approval::PENDING) {
                continue;
            }

            $for = $this->engine->standInFor($approval, $user);

            if ($for !== null) {
                $out[(int) $approval->id] = (string) User::query()->whereKey($for)->value('name');
            }
        }

        return $out;
    }

    /**
     * ⭐ শাখার তালিকা — ছাঁকনির জন্য, এই কোম্পানির (দফা ৩)।
     *
     * @return array<int, string>
     */
    private function branchChoices(): array
    {
        return \App\Models\Branch::query()
            ->ordered()
            ->get()
            ->mapWithKeys(fn ($b) => [(int) $b->id => (string) $b->name()])
            ->all();
    }

    /**
     * ⭐ *"দেরি"* মানে কী — একটাই জায়গায়।
     *
     * ⚠️ তিনটা কোয়েরিতে একই শর্ত লাগে: চিপের সংখ্যা, সারিগুলো,
     * আর মোট। ⛔ তিন জায়গায় লিখলে একদিন একটা বদলাত, আর
     * চিপে ১২ লেখা থাকত যখন তালিকায় সাতটা সারি।
     *
     * ⓘ `due_at` খালি মানে ওই ধাপে ঘড়ি নেই — ওগুলো কখনো দেরি নয়।
     *
     * @template TModel of Approval
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function lateOnes($query, bool $only = true)
    {
        return $query->when($only, fn ($q) => $q
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()));
    }

    /**
     * ⓘ এই কোম্পানির সইকারীরা — সংজ্ঞাটা এখন ইঞ্জিনে।
     *
     * ── ⚠️ কেন সরানো হলো, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────
     * ফরওয়ার্ড বসানোর সময় ঠিক এই তালিকাটাই দ্বিতীয়বার দরকার হলো —
     * *"কার কাছে পাঠানো যায়"*। ⛔ দুই জায়গায় দুইবার লিখলে একদিন
     * একটা বদলাত আর অন্যটা পুরনো নিয়মে চলত, আর পার্থক্যটা **নীরব**
     * হত: ইনবক্স একজনকে দেখাত, বাছাইয়ের তালিকা আরেকজনকে।
     *
     * @return array<int, string>
     */
    private function theSigners(): array
    {
        return $this->engine->signers();
    }

    /** আমার করা অনুরোধগুলো — নতুন আগে। */
    /**
     * খোঁজা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: দুই তালিকাতেই খোঁজার ঘর।
     *
     * ⓘ যা পর্দায় দেখা যায় তা ধরেই: অনুরোধকারীর নাম, অনুরোধের কারণ, অঙ্ক, আর
     * কাগজের তথ্য (পক্ষ, কী বাবদ, কোথায় — `payload`)। ⚠️ খালি হলে কিছুই বদলায় না।
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    private static function searched($query, string $term)
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn ($q) => $q
            ->where('requested_reason', 'like', $like)
            ->orWhere('payload', 'like', $like)
            ->orWhere('amount', 'like', $like)
            ->orWhereHas('requester', fn ($u) => $u->where('name', 'like', $like)));
    }

    public function mine(Request $request): View
    {
        $approvals = self::searched(Approval::query(), (string) $request->query('q', ''))
            ->where('requested_by', $request->user()?->id)
            ->with(['decisions.user'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('approval::inbox.mine', [
            'menu' => $this->menu->forUser($request->user()),
            'labels' => $this->flows->labels(),
            'approvals' => $approvals,
            // ⭐ কাগজের নম্বর আর পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬; নিজের অনুরোধ, তাই দেয়াল তোলা ([[ApprovalFacts::VIEW_WALLS]])
            'facts' => $this->facts->of($approvals->getCollection(), true),
        ]);
    }

    /**
     * ⭐ সই হয়েছে, পোস্ট হয়নি — মালিক, ১১ অক্টোবর ২০২৬ (ক): শেষ সইয়ে কাগজ নিজেই পোস্ট হয়, যা আটকায় তা এখানে
     * ([[SignedButNotPosted]])। ⓘ সই দেন বা রিপোর্ট দেখেন যিনি — গোটা কোম্পানির; বাকিরা নিজের পাঠানো কাগজ — প্রশ্নটা
     * সেবার ভেতরে, তাই যেকোনো লগইন করা মানুষ পাতা খোলেন কিন্তু অন্যের কাগজ দেখেন না।
     */
    public function signedUnposted(Request $request): View
    {
        $signed = app(\App\Core\Engines\Approval\SignedButNotPosted::class);

        $approvals = self::searched($signed->query($request->user()), (string) $request->query('q', ''))
            ->with(['decisions.user'])
            ->orderByDesc('decided_at')
            ->paginate(50);

        return view('approval::inbox.mine', [
            'papers' => $signed->papers($request->user()),
            'menu' => $this->menu->forUser($request->user()),
            'labels' => $this->flows->labels(),
            'heading' => __('approval::menu.signed_unposted'),
            'emptyText' => __('approval::message.no_signed_unposted'),
            'approvals' => $approvals,
            /*
             * ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬। ⓘ দেয়াল তোলা: তালিকাটা আগেই বেছেছে কে কী দেখেন (সইকারী/রিপোর্ট —
             * গোটা কোম্পানি, বাকিরা নিজের পাঠানো; [[SignedButNotPosted::papers()]]), আর নম্বর ঐ একই দেয়াল-ছাড়া পথে আসে।
             */
            'facts' => $this->facts->of($approvals->getCollection(), true),
        ]);
    }

    public function show(Request $request, int $approval): View
    {
        /** @var User $user */
        $user = $request->user();

        $entry = Approval::query()->with(['requester', 'decisions.user', 'decisions.forwardedTo'])->findOrFail($approval);

        $detail = $this->detailFor($entry, $user);

        abort_if($detail === null, 403);

        return view('approval::inbox.show', [
            'menu' => $this->menu->forUser($user),
            ...$detail,

            /*
             * ⭐ আগের আর পরের কাগজ — কেবল যিনি অনুমোদন দিতে পারেন।
             *
             * ⓘ নিরীক্ষক বা অনুরোধকারীর কাছে *"পরেরটা"* কথাটারই কোনো অর্থ নেই — তাঁদের কোনো সারি নেই। ⛔ তবু তীর দুইটা দেখালে
             * সেগুলো মৃত বোতাম হত।
             */
            ...$this->neighboursOf($entry, $user, $detail['canDecide']),
        ]);
    }

    /**
     * ⭐ একটা কাগজের পুরো ছবি — কাগজের নিজের পাতা ([[show()]]) আর ইনবক্সের ডান দিক ([[index()]]) দুই জায়গাতেই, অনুমোদন পর্দা
     * ভাগ ২, দফা ১। ⛔ দুই জায়গায় আলাদা করে বানালে একদিন ইনবক্সের ডানে এক অঙ্ক আর পাতায় আরেক অঙ্ক দেখাত।
     *
     * ⓘ পাঠক পাতাটা খুলতেই না পারলে `null` — ডাকা পক্ষ ঠিক করে ৪০৩ দেবে ([[show()]]) নাকি তালিকার প্রথমটা খুলবে ([[index()]])।
     *
     * @return array<string, mixed>|null
     */
    private function detailFor(Approval $entry, User $user): ?array
    {
        $entry->loadMissing(['requester', 'decisions.user', 'decisions.forwardedTo', 'decisions.onBehalfOf']);

        /*
         * ── তিনটা আলাদা প্রশ্ন, আর ওদের এক করা যাবে না ──────────────
         *
         *   পাতাটা খোলা যাবে?      নিজের · সিদ্ধান্তদাতা · নিরীক্ষক
         *   কাগজটা দেখা যাবে?      নিজের · সিদ্ধান্তদাতা — নিরীক্ষক নয়
         *   বোতাম চাপা যাবে?       কেবল সিদ্ধান্তদাতা
         *
         * ── কেন নিরীক্ষক কাগজটা দেখেন না ────────────────────────────
         * `approval.report` দেয় অনুমোদনের **রেকর্ড** — কে চেয়েছে, কোন
         * স্তরে, কে কী মন্তব্যে সিদ্ধান্ত দিয়েছেন। ওটা নিরীক্ষার জিনিস,
         * আর ওই সংখ্যাগুলো রিপোর্টে এমনিতেই আছে।
         *
         * ⛔ কিন্তু নিচের কাগজটা আলাদা। `documentOf()` গোটা নথিটা তুলে
         * আনে — ক্রয় বিল, উত্তোলন, **আর বেতনের রান**। রেকর্ডের সাথে
         * ওটাও দিয়ে দিলে যে ম্যানেজারের `approval.report` আছে অথচ
         * HR-এর কিছুই নেই, তিনি অনুমোদনের পাতা দিয়ে **বেতনের কাগজ**
         * দেখে ফেলতেন — আর কোনো ত্রুটি আসত না।
         *
         * ⚠️ ধরাও পড়ত না: পরীক্ষা করা হত এমন একজনকে দিয়ে যাঁর দুইটা
         * অনুমতিই আছে। **যাঁর অনুমতি নেই তাঁকে দিয়ে না দেখলে অনুমতির
         * ফাঁক দেখা যায় না।**
         */
        $mine = $entry->requested_by === $user->id;
        $canDecide = $this->engine->canDecide($entry, $user);
        $auditing = $user->can('approval.report');

        /*
         * ⭐ চতুর্থ দল — খবর যাঁকে এই পাতায় পাঠিয়েছে (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬: "খবর যাঁকে কাগজে পাঠায় তিনি সেটা খোলেন;
         * না পারলে কারণটা বাংলায় দেখেন — খালি ৪০৩ কখনো নয়")। ⛔ আগে উপরে-পাঠানোর গন্তব্য যাঁর সীমা ছোট, মনে-করানো পাওয়া
         * সইকারী যাঁর কাগজ অন্যজনের হাতে, বা সীমার বাইরের সইকারী খবর চাপলেই ৪০৩ পেতেন — কেন, কেউ বলত না।
         * ⓘ পড়ার ছাড় কেবল **নিজের খবরে** — `approval.view` থাকলেই নয়; আর সই দেওয়া আগের মতোই কেবল [[ApprovalEngine::canDecide()]]।
         * কাগজটা (`$document`) এঁরা নিরীক্ষকের মতোই দেখেন না — রেকর্ড আর "কেন পারছেন না", এটুকুই।
         */
        $sentHere = ! ($mine || $canDecide || $auditing) && $this->sentHere($entry, $user);

        if (! ($mine || $canDecide || $auditing || $sentHere)) {
            return null;
        }

        $mayReadDocument = $mine || $canDecide;

        $document = $mayReadDocument ? $this->documentOf($entry) : null;

        $steps = $this->stepsOf(collect([$entry]))[(int) $entry->id];

        return [
            'approval' => $entry,

            // ⭐ "ধাপ ২ / ৩ · ম্যানেজার" — তালিকার অবিকল একই হিসাব (দফা ৮)
            'step' => $steps,
            'deadline' => $this->deadlineOf($entry),

            // ⭐ টাকা কথায় — দফা ৬, বিস্তারিতের মাথায় (অঙ্কের নিচে নয়, পাশে বন্ধনীতে, পরিকল্পনার ছবির মতো)
            'amountWords' => $entry->amount === null ? null : \App\Core\Support\AmountInWords::of((string) $entry->amount),

            // ⭐ কার হয়ে অনুমোদন দেবেন — কেবল যিনি দিতে পারেন (দফা ২)
            'standInFor' => $canDecide && ($for = $this->engine->standInFor($entry, $user)) !== null
                ? (string) User::query()->whereKey($for)->value('name')
                : null,

            /*
             * ⛔ পর্দায় যে অঙ্কটা লেখা, সেটা **সই চাওয়ার দিনের** অঙ্ক।
             *
             * ── ⚠️ কেন এটা বলা দরকার, ২২ সেপ্টেম্বর ২০২৬ ────────────
             * অনুমোদন পাওয়ার অপেক্ষায় থাকা কাগজ **খসড়াই থাকে, আর খসড়া
             * সম্পাদনা করা যায়**। ⓘ `approvals.amount` বসে অনুরোধের
             * সময়, তারপর আর বদলায় না।
             *
             * ⛔ ফলে সইকারী ৫০ হাজার দেখে সই দিতে পারতেন, অথচ কাগজটা
             * ততক্ষণে ৫ লাখ। ⚠️ টাকাটা পাশ হয় না — [[Approval::covers()]]
             * পোস্টের সময় আটকায় — কিন্তু **মানুষটা ভুল সংখ্যা দেখে সই
             * দিয়েছেন**, আর খাতায় তাঁর নামই থাকে।
             *
             * ── ⓘ কেন `updated_at`, অঙ্ক মিলিয়ে নয় ─────────────────
             * ইনবক্স সতেরো রকম কাগজ দেখায়, আর "অঙ্ক" প্রত্যেকটায় আলাদা
             * ঘরে থাকে। ⚠️ ওদের নাম জানতে গেলে কোরকে প্রতিটা মডিউলের
             * ভিতরে তাকাতে হত (§১৯.৭)। ⭐ "কাগজটা কি নড়েছে" প্রশ্নটার
             * উত্তর সব কাগজেই একভাবে আছে।
             *
             * ⚠️ তাই মাঝে মাঝে মিথ্যা সতর্কতা আসবে — সম্পর্কিত সারি
             * সেভ হলেও `updated_at` নড়ে। ⓘ সেটা মেনে নেওয়া হলো: এই
             * ভুলটা মানুষকে **তাকাতে** বলে, আর উল্টো ভুলটা তাঁকে না
             * জানিয়ে সই করায়।
             */
            /*
             * ⭐ ছাপ থাকলে ছাপ মিলিয়ে (১৩ অক্টোবর ২০২৬) — ⛔ `updated_at` সারির বদল (খাত, অঙ্ক) দেখত না, সইকারী "বদলায়নি" ভেবে সই
             * দিতেন। ছাপ না থাকলে বা মেলানো না গেলে উপরের ঘড়ির নিয়মই ([[DocumentFingerprint::changedSinceAsked()]])।
             */
            'changedSinceAsked' => $document !== null
                && app(\App\Core\Engines\Approval\DocumentFingerprint::class)->changedSinceAsked($entry, $document),
            'labels' => $this->flows->labels(),
            'document' => $document,

            /*
             * "নেই" আর "আপনার জন্য নয়" — পর্দাটা দুইটাকে এক দেখাতে
             * পারে না, তাই আলাদা একটা পতাকা। খালি ঘর দেখে নিরীক্ষক
             * ভাববেন কাগজটা মুছে গেছে, আর সেটা মিথ্যা।
             */
            'documentHidden' => ! $mayReadDocument,

            'canDecide' => $canDecide,
            // ⭐ "সংশোধনে ফেরত" আলাদা অবস্থায় যায় কি না — না গেলে পুরনো পথ ("না", কারণ: কাগজের ভুল)
            'canSendBack' => $canDecide && $this->engine->canSendBack($entry),

            /*
             * ⭐ কার কাছে পাঠানো যায় — কেবল ছকে নাম থাকা সইকারীরা।
             *
             * ⚠️ নিজেকে বাদ: কাগজটা নিজের হাতেই ফেরত দেওয়ার কোনো মানে
             * নেই, আর তালিকায় নিজের নাম থাকলে কেউ একবার ভুল করবেনই।
             *
             * ⓘ সিদ্ধান্ত দিতে পারেন না এমন কাউকে ফর্মটাই দেখানো হয় না
             * (`$canDecide`), তাই তালিকাটাও তখন খালি।
             */
            'forwardTo' => $canDecide
                ? array_diff_key($this->engine->signers(), [(int) $user->id => true])
                : [],

            // ⓘ স্তর ধরে ধাপের নাম — "ধাপ ২" কে, সেটা বলার জন্য
            'stepNames' => $this->engine->stepNamesFor($entry),

            /*
             * ⭐ ঘড়ির অবস্থা — ইনবক্সের অবিকল একই হিসাব।
             *
             * ⛔ দুই পর্দায় দুই রকম হলে তালিকায় *"সময় পার"* আর
             * খুললে *"সময়ের ভিতরে"* দেখাত — আর মানুষ কোনটাকে
             * বিশ্বাস করবেন বলতে পারতেন না।
             */
            'slaState' => $this->sla->stateOf($entry),

            /*
             * ⭐ যাত্রাপথ — কাগজটা কোথায় আছে, আর কী বাকি।
             *
             * ── ⚠️ সিদ্ধান্তের ইতিহাস এই প্রশ্নের উত্তর দেয় না ────
             * ⓘ ওই ছকটা বলে **যা হয়ে গেছে**। ⛔ কিন্তু যিনি সই দিচ্ছেন
             * তাঁর প্রশ্ন উল্টো: *"আমার পরে আর কয়জন আছেন?"* — কারণ
             * শেষ সইটা দিলে টাকাটা সত্যিই নড়ে।
             *
             * ⚠️ উত্তরটা আজ পর্যন্ত কোথাও লেখা ছিল না — ডাটাবেজে
             * ছিল, পর্দায় নয়।
             */
            'timeline' => $this->timelineOf($entry),

            /*
             * ⭐ পাশের তথ্য — কার, কী বাবদ, কোথায়।
             *
             * ── ⚠️ কেন এগুলো এই পাতায়ই থাকতে হয় ─────────────────
             * ⓘ যিনি সই দিচ্ড়েন তাঁর পরের প্রশ্ন সবসময় একটাই:
             * *"কার কাগজ, কী বাবদ"*। ⛔ উত্তরটা অন্য পর্দায় থাকলে
             * তিনি হয় সেখানে যান আর ফিরে এসে বোতামটা খোঁজেন, নয়
             * **না দেখেই সই দেন** — আর দ্বিতীয়টাই বেশি হয়।
             *
             * ⓘ তথ্যটা ইনবক্সের অবিকল একই সেবা থেকে ([[ApprovalFacts]]),
             * তাই দুই পর্দা কখনো দুই রকম কথা বলতে পারে না।
             *
             * ⚠️ কাগজটা এই পাঠকের জন্য না হলে তথ্যও নয় — নাহলে
             * নিরীক্ষক কাগজটা দেখতে পারতেন না অথচ পাশে ক্রেতার নাম
             * আর অঙ্ক লেখা থাকত।
             */
            'facts' => $mayReadDocument
                ? ($this->facts->of(collect([$entry]), true)[$entry->id] ?? [])
                : [],

            /*
             * ⭐ কাগজের নিজের সইয়ের পাতা — সারি, মাধ্যম, লেনদেন নম্বর — ২৮ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ মালিকের কাজ: সইকারী যেন এই পাতা ছেড়ে না গিয়েই পুরো কাগজটা
             * দেখেন ([[ShowsItselfForSigning]])। ⚠️ `$document` কাগজ দেখার
             * অনুমতি না থাকলে আগেই `null` — তাই নিরীক্ষক সারিও পান না।
             */
            /*
             * ⭐ এক বিক্রি, এক পাতা — চালান, বিল আর জমা একসাথে ([[ApprovalBundles]])।
             * ⓘ কাগজ দেখার অনুমতি না থাকলে দলও নয় — `$document`-এর একই শর্ত।
             */
            'bundle' => $mayReadDocument ? $this->bundleOf($entry) : null,

            'sheet' => $sheet = $document instanceof ShowsItselfForSigning
                ? $document->signingSheet()
                : null,

            /*
             * ⓘ যার কাগজ তার খবর — ফোন, ঠিকানা, বকেয়া, বাকির সীমা। মডিউলগুলো
             * নিজের নিজের অংশ দেয় ([[FactRegistry]], entity `{type}-card`);
             * অনুমোদন কারও ভিতরে তাকায় না।
             */
            'partyCard' => isset($sheet['party'])
                ? app(FactRegistry::class)->forRecord($sheet['party']['type'].'-card', $sheet['party']['id'])
                : [],

            /*
             * ⭐ পারছি না — কিন্তু **কেন**।
             *
             * ── ⛔ আগে পর্দা একটাই কথা বলত ────────────────────
             * *"আপনার পালা নয়"* — আর সেটা তিনটা আলাদা কারণের
             * উপর একটাই উত্তর হত: অন্য ধাপের কাজ, নিজের অনুরোধ,
             * বা **কর্তৃত্বের সীমা পার**।
             *
             * ⚠️ তৃতীযটা সবচেয়ে খারাপ হয়: মানুষটা ছকে আছেন, তাই
             * তিনি ধরে নেন কাগজটা অন্য কারো কাছে আছে, আর অপেক্ষা
             * করতে থাকেন — কাগজটা কারো কাছে নেই, আর সেটা কেউ বলে না।
             */
            'whyNot' => $canDecide ? null : $this->whyNot($entry, $user, $mine),
        ];
    }

    /**
     * ⭐ এই মানুষের ঘণ্টায় এমন খবর আছে যা এই অনুরোধের পাতায় পাঠায় — মনে করানো, উপরে পাঠানো, অনুরোধের ফল, নিয়মের যোগ করা
     * প্রাপক, যে-ই হোক।
     *
     * ⓘ ঠিকানার শেষটা মিলিয়ে (`/approvals/{id}`), পুরো ঠিকানা নয় — খবর লেখে কখনো সময়সূচি (APP_URL), কখনো ব্রাউজারের অনুরোধ
     * (তার নিজের ঠিকানা); দুইটার শুরু আলাদা হতে পারে। ⓘ খবর নিজের আর দেখার মতো ([[NotificationService::mine()]] — শাখার দেয়ালসহ)।
     */
    private function sentHere(Approval $approval, User $user): bool
    {
        $path = route('approval.inbox.show', $approval->id, false);

        return app(\App\Core\Services\NotificationService::class)->mine($user)
            ->where('url', 'like', '%'.$path)
            ->exists();
    }

    /**
     * ⭐ সই দিতে পারছেন না — তিনটা কারণের মধ্যে কোনটায়।
     *
     * ── ⚠️ ক্রমটা গুরুত্বপূর্ণ ──────────────────────────────
     * ⓘ নিজের অনুরোধ আগে, কারণ সেটাই সবচেয়ে পরিষ্কার উত্তর।
     * তারপর সীমা, কারণ ওটাই সবচেয়ে কম অনুমানযোগ্য।
     *
     * ⛔ সীমার কথাটা শুধু তাঁকে বলা হয় যিনি সত্যিই ওই ধাপে
     * আছেন। ⚠️ নাহলে যে কেউ পাতাটা খুলে *"আপনার কর্তৃত্বের
     * বাইরে"* পড়তেন, আর সেটা মিথ্যা: তাঁর কোনো কর্তৃত্বই নেই।
     */
    private function whyNot(Approval $approval, User $user, bool $mine): ?string
    {
        if ($approval->status !== Approval::PENDING) {
            return null;
        }

        if ($mine) {
            return __('approval::message.own_request');
        }

        /*
         * ⭐ কাগজটা অন্যজনের হাতে দেওয়া (`assigned_to`) — তখন ধাপের সইকারীরাও সই দেন না, যতক্ষণ না ফেরত আসে
         * ([[ApprovalEngine::canDecide()]])। ⛔ আগে এঁরা "এই স্তরে ছকে আপনি নেই" পড়তেন — মিথ্যা: ছকে আছেন, কাগজটা অন্যের কাছে।
         */
        if ($approval->assigned_to !== null && (int) $approval->assigned_to !== (int) $user->id) {
            return __('approval::message.forwarded_to_other', [
                'name' => (string) ($approval->assignee?->name ?? '—'),
            ]);
        }

        // ⓘ পালা যাঁর — ধাপের সইকারী, হাতে দেওয়া কাগজের মানুষ, বা উপরে-পাঠানোর গন্তব্য; সীমার কথা কেবল এঁদেরই বলা হয়
        $atThisLevel = (int) $approval->assigned_to === (int) $user->id
            || $this->engine->escalatedHere($approval, $user)
            || $this->engine->stepsFor($approval)
                ->where('level', (int) $approval->current_level)
                ->contains(fn ($step) => $step->allows($user));

        if ($atThisLevel && ! app(AuthorityService::class)->allows($user, $approval)) {
            return __('approval::message.beyond_your_authority');
        }

        return __('approval::message.not_your_turn');
    }

    /**
     * ⭐ প্রবাহের প্রতিটা ধাপ, আর এখন সেটা কোন অবস্থায়।
     *
     * ── ⓘ কেন সিদ্ধান্ত নয়, ধাপ ধরে ───────────────────────
     * সিদ্ধান্তগুলো ধরে সাজালে **যে ধাপে এখনো কেউ কিছু করেননি**
     * সেটা তালিকায় আসতই না — আর ঠিক সেটাই পাঠকের প্রশ্ন।
     *
     * @return list<array{level: int, name: string|null, state: string}>
     */
    private function timelineOf(Approval $approval): array
    {
        $names = $this->engine->stepNamesFor($approval);

        $decided = $approval->decisions
            ->groupBy('level')
            ->map(fn ($rows) => $rows->last()?->decision);

        /*
         * ⭐ একটা ধাপে কত সময় গেল — ঘণ্টায়।
         *
         * ⓘ গণনাটা **আগের সিদ্ধান্ত থেকে**, অনুরোধের দিন থেকে
         * নয়। ⛔ অনুরোধ ধরে গুনলে তৃতীয় ধাপে তিন ধাপের সময় যোগ
         * হয়ে দেখাত, আর সবসময় শেষ ধাপটাই সবচেয়ে ধীর দেখাত —
         * যে ধাপে সত্যি দেরি হয় তাকে কখনো দেখা যেত না।
         */
        $took = [];
        $from = $approval->requested_at;

        /*
         * ⛔ হিসাবটা **ধাপ ধরে**, সিদ্ধান্ত ধরে নয়।
         *
         * ⓘ একটা ধাপ **শেষ হয়** তার শেষ সইয়ে, আর **শুরু হয়**
         * আগের ধাপের শেষ সইয়ে (প্রথমটার বেলায় অনুরোধের দিন)।
         *
         * ⚠️ প্রতিটা সিদ্ধান্তে লিখলে দুইজনের সই লাগা ধাপে সংখ্যাটা
         * হত **দুই সইয়ের মাঝের ফাঁক** — অর্থাৎ যে ধাপে কিছু দিন
         * কেউ তাকাননি, সেটাই সবচেয়ে ছোট দেখাত।
         */
        $endOfLevel = $approval->decisions
            ->filter(fn ($d) => $d->decided_at !== null)
            ->groupBy('level')
            ->map(fn ($rows) => $rows->max('decided_at'))
            ->sortKeys();

        foreach ($endOfLevel as $level => $ended) {
            if ($from !== null) {
                $took[(int) $level] = $from->diffInHours($ended);
            }

            $from = $ended;
        }

        $out = [];

        /*
         * ⛔ ধাপগুলো [[ApprovalEngine::stepsFor()]] থেকে, নামগুলো থেকে নয়।
         *
         * ⚠️ `stepNamesFor()` **নামহীন ধাপ বাদ দেয়** — ওটা দিয়ে
         * যাত্রাপথ বানালে নাম না বসানো প্রবাহে পর্দাটা **নীরবে
         * খালি** থাকত, আর সেটা দেখতে *"কোনো ধাপ নেই"*-এর মতো।
         */
        $steps = $this->engine->stepsFor($approval);

        /*
         * ⓘ একই স্তরে একাধিক সইকারী থাকতে পারেন (N-of-M)।
         * ⚠️ তাই যাত্রাপথে সারি হয় **স্তর** ধরে, মানুষ ধরে নয় —
         * নাহলে তিনজনের একটা ধাপ তিনটা ধাপ দেখাত।
         */
        foreach ($steps->groupBy('level') as $level => $atLevel) {
            $out[] = [
                'level' => (int) $level,
                'name' => $names[$level] ?? null,
                'took' => $took[(int) $level] ?? null,

                // ⓘ এই স্তরে কয়জনের সই লাগে, আর কয়জন দিয়েছেন
                /*
                 * ⛔ হিসাবটা ইঞ্জিনের, এখানে দ্বিতীয়বার লেখা নয়।
                 *
                 * ⚠️ দুই জায়গায় লিখলে একদিন পর্দা বলত *"২-এর ১"*
                 * আর ইঞ্জিন কাগজটা এগিয়ে দিত — আর পাঠক কোনটাকে
                 * বিশ্বাস করবেন বলতে পারতেন না।
                 */
                'needed' => ApprovalEngine::signaturesNeededAt($atLevel),
                'signed' => $approval->decisions
                    ->where('level', (int) $level)
                    ->where('decision', ApprovalDecision::APPROVED)
                    ->count(),

                'state' => match (true) {
                    isset($decided[$level]) => (string) $decided[$level],
                    (int) $level === (int) $approval->current_level
                        && $approval->status === Approval::PENDING => 'now',
                    default => 'waiting',
                },

                /*
                 * ⭐ এই ধাপে কে কখন কী বললেন — দফা ১০ (অনুমোদন পর্দা ভাগ ২): সময়, মন্তব্য, আর দায়িত্ব হস্তান্তরে হলে কার হয়ে।
                 * ⓘ অন্যের হাতে দেওয়াও থাকে (কার কাছে গেল সহ) — শিকলটা যেন না ছেঁড়ে।
                 */
                'by' => $approval->decisions
                    ->where('level', (int) $level)
                    ->sortBy('decided_at')
                    ->map(fn (ApprovalDecision $d) => [
                        'name' => (string) ($d->user?->name ?? '—'),
                        'decision' => (string) $d->decision,
                        'at' => $d->decided_at,
                        'remarks' => $d->remarks,
                        'for' => $d->onBehalfOf?->name,
                        'to' => $d->forwardedTo?->name,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $out;
    }

    /**
     * ⭐ এই সারির আগে আর পরে কোনটা — ইনবক্সের অবিকল একই ক্রমে।
     *
     * ── ⚠️ কেন তালিকাটাই তোলা হয় ─────────────────────────
     * ⓘ ক্রমটা `requested_at` ধরে, তাই *"এর পরেরটা"* একটা
     * `where` দিয়েও বের করা যেত। ⛔ কিন্তু দুইটা অনুরোধের সময় এক
     * হলে সেটা লুপে পড়ত — দুইটা একে অপরকে *"পরেরটা"* বলত।
     *
     * ⓘ তালিকাটা ইনবক্সের সীমাতেই বাঁধা, আর কেবল চাবিগুলো —
     * পঞ্চাশটা সংখ্যা, সারি নয়।
     *
     * @return array{prev: int|null, next: int|null}
     */
    private function neighboursOf(Approval $approval, User $user, bool $canDecide): array
    {
        if (! $canDecide) {
            return ['prev' => null, 'next' => null];
        }

        $queue = $this->engine->pendingQueryFor($user)
            ->withoutEagerLoads()
            ->limit(self::INBOX_LIMIT)
            ->pluck('id')
            ->all();

        $at = array_search((int) $approval->id, array_map('intval', $queue), true);

        if ($at === false) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $queue[$at - 1] ?? null,
            'next' => $queue[$at + 1] ?? null,
        ];
    }

    /**
     * ⭐ একসাথে অনেকগুলো — তবে টাকা নড়ার কাজে নয়।
     *
     * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ──────────────
     * পরিশোধ, উত্তোলন, টাকা স্থানান্তর, আদায়, বছর বন্ধ —
     * একটা একটা করে দেখে সই দিতে হবে।
     *
     * ⛔ নিয়মটা [[BulkApproval]]-এ, এখানে নয় — বোতাম লুকানো
     * নিরাপত্তা নয়, আর কেউ সরাসরি POST করলেও আটকাতে হবে।
     */
    /**
     * ⭐ দফা ১৩ — একসাথে সইয়ের আগে দেখা (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬): কয়টা সই হবে, মোট কত টাকা, আর কোনগুলো
     * কেন বাদ। ⓘ কিছু লেখে না; নিয়ম [[BulkApproval::review()]]-এর, যেটা আসল সইও মানে — তাই দেখা আর করা মেলে।
     */
    public function bulkReview(Request $request): View
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer']]);
        $review = app(BulkApproval::class)->review(array_map('intval', $data['ids']), $request->user());

        return view('approval::inbox.bulk-review', [
            'menu' => $this->menu->forUser($request->user()),
            'sign' => $review['sign'],
            'skip' => $review['skip'],
            'total' => $review['total'],
        ]);
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = app(BulkApproval::class)->approve(
            array_map('intval', $data['ids']),
            $request->user(),
            $data['remarks'] ?? null,
        );

        /*
         * ⓘ যা হয়নি তাও বলা হয়, কারণসহ।
         *
         * ⚠️ "৪টা হলো" বলে চুপ করলে বাকি তিনটা কোথায় গেল
         * তা কেউ জানত না, আর মানুষ ভাবতেন সবগুলোই হয়ে গেছে।
         */
        $note = __('approval::message.bulk_done', ['count' => $result['done']]);

        foreach ($result['skipped'] as $why => $count) {
            $note .= ' · '.__('approval::message.bulk_skipped_'.$why, ['count' => $count]);
        }

        return redirect()
            ->route('approval.inbox.index')
            ->with('saved', $note);
    }

    public function approve(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        $entry = Approval::query()->findOrFail($approval);

        $this->engine->approve($entry, $request->user(), $validated['remarks'] ?? null);

        return $this->backToInbox($request)
            ->with('saved', __('approval::message.approved'));
    }

    /**
     * প্রত্যাখ্যানে মন্তব্য বাধ্যতামূলক।
     *
     * "না" শুনে মানুষ প্রথমেই জানতে চান কেন। কারণটা না লিখলে তিনি
     * একই অনুরোধ আবার পাঠান, আর দ্বিতীয়বারও একই কারণে না হয়।
     */
    /**
     * ⭐ কাগজটা অন্যের হাতে দেওয়া।
     *
     * ── ⓘ কারণ লেখা বাধ্যতামূলক, ঠিক ফেরত পাঠানোর মতো ───────────────
     * কাগজটা কারো হাতে এসে পড়লে তাঁর প্রথম প্রশ্ন *"আমাকে কেন?"*। ⛔
     * উত্তর না থাকলে তিনি আবার কাউকে পাঠান, আর কাগজটা ঘুরতেই থাকে।
     *
     * ── ⚠️ কার কাছে যাবে, সেটা এখানে যাচাই হয় না ────────────────────
     * নিয়মটা ইঞ্জিনে ([[ApprovalEngine::forward()]]), কারণ ওটা
     * ব্যবসার নিয়ম — পর্দার নয়। ⓘ এখানে বসালে আগামীকাল কোনো কনসোল
     * কমান্ড বা API ওটা এড়িয়ে যেতে পারত।
     */
    /**
     * এক বিক্রির সব অপেক্ষমাণ অনুরোধ একসাথে নিশ্চিত — ২৮ সেপ্টেম্বর ২০২৬, মালিকের কাজ।
     *
     * ⓘ মালিক: *"এটা অনুমোদন বললে ভুল হবে, এটা কনফার্মেশন"* — সিস্টেম আগেই আটকে
     * রেখেছে, মানুষটা কেবল পুরো ছবিটা দেখে হ্যাঁ বলেন।
     *
     * ⛔ সব বা কিছুই না: দলের একটা কাগজও এই মানুষটার পালা না হলে ([[ApprovalEngine::canDecide()]])
     * কিছুই সই হয় না, আর কোনগুলো তাঁর নয় তা বলা হয় — আধা-সই করা বিক্রি থাকে না।
     * ⓘ প্রতিটা অনুরোধ তবু নিজের সিদ্ধান্ত-সারি পায় (ইঞ্জিনের `approve()`), একই মানুষ,
     * একই মুহূর্ত; শেষ সইয়ে বিক্রিটা নিজেই শেষ হয় ([[FinishTheHeldSaleOnTheLastSignature]])।
     */
    public function confirmAll(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        /** @var User $user */
        $user = $request->user();
        $entry = Approval::query()->findOrFail($approval);
        $bundle = $this->bundleOf($entry);

        abort_if($bundle === null, 404);

        $waiting = collect($bundle['approvals'])
            ->filter(fn (Approval $a) => $a->status === Approval::PENDING)
            ->values();

        if ($waiting->isEmpty()) {
            throw ValidationException::withMessages(['confirm' => __('approval::message.bundle_nothing')]);
        }

        $notYours = $waiting->reject(fn (Approval $a) => $this->engine->canDecide($a, $user));

        if ($notYours->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm' => __('approval::message.bundle_not_yours', [
                'papers' => $notYours->map(fn (Approval $a) => $this->paperName($a))->implode(', '),
            ])]);
        }

        $remarks = $validated['remarks'] ?? __('approval::message.bundle_remark');

        DB::transaction(function () use ($waiting, $user, $remarks): void {
            foreach ($waiting as $pending) {
                $this->engine->approve($pending->fresh(), $user, $remarks);
            }
        });

        return $this->backToInbox($request)
            ->with('saved', __('approval::message.bundle_confirmed', ['count' => $waiting->count()]));
    }

    /** দলটা — যে মডিউল জানে সে বাঁধে; না বাঁধলে `null`। */
    private function bundleOf(Approval $entry): ?array
    {
        return app()->bound(ApprovalBundles::class)
            ? app(ApprovalBundles::class)->bundleFor($entry)
            : null;
    }

    /** কাগজের নাম — নম্বর থাকলে নম্বর, নাহলে ধরন আর আইডি। */
    private function paperName(Approval $entry): string
    {
        // ⓘ ভুল-বার্তার নাম — পাঠক সইকারী নাও হতে পারেন, তাই দেয়ালসহ খোঁজা (৯ অক্টোবর ২০২৬, অডিট)
        $class = (string) $entry->approvable_type;
        $document = class_exists($class) ? $class::query()->find($entry->approvable_id) : null;

        return $document !== null && method_exists($document, 'drillDocumentNo')
            ? (string) $document->drillDocumentNo()
            : class_basename((string) $entry->approvable_type).' #'.$entry->approvable_id;
    }

    public function forward(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'integer', 'min:1'],
            'remarks' => ['required', 'string', 'max:500'],
        ]);

        $entry = Approval::query()->findOrFail($approval);

        /*
         * ⚠️ `User::query()` কোম্পানির দেয়ালের বাইরে — `User`-এ কোনো
         * গ্লোবাল স্কোপ নেই। ⓘ তাই ব্যক্তিটা সত্যিই এই কোম্পানির সইকারী
         * কি না সেটা ইঞ্জিন মেলায়; এখানে কেবল সারিটা তোলা হয়।
         */
        $to = User::query()->findOrFail($validated['to']);

        $this->engine->forward($entry, $request->user(), $to, $validated['remarks']);

        return $this->backToInbox($request)
            ->with('saved', __('approval::message.forwarded', ['name' => $to->name]));
    }

    public function reject(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],

            /*
             * ⭐ কারণ-কোড — ঐচ্ছিক, আর সেটা ইচ্ছাকৃত।
             *
             * ⛔ বাধ্যতামূলক করলে পুরনো সব ডাকা জায়গা ভাঙত —
             * আর একজন মানুষ যত দ্রুত *"দাম ঠিক নেই"* লিখতে পারেন,
             * তার আগে একটা ড্রপডাউন বাধ্য করলে তিনি যেকোনো একটা
             * বেছে দিতেন, আর রিপোর্টটা দেখতে পরিষ্কার হয়ে মিথ্যা হত।
             *
             * ⓘ অচেনা কোড [[ApprovalEngine::reject()]] নিজে "অন্য" করে,
             * তাই এখানে `Rule::in` নয় — দুই জায়গায় একই তালিকা রাখা
             * হয় না।
             */
            'reason_code' => ['nullable', 'string', 'max:32'],
        ]);

        $entry = Approval::query()->findOrFail($approval);

        $this->engine->reject(
            $entry,
            $request->user(),
            $validated['remarks'],
            ($validated['reason_code'] ?? '') !== '' ? $validated['reason_code'] : null,
        );

        return $this->backToInbox($request)
            ->with('saved', __('approval::message.rejected'));
    }

    /**
     * ⭐ সংশোধনে ফেরত — নোট বাধ্যতামূলক (মালিকের আদেশ, ১৪ অক্টোবর ২০২৬)। ⓘ নিয়ম ইঞ্জিনে ([[ApprovalEngine::sendBack()]]) — ফোনও একই পথে।
     */
    public function sendBack(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        $this->engine->sendBack(Approval::query()->findOrFail($approval), $request->user(), $validated['remarks']);

        return $this->backToInbox($request)
            ->with('saved', __('approval::message.sent_back'));
    }

    /** অনুরোধকারী নিজে প্রত্যাহার করলে। */
    public function withdraw(Request $request, int $approval): RedirectResponse
    {
        $entry = Approval::query()->findOrFail($approval);

        $this->engine->cancel($entry, $request->user());

        return redirect()
            ->route('approval.inbox.mine')
            ->with('saved', __('approval::message.withdrawn'));
    }

    /**
     * ⭐ শেষ সময় — লেখায়, মাউস রাখার অপেক্ষায় নয় (অনুমোদন পর্দা ভাগ ২, দফা ৭): "শেষ সময় আজ 5:00", "শেষ সময় কাল 11:00",
     * "শেষ সময় পেরিয়েছে 3 ঘণ্টা", "দেরি 2 দিন"। ঘড়ি না থাকলে `null` — "—" বসিয়ে জায়গা নেওয়া হয় না।
     *
     * ⓘ রং [[ApprovalSla::stateOf()]]-এর, লেখা নয় — তাই তালিকা, কাগজের পাতা আর ঘণ্টার কমান্ড "দেরি কি না" প্রশ্নে কখনো দুই কথা বলে
     * না। ⛔ রং একা কিছু বলে না: লেখাতেই "পেরিয়েছে"/"দেরি" থাকে (সাদাকালো ছাপা, রং-কানা পাঠক)।
     *
     * @return array{text: string, tone: string}|null
     */
    private function deadlineOf(Approval $approval): ?array
    {
        $due = $approval->due_at;

        if ($due === null || $approval->status !== Approval::PENDING) {
            return null;
        }

        $state = $this->sla->stateOf($approval);
        $now = now();
        $time = $due->format(\App\Core\Support\DateFormat::time());

        $text = match (true) {
            $due->lessThan($now) && (int) floor($due->diffInHours($now, true)) < 24 => __('approval::message.due_passed_hours', [
                'hours' => max(1, (int) floor($due->diffInHours($now, true))),
            ]),
            $due->lessThan($now) => __('approval::message.due_late_days', ['days' => (int) floor($due->diffInDays($now, true))]),
            $due->isSameDay($now) => __('approval::message.due_today', ['time' => $time]),
            $due->isSameDay($now->copy()->addDay()) => __('approval::message.due_tomorrow', ['time' => $time]),
            default => __('approval::message.sla_due', ['when' => \App\Core\Support\DateFormat::spoken($due)]),
        };

        if ($state === ApprovalSla::ESCALATED) {
            $text .= ' · '.__('approval::message.sla_escalated');
        }

        return [
            'text' => $text,
            'tone' => match ($state) {
                ApprovalSla::LATE, ApprovalSla::ESCALATED => 'danger',
                ApprovalSla::NEAR => 'warning',
                default => 'success',
            },
        ];
    }

    /** ⓘ ইনবক্সের যে ছাঁকনিগুলো সিদ্ধান্তের পরে ফেরার পথে সাথে যায় — বাকি সব ফেলে দেওয়া হয় */
    private const KEEP_AFTER_DECISION = ['tab', 'module', 'branch', 'amount', 'late', 'sort', 'q', 'page'];

    /**
     * ⭐ সিদ্ধান্তের পরে ইনবক্সে ফেরা — একই ট্যাব, একই ছাঁকনি, আর পরের কাগজ খোলা (অনুমোদন পর্দা ভাগ ২, দফা ১: "অনুমোদন দিলে পরের
     * কাগজ নিজে খোলে")। ⓘ ফর্ম দুইটা জিনিস পাঠায়: `next` (তালিকার পরের সারি, [[nextAfterDecision()]]) আর `from` (ইনবক্সের ঠিকানার
     * প্রশ্ন-অংশ)। ⛔ `from` থেকে কেবল চেনা চাবিগুলো নেওয়া হয় — অন্য কিছু (ধরুন বাইরের ঠিকানা) ফেরার পথে ঢোকে না।
     */
    private function backToInbox(Request $request): RedirectResponse
    {
        parse_str((string) $request->input('from', ''), $from);

        $keep = [];

        foreach (self::KEEP_AFTER_DECISION as $key) {
            if (isset($from[$key]) && is_scalar($from[$key]) && (string) $from[$key] !== '') {
                $keep[$key] = (string) $from[$key];
            }
        }

        $next = (int) $request->input('next', 0);

        if ($next > 0) {
            $keep['open'] = $next;
        }

        return redirect()->route('approval.inbox.index', $keep);
    }

    /**
     * যে ডকুমেন্টটার জন্য অনুরোধ — থাকলে।
     *
     * মুছে গিয়ে থাকলে null, আর পর্দায় সেটা লেখা থাকে। না লিখলে
     * "ডকুমেন্ট খুলুন" বোতামটা চাপার পর কিছুই হত না।
     */
    private function documentOf(Approval $entry): ?object
    {
        $class = $entry->approvable_type;

        if (! class_exists($class)) {
            return null;
        }

        // ⓘ হেডারের শাখা সইয়ের কাগজ লুকায় না — [[ApprovalFacts::VIEW_WALLS]]; ডাক আসে কেবল `$mayReadDocument`-এ
        return $class::query()->withoutGlobalScopes(ApprovalFacts::VIEW_WALLS)->find($entry->approvable_id);
    }
}
