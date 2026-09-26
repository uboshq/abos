<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
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

        $approvals = collect($page->items());

        $facts = app(ApprovalFacts::class)->of($approvals);
        $documents = $this->documentsOf($approvals);

        $rows = $approvals->map(function (Approval $approval) use ($facts, $documents): array {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;
            $fact = $facts[(int) $approval->id] ?? [];

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
                'amount' => $approval->amount === null ? null : (string) $approval->amount,
                'currentLevel' => (int) $approval->current_level,
                'requestedAt' => $approval->requested_at?->toIso8601String(),
                'requesterName' => $approval->requester?->name,
                'summary' => $this->summaryOf($approval, $fact),
            ];
        })->values();

        return response()->json([
            'rows' => $rows,
            // ⓘ `null` মানে শেষ পাতা — খালি তালিকাও স্বাভাবিক (চুক্তি §৫ ঘ)
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** `POST /approvals/{approval}/approve` — মন্তব্য ঐচ্ছিক, ওয়েবের মতো। */
    public function approve(Request $request, string $approval): JsonResponse
    {
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        $entry = $this->decidable($request, $approval);

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
        ]);
    }

    /**
     * সারির এক লাইনের কথা — কার, কী বাবদ। ওয়েবের ইনবক্স যা দেখায়
     * ([[ApprovalFacts]]), তাই; নিজে আবার খোঁজা হয় না।
     *
     * @param  array{party?: ?string, about?: ?string, where?: ?string}  $fact
     */
    private function summaryOf(Approval $approval, array $fact): ?string
    {
        $about = $fact['about'] ?? null;

        if ($about === null && trim((string) $approval->requested_reason) !== '') {
            $about = (string) $approval->requested_reason;
        }

        $parts = array_values(array_filter(
            [$fact['party'] ?? null, $about],
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

            $found[$class] = $class::query()
                ->whereKey($group->pluck('approvable_id')->unique()->values()->all())
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey());
        }

        return $found;
    }
}
