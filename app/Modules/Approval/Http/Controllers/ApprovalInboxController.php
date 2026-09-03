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
             * show দুই ধরনের মানুষ খোলেন — যিনি সিদ্ধান্ত দেবেন, আর
             * যিনি অনুরোধ করেছেন। তাই এখানে কেবল "কিছু একটা অনুমতি
             * আছে" দেখা হয়, আর কে কী করতে পারবেন সেটা পর্দাতেই ঠিক হয়।
             */
            new Middleware('can:approval.view', only: ['show', 'withdraw']),
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
         * অন্যের অনুরোধ, আর আমি সিদ্ধান্তের ছকেও নেই — তাহলে এটা আমার
         * দেখার জিনিস নয়। ছাড়ের অঙ্ক আর গ্রাহকের নাম দুইটাই এখানে
         * থাকে, তাই "লিংক জানলেই দেখা যায়" চলে না।
         */
        if ($entry->requested_by !== $user->id && ! $this->engine->canDecide($entry, $user)) {
            abort(403);
        }

        return view('approval::inbox.show', [
            'menu' => $this->menu->forUser($user),
            'approval' => $entry,
            'labels' => $this->flows->labels(),
            'document' => $this->documentOf($entry),
            'canDecide' => $this->engine->canDecide($entry, $user),
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
