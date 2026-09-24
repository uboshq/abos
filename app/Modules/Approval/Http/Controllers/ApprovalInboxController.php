<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Engines\Approval\AuthorityService;
use App\Core\Engines\Approval\BulkApproval;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Builder;
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
            new Middleware('can:approval.decide', only: ['approve', 'reject', 'forward', 'bulkApprove']),

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

    /** আমার সিদ্ধান্তের অপেক্ষায়। */
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
        abort_unless($user->can('approval.decide') || $user->can('approval.report'), 403);

        /*
         * কার ইনবক্স — নিজের, নাকি অন্য কারো?
         *
         * ── কেন এটা `approval.report`-এর পেছনে ──────────────────────
         * "রহিমের অপেক্ষায় কী কী" জানা মানে **রহিমের কাজের চাপ জানা** —
         * ম্যানেজার, নিরীক্ষক ও মালিকের প্রশ্ন, সহকর্মীর নয়। ওটা ঠিক
         * তাঁদেরই চাবি যাঁরা গোটা কোম্পানির অপেক্ষমাণ তালিকা দেখেন।
         *
         * ⓘ চতুর্থ একটা নতুন চাবি বানানো হয়নি ইচ্ছে করে: প্রতিটা ক্রেতা
         * হাতে রোল সাজান, আর একটা ঘর বসাতে ভুলে গেলে পর্দাটা **নীরবে
         * খালি** দেখাত।
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

        /*
         * পুরনোটা আগে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই কাউকে
         * সবচেয়ে বেশিক্ষণ আটকে রেখেছে (ইঞ্জিনই ওই ক্রমে দেয়)।
         *
         * ⚠️ কোয়েরিটা এখানে **চালানো হয় না** — নিচে দুইবার আলাদা করে
         * চলে, দুইটা আলাদা প্রশ্নের জন্য: চিপের সংখ্যাগুলো পুরো
         * তালিকার, আর সারিগুলো সীমার ভেতরে।
         */
        $pending = $this->engine->pendingQueryFor($subject);

        /*
         * মডিউল ধরে ছাঁকনি — §২.২।
         *
         * ── কেন গণনাটা তালিকা থেকেই, ডাটাবেস থেকে নয় ────────────────
         * সারিগুলো ইতিমধ্যে হাতে আছে, তাই আলাদা একটা `count` কোয়েরি
         * পাঠানো মানে একই প্রশ্ন দুইবার করা। ⓘ আর সংখ্যাটা তখন
         * তালিকার সাথে **মিলতেও বাধ্য** — দুই জায়গা থেকে গুনলে একদিন
         * চিপে ৫ আর তালিকায় ৪ দেখাত, আর কোনটা সত্যি তা বলার উপায়
         * থাকত না।
         *
         * ⚠️ ছাঁকনিটা মূল তালিকা **কমায় না, বাছে** — চিপের সংখ্যাগুলো
         * সবসময় পুরো তালিকার, নাহলে "ক্রয় ৫" বেছে নেওয়ার পর বাকি
         * চিপগুলো শূন্য দেখাত।
         *
         * ── কেন গোনাটা এখন ডাটাবেজে, তালিকা থেকে নয় (১২ সেপ্টেম্বর ২০২৬) ──
         * আগে ছিল `$waiting->countBy('module')` — অর্থাৎ পুরো তালিকাটা
         * হাতে ছিল বলে গোনাটা বিনামূল্যে হত, আর উপরের যুক্তিটাও তাই
         * খাটত। কিন্তু তালিকাটা সীমাহীন ছিল: যে ম্যানেজার ছয় মাস
         * অনুমোদন করেননি, তাঁর ইনবক্স হাজার সারি টানত।
         *
         * সীমা বসানোর পর ওই গোনাটা **আর করা যায় না** — সে তখন কেবল
         * প্রথম পঞ্চাশটার কথা বলত, আর চিপে "ক্রয় ৫০" দেখাত যেখানে
         * সত্যিকারের সংখ্যা ১৩৭। তাই গোনাটা নেমে এসেছে একটা group-by
         * কোয়েরিতে, যেটা সারি না তুলেই পুরো তালিকার হিসাব দেয়।
         *
         * ⛔ `reorder()` — গোনার কোয়েরিতে ক্রমের কোনো মানে নেই, আর
         * `requested_at` ধরে সাজানো একটা group-by কড়া MySQL সরাসরি
         * খারিজ করে (ONLY_FULL_GROUP_BY)।
         */
        $counts = (clone $pending)
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
         * বেছে নেওয়া মডিউলটা ঘোষিত নয় — তবু ছাঁকনিটা মানা হয়।
         *
         * ফলে তালিকা খালি দেখাবে, আর সেটাই সৎ: পুরনো একটা লিংক ধরে
         * এসে "সব" দেখলে মানুষ ভাবতেন ছাঁকনিটা কাজ করেনি।
         */
        /*
         * এখন সারিগুলো — ছাঁকনিসহ, আর সীমার ভেতরে।
         *
         * ⚠️ ছাঁকনিটা **কোয়েরিতে**, আগের মতো তোলা তালিকার উপর নয়। উপরে
         * ছাঁকলে সীমাটা ভুল জায়গায় পড়ত: ডাটাবেজ প্রথম পঞ্চাশটা দিত
         * (সব মডিউল মিলিয়ে), আর তারপর তার ভেতর থেকে "ক্রয়" বাছা হত —
         * ফলে ক্রয়ের একশো সারি থাকলেও পর্দায় হয়তো তিনটা আসত, আর
         * চিপে লেখা থাকত ১০০। সীমা সবসময় ছাঁকনির পরে বসতে হয়।
         */
        /*
         * ⭐ দেরির ছাঁকনি — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ শর্তটা একটাই জায়গায় লেখা ([[lateOnes]]), কারণ এখানে
         * তিনবার লাগে: চিপের সংখ্যা, সারিগুলো, আর মোট। ⛔ তিন
         * জায়গায় লিখলে একদিন চিপে ১২ আর তালিকায় ৭ দেখাত।
         */
        $late = $request->boolean('late');

        $lateCount = self::lateOnes((clone $pending)->reorder()->withoutEagerLoads())->count();

        $waiting = self::lateOnes(
            (clone $pending)->when($selected !== '', fn ($q) => $q->where('module', $selected)),
            $late,
        )
            ->limit(self::INBOX_LIMIT)
            ->get();

        /*
         * এখন যা দেখা যাচ্ছে তার সত্যিকারের মোট — ছাঁকনি ধরে।
         *
         * ছাঁকনি থাকলে ওই মডিউলের সংখ্যা, নাহলে সবার যোগফল। এই
         * সংখ্যাটাই উপরে "কয়টা" বলে, আর কাটা পড়েছে কি না তাও এটাই ঠিক
         * করে — `$waiting->count()` দিয়ে করলে দুইটাই বড়জোর পঞ্চাশ বলত।
         */
        /*
         * ⚠️ দেরির ছাঁকনি চালু থাকলে গোনাটা আলাদা করে করতে হয়।
         *
         * ⓘ `$counts` গোনা হয় ছাঁকনির **আগে**, মডিউল ধরে — তাই
         * ওটা *"দেরিগুলো কয়টা"* প্রশ্নের উত্তর দিতে পারে না।
         * ⛔ ওটাই দেখালে শিরোনামে *"১৩৭টি"* আর নিচে তিনটা সারি
         * থাকত, আর মানুষ ভাবতেন পাতাটা ভাঙা।
         */
        $visibleTotal = $late
            ? self::lateOnes(
                (clone $pending)->reorder()->withoutEagerLoads()
                    ->when($selected !== '', fn ($q) => $q->where('module', $selected)),
            )->count()
            : ($selected !== ''
                ? (int) $counts->get($selected, 0)
                : (int) $counts->sum());

        return view('approval::inbox.index', [
            'menu' => $this->menu->forUser($user),
            'approvals' => $waiting,

            /*
             * ⭐ কার · কী বাবদ · কোথায় — ২০ সেপ্টেম্বর ২০২৬। কাগজগুলো ধরন
             * ধরে একবারে তোলা হয় ([[ApprovalFacts]]), সারি ধরে নয়।
             */
            'facts' => $this->facts->of($waiting),
            'visibleTotal' => $visibleTotal,
            'labels' => $this->flows->labels(),
            'modules' => $modules,
            'selected' => $selected,
            // "সব" চিপের সংখ্যা — সবসময় পুরো তালিকার, ছাঁকনি নির্বিশেষে
            'total' => (int) $counts->sum(),

            /*
             * ব্যক্তির তালিকা — কেবল যাঁর অনুমতি আছে তাঁর জন্য, আর
             * একজনের বেশি থাকলে।
             *
             * ⚠️ "সবাই" বলে কোনো বিকল্প নেই, আর সেটা ইচ্ছাকৃত:
             * `pendingFor()` একজনের প্রশ্নের উত্তর দেয়। "সবার অপেক্ষমাণ"
             * প্রশ্নটার উত্তর **রিপোর্টের**, ইনবক্সের নয় — আর দুই
             * জায়গায় রাখলে সংখ্যা দুইটা একদিন আলাদা হত।
             */
            'signers' => $signers,
            'person' => $subject->id === $user->id ? 0 : $subject->id,
            'personName' => $subject->name,

            /*
             * ⭐ প্রতিটা সারির ঘড়ির অবস্থা — পর্দা নিজে হিসাব করে না।
             *
             * ⛔ ব্লেডে `now()` আর `due_at` মিলাতে গেলে তিন জায়গায়
             * তিন রকম হিসাব হত ([[ApprovalSla]]-এর মাথায় লেখা)।
             */
            'sla' => $waiting->mapWithKeys(
                fn (Approval $a) => [$a->id => $this->sla->stateOf($a)],
            )->all(),

            'late' => $late,
            'lateCount' => $lateCount,

            /*
             * ⭐ কোন সারিগুলো একসাথে সই করা যায় — মালিকের সিদ্ধান্ত ৫।
             *
             * ⚠️ পর্দায় চেকবক্সটা **দেখানোই হয় না** টাকার কাগজে।
             * ⓘ তবু [[BulkApproval]] সার্ভারেও আলাদা করে দেখে — কারণ
             * লুকানো একটা চেকবক্স হাতে বানিয়ে পাঠানো যায়, আর পর্দা
             * কখনো শেষ কথা নয়।
             */
            'bulkable' => $waiting->reject(
                fn (Approval $a) => $this->bulk->movesMoney($a),
            )->pluck('id')->all(),
        ]);
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
    public function mine(Request $request): View
    {
        return view('approval::inbox.mine', [
            'menu' => $this->menu->forUser($request->user()),
            'labels' => $this->flows->labels(),
            'approvals' => Approval::query()
                ->where('requested_by', $request->user()?->id)
                ->with(['decisions.user'])
                ->orderByDesc('id')
                ->paginate(50),
        ]);
    }

    public function show(Request $request, int $approval): View
    {
        /** @var User $user */
        $user = $request->user();

        $entry = Approval::query()->with(['requester', 'decisions.user', 'decisions.forwardedTo'])->findOrFail($approval);

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

        abort_unless($mine || $canDecide || $auditing, 403);

        $mayReadDocument = $mine || $canDecide;

        $document = $mayReadDocument ? $this->documentOf($entry) : null;

        return view('approval::inbox.show', [
            'menu' => $this->menu->forUser($user),
            'approval' => $entry,

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
            'changedSinceAsked' => $document !== null
                && $entry->requested_at !== null
                && $document->getAttribute('updated_at') !== null
                && $document->getAttribute('updated_at')->greaterThan($entry->requested_at),
            'labels' => $this->flows->labels(),
            'document' => $document,

            /*
             * "নেই" আর "আপনার জন্য নয়" — পর্দাটা দুইটাকে এক দেখাতে
             * পারে না, তাই আলাদা একটা পতাকা। খালি ঘর দেখে নিরীক্ষক
             * ভাববেন কাগজটা মুছে গেছে, আর সেটা মিথ্যা।
             */
            'documentHidden' => ! $mayReadDocument,

            'canDecide' => $canDecide,

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
                ? ($this->facts->of(collect([$entry]))[$entry->id] ?? [])
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

            /*
             * ⭐ আগের আর পরের কাগজ — কেবল যিনি সই দিতে পারেন।
             *
             * ⓘ নিরীক্ষক বা অনুরোধকারীর কাছে *"পরেরটা"* কথাটারই কোনো
             * অর্থ নেই — তাঁদের কোনো সারি নেই। ⛔ তবু তীর দুইটা
             * দেখালে সেগুলো মৃত বোতাম হত।
             */
            ...$this->neighboursOf($entry, $user, $canDecide),
        ]);
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

        $atThisLevel = $this->engine->stepsFor($approval)
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

        return redirect()
            ->route('approval.inbox.index')
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

        return redirect()
            ->route('approval.inbox.index')
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

        return redirect()
            ->route('approval.inbox.index')
            ->with('saved', __('approval::message.rejected'));
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

        return $class::query()->find($entry->approvable_id);
    }
}
