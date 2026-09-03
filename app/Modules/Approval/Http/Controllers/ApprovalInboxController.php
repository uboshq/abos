<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
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
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalEngine $engine,
        private readonly ApprovalFlowService $flows,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:approval.view', only: ['mine']),
            new Middleware('can:approval.decide', only: ['index', 'approve', 'reject']),

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

        // পুরনোটা আগে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই কাউকে
        // সবচেয়ে বেশিক্ষণ আটকে রেখেছে (ইঞ্জিনই ওই ক্রমে দেয়)
        $waiting = $this->engine->pendingFor($user);

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
         */
        $counts = $waiting->countBy('module');
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
        if ($selected !== '') {
            $waiting = $waiting->where('module', $selected)->values();
        }

        return view('approval::inbox.index', [
            'menu' => $this->menu->forUser($user),
            'approvals' => $waiting,
            'labels' => $this->flows->labels(),
            'modules' => $modules,
            'selected' => $selected,
            'total' => $counts->sum(),
        ]);
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

        $entry = Approval::query()->with(['requester', 'decisions.user'])->findOrFail($approval);

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

        return view('approval::inbox.show', [
            'menu' => $this->menu->forUser($user),
            'approval' => $entry,
            'labels' => $this->flows->labels(),
            'document' => $mayReadDocument ? $this->documentOf($entry) : null,

            /*
             * "নেই" আর "আপনার জন্য নয়" — পর্দাটা দুইটাকে এক দেখাতে
             * পারে না, তাই আলাদা একটা পতাকা। খালি ঘর দেখে নিরীক্ষক
             * ভাববেন কাগজটা মুছে গেছে, আর সেটা মিথ্যা।
             */
            'documentHidden' => ! $mayReadDocument,

            'canDecide' => $canDecide,
        ]);
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
    public function reject(Request $request, int $approval): RedirectResponse
    {
        $validated = $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        $entry = Approval::query()->findOrFail($approval);

        $this->engine->reject($entry, $request->user(), $validated['remarks']);

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
