<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\BulkApproval;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Approval\Services\ApprovalInbox;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * মালিক ডেস্কে নেই, আর কাগজগুলো দাঁড়িয়ে আছে — ফোন থেকে সই। চুক্তি §৫।
 *
 * ── ⚠️ এটা সিঙ্ক নয় ───────────────────────────────────────────────────
 * অফলাইনে কাজ করে না, কিউতে বসে না, ওয়াটারমার্ক নেই। ⓘ একটা অনুমোদন
 * মানে কাগজটা এগিয়ে যায় — মজুদ সরে, টাকা ছাড়ে। ⛔ নেট ছাড়া সেটা করলে
 * দুইজন একই কাগজ দুইবার সই করে বসতেন।
 *
 * ── ⭐ নিয়ম একটাই জায়গায় — [[ApprovalEngine]] ─────────────────────────
 * তালিকা আসে `pendingQueryFor()` থেকে, সিদ্ধান্ত যায় `approve()` আর
 * `reject()`-এ — ওয়েবের ইনবক্স ([[ApprovalInboxController]]) ঠিক যেভাবে
 * ডাকে। ⛔ এখানে কোনো সিদ্ধান্তের নিয়ম লেখা নেই; লিখলে একদিন ফোন আর
 * ওয়েব একই কাগজে দুই কথা বলত।
 *
 * ── ⛔ বেতন কখনো নয় — মালিকের সিদ্ধান্ত, ১৩ সেপ্টেম্বর ২০২৬ ──────────
 * ⓘ চুক্তি §৪ বলে জনবল/বেতন ফোনে যায় না। কিন্তু বেতন **অনুমোদনযোগ্য**
 * কাগজ, তাই সরলভাবে বানানো এই দরজা ঐ সিদ্ধান্তটা পিছনের দরজা দিয়ে
 * ভাঙত — আর কেউ ধরত না, কারণ দরজার নাম "approvals", "payroll" নয়।
 * ⚠️ ছাঁকনিটা তাই **ক্যোয়ারিতে** ([[forThePhone]]), পর্দায় নয় — আর
 * সিদ্ধান্তের দরজাও একই ছাঁকনি দিয়ে কাগজ খোঁজে, তাই আইডি জানা থাকলেও
 * বেতনের কাগজ ফোন থেকে সই হয় না।
 *
 * ── চাবি ──────────────────────────────────────────────────────────────
 * রুটে `can:approval.decide` — ওয়েবের ইনবক্স মেনু আর সই-দরজা যে চাবি
 * চায়। ⓘ ওয়েবের তালিকা-পাতা `approval.report`-কেও ঢুকতে দেয়, কারণ
 * নিরীক্ষক **অন্যের** ইনবক্স দেখেন; ফোনের তালিকা কেবল নিজের, তাই সেই
 * দ্বিতীয় দরজার দরকার নেই।
 */
final class ApprovalApiController extends Controller
{
    /** এক পাতায় কয়টা — না বললে। */
    private const DEFAULT_LIMIT = 25;

    /**
     * এক পাতায় সর্বোচ্চ।
     *
     * ⚠️ সীমা না থাকলে `?limit=100000` দিয়ে গোটা জট একবারে টানা যেত —
     * ঠিক যে কারণে `pendingQueryFor()` আলাদা করা হয়েছিল।
     */
    private const MAX_LIMIT = 100;

    /**
     * ⛔ ফোনে যে মডিউল কখনো আসে না — জনবল/বেতন (চুক্তি §৪)।
     *
     * ⓘ কেবল `payroll` কাজটা নয়, গোটা মডিউল: আজ HR-এর একমাত্র
     * অনুমোদনযোগ্য কাজ বেতন, কিন্তু কাল একটা নতুন কাজ (বোনাস, অগ্রিম)
     * যোগ হলে সেটাও টাকার, আর §৪ তখনও একই কথা বলবে।
     */
    private const DESK_ONLY_MODULE = 'hr';

    /**
     * ⛔ মডিউলের নাম যা-ই হোক, এই কাগজগুলো ফোনে নয়।
     *
     * ⓘ দুই দিক থেকে বাঁধা — মডিউল ধরে, আর কাগজের ধরন ধরে — কারণ ছক
     * মডিউলের নামে বসে, আর কেউ একদিন বেতনের কাগজ অন্য নামে চাইলে একটা
     * শর্ত একাই নীরবে ফসকে যেত।
     */
    private const DESK_ONLY_DOCUMENTS = [PayrollRun::class, Payslip::class];

    /** `GET /approvals/pending?limit=&cursor=` */
    public function pending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /*
         * ⚠️ `pendingQueryFor()`, `pendingFor()` নয় — চুক্তির নিয়ম।
         *
         * ⓘ Builder হাতে থাকলে পাতা-ভাগ ডাটাবেজে হয়। ⛔ `pendingFor()`
         * প্রতিটা সারি মেমরিতে তুলত, আর ছয় মাস সই না করা একজনের ফোন
         * হাজার সারি টানত।
         *
         * ⓘ `id` দ্বিতীয় ক্রম — কার্সরের জন্য ক্রমটা অনন্য হতেই হয়।
         * ⛔ কেবল `requested_at` ধরলে একই সেকেন্ডের দুইটা অনুরোধের একটা
         * পাতার সীমানায় পড়ে নীরবে হারাত।
         */
        $page = self::forThePhone(app(ApprovalEngine::class)->pendingQueryFor($user))
            ->orderBy('id')
            ->cursorPaginate((int) ($validated['limit'] ?? self::DEFAULT_LIMIT));

