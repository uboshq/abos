<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Support\PromotionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * অফারের বহু-স্তর অনুমোদন — স্পেক §১৪: *খসড়া → জমা → বিক্রয় ব্যবস্থাপক
 * → বিক্রয় প্রধান → অর্থ → অনুমোদিত → সক্রিয়*।
 *
 * ── ⭐ কেন নিজের টেবিল নয়, বাড়ির ইঞ্জিন ─────────────────────────────
 * ⓘ চেকলিস্টের §২: *"প্রতিটা মডিউল কেবল নিজের Request পাঠাবে; Rule ·
 * Matrix · UI এখানেই একবার"* ([[DocumentApproval]])। ⛔ Promotion-এর
 * নিজের `promotion_approval_steps` টেবিল বানালে কোম্পানির সইয়ের ছক দুই
 * জায়গায় থাকত — Approval Centre-এর পর্দায় একটা, এখানে আরেকটা — আর
 * ইনবক্স, ভার দেওয়া (delegation), সময়সীমা, উপরে পাঠানো, রিপোর্ট সবই
 * নতুন করে লিখতে হত। ⚠️ দ্বিতীয় কপি একদিন প্রথমটা থেকে আলাদা হয়ই।
 *
 * ⓘ তাই স্তরগুলো কোম্পানির নিজের [[ApprovalFlow]] (`promotion · approve`)
 * — কয় স্তর, কোন রোল, কে — সবই Approval Centre-এর ছকের পর্দায়। ⭐ ছক
 * না থাকলে আচরণ **অবিকল আজকের**: এক সই, [[PromotionLifecycle::approve()]]।
 *
 * ⓘ ইঞ্জিনটা `App\Core`-এর, তাই `depends_on`-এ `approval` লাগে না —
 * Customer-ও একইভাবে বাকির সীমায় এটা ব্যবহার করে।
 *
 * ── ⚠️ ইঞ্জিন যা করে না, আর এই ক্লাস কেন আছে ────────────────────────
 * 1. ⛔ ইঞ্জিন কাগজটা এগোয় না — শেষ সইয়ে কেবল অনুরোধটা `approved` হয়।
 *    ইনবক্স থেকে সই হলে অফারটা *"জমা"*-তেই পড়ে থাকত। ⭐ [[settle()]]
 *    অনুরোধের ফল পড়ে অফারের অবস্থা মেলায় — যে দরজা দিয়েই সই আসুক।
 * 2. ⛔ ইঞ্জিন কেবল **একই স্তরে** দুইবার সই আটকায়। স্পেকের তিন স্তরের
 *    মানে *"তিনজন আলাদা মানুষ দেখেছেন"* — একজন দুই রোলে থাকলে দুই সই
 *    দিয়ে ঐ মানেটা নীরবে ভাঙত। ⭐ নিজের দরজায় আগেই থামানো হয়, আর
 *    ইনবক্স দিয়ে পার হলে [[settle()]] অফারটা খসড়ায় ফেরত পাঠায়।
 * 3. ⚠️ ইঞ্জিন সুপার অ্যাডমিনকে নিজের অনুরোধে সই দিতে দেয়। ⓘ অফারের
 *    আজকের নিয়ম (*"নিজের অফার নিজে অনুমোদন নয়"*) কাউকে ছাড় দেয় না —
 *    এই ক্লাস সেটাই রাখে, যতক্ষণ না মালিক অন্য কিছু বলেন।
 *
 * ── ⓘ অবস্থা কোথায় লেখা হয় ──────────────────────────────────────────
 * ⛔ এখানে নয়। `status` লেখার একমাত্র দরজা [[PromotionLifecycle]] —
 * এই ক্লাস তাকে [[PromotionLifecycle::signedOff()]] আর
 * [[PromotionLifecycle::sentBack()]] দিয়ে ডাকে।
 */
final class PromotionApprovalChain
{
    /** ⓘ ছকের পর্দায় মডিউলের নাম — `module.php`-এর `code`। */
    public const MODULE = 'promotion';

    /** ⓘ ছকের পর্দায় কাজের নাম — `module.php`-এর `approvals`-এর চাবি। */
    public const ACTION = 'approve';