        return response()->json([
            'rows' => $this->rowsOf(collect($page->items()), $user),
            // ⓘ `null` মানে শেষ পাতা — খালি তালিকাও স্বাভাবিক (চুক্তি §৫ ঘ)
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * ⭐ অপেক্ষার সারি — [[pending()]] আর [[inbox()]]-এর একই কার্ড (দফা ২১)। ⛔ দুই জায়গায় লিখলে একদিন নতুন অ্যাপের কার্ডে এক
     * অঙ্ক আর পুরনো অ্যাপের কার্ডে আরেক অঙ্ক দেখাত।
     *
     * @param  Collection<int, Approval>  $approvals
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsOf(Collection $approvals, User $user): Collection
    {
        $facts = app(ApprovalFacts::class)->of($approvals, true); // ⓘ নিজের অপেক্ষার সারি — সইকারী নিজেই
        $documents = $this->documentsOf($approvals);

        $engine = app(ApprovalEngine::class);
        $owner = app(\App\Core\Services\Ownership::class)->isOwnerIn($user, (int) \App\Core\Support\CompanyContext::id());
        // ⭐ "সংশোধনে ফেরত" — গোটা পাতার, কাগজের ধরন ধরে এক ডাকে; ⛔ আগে সারি ধরে কাগজ পড়া হত (a6, ২০ অক্টোবর ২০২৬; [[ApprovalEngine::canSendBackMany()]])
        $sendBack = $engine->canSendBackMany($approvals);

        return $approvals->map(function (Approval $approval) use ($facts, $documents, $engine, $owner, $sendBack): array {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;
            $fact = $facts[(int) $approval->id] ?? [];
            $now = self::paperAmountNow($document);
            $asked = $approval->amount === null ? null : (string) $approval->amount;
            $changed = $now !== null && $asked !== null && bccomp($now, $asked, 4) !== 0;

            return [
                // ⛔ ক্রমিক `id` কখনো নয় — চুক্তি §৩ ক
                'id' => $approval->public_id,
                'documentType' => class_basename((string) $approval->approvable_type),
                'documentNo' => $document?->getAttribute('document_no'),

                /*
                 * ⭐ নথিটার নিজের public_id — ইনবক্স থেকে কাগজটা খোলার জন্য
                 * (§১০-এর `/documents/{type}/{id}`), ২৭ সেপ্টেম্বর ২০২৬।
                 * ⓘ না জানা গেলে `null` — ক্রমিক id কখনো নয় (§৩ ক)।
                 */
                'documentId' => $document?->getAttribute('public_id'),
                'action' => $approval->action,

                /*
                 * ⚠️ স্ট্রিং, সংখ্যা নয় — `decimal:4` ⇒ `"125000.0000"`।
                 * ⓘ অঙ্ক না জানা থাকলে `null`, `"0.0000"` নয়: "শূন্য
                 * টাকা" আর "অঙ্ক নেই" দুই কথা।
                 */
                /*
                 * ⭐ কাগজের **এখনকার** অঙ্ক — মালিক, ১৪ অক্টোবর ২০২৬ (RCV-0090: সই চাওয়া ৩৯,৯৯০-এ, পরে খসড়া ২৪,৯১৩-এ বদলানো;
                 * কার্ডে অঙ্ক পুরনো আর বিবরণ নতুন — "এক কাগজের বিবরণ আরেকটায়")। ⓘ যেখানে সইয়ের অঙ্ক হুবহু কাগজের অঙ্ক
                 * ([[PAPER_AMOUNT]]) সেখানেই; বাকিতে আগের মতো সইয়ের অঙ্ক। চাওয়ার অঙ্ক আলাদা ঘরে, বদলালে চিহ্ন।
                 */
                'amount' => $now ?? $asked,
                'requestedAmount' => $asked,
                'amountChanged' => $changed,
                'currentLevel' => (int) $approval->current_level,
                ...self::standing($engine, $approval),
                'requestedAt' => $approval->requested_at?->toIso8601String(),
                'requesterName' => $approval->requesterName(),

                /*
                 * ⭐ পক্ষ আলাদা ঘরে — নম্বরের লাইনে বসানোর জন্য (মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের
                 * নাম দিলে সহজে বোঝা যায়"*)। ⓘ গ্রাহক হলে "নাম · পয়েন্ট", নয়তো সরবরাহকারী/কর্মী/ব্যক্তি ([[ApprovalFacts]]) — ওয়েবের
                 * ইনবক্সের একই লেখা। ⚠️ `summary` আগের মতোই (পক্ষ দিয়েই শুরু) — পুরনো অ্যাপ নতুন ঘর উপেক্ষা করে আর সারাংশে পক্ষ পায়।
                 */
                'party' => $fact['party'] ?? null,
                'summary' => ($changed ? __('approval::field.changed_after_request', ['amount' => \App\Core\Support\Money::format($asked)]).' · ' : '')
                    .$this->summaryOf($approval, $fact),

                /*
                 * ⭐ কোন বোতাম দেখাবে — মালিকের আদেশ, ১৪ অক্টোবর ২০২৬। ⓘ "সংশোধনে ফেরত" খসড়া কাগজে; "বদলে সই" কেবল মালিক
                 * (বদল হয় কাগজের নিজের পর্দায়); অন্য সইকারী কেবল বিবরণ ঠিক করতে পারেন। ⚠️ পুরনো অ্যাপ ঘরগুলো উপেক্ষা করে।
                 */
                'canSendBack' => $sendBack[(int) $approval->id] ?? false,
                'canEditAndSign' => $owner,
                'canCorrectText' => ! $owner,
            ];
        })->values();
    }

    /**
     * `GET /approvals/inbox?tab=&module=&late=&page=` — ফোনের ইনবক্স, ওয়েবের চার ট্যাব আর একই নিয়মে (অনুমোদন ভাগ ৩, দফা ২১;
     * পরিকল্পনার পাতা ৬, ছবি ১: *"অ্যাপে একই ট্যাব, একই নাম আর একই নিয়ম"*)।
     *
     * ⓘ ট্যাব, দেরি, সাজানো, ধাপ, কার হয়ে আর শেষ সময় — সব [[ApprovalInbox]]-এর, ওয়েবের ইনবক্স যেখান থেকে পড়ে; কার্ডের বাকিটা
     * [[rowsOf()]]-এর, পুরনো অ্যাপের [[pending()]] যেটা পায়। ⛔ বেতন ফোনে আসে না — প্রতিটা ট্যাব [[forThePhone()]] দিয়ে, তাই সংখ্যা,
     * মোট আর সারি তিনটাই একই ছাঁকা তালিকার।
     *
     * ⓘ পাতা নম্বরে (`page`), কার্সরে নয় — "দেরি আগে" সাজানো একটা হিসাব (`CASE WHEN due_at …`), আর কার্সর কেবল কলাম ধরে চলে।
     * ⓘ মোট দুইটা, ওয়েবের দফা ৬-এর মতো: এই পাতার আর পুরো তালিকার। ⓘ একসাথে অনুমোদন কেবল অপেক্ষা আর অন্যের-হয়ে ট্যাবে, আর
     * টাকা নড়ার কাজে কখনো নয় (`bulkable`); বাকি সব নিয়ম [[bulkPreview()]] সার্ভারে আবার দেখে।
     */
    public function inbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['nullable', 'string', 'in:'.implode(',', ApprovalInbox::TABS)],
            'module' => ['nullable', 'string', 'max:64'],
            'late' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            // ⭐ ফোনের নতুন ইনবক্স (মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok"): খোঁজা আর সাজানো — ওয়েবের একই [[ApprovalInbox::searched()]]/[[sorted()]]
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:late,new,amount'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $inbox = app(ApprovalInbox::class);

        $tabs = array_map(fn (Builder $query) => self::forThePhone($query), $inbox->tabQueries($user, true));
        $tab = (string) ($validated['tab'] ?? 'waiting');
        $waiting = in_array($tab, ['waiting', 'behalf'], true);

        // ⓘ কাজের চিপ — ট্যাবের পুরো তালিকা ধরে, কাজ বাছার আগে (নাহলে "ক্রয়" বাছার পরে বাকি চিপ শূন্য দেখাত); ওয়েবের একই গোনা
        $counts = (clone $tabs[$tab])
            ->reorder()
            ->withoutEagerLoads()
            ->select('module')
            ->selectRaw('COUNT(*) as tally')
            ->groupBy('module')
            ->pluck('tally', 'module');

        $selected = trim((string) ($validated['module'] ?? ''));
        $modules = [];

        foreach (app(ApprovalFlowService::class)->choices() as $code => $entry) {
            if ($counts->has($code) || $code === $selected) {
                $modules[] = ['key' => (string) $code, 'label' => (string) $entry['label'], 'count' => (int) $counts->get($code, 0)];
            }
        }

        $late = (bool) ($validated['late'] ?? false);
        $lateCount = ApprovalInbox::lateOnes((clone $tabs[$tab])->reorder()->withoutEagerLoads())->count();

        $list = ApprovalInbox::searched(ApprovalInbox::lateOnes(
            (clone $tabs[$tab])->when($selected !== '', fn ($q) => $q->where('module', $selected)),
            $late,
        ), (string) ($validated['q'] ?? ''));

        $count = (clone $list)->reorder()->withoutEagerLoads()->count();
        $sum = (string) ((clone $list)->reorder()->withoutEagerLoads()->sum('amount') ?? '0');

        $page = ApprovalInbox::sorted($list, (string) ($validated['sort'] ?? ($waiting ? 'late' : 'new')))
            ->paginate(self::DEFAULT_LIMIT, ['*'], 'page', (int) ($validated['page'] ?? 1));

        $approvals = $page->getCollection()->values();
        $steps = $inbox->stepsOf($approvals);
        $standIns = $inbox->standInsOf($approvals, $user, true);
        $bulk = app(BulkApproval::class);
        $cards = $this->rowsOf($approvals, $user);
        $files = $this->attachmentCounts($approvals);

        $rows = $approvals->map(fn (Approval $approval, int $i) => $cards[$i] + [
            'status' => (string) $approval->status,
            'statusLabel' => __('approval::status.'.$approval->status),
            // ⓘ লেখা আর রং সার্ভারের ([[ApprovalInbox::deadlineOf()]]) — ফোন নিজে হিসাব করে না; ঘড়ি না থাকলে বা অপেক্ষায় না থাকলে null
            'deadline' => $inbox->deadlineOf($approval),
            'step' => $steps[(int) $approval->id] ?? null,
            'standInFor' => $standIns[(int) $approval->id] ?? null,
            'bulkable' => $waiting && ! $bulk->movesMoney($approval),
            // ⭐ নমুনার কার্ডে "📎 3" — খোলার আগেই জানা যায় প্রমাণ আছে কি না; চাওয়ার কারণ; "দিয়েছি" ট্যাবে সিদ্ধান্তের সময়
            'attachments' => $files[(int) $approval->id] ?? 0,
            'reason' => trim((string) $approval->requested_reason) === '' ? null : (string) $approval->requested_reason,
            'decidedAt' => $approval->decided_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'tab' => $tab,
            'tabs' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('approval::field.tab_'.$key),
                'count' => (clone $tabs[$key])->reorder()->withoutEagerLoads()->count(),
            ], ApprovalInbox::TABS),
            'modules' => $modules,
            'module' => $selected === '' ? null : $selected,
            'allCount' => (int) $counts->sum(),
            'lateCount' => $lateCount,
            'rows' => $rows,
            // ⓘ টাকা স্ট্রিং, চার ঘর — সারির `amount`-এর একই রূপ; ভাসমান সংখ্যা কোথাও নেই
            'totals' => [
                'pageCount' => $approvals->count(),
                'pageAmount' => \App\Core\Support\Money::sumOf($approvals, fn (Approval $a) => (string) ($a->amount ?? '0')),
                'count' => $count,
                'amount' => bcadd($sum, '0', 4),
            ],
            'nextPage' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /**
     * কাগজপ্রতি চলতি সংযুক্তির সংখ্যা — অনুমোদন-id => সংখ্যা। ⓘ কাগজের ধরন ধরে একটা করে প্রশ্ন (সারিপ্রতি নয়), সংযুক্তির ইঞ্জিনের
     * একই ঠিকানায় ([[AttachmentEngine::listFor()]]: মডিউল · ধরন · id, কেবল চলতি সংস্করণ) — কাগজের পাতার তালিকার সাথে মেলে।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $approvals
     * @return array<int, int>
     */
    private function attachmentCounts(\Illuminate\Support\Collection $approvals): array
    {
        $out = [];
        $drill = app(\App\Core\Engines\Drill\DrillResolver::class);

        foreach ($approvals->groupBy(fn (Approval $a) => (string) $a->approvable_type) as $class => $group) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($class) ?? $class;

            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, \App\Core\Contracts\Drillable::class)) {
                continue;
            }

            $entity = $class::drillSourceType();
            $module = $drill->moduleFor($entity) ?? '';
            $byPaper = \App\Models\Attachment::query()
                ->where('source_module', $module)
                ->where('source_entity', $entity)
                ->whereIn('source_entity_id', $group->pluck('approvable_id')->map(fn ($id) => (int) $id)->unique()->values())
                ->current()
                ->selectRaw('source_entity_id, COUNT(*) as tally')
                ->groupBy('source_entity_id')
                ->pluck('tally', 'source_entity_id');

            foreach ($group as $approval) {
                $out[(int) $approval->id] = (int) ($byPaper[(int) $approval->approvable_id] ?? 0);
            }
        }

        return $out;
    }

    /**
     * `GET /approvals/mine?limit=&cursor=` — আমার পাঠানো অনুরোধ, নতুন আগে: কোনটা কোথায় দাঁড়িয়ে, কে কী বললেন।
     *
     * ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"* — মেনুর "আমার অনুরোধ" ফোনে ওয়েব-পাতা হয়ে খুলত।
     * ⓘ ওয়েবের পাতার একই প্রশ্ন ([[ApprovalInboxController::mine()]]: `requested_by` = আমি), একই চাবি (`approval.view`);
     * ⛔ বেতন ফোনে আসে না ([[forThePhone]])। ⓘ মোট: সব অনুরোধের গোনা আর এখনো অপেক্ষমাণগুলোর গোনা — পাতার নয়, পুরোটার।
     */
    public function mine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = self::forThePhone(Approval::query()->where('requested_by', $user->id));
        $count = (clone $query)->count();
        $waiting = (clone $query)->where('status', Approval::PENDING)->count();

        $page = (clone $query)->with(['decisions.user'])
            ->orderByDesc('id')
            ->cursorPaginate((int) ($validated['limit'] ?? self::DEFAULT_LIMIT));

        $approvals = collect($page->items());
        $facts = app(ApprovalFacts::class)->of($approvals, false);
        $documents = $this->documentsOf($approvals);
        $engine = app(ApprovalEngine::class);

        $rows = $approvals->map(function (Approval $approval) use ($facts, $documents, $engine): array {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;
            $last = $approval->decisions->sortBy('id')->last();

            return [
                'id' => $approval->public_id,
                'documentType' => class_basename((string) $approval->approvable_type),
                'documentNo' => $document?->getAttribute('document_no'),
                'documentId' => $document?->getAttribute('public_id'),
                'action' => $approval->action,
                'amount' => $approval->amount === null ? null : (string) $approval->amount,
                'status' => (string) $approval->status,
                'statusLabel' => __('approval::status.'.$approval->status),
                'currentLevel' => (int) $approval->current_level,
                ...self::standing($engine, $approval),
                'requestedAt' => $approval->requested_at?->toIso8601String(),
                'decidedAt' => $approval->decided_at?->toIso8601String(),
                // ⭐ পক্ষ আলাদা ঘরে — নম্বরের লাইনে (১৯ অক্টোবর ২০২৬; উপরের [[pending()]]-এর একই নিয়ম)
                'party' => $facts[(int) $approval->id]['party'] ?? null,
                'summary' => $this->summaryOf($approval, $facts[(int) $approval->id] ?? []),
                // ⓘ শেষ কথা — কে, কী, আর কেন (ফেরত বা সংশোধনের নোট লেখককে জানতেই হয়)
                'lastDecision' => $last === null ? null : [
                    'by' => $last->user?->name,
                    'decision' => (string) $last->decision,
                    'label' => __('approval::status.'.$last->decision),
                    'remarks' => $last->remarks,
                    'at' => $last->decided_at?->toIso8601String(),
                ],
            ];
        })->values();

        return response()->json([
            'rows' => $rows,
            'nextCursor' => $page->nextCursor()?->encode(),
            'count' => $count,
            'pendingCount' => $waiting,
        ]);
    }

    /**
     * `GET /approvals/{approval}/sheet` — সইয়ের আগে কাগজের বিস্তারিত: ঘর আর মান, সারি, যোগফল।
     *
     * ⭐ মালিক, ৭ অক্টোবর ২০২৬: *"app e voucher approvale asle … details dekhayna, approval e kono kichui details dekhay
     * na"*। ওয়েবের অনুমোদনের পাতা যা দেখায়, তাই — কাগজ নিজে বলে ([[ShowsItselfForSigning]]: ভাউচার, চালান, বিক্রয় বিল);
     * যে কাগজ বলে না, তার জন্য সারির নিজের কথা ([[ApprovalFacts]]: কার, কী বাবদ, কোথায়)। মান সবই লেখা — ফোন কেবল আঁকে।
     * ⛔ সই-দরজার একই পাহারা ([[decidable()]]): অপেক্ষমাণ, আর যিনি সিদ্ধান্ত দিতে পারেন; বেতন ফোনে আসেই না।
     */
    public function sheet(Request $request, string $approval): JsonResponse
    {
        $entry = $this->decidable($request, $approval);
        $document = $this->documentsOf(collect([$entry]))[$entry->approvable_type][(int) $entry->approvable_id] ?? null;
        abort_if($document === null, 404);

        if ($document instanceof \App\Core\Contracts\ShowsItselfForSigning) {
            $sheet = $document->signingSheet();
        } else {
            $fact = app(ApprovalFacts::class)->of(collect([$entry]), true)[(int) $entry->id] ?? [];
            $sheet = ['facts' => array_values(array_filter([
                ['label' => __('approval::field.party'), 'value' => (string) ($fact['party'] ?? '')],
                ['label' => __('approval::field.what_for'), 'value' => (string) ($fact['about'] ?? $entry->requested_reason ?? '')],
                ['label' => __('approval::field.where_money'), 'value' => (string) ($fact['where'] ?? '')],
            ], fn (array $f) => trim($f['value']) !== '')), 'columns' => [], 'rows' => []];
        }

        return response()->json([
            'documentType' => class_basename((string) $entry->approvable_type),
            'documentNo' => $document->getAttribute('document_no'),
            ...self::standing(app(ApprovalEngine::class), $entry),
            'facts' => array_values(array_map(fn (array $f) => ['label' => (string) $f['label'], 'value' => (string) $f['value']], $sheet['facts'] ?? [])),
            'columns' => array_values(array_map(fn (array $c) => ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'numeric' => (bool) ($c['numeric'] ?? false)], $sheet['columns'] ?? [])),
            'rows' => array_values(array_map(fn (array $r) => array_map(fn ($v) => $v === null ? null : (string) $v, $r), $sheet['rows'] ?? [])),
            /*
             * ⛔ সবসময় বস্তু — খালি PHP তালিকা JSON-এ `[]` হয়, আর ফোন যোগফলকে চাবি-মানের বস্তু হিসেবে পড়ে; যে কাগজের যোগফল
             * নেই (DO, আদেশ, খরচের দাবি …) তার বিস্তারিত ফোনে "আনা গেল না" দেখাত (মালিক, ৮ অক্টোবর ২০২৬)।
             */
            'totals' => (object) array_map('strval', $sheet['totals'] ?? []),
            ...$this->paperPage($entry, $document, $request->user()),
        ]);
    }

    /**
     * ⭐ কাগজের পুরো পাতা — পরিকল্পনার পাতা ৬, ছবি ২ (দফা ২১): সারাংশ, যাত্রাপথ আর সংযুক্তি, ওয়েবের কাগজের পাতার একই হিসাবে।
     *
     * ⓘ সারাংশের ধাপ, শেষ সময় আর যাত্রাপথ [[ApprovalInbox]]-এর — ওয়েবের ইনবক্সের ডান দিক যেখান থেকে পড়ে; টাকা কথায়
     * [[AmountInWords]]-এর। ⓘ সংযুক্তি কাগজের নিজের ([[AttachmentEngine::listFor()]]), খোলে [[attachment()]] দিয়ে — একই সই-পাহারায়।
     * ⓘ সময় ISO-তে, ফোন নিজের ভাষায় লেখে; সিদ্ধান্তের নাম সার্ভারের ভাষায় (`label`), ওয়েবের যাত্রাপথের একই লেখা।
     *
     * @return array<string, mixed>
     */
    /**
     * ⭐ "কেন আপনার অনুমোদন লাগছে" — মালিকের নমুনা "অনুমোদনের নতুন ইনবক্স" v4 ("ok", ৮ নভেম্বর ২০২৬; SAP Fiori-র "why am I
     * approving"): কোন নিয়মে কাগজটা এল (কাজের নাম, আর ধাপটা কত টাকা থেকে সই দেয়), এখন কোন ধাপ, আর যিনি চেয়েছেন তার লেখা কারণ।
     * ⓘ নিয়ম আর ধাপ ইঞ্জিনের নিজের ছক থেকে ([[ApprovalEngine::stepsFor()]], [[ApprovalLabel]]) — ওয়েব যেখান থেকে পড়ে।
     *
     * @return array{rule: string, step: string|null, reason: string|null}
     */
    private function whyOf(Approval $entry): array
    {
        $engine = app(ApprovalEngine::class);
        $at = (int) $entry->current_level;
        $step = $engine->stepsFor($entry)->first(fn ($s) => (int) $s->level === $at);
        $from = $step?->from_amount === null ? null : (string) $step->from_amount;
        $what = app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $entry->module, (string) $entry->action);
        $name = $engine->stepNamesFor($entry)[$at] ?? null;
        $reason = trim((string) $entry->requested_reason);

        return [
            'rule' => $from !== null && bccomp($from, '0', 4) > 0
                ? (string) __('approval::field.why_rule_from', ['what' => $what, 'amount' => \App\Core\Support\Money::format($from)])
                : (string) __('approval::field.why_rule', ['what' => $what]),
            'step' => $at < 1 ? null : (string) ($name === null
                ? __('approval::field.why_step', ['at' => $at])
                : __('approval::field.why_step_named', ['at' => $at, 'name' => $name])),
            'reason' => $reason === '' ? null : $reason,
        ];
    }

    private function paperPage(Approval $entry, Model $document, User $user): array
    {
        $entry->loadMissing(['requester', 'decisions.user', 'decisions.forwardedTo', 'decisions.onBehalfOf']);
        $inbox = app(ApprovalInbox::class);
        $engine = app(ApprovalEngine::class);
        $standIn = $engine->standInFor($entry, $user);

        $sourceType = $document instanceof \App\Core\Contracts\Drillable ? $document::drillSourceType() : null;
        $papers = $sourceType === null ? collect() : app(\App\Core\Engines\Attachment\AttachmentEngine::class)->listFor(
            app(\App\Core\Engines\Drill\DrillResolver::class)->moduleFor($sourceType) ?? '',
            $sourceType,
            (int) $document->getKey(),
        );

        return [
            'why' => $this->whyOf($entry),
            'summary' => [
                'requesterName' => $entry->requesterName(),
                'requestedAt' => $entry->requested_at?->toIso8601String(),
                'amountWords' => $entry->amount === null ? null : \App\Core\Support\AmountInWords::of((string) $entry->amount),
                'deadline' => $inbox->deadlineOf($entry),
                'step' => $inbox->stepsOf(collect([$entry]))[(int) $entry->id] ?? null,
                'standInFor' => $standIn === null ? null : (string) User::query()->whereKey($standIn)->value('name'),
            ],
            'journey' => array_map(fn (array $step) => [
                'level' => $step['level'],
                'name' => $step['name'],
                'state' => $step['state'],
                'tookHours' => $step['took'],
                'needed' => $step['needed'],
                'signed' => $step['signed'],
                'by' => array_map(fn (array $who) => [
                    'name' => $who['name'],
                    'decision' => $who['decision'],
                    'label' => __('approval::status.'.$who['decision']),
                    'at' => $who['at']?->toIso8601String(),
                    'remarks' => $who['remarks'],
                    'for' => $who['for'],
                    'to' => $who['to'],
                ], $step['by']),
            ], $inbox->timelineOf($entry)),
            'attachments' => $papers->map(fn ($paper) => [
                'id' => (int) $paper->id,
                'name' => (string) $paper->original_name,
                'mime' => (string) $paper->mime_type,
                'size' => $paper->humanSize(),
            ])->values()->all(),
        ];
    }

    /**
     * `GET /approvals/{approval}/attachments/{attachment}` — সইয়ের আগে স্লিপ বা বিল খোলা (পাতা ৬, ছবি ২-এর "সংযুক্তি"; দফা ২১)।
     *
     * ⛔ পাহারা সই-দরজার ([[decidable()]]) — অপেক্ষমাণ, আর যিনি সিদ্ধান্ত দিতে পারেন; আর ফাইলটা **এই কাগজেরই** হতে হয়।
     * ⚠️ নাহলে কেউ নিজের অপেক্ষার একটা কাগজ দিয়ে অন্য যেকোনো কাগজের সংযুক্তির নম্বর বসিয়ে সেটা নামাতেন। অন্য কাগজের হলে ৪০৪।
     */
    public function attachment(Request $request, string $approval, int $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $entry = $this->decidable($request, $approval);
        $document = $this->documentsOf(collect([$entry]))[$entry->approvable_type][(int) $entry->approvable_id] ?? null;
        abort_unless($document instanceof \App\Core\Contracts\Drillable, 404);

        $engine = app(\App\Core\Engines\Attachment\AttachmentEngine::class);
        $sourceType = $document::drillSourceType();
        $paper = $engine->listFor(
            app(\App\Core\Engines\Drill\DrillResolver::class)->moduleFor($sourceType) ?? '',
            $sourceType,
            (int) $document->getKey(),
        )->firstWhere('id', $attachment);
        abort_if($paper === null || ! $engine->exists($paper), 404);

        // ⓘ ওয়েবের দরজার একই পড়া ([[AttachmentController::download()]]) — ফাইল কোথায় আর কীভাবে রাখা, ইঞ্জিন জানে
        return response()->streamDownload(
            fn () => print ($engine->contents($paper)),
            (string) $paper->original_name,
            ['Content-Type' => $paper->mime_type ?: 'application/octet-stream'],
        );
    }

    /**
     * `POST /approvals/bulk/preview` {ids[]} — একসাথে অনুমোদনের আগে দেখা (পরিকল্পনার পাতা ৬, ছবি ৩; দফা ২১): কোনগুলো যাবে আর
     * তাদের মোট, কোনগুলো বাদ আর কেন। ⓘ কিছুই জমা পড়ে না। ⓘ নিয়ম [[BulkApproval::review()]]-এর — ওয়েবের আগে-দেখার পাতা
     * ([[ApprovalInboxController::bulkReview()]]) যা বলে, ফোনও তাই বলে; কারণের লেখাও একই।
     */
    public function bulkPreview(Request $request): JsonResponse
    {
        $review = app(BulkApproval::class)->review($this->bulkIds($request), $request->user());
        $documents = $this->documentsOf(collect([...$review['sign'], ...array_column($review['skip'], 'approval')]));
        $labels = app(ApprovalFlowService::class)->labels();

        $line = function (Approval $approval) use ($documents, $labels): array {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;

            return [
                'id' => $approval->public_id,
                'work' => $labels[$approval->module.'.'.$approval->action] ?? (string) $approval->action,
                'documentNo' => $document?->getAttribute('document_no'),
                'amount' => $approval->amount === null ? null : (string) $approval->amount,
            ];
        };

        return response()->json([
            'sign' => array_map($line, $review['sign']),
            'total' => bcadd($review['total'], '0', 4),
            'skip' => array_map(fn (array $row) => $line($row['approval']) + [
                'why' => $row['why'],
                'reason' => __('approval::message.bulk_why_'.$row['why']),
            ], $review['skip']),
        ]);
    }

    /**
     * `POST /approvals/bulk/approve` {ids[], remarks?} — একসাথে অনুমোদন। ⛔ ফোনের বাছাই বিশ্বাস করা হয় না: সার্ভার আবার একই নিয়মে
     * দেখে ([[BulkApproval::approve()]] ভেতরে [[BulkApproval::review()]] ডাকে), তাই আগে-দেখার পরে কিছু বদলালে সেটা বাদ পড়ে,
     * আর টাকা নড়ার কাজ কখনো একসাথে যায় না। উত্তর: কয়টা হলো, আর কারণ ধরে কয়টা বাদ।
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:500']])['remarks'] ?? null;

        $result = app(BulkApproval::class)->approve($this->bulkIds($request), $request->user(), $remarks);

        return response()->json([
            'done' => $result['done'],
            'skipped' => (object) $result['skipped'],
        ]);
    }

    /**
     * ফোনের পাঠানো নম্বর (public_id) থেকে সারির নম্বর — ⛔ বেতন বাদ ([[forThePhone()]]), আর অন্য কোম্পানির সারি কোম্পানির দেয়ালেই
     * আটকায়। ⓘ অচেনা নম্বর নীরবে বাদ নয়: আগে-দেখায় সেটা আসেই না, তাই "যাবে" তালিকা ফোনের বাছাইয়ের চেয়ে ছোট দেখায়।
     *
     * @return list<int>
     */
    private function bulkIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LIMIT],
            'ids.*' => ['required', 'string', 'max:64'],
        ]);

        return self::forThePhone(Approval::query())
            ->whereIn('public_id', $validated['ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** `POST /approvals/{approval}/approve` — মন্তব্য ঐচ্ছিক, ওয়েবের মতো। */
    public function approve(Request $request, string $approval): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
            // ⓘ ফোনের কার্ডে যে অঙ্ক দেখানো হয়েছিল — নতুন অ্যাপ পাঠায়; পুরনো অ্যাপ না পাঠালে আগের মতোই
            'seenAmount' => ['nullable', 'string', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
        ]);

        $entry = $this->decidable($request, $approval);

        /*
         * ⛔ যে অঙ্ক দেখে সই, সেই অঙ্কেই — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[TheSignerSignsTheAmountTheySawTest]])।
         * ⓘ তালিকা খোলার পরে খসড়ার অঙ্ক বদলালে (RCV-0090: ৩৯,৯৯০ → ২৪,৯১৩) পুরনো কার্ডের বোতাম নতুন অঙ্কে সই দিত। কার্ডের
         * অঙ্ক ([[index()]]-এর `amount`) আর এখনকার অঙ্ক না মিললে ৪১২ — ফোন তালিকা আবার আনে।
         */
        if (filled($validated['seenAmount'] ?? null)) {
            $class = (string) $entry->approvable_type;
            $document = class_exists($class) && is_subclass_of($class, Model::class)
                ? $class::query()->withoutGlobalScopes(ApprovalFacts::VIEW_WALLS)->find($entry->approvable_id)
                : null;
            $now = self::paperAmountNow($document) ?? ($entry->amount === null ? null : (string) $entry->amount);

            if ($now !== null && bccomp((string) $validated['seenAmount'], $now, 4) !== 0) {
                return response()->json(['message' => __('approval::message.amount_changed_since_seen'), 'amount' => $now], 412);
            }
        }

        $decided = $this->settle($entry, fn () => app(ApprovalEngine::class)->approve(
            $entry,
            $request->user(),
            $validated['remarks'] ?? null,
        ));

        return $this->outcome($decided);
    }

    /**
     * `POST /approvals/{approval}/reject` — ⚠️ মন্তব্য বাধ্যতামূলক।
     *
     * ⓘ নিয়মটা দুই পাশেই: `ApprovalEngine::reject()`-এর সই `string $remarks`
     * (nullable নয়), আর অ্যাপের বোতাম ঘর খালি থাকলে নিষ্ক্রিয়। ⛔ "না"
     * শুনে মানুষ প্রথমেই জানতে চান কেন — কারণ না থাকলে একই অনুরোধ আবার
     * আসে, আর একই কারণে আবার "না" হয়।
     *
     * ⓘ বাছাই-করা কারণ-কোড ওয়েবের মতোই ঐচ্ছিক; অচেনা কোড ইঞ্জিন নিজে
     * "অন্য" করে, তাই তালিকাটা এখানে দ্বিতীয়বার লেখা হয়নি।
     */
    public function reject(Request $request, string $approval): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],
            'reasonCode' => ['nullable', 'string', 'max:32'],
        ]);

        $entry = $this->decidable($request, $approval);

        $decided = $this->settle($entry, fn () => app(ApprovalEngine::class)->reject(
            $entry,
            $request->user(),
            $validated['remarks'],
            ($validated['reasonCode'] ?? '') !== '' ? $validated['reasonCode'] : null,
        ));

        return $this->outcome($decided);
    }

    /**
     * ⭐ ফোনের তালিকা আর ফোনের সিদ্ধান্ত — দুইটাই এই এক ছাঁকনি দিয়ে।
     *
     * ⛔ দুই জায়গায় লিখলে একদিন তালিকা থেকে বেতন বাদ পড়ত, কিন্তু আইডি
     * জানা থাকলে সই-দরজা ঠিকই খুলত।
     *
     * ⓘ `public static` যাতে অন্য ফোন-দরজা (যেমন আজকের পাতার গোনা)
     * একই ছাঁকনি নিতে পারে — নিজে আরেকবার না লিখে।
     *
     * @template TModel of Approval
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    /** `POST /approvals/{id}/send-back` {remarks} — সংশোধনে ফেরত, নোট বাধ্যতামূলক ([[ApprovalEngine::sendBack()]]) */
    public function sendBack(Request $request, string $approval): JsonResponse
    {
        $validated = $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        $entry = $this->decidable($request, $approval);

        $decided = $this->settle($entry, fn () => app(ApprovalEngine::class)->sendBack($entry, $request->user(), $validated['remarks']));

        return $this->outcome($decided);
    }

    public static function forThePhone(Builder $query): Builder
    {
        return $query
            ->where('module', '!=', self::DESK_ONLY_MODULE)
            ->whereNotIn('approvable_type', self::DESK_ONLY_DOCUMENTS);
    }

    /**
     * কাগজটা খোঁজা, তারপর দুইটা প্রশ্ন — এই ক্রমে।
     *
     * ── ⚠️ কেন ক্রমটা এমন ────────────────────────────────────────────
     * ৪০৪: নেই, অন্য কোম্পানির (কোম্পানির দেয়াল — [[BelongsToCompany]]),
     *      বা ডেস্কের কাগজ (বেতন)। ⓘ ৪০৩ দিলে বলা হত *"আছে, কিন্তু
     *      তোমার নয়"*, আর সেটুকুই গোনার জন্য যথেষ্ট খবর।
     * ৪০৯: আর অপেক্ষায় নেই — কেউ আগেই সিদ্ধান্ত দিয়েছেন। ⓘ চুক্তি §৫ খ:
     *      এটা ভুল নয়, ঘটনা; অ্যাপ সারিটা সরিয়ে দেয়, লাল বার্তা নয়।
     *      ⚠️ তাই এটা আগে দেখা হয় — সিদ্ধান্ত হয়ে যাওয়া কাগজে
     *      `canDecide()` "না" বলে, আর তখন ৪০৩ গিয়ে অ্যাপকে ভুল কথা বলত।
     * ৪০৩: `canDecide()` "না" — এই স্তরে এই মানুষটার সই নয়। ⓘ চুক্তি
     *      §৫ ক: পাহারাটা সার্ভারে, ফোনের `permissions` তালিকায় নয়।
     */
    private function decidable(Request $request, string $publicId): Approval
    {
        $entry = self::forThePhone(Approval::query())->wherePublicId($publicId)->firstOrFail();

        abort_unless($entry->isPending(), 409);

        abort_unless(app(ApprovalEngine::class)->canDecide($entry, $request->user()), 403);

        return $entry;
    }

    /**
     * ইঞ্জিনকে ডাকা — আর মাঝখানে কেউ সিদ্ধান্ত দিয়ে ফেললে ৪০৯।
     *
     * ⚠️ উপরের যাচাই আর ইঞ্জিনের ডাকের মাঝে এক মুহূর্ত থাকে, আর দুইজন
     * একসাথে চাপলে দ্বিতীয়জনের জন্য ইঞ্জিন ব্যতিক্রম ছুঁড়ে। ⓘ সেটা ৫০০
     * নয়, ঘটনা — তাই কাগজটা আবার পড়ে দেখা হয়। ⛔ তখনও অপেক্ষায় থাকলে
     * ব্যতিক্রমটা অন্য কিছুর, আর সেটা চাপা দেওয়া হয় না।
     *
     * @param  callable(): Approval  $decide
     */
    private function settle(Approval $entry, callable $decide): Approval
    {
        try {
            return $decide();
        } catch (RuntimeException $e) {
            abort_if($entry->fresh()?->isPending() === false, 409);

            throw $e;
        }
    }

    /**
     * ⓘ সিদ্ধান্তের পরের অবস্থা — এক স্তর পার হলে কাগজ এখনো `pending`,
     * কেবল `currentLevel` বেড়েছে; অ্যাপ সারিটা তবু সরায়, কারণ পরের
     * স্তরটা আর এই মানুষটার নয়।
     */
    private function outcome(Approval $approval): JsonResponse
    {
        return response()->json([
            'id' => $approval->public_id,
            'status' => $approval->status,
            'currentLevel' => (int) $approval->current_level,
            ...self::standing(app(ApprovalEngine::class), $approval),
        ]);
    }

    /**
     * ⭐ সইয়ের ধাপ — "২-এর ১" (a6, ২০ অক্টোবর ২০২৬; ফোনের কাগজের চিপ, ভাউচার আর দাবির সারির একই ঘর)।
     *
     * ⓘ উৎস ইঞ্জিন ([[ApprovalEngine::standingOf()]]) — ওয়েবের যাত্রাপথের একই স্তর; অপেক্ষায় না থাকলে বা ছক আর চালু না থাকলে
     * দুটোই null। ⓘ `currentLevel` আগের মতো কাঁচা স্তর (ফাঁকা স্তরে ৩ হতে পারে); `approval_level` ক্রমিক।
     * ⛔ কোনো কোয়েরি নয়: অনুরোধের সারি পাতায় আগেই তোলা, আর ছকগুলো ইঞ্জিনে অনুরোধ-প্রতি একবার ([[ApprovalEngine::flows()]])।
     *
     * @return array{approval_level: int|null, approval_levels: int|null}
     */
    private static function standing(ApprovalEngine $engine, Approval $approval): array
    {
        $standing = $engine->standingOf($approval);

        return [
            'approval_level' => $standing['level'] ?? null,
            'approval_levels' => $standing['levels'] ?? null,
        ];
    }

    /**
     * সারির এক লাইনের কথা — কার, কী বাবদ। ওয়েবের ইনবক্স যা দেখায়
     * ([[ApprovalFacts]]), তাই; নিজে আবার খোঁজা হয় না।
     *
     * @param  array{party?: ?string, about?: ?string, where?: ?string}  $fact
     */
    /**
     * যে কাগজের সই-অঙ্ক হুবহু তার `amount` ঘর ([[VoucherApproval]], [[MoneyTransferService]], [[PaymentService]],
     * [[CollectionService]]) — কেবল এগুলোতে কার্ড কাগজের এখনকার অঙ্ক পড়ে। ⛔ অন্য কাগজে সইয়ের অঙ্ক অন্য কিছু মাপে (যেমন ছাড়),
     * তুলনা করলে মিথ্যা "বদলেছে" দেখাত।
     */
    private const PAPER_AMOUNT = [
        \App\Modules\Accounts\Models\Voucher::class,
        \App\Modules\Accounts\Models\MoneyTransfer::class,
        \App\Modules\Purchase\Models\Payment::class,
        \App\Modules\Sales\Models\Collection::class,
    ];

    private static function paperAmountNow(?Model $document): ?string
    {
        if ($document === null || ! in_array($document::class, self::PAPER_AMOUNT, true)) {
            return null;
        }

        $amount = $document->getAttribute('amount');

        return $amount === null ? null : bcadd((string) $amount, '0', 4);
    }

    private function summaryOf(Approval $approval, array $fact): ?string
    {
        $about = $fact['about'] ?? null;

        if ($about === null && trim((string) $approval->requested_reason) !== '') {
            $about = (string) $approval->requested_reason;
        }

        /*
         * ⭐ টাকা কোথায় আর কোন শাখায় — কার্ডেই (মালিক, ১৪ অক্টোবর ২০২৬, ফোনের সই-বাক্সের ছবি: *"kon branch e joma tar
         * ullekh nai"*)। ⓘ আগে কার্ডে কেবল পক্ষ আর বিবরণ; খাত আর শাখা দেখতে প্রতিটা কাগজের "বিস্তারিত" খুলতে হত — আর
         * বিবরণে লেখা ব্যাংকের সাথে আসল খাত মেলে কি না, কার্ড দেখে বলা যেত না।
         */
        $where = trim((string) ($fact['where'] ?? '')) === '' ? null : __('approval::field.deposited_to', ['place' => $fact['where']]);
        $branch = trim((string) ($fact['branch'] ?? '')) === '' ? null : __('approval::field.in_branch', ['branch' => $fact['branch']]);

        $parts = array_values(array_filter(
            [$fact['party'] ?? null, $about, $where, $branch],
            fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * কাগজগুলো — ধরন ধরে একবারে, সারি ধরে নয়।
     *
     * ⓘ ওয়েবের `documentOf()`-এর মতো: ক্লাস না থাকলে কিছু নয়, মুছে
     * গেলে কিছু নয়। ⛔ সারি ধরে খুঁজলে পঁচিশটা সারিতে পঁচিশটা কোয়েরি।
     *
     * @param  Collection<int, Approval>  $approvals
     * @return array<string, Collection<int, Model>>
     */
    private function documentsOf(Collection $approvals): array
    {
        $found = [];

        foreach ($approvals->groupBy('approvable_type') as $class => $group) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            // ⓘ সইয়ের কাগজ হেডারের শাখায় লুকায় না — [[ApprovalFacts::VIEW_WALLS]] (ওয়েবের পাতার একই নিয়ম)
            $found[$class] = $class::query()
                ->withoutGlobalScopes(ApprovalFacts::VIEW_WALLS)
                ->whereKey($group->pluck('approvable_id')->unique()->values()->all())
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey());
        }

        return $found;
    }
}