    /**
     * ⓘ অডিটের কাজের নাম — খসড়ায় ফেরার প্রতিটা ঘটনা, কারণসহ।
     *
     * ⚠️ কলামটা ২৪ অক্ষরের; নামটা ছোট রাখা ইচ্ছাকৃত।
     */
    public const SENT_BACK = 'sent_back';

    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly PromotionLifecycle $life,
        private readonly SettingsService $settings,
    ) {}

    /**
     * ⭐ ছকের সীমার সাথে মেলানোর অঙ্ক — অফারের মোট ছাদ।
     *
     * ⓘ এতে কোম্পানি বলতে পারে *"এক লাখের বেশি বাজেটের অফারে অর্থ বিভাগ
     * লাগবে"* — ছকের `threshold_amount` দিয়ে, কোনো কোড ছাড়া।
     *
     * ⚠️ ছাদ না থাকলে `null` — আর ইঞ্জিন তখন সীমা যা-ই হোক অনুমোদন চায়
     * ([[ApprovalFlow::appliesTo()]])। ⛔ সীমাহীন অফার সবচেয়ে দামি হতে
     * পারে, তাই সন্দেহে কড়া দিকটাই।
     */
    public static function amountOf(Promotion $offer): ?string
    {
        $ceiling = PromotionBudget::query()
            ->where('promotion_id', $offer->getKey())
            ->where('kind', PromotionBudget::TOTAL)
            ->value('ceiling');

        return $ceiling === null ? null : bcadd((string) $ceiling, '0', 4);
    }

    /**
     * ⭐ এই অফারে বহু-স্তর ছক খাটে কি না।
     *
     * ⛔ অনুমোদনের সুইচ বন্ধ থাকলে কখনো নয় — সুইচটাই মালিকের কথা, ছক নয়।
     */
    public function hasChain(Promotion $offer): bool
    {
        return $this->needsApproval()
            && $this->engine->requires(
                self::MODULE,
                self::ACTION,
                self::amountOf($offer),
                class_basename($offer),
                ApprovalEngine::fieldsOf($offer),
            );
    }

    /**
     * খসড়া → জমা, আর ছক থাকলে অনুরোধটা খোলা — এক লেনদেনে।
     *
     * ⚠️ দুইটা আলাদা ধাপ হলে মাঝপথে ভাঙলে অফার *"জমা"* দেখাত অথচ কারও
     * ইনবক্সে থাকত না — কেউ সই দিতে পারতেন না, কেউ জানতেনও না কেন।
     */
    public function submit(Promotion $offer): Promotion
    {
        return DB::transaction(function () use ($offer) {
            $offer = $this->life->submit($offer);

            if ($this->hasChain($offer)) {
                $this->open($offer);
            }

            return $offer;
        });
    }

    /**
     * ⭐ একটা সই — চলতি স্তরে।
     *
     * ⓘ ছক না থাকলে আজকের একক অনুমোদন ([[PromotionLifecycle::approve()]])
     * — আচরণ হুবহু আগের মতো। ⭐ ছক থাকলে কেবল শেষ স্তরের পরেই অফার
     * *"অনুমোদিত"* হয়।
     */
    public function sign(Promotion $offer, User $user, ?string $note = null): Promotion
    {
        return DB::transaction(function () use ($offer, $user, $note) {
            $offer = $this->locked($offer);
            $this->assertWaiting($offer);

            /*
             * ⓘ ছক জমা দেওয়ার **পরে** বসানো হলে অনুরোধ তখনো নেই — এখন
             * খোলা হয়। ⛔ নাহলে নতুন ছক থাকা সত্ত্বেও অফারটা একক সইয়ে পার
             * হত, আর ছকটা মিথ্যা বলত।
             */
            $approval = $this->pendingOf($offer) ?? ($this->hasChain($offer) ? $this->open($offer) : null);

            /* ⭐ ছক নেই — আজকের দরজা, তার নিজের নিয়ম আর নিজের বার্তাসহ */
            if ($approval === null) {
                return $this->life->approve($offer);
            }

            /*
             * ⛔ যিনি বানালেন তিনি কোনো স্তরেই সই দেন না।
             *
             * ⓘ আজকের নিয়মেরই বিস্তার: সুইচ চালু থাকলেই খাটে, আর কাউকে ছাড়
             * দেয় না। ⚠️ ইঞ্জিন সুপার অ্যাডমিনকে ছাড় দেয় — এখানে দেয় না।
             * ⓘ থামলে লেনদেন ফেরে, তাই উপরে খোলা অনুরোধও থাকে না।
             */
            if ($this->needsApproval() && $this->isCreator($offer, $user)) {
                throw ValidationException::withMessages([
                    'status' => __('promotion::approval.cannot_sign_own'),
                ]);
            }

            $this->assertNotSignedBefore($approval, $user);
            $this->assertCanDecide($approval, $user);

            $this->engine->approve($approval, $user, $note);

            return $this->settle($offer);
        });
    }

    /**
     * ⭐ ফেরত পাঠানো — চলতি স্তরের সইকারী *"না"* বলেন, অফার খসড়ায় ফেরে।
     *
     * ⚠️ কারণ বাধ্যতামূলক। ⓘ যিনি অফারটা বানালেন তাঁর প্রথম প্রশ্ন
     * *"কী বদলাব?"* — উত্তর না থাকলে তিনি ফোন করেন, আর অনুমোদন ফোনে চললে
     * সেটা আর ব্যবস্থা থাকে না।
     *
     * ⓘ কারণটা দুই জায়গায় থাকে: ইঞ্জিনের সিদ্ধান্তের সারিতে (Approval
     * Centre-এর রিপোর্টের জন্য), আর অফারের নিজের অডিটে ([[lastReason()]])।
     */
    public function sendBack(Promotion $offer, User $user, string $reason, ?string $reasonCode = null): Promotion
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('promotion::approval.reason_required'),
            ]);
        }

        return DB::transaction(function () use ($offer, $user, $reason, $reasonCode) {
            $offer = $this->locked($offer);
            $this->assertWaiting($offer);

            $approval = $this->pendingOf($offer);

            /*
             * ⓘ ছক নেই — একক অনুমোদনের পথ। ⛔ তবু যিনি বানালেন তিনি
             * "ফেরত" দেন না; তাঁর পথ [[withdraw()]]।
             */
            if ($approval === null) {
                if ($this->isCreator($offer, $user)) {
                    throw ValidationException::withMessages([
                        'status' => __('promotion::approval.cannot_sign_own'),
                    ]);
                }

                return $this->returnToDraft($offer, $reason);
            }

            $this->assertCanDecide($approval, $user);

            $this->engine->reject($approval, $user, $reason, $reasonCode);

            return $this->settle($offer);
        });
    }

    /**
     * ⓘ যিনি বানালেন তিনি জমা দেওয়া অফার ফেরত নেন — ভুল ধরা পড়লে।
     *
     * ⚠️ ইনবক্সের *"ফেরত নিন"* বোতামও ঠিক এটাই করে ([[ApprovalEngine::cancel()]]);
     * [[settle()]] দুই পথকেই একইভাবে মেলায়।
     */
    public function withdraw(Promotion $offer, User $user): Promotion
    {
        return DB::transaction(function () use ($offer, $user) {
            $offer = $this->locked($offer);
            $this->assertWaiting($offer);

            if (! $this->isCreator($offer, $user)) {
                throw ValidationException::withMessages([
                    'status' => __('promotion::approval.only_creator_withdraws'),
                ]);
            }

            $approval = $this->pendingOf($offer);

            if ($approval === null) {
                return $this->returnToDraft($offer, __('promotion::approval.withdrawn'));
            }

            $this->engine->cancel($approval, $user);

            return $this->settle($offer);
        });
    }

    /**
     * ⭐ অনুরোধের ফল পড়ে অফারের অবস্থা মেলানো — বারবার ডাকলেও নিরাপদ।
     *
     * ── ⚠️ কেন দরকার ────────────────────────────────────────────────
     * ⓘ সই কেবল অফারের পাতা থেকে আসে না — Approval Centre-এর ইনবক্স, একসাথে
     * সই ([[BulkApproval]]), আর ফোন থেকেও আসে। ⛔ ঐ পথগুলো ইঞ্জিন সরাসরি
     * ডাকে, আর ইঞ্জিন কাগজটা এগোয় না। ⭐ তাই অফারের পাতা, চালু করার দরজা
     * আর এই ক্লাসের প্রতিটা দরজা শেষে এটা ডাকে।
     *
     * ── ⛔ শেষ সইয়ের পরেও দুইটা প্রশ্ন ───────────────────────────────
     * ইনবক্সের পথে এই ক্লাসের পাহারা বসে না। ⓘ তাই এখানে আবার মাপা হয়:
     * যিনি বানালেন তিনি কি কোথাও সই দিয়েছেন, আর একজন কি দুই স্তরে সই
     * দিয়েছেন। ⚠️ দিলে অফারটা *"অনুমোদিত"* হয় না — কারণসহ খসড়ায় ফেরে।
     * ইঞ্জিনের সারিগুলো অবিকল থাকে: কে কী দিয়েছিলেন সেটা সত্য ইতিহাস।
     */
    public function settle(Promotion $offer): Promotion
    {
        if ($offer->status !== PromotionStatus::SUBMITTED) {
            return $offer;
        }

        $latest = $this->engine->latestFor($offer, self::ACTION);

        if ($latest === null || $latest->status === Approval::PENDING || $this->consumed($offer, $latest)) {
            return $offer;
        }

        if ($latest->status === Approval::REJECTED) {
            return $this->returnToDraft($offer, $this->rejectionOf($latest));
        }

        if ($latest->status === Approval::CANCELLED) {
            return $this->returnToDraft($offer, __('promotion::approval.withdrawn'));
        }

        $signed = $this->approvedDecisions($latest);

        if ($signed->contains(fn (ApprovalDecision $d) => $this->isCreatorId($offer, (int) $d->user_id)
            || ($d->on_behalf_of !== null && $this->isCreatorId($offer, (int) $d->on_behalf_of)))) {
            return $this->returnToDraft($offer, __('promotion::approval.creator_signed'));
        }

        $twice = $this->signedAtTwoLevels($signed);

        if ($twice !== null) {
            return $this->returnToDraft($offer, __('promotion::approval.signed_twice', [
                'name' => (string) (User::query()->whereKey($twice)->value('name') ?? '#'.$twice),
            ]));
        }

        $last = $signed->sortByDesc('id')->first();

        return $this->life->signedOff(
            $offer,
            (int) $last->user_id,
            $latest->decided_at !== null ? Carbon::parse($latest->decided_at) : Carbon::now(),
        );
    }

    /**
     * ⭐ পাতায় বোতাম দেখাবে কি না — সেবা যা মানবে, হুবহু তাই।
     *
     * ⚠️ পাতায় আলাদা করে লিখলে একদিন বোতাম দেখা যেত অথচ চাপলে ভুল আসত।
     */
    public function canSign(Promotion $offer, User $user): bool
    {
        if ($offer->status !== PromotionStatus::SUBMITTED) {
            return false;
        }

        if ($this->needsApproval() && $this->isCreator($offer, $user)) {
            return false;
        }

        $approval = $this->pendingOf($offer);

        if ($approval === null) {
            return true;
        }

        return ! $this->hasSigned($approval, $user) && $this->engine->canDecide($approval, $user);
    }

    /**
     * ⓘ খসড়ায় ফেরার সবশেষ কারণ — পাতায় দেখানোর জন্য।
     */
    public function lastReason(Promotion $offer): ?string
    {
        $reason = AuditTrail::query()
            ->forRecord($offer::class, (int) $offer->getKey())
            ->where('action', self::SENT_BACK)
            ->orderByDesc('id')
            ->value('reason');

        return $reason === null ? null : (string) $reason;
    }

    /**
     * ⭐ যাত্রাপথ — প্রতিটা স্তর, কে সই দিলেন, কখন।
     *
     * ⓘ স্তরের নাম ছকের `step_name` থেকে; ⚠️ নাম না থাকলে *"স্তর ২"* —
     * খালি ঘর নয়, কারণ খালি ঘর আর "এখনো কেউ দেননি" একরকম দেখাত।
     *
     * @return list<array{level: int, name: string, decision: ?string, by: ?string, at: ?Carbon, current: bool}>
     */
    public function progress(Promotion $offer): array
    {
        $approval = $this->engine->latestFor($offer, self::ACTION);

        if ($approval === null) {
            return [];
        }

        $decisions = $approval->decisions()
            ->with('user')
            ->where('decision', '!=', ApprovalDecision::FORWARDED)
            ->orderBy('id')
            ->get()
            ->groupBy('level');

        $rows = [];

        foreach ($this->engine->stepsFor($approval)->groupBy('level') as $level => $steps) {
            $named = $steps->first(fn ($s) => ($s->step_name ?? '') !== '');
            $last = ($decisions->get($level) ?? collect())->last();

            $rows[] = [
                'level' => (int) $level,
                'name' => $named !== null ? (string) $named->step_name : __('promotion::approval.level', ['n' => $level]),
                'decision' => $last?->decision,
                'by' => $last?->user?->name,
                'at' => $last?->decided_at,
                'current' => $approval->isPending() && (int) $approval->current_level === (int) $level,
            ];
        }

        return $rows;
    }

    // ── ভিতরের কাজ ─────────────────────────────────────────────────────

    /**
     * ⭐ ইঞ্জিনে অনুরোধ খোলা — যিনি **বানালেন** তাঁর নামে।
     *
     * ⓘ জমা যিনিই দিন, অনুরোধকারী হিসেবে বানানেওয়ালা বসেন। ⚠️ তাতে ইঞ্জিনের
     * নিজের *"নিজের অনুরোধে সই নয়"* নিয়মটা ইনবক্সেও বানানেওয়ালাকে আটকায়,
     * আর *"ফেরত নিন"* বোতামটাও তাঁর কাছেই যায়।
     */
    private function open(Promotion $offer): Approval
    {
        $approval = $this->engine->request(
            $offer,
            self::MODULE,
            self::ACTION,
            self::amountOf($offer),
            reason: __('promotion::approval.request_reason', ['code' => $offer->code, 'name' => $offer->name()]),
            userId: (int) $offer->created_by,

            /* ⚠️ শর্তযুক্ত ছক কাগজের ঘর না পেলে "মেলে না" বলে — তাই ঘরগুলো পাঠানো */
            matchOn: ApprovalEngine::fieldsOf($offer),
        );

        if ($approval === null) {
            throw new LogicException('The approval flow for promotion.approve matched but opened no request.');
        }

        return $approval;
    }

    private function pendingOf(Promotion $offer): ?Approval
    {
        $latest = $this->engine->latestFor($offer, self::ACTION);

        return $latest !== null && $latest->isPending() ? $latest : null;
    }

    /**
     * ⭐ এই ফলটা আগেই মেলানো হয়েছে কি না।
     *
     * ⛔ না দেখলে: ফেরত পাঠানো অফার আবার জমা পড়ল, অথচ এবার ছক খাটে না
     * (সীমার নিচে বা ছক বন্ধ) — তখন সবশেষ অনুরোধ সেই পুরনো *"না"*, আর
     * অফারটা বিনা কারণে আবার খসড়ায় ফিরত, বারবার।
     *
     * ⓘ মেলানো মানে খসড়ায় ফেরা, আর প্রতিটা ফেরা অডিটে বসে। ⚠️ সময় একই
     * সেকেন্ডে পড়লে উত্তর *"মেলানো হয়েছে"* — ভুল হলে ভুলটা নিরাপদ দিকে:
     * অফার *"জমা"*-তে থাকে, আর পরের সইয়ে [[sign()]] নতুন অনুরোধ খোলে।
     */
    private function consumed(Promotion $offer, Approval $approval): bool
    {
        if ($approval->decided_at === null) {
            return false;
        }

        return AuditTrail::query()
            ->forRecord($offer::class, (int) $offer->getKey())
            ->where('action', self::SENT_BACK)
            ->where('created_at', '>=', $approval->decided_at)
            ->exists();
    }

    private function returnToDraft(Promotion $offer, string $reason): Promotion
    {
        $offer = $this->life->sentBack($offer);
        $offer->auditAction(self::SENT_BACK, $reason);

        return $offer;
    }

    private function rejectionOf(Approval $approval): string
    {
        $remarks = $approval->decisions()
            ->where('decision', ApprovalDecision::REJECTED)
            ->orderByDesc('id')
            ->value('remarks');

        return trim((string) $remarks) !== '' ? (string) $remarks : __('promotion::approval.rejected_without_reason');
    }

    /**
     * @return \Illuminate\Support\Collection<int, ApprovalDecision>
     */
    private function approvedDecisions(Approval $approval): \Illuminate\Support\Collection
    {
        return $approval->decisions()
            ->where('decision', ApprovalDecision::APPROVED)
            ->orderBy('id')
            ->get();
    }

    /**
     * ⛔ একজন মানুষ কি দুই স্তরে সই দিয়েছেন — নিজে, বা কারও হয়ে।
     *
     * ⓘ `on_behalf_of`-ও গোনা হয়: ক স্তর ১-এ নিজে সই দিলেন, আর খ স্তর
     * ২-এ **ক-এর হয়ে** দিলেন — ⚠️ তাহলে ক-এর ক্ষমতা দুইবার খাটল।
     *
     * @param  \Illuminate\Support\Collection<int, ApprovalDecision>  $signed
     */
    private function signedAtTwoLevels(\Illuminate\Support\Collection $signed): ?int
    {
        $levelsOf = [];

        foreach ($signed as $decision) {
            foreach (array_filter([$decision->user_id, $decision->on_behalf_of]) as $person) {
                $levelsOf[(int) $person][(int) $decision->level] = true;
            }
        }

        foreach ($levelsOf as $person => $levels) {
            if (count($levels) > 1) {
                return $person;
            }
        }

        return null;
    }

    private function hasSigned(Approval $approval, User $user): bool
    {
        return $approval->decisions()
            ->where('decision', ApprovalDecision::APPROVED)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('on_behalf_of', $user->id))
            ->exists();
    }

    private function assertNotSignedBefore(Approval $approval, User $user): void
    {
        if (! $this->hasSigned($approval, $user)) {
            return;
        }

        $level = (int) $approval->decisions()
            ->where('decision', ApprovalDecision::APPROVED)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('on_behalf_of', $user->id))
            ->min('level');

        throw ValidationException::withMessages([
            'status' => __('promotion::approval.cannot_sign_twice', ['level' => $level]),
        ]);
    }

    /**
     * ⓘ ইঞ্জিন নিজেও থামায়, কিন্তু `RuntimeException` দিয়ে — পর্দায় ৫০০।
     * ⭐ এখানে আগেই জিজ্ঞেস করে মানুষের ভাষায় বলা হয় কোন স্তরে আটকে আছে।
     */
    private function assertCanDecide(Approval $approval, User $user): void
    {
        if ($this->engine->canDecide($approval, $user)) {
            return;
        }

        $level = (int) $approval->current_level;
        $step = $this->engine->stepNamesFor($approval)[$level] ?? __('promotion::approval.level', ['n' => $level]);

        throw ValidationException::withMessages([
            'status' => __('promotion::approval.not_your_level', ['step' => $step]),
        ]);
    }

    private function assertWaiting(Promotion $offer): void
    {
        if ($offer->status !== PromotionStatus::SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => __('promotion::approval.not_waiting', ['status' => $offer->status->label()]),
            ]);
        }
    }

    /**
     * ⚠️ সারিটা তালা দিয়ে নতুন করে পড়া।
     *
     * ⓘ দুইজন একই মুহূর্তে শেষ স্তরে চাপলে দুইজনই *"জমা"* দেখতেন, আর
     * দুইবার অবস্থা বদলানোর চেষ্টা হত। ⛔ তালায় দ্বিতীয়জন প্রথমজনের ফল
     * দেখেন।
     */
    private function locked(Promotion $offer): Promotion
    {
        return Promotion::query()->whereKey($offer->getKey())->lockForUpdate()->firstOrFail();
    }

    private function isCreator(Promotion $offer, User $user): bool
    {
        return $this->isCreatorId($offer, (int) $user->id);
    }

    private function isCreatorId(Promotion $offer, int $userId): bool
    {
        return $offer->created_by !== null && (int) $offer->created_by === $userId;
    }

    private function needsApproval(): bool
    {
        return (bool) $this->settings->get('promotion.needs_approval', true);
    }
}
