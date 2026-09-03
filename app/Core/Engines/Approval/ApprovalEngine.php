<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * অনুমোদন — প্ল্যান সেকশন ২.২, তৃতীয় engine।
 *
 * Phase 1-এই বানানো হচ্ছে, Phase 10-এ নয়। কারণ সিকিউরিটি চেকলিস্ট বলছে
 * edit/delete/reprint/discount-এ অনুমোদন লাগবে — মানে Sales ও Purchase
 * লেখার সময়েই hook দরকার। পরে বসাতে গেলে প্রতিটা কন্ট্রোলার আবার ছুঁতে হত।
 *
 * ইঞ্জিনটা কোনো ডকুমেন্টের কথা জানে না। যে মডিউল অনুমোদন চায় সে শুধু
 * ডকুমেন্টটা আর কাজটার নাম দেয়; কয় স্তর, কে অনুমোদন করবে, সীমা কত —
 * সবই কোম্পানির নিজের সাজানো ছক থেকে আসে।
 */
final class ApprovalEngine
{
    /**
     * কোম্পানির সব সক্রিয় ছক — এই অনুরোধে একবার তোলা।
     *
     * @var array<string, ApprovalFlow>|null
     */
    private ?array $flowCache = null;

    /**
     * ইনার রোলের আইডিগুলো — ব্যবহারকারী ধরে, একবার।
     *
     * @var array<int, list<int>>
     */
    private array $roleCache = [];

    /** নিজে সই করার সীমা — সেটিংস একবারই জিজ্ঞেস করা হয়। */
    private ?string $selfLimit = null;

    /**
     * অনুমোদন লাগবে কি না — লাগলে অনুরোধ তৈরি করে ফেরত দেয়, নাহলে null।
     *
     * null ফেরা মানে "এগিয়ে যাও", তাই কলিং কোড সরল থাকে:
     *   if ($engine->request(...) === null) { /* সরাসরি করে ফেলো *​/ }
     */
    public function request(
        Model $document,
        string $module,
        string $action,
        ?string $amount = null,
        ?array $payload = null,
        ?string $reason = null,
        ?int $userId = null,
    ): ?Approval {
        $flow = $this->flowFor($module, $action, class_basename($document));

        if ($flow === null || ! $flow->appliesTo($amount)) {
            return null;
        }

        if ($flow->steps()->count() === 0) {
            throw new RuntimeException(
                "Approval flow for {$module}.{$action} has no steps. "
                .'A flow with nobody in it would leave every document waiting forever.'
            );
        }

        $existing = Approval::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', $action)
            ->pending()
            ->first();

        // একই কাজের জন্য দুইটা অনুরোধ থাকলে অনুমোদনকারী দুইবার একই জিনিস
        // দেখে, আর একটা অনুমোদন করে অন্যটা ঝুলে থাকে।
        if ($existing !== null) {
            return $existing;
        }

        return Approval::create([
            'company_id' => CompanyContext::id(),
            'approvable_type' => $document::class,
            'approvable_id' => $document->getKey(),
            'module' => $module,
            'action' => $action,
            'amount' => $amount,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'payload' => $payload,
            'requested_reason' => $reason,
            'requested_by' => $userId ?? auth()->id(),
            'requested_at' => now(),
        ]);
    }

    /**
     * এক স্তরের অনুমোদন। সব স্তর শেষ হলে অনুরোধটাই approved হয়।
     */
    public function approve(Approval $approval, User $user, ?string $remarks = null): Approval
    {
        $this->assertPending($approval);
        $this->assertCanDecide($approval, $user);

        return DB::transaction(function () use ($approval, $user, $remarks) {
            ApprovalDecision::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'user_id' => $user->id,
                'decision' => 'approved',
                'remarks' => $remarks,
                'decided_at' => now(),
            ]);

            // ⚠️ অনুরোধের নিজের নথি-ধরন ধরে, `null` ধরে নয় — নাহলে
            // নথি-নির্দিষ্ট ছকে বসা অনুরোধ ভুল ছকের স্তর গুনত, আর
            // পরের স্তরটাই খুঁজে পেত না ([[flowOf]])।
            $flow = $this->flowOf($approval);
            $steps = $flow?->steps ?? collect();

            $currentStep = $steps->firstWhere('level', $approval->current_level);

            // একই স্তরে দুইজন থাকলে এবং সবার সম্মতি লাগলে — বাকিরা না দিলে
            // স্তরটা এখনো শেষ হয়নি।
            if ($currentStep?->requires_all) {
                $required = $steps->where('level', $approval->current_level)->count();
                $given = $approval->decisions()
                    ->where('level', $approval->current_level)
                    ->where('decision', 'approved')
                    ->count();

                if ($given < $required) {
                    return $approval->fresh();
                }
            }

            $nextLevel = $steps->where('level', '>', $approval->current_level)->min('level');

            if ($nextLevel !== null) {
                $approval->update(['current_level' => $nextLevel]);

                return $approval->fresh();
            }

            $approval->update([
                'status' => Approval::APPROVED,
                'decided_at' => now(),
            ]);

            $this->tell($approval, 'approved');

            return $approval->fresh();
        });
    }

    /**
     * প্রত্যাখ্যান — এক স্তরের একটা "না"-ই যথেষ্ট।
     *
     * বাকি স্তরে পাঠানো হয় না, কারণ নিচের স্তর না চাইলে উপরের স্তরের
     * মতামত নেওয়ার কোনো অর্থ নেই — আর সেটা চাইলে অনুরোধ নতুন করে করতে হবে।
     */
    public function reject(Approval $approval, User $user, string $remarks): Approval
    {
        $this->assertPending($approval);
        $this->assertCanDecide($approval, $user);

        return DB::transaction(function () use ($approval, $user, $remarks) {
            ApprovalDecision::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'user_id' => $user->id,
                'decision' => 'rejected',
                'remarks' => $remarks,
                'decided_at' => now(),
            ]);

            $approval->update([
                'status' => Approval::REJECTED,
                'decided_at' => now(),
            ]);

            $this->tell($approval, 'rejected', $remarks);

            return $approval->fresh();
        });
    }

    /**
     * অনুরোধকারীকে ফলটা জানানো।
     *
     * ── কেন এটা ইঞ্জিনের ভেতরে, কন্ট্রোলারে নয় ──────────────────────
     * সিদ্ধান্ত কেবল Approval Centre-এর পর্দা থেকে আসে না — ভবিষ্যতে
     * API থেকে, ইমপোর্ট থেকে, বা কোনো নির্ধারিত কাজ থেকেও আসতে পারে।
     * খবরটা কন্ট্রোলারে বসালে ওই পথগুলোতে নীরব থাকত, আর "কখনো কখনো
     * খবর আসে" ব্যবস্থাটা খবর না আসার চেয়েও খারাপ: মানুষ তখন ঘণ্টার
     * উপর ভরসা করেন, অথচ ভরসাটা সবসময় খাটে না।
     *
     * ── প্রত্যাখ্যানের কারণটা খবরের সাথেই যায় ────────────────────────
     * "আপনার দাবি বাতিল" শুনে মানুষ ফোন করেন কারণ জানতে। কারণটা সাথে
     * থাকলে ফোনটা লাগে না — আর অনুমোদনের ব্যবস্থা ফোনে চললে ওটা আর
     * ব্যবস্থা থাকে না।
     */
    private function tell(Approval $approval, string $outcome, ?string $remarks = null): void
    {
        $requester = $approval->requested_by;

        if ($requester === null) {
            return;
        }

        app(NotificationService::class)->send(
            $requester,
            'approval.'.$outcome,
            __('core.notify.approval_'.$outcome, [
                /*
                 * কাগজের নম্বর `approvals`-এ নেই, তাই মডিউল ও কাজের নাম।
                 *
                 * অনুবাদ করা নামই যায় ("বিক্রয় · ছাড়"), কাঁচা কী নয় —
                 * ব্যবহারকারী `sales.discount` দেখে কিছু বোঝেন না।
                 */
                'document' => $this->documentLabel($approval),
            ]),
            $remarks,
            Route::has('approval.inbox.index') ? route('approval.inbox.index') : null,
        );
    }

    /** অনুরোধকারী নিজে প্রত্যাহার করলে। */
    public function cancel(Approval $approval, User $user): Approval
    {
        $this->assertPending($approval);

        if ($approval->requested_by !== $user->id) {
            throw new RuntimeException('Only the person who asked for an approval can withdraw it.');
        }

        $approval->update(['status' => Approval::CANCELLED, 'decided_at' => now()]);

        return $approval->fresh();
    }

    /**
     * এই ডকুমেন্টের এই কাজের সবশেষ অনুরোধ — থাকলে।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * request() কেবল *অপেক্ষমাণ* অনুরোধ দেখে পুনরাবৃত্তি ঠেকায়। তাই
     * অনুমোদন হয়ে যাওয়ার পর আবার ডাকলে সে একটা নতুন অনুরোধ বানাত —
     * আর ডকুমেন্টটা চিরকাল আটকে থাকত: অনুমোদন পেলেই আবার নতুন
     * অনুমোদন লাগত।
     *
     * তাই যে সেবা পাহারা বসায় সে আগে এটা দেখে নেয়: সিদ্ধান্ত হয়ে
     * থাকলে সেই সিদ্ধান্তটাই মানা হয়, নতুন অনুরোধ নয়।
     */
    /**
     * বিজ্ঞপ্তিতে কাগজটাকে কী বলে ডাকা হবে।
     *
     * অনুবাদ থাকলে সেটাই, নাহলে কাঁচা নামটাই — অনুপস্থিত অনুবাদের
     * জায়গায় `core.module.sales` লেখা দেখানোর চেয়ে `sales` ভালো।
     */
    private function documentLabel(Approval $approval): string
    {
        $module = __('core.module.'.$approval->module);
        $action = __('core.approval.action.'.$approval->action);

        return trim(
            (str_starts_with($module, 'core.') ? $approval->module : $module)
            .' · '.
            (str_starts_with($action, 'core.') ? $approval->action : $action)
        );
    }

    public function latestFor(Model $document, string $action): ?Approval
    {
        return Approval::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', $action)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * এই ব্যবহারকারীর অপেক্ষমাণ তালিকা — Approval Centre-এর queue।
     *
     * ── কেন ছাঁকনিটা SQL-এ, PHP-তে নয় ───────────────────────────────
     * আগে কোম্পানির **সব** অপেক্ষমাণ অনুরোধ মেমরিতে তুলে তারপর একটা
     * একটা করে `canDecide()` জিজ্ঞেস করা হত, আর প্রতিটা সারিতে দুইটা
     * করে কোয়েরি যেত — একটা "ইনি কি এই স্তরে সই দিতে পারেন" (রোল
     * দেখতে), আরেকটা "ইনি কি আগেই দিয়েছেন"। অর্থাৎ খরচটা সারির
     * সংখ্যার সাথে বাড়ত।
     *
     * আজ অপেক্ষমাণ অনুরোধ হাতেগোনা, তাই কেউ টের পায় না। **কিন্তু এই
     * তালিকাটা তিন জায়গা থেকে ডাকা হয়** — Inbox, হোম পর্দার উইজেট,
     * আর স্ট্যাটাস বার — আর মডিউলগুলো একে একে অনুমোদন চাইতে শুরু করলে
     * সারির সংখ্যাটাই বাড়বে। তখন "জোড়া দেওয়ার পর সব ধীর হয়ে গেল" বলে
     * ভুল জায়গায় কারণ খোঁজা হত।
     *
     * ── এখন খরচটা সারির সংখ্যা থেকে স্বাধীন ─────────────────────────
     * ছক, ধাপ আর রোল — তিনটাই একবার তোলা হয়, তা থেকে বেরোয় "ইনি কোন
     * কাজের কোন স্তরে সই দিতে পারেন", আর বাকিটা একটাই কোয়েরি।
     * ইনডেক্সটাও তৈরি ছিল: `approval_queue` (company, status, module)।
     *
     * ⚠️ নিয়মগুলো `canDecide()`-এর সাথে **অবিকল** এক থাকতে হবে — দুইটা
     * আলাদা হয়ে গেলে ইনবক্স এমন সারি দেখাত যেটা খুলে সিদ্ধান্ত দেওয়া
     * যায় না, বা উল্টোটা (আর উল্টোটা নীরব)। সেটা টেস্টে বাঁধা:
     * [[TheInboxAgreesWithTheDecisionTest]]। দুইটাই ছক বাছে একই
     * [[flowFor]] দিয়ে, নথির ধরনসহ।
     *
     * @return Collection<int, Approval>
     */
    public function pendingFor(User $user): Collection
    {
        $tuples = $this->decidableTuples($user);

        // কোনো ছকেই ইনি নেই — একটা কোয়েরিও পাঠানোর দরকার নেই।
        if ($tuples === []) {
            return new Collection();
        }

        $query = Approval::query()
            ->pending()
            // অনুরোধকারীর নাম প্রতিটা সারিতে দেখানো হয়, তাই সাথেই আসে
            ->with('requester')
            ->where(function (Builder $any) use ($tuples): void {
                foreach ($tuples as [$module, $action, $type, $levels]) {
                    $any->orWhere(function (Builder $one) use ($module, $action, $type, $levels): void {
                        $one->where('module', $module)
                            ->where('action', $action)
                            // ⚠️ নথির ধরনটাও শর্তে, কারণ একই কাজে দুইটা
                            // ছক থাকতে পারে — একটা নির্দিষ্ট নথির, একটা
                            // সবার — আর দুইটায় অনুমোদনকারী আলাদা।
                            ->where('approvable_type', $type)
                            ->whereIn('current_level', $levels);
                    });
                }
            })
            // ইনি এই স্তরে আগেই সিদ্ধান্ত দিয়েছেন — আর দেখানোর কিছু নেই
            ->whereNotExists(function (QueryBuilder $already) use ($user): void {
                $already->selectRaw('1')
                    ->from('approval_decisions')
                    ->whereColumn('approval_decisions.approval_id', 'approvals.id')
                    ->whereColumn('approval_decisions.level', 'approvals.current_level')
                    ->where('approval_decisions.user_id', $user->id);
            });

        $this->exceptOwnBeyondLimit($query, $user);

        return $query->orderBy('requested_at')->get();
    }

    /**
     * নিজের অনুরোধ — কেবল সীমার নিচেরগুলো থাকবে।
     *
     * `canDecide()`-এর `withinSelfLimit()` নিয়মটাই, SQL-এ বলা। সীমা
     * শূন্য বা বসানো না থাকলে নিজের একটা অনুরোধও নয় — পুরনো কঠোর
     * নিয়ম, আর সেটাই ডিফল্ট।
     */
    private function exceptOwnBeyondLimit(Builder $query, User $user): void
    {
        $limit = $this->selfLimit();

        if (bccomp($limit, '0', 4) <= 0) {
            $query->where('requested_by', '!=', $user->id);

            return;
        }

        $query->where(function (Builder $mine) use ($user, $limit): void {
            $mine->where('requested_by', '!=', $user->id)
                // অঙ্ক জানা না থাকলে সীমার নিচে কি না তাও জানা নেই —
                // সন্দেহে কড়া দিকটাই, তাই `whereNotNull`।
                ->orWhere(function (Builder $small) use ($limit): void {
                    $small->whereNotNull('amount')->where('amount', '<', $limit);
                });
        });
    }

    /**
     * ইনি কোন কাজের কোন ধরনের নথিতে, কোন স্তরে সই দিতে পারেন।
     *
     * ⭐ ছক বাছাই হয় [[flowFor]] দিয়ে — অর্থাৎ `canDecide()` যে নিয়মে
     * বাছে, ঠিক সেই নিয়মে, নথির ধরনসহ। দুইটা আলাদা হলে ইনবক্স আর
     * সিদ্ধান্তের দরজা দুই কথা বলত।
     *
     * @return list<array{0: string, 1: string, 2: string, 3: list<int>}>
     *         module · action · approvable_type · যেসব স্তর ইনার
     */
    private function decidableTuples(User $user): array
    {
        /*
         * অপেক্ষমাণ সারিগুলোতে কোন কোন ধরনের নথি আছে — একটা কোয়েরি।
         *
         * ── কেন ধরনগুলো ডাটাবেসকেই জিজ্ঞেস করা হয় ──────────────────
         * কোন ছকটা চলবে তা নির্ভর করে নথির ধরনের উপর, আর ছকে ধরনটা
         * লেখা থাকে সংক্ষিপ্ত নামে (`class_basename`) — `Voucher`,
         * পুরো namespace নয়। উল্টো দিকে যাওয়া যায় না: `Voucher` থেকে
         * পুরো শ্রেণির নাম বের করার কোনো নির্ভরযোগ্য উপায় নেই, কারণ
         * দুই মডিউলে একই নামের শ্রেণি থাকতে পারে।
         *
         * তাই সোজা পথ: সারিতে যা যা ধরন আছে সেগুলোই তোলা হয় (একটা
         * ছোট DISTINCT), আর প্রতিটার জন্য ছকটা মেমরিতেই বাছা হয়।
         * সংখ্যাটা সারির সাথে বাড়ে না — ধরনের সাথে বাড়ে, আর ধরন
         * হাতেগোনা।
         */
        $types = Approval::query()->pending()
            ->select('module', 'action', 'approvable_type')
            ->distinct()
            ->get();

        $tuples = [];

        foreach ($types as $row) {
            $levels = $this->levelsIn(
                $this->flowFor($row->module, $row->action, class_basename((string) $row->approvable_type)),
                $user,
            );

            if ($levels !== []) {
                $tuples[] = [$row->module, $row->action, $row->approvable_type, $levels];
            }
        }

        return $tuples;
    }

    public function canDecide(Approval $approval, User $user): bool
    {
        /*
         * ছক আছে, ওই স্তরে ধাপ আছে, আর ধাপটা ইনাকে অনুমতি দেয় —
         * তিনটাই একসাথে এখানে। ⚠️ `pendingFor()` ঠিক এই দুইটা মেথডই
         * ব্যবহার করে ([[flowOf]] · [[levelsIn]]), যাতে ইনবক্স আর এই
         * প্রশ্নটা কখনো দুই কথা না বলে।
         */
        $levels = $this->levelsIn($this->flowOf($approval), $user);

        if (! in_array((int) $approval->current_level, $levels, true)) {
            return false;
        }

        /*
         * নিজের অনুরোধ নিজে অনুমোদন — কেবল সীমার নিচে।
         *
         * ── কেন নিয়মটা একেবারে কঠোর নয় ──────────────────────────────
         * "কখনোই নয়" বড় অঙ্কে ঠিক: যে ছাড় চায় সে-ই যদি দেয়, তবে পুরো
         * ব্যবস্থাটাই সাজানো। কিন্তু ছোট অঙ্কে ওটা কাজ থামায় — এক
         * টাকার চায়ের বিল দ্বিতীয় একজনের সইয়ের অপেক্ষায় বসে থাকলে
         * বাস্তবে চা-টা কেউ নিজের পকেট থেকে কিনে ফেলেন, আর খাতা নীরবে
         * দিনের সাথে মেলা বন্ধ করে।
         *
         * তাই সীমা (`approval.self_limit`): এর নিচে নিজে সই করা যায়,
         * এতে বা এর উপরে অন্য কাউকে লাগে।
         *
         * ── ডিফল্ট শূন্য, অর্থাৎ আজকের আচরণ অবিকল ────────────────────
         * মালিক সংখ্যাটা না বসানো পর্যন্ত কিছুই বদলায় না। নিয়ম শিথিল
         * করার সিদ্ধান্তটা তাঁর, কোডের নয়।
         *
         * অঙ্ক না জানা থাকলেও (`amount` খালি) নিজে সই করা যায় না:
         * কত টাকা জানা নেই মানে সীমার নিচে কি না তাও জানা নেই, আর
         * সন্দেহে কড়া দিকটাই নিরাপদ।
         */
        if ($approval->requested_by === $user->id && ! $this->withinSelfLimit($approval)) {
            return false;
        }

        return ! $approval->decisions()
            ->where('level', $approval->current_level)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * অনুরোধটা নিজে সই করার মতো ছোট কি না।
     *
     * সীমা শূন্য বা বসানো না থাকলে উত্তর সবসময় "না" — অর্থাৎ পুরনো
     * কঠোর নিয়ম। এটাই ডিফল্ট, আর ইচ্ছাকৃত।
     */
    private function withinSelfLimit(Approval $approval): bool
    {
        $limit = $this->selfLimit();

        if (bccomp($limit, '0', 4) <= 0) {
            return false;
        }

        $amount = (string) ($approval->amount ?? '');

        if ($amount === '') {
            return false;
        }

        return bccomp($amount, $limit, 4) < 0;
    }

    /**
     * সীমাটা অনুরোধ প্রতি একবার — সারি প্রতি একবার নয়।
     *
     * ইঞ্জিনটা `scoped`, তাই এই জমানোটা এক অনুরোধেই থাকে; মালিক সংখ্যাটা
     * বদলালে পরের পাতাতেই কার্যকর হয়।
     */
    private function selfLimit(): string
    {
        return $this->selfLimit ??= (string) (app(SettingsService::class)->get('approval.self_limit') ?? '0');
    }

    /**
     * কোম্পানির সব সক্রিয় ছক — অনুরোধ প্রতি একবার, ধাপসহ।
     *
     * ── কেন সবগুলো একসাথে, চাহিদামতো একটা করে নয় ────────────────────
     * আগে প্রতিটা `module.action` আলাদা কোয়েরিতে খোঁজা হত আর ফলটা
     * জমিয়ে রাখা হত। কিন্তু `pendingFor()`-কে জানতে হয় **কোন কোন কাজে**
     * এই মানুষটা সই দিতে পারেন — অর্থাৎ সবগুলোই লাগে। ছকের টেবিলটা
     * ছোট (কাজপ্রতি একটা, কোম্পানিপ্রতি), তাই একবারে তুলে নেওয়াই সস্তা।
     *
     * ⓘ `BelongsToCompany` কোম্পানির সীমাটা নিজেই বসায়।
     *
     * @return array<string, ApprovalFlow>  "module|action|document_type"
     */
    private function flows(): array
    {
        if ($this->flowCache !== null) {
            return $this->flowCache;
        }

        $flows = [];

        foreach (ApprovalFlow::query()->where('is_active', true)->with('steps')->get() as $flow) {
            $flows[$flow->module.'|'.$flow->action.'|'.$flow->document_type] = $flow;
        }

        return $this->flowCache = $flows;
    }

    /**
     * এই কাজে কোন ছকটা চলবে।
     *
     * ⭐ ── একটাই নিয়ম, আর সেটা এখানেই ────────────────────────────────
     * নথি-নির্দিষ্ট ছক আগে, না থাকলে মডিউল-ব্যাপী ছক। **তিন জায়গা এই
     * একটা মেথডকেই জিজ্ঞেস করে** — অনুরোধ তৈরি, সিদ্ধান্তের অধিকার, আর
     * ইনবক্সের তালিকা।
     *
     * ⚠️ ── আগে তা ছিল না, আর ফলটা নীরব ছিল ──────────────────────────
     * `request()` নথিটা দিত, তাই সে নথি-নির্দিষ্ট ছক পেত ও অনুরোধ
     * বানাত। কিন্তু `canDecide()` ও `approve()` নথিটা দিত না (`null`),
     * তাই তারা কেবল মডিউল-ব্যাপী ছক খুঁজত। যে কোম্পানি **শুধু** একটা
     * নথি-নির্দিষ্ট ছক বসাতেন — "বড় চালানের জন্য অনুমোদন" — তাঁর
     * অনুরোধ তৈরি হত, অথচ কেউ কোনোদিন সিদ্ধান্ত দিতে পারতেন না।
     * **কাগজটা চিরকাল ঝুলে থাকত, আর কেউ বুঝত না কেন।**
     *
     * ⛔ আমাদের কোনো ডাটাবেসে এটা ধরা পড়ত না — সব seeded ছক
     * মডিউল-ব্যাপী। ধরা পড়ত ক্রেতার অফিসে, প্রথম নথি-নির্দিষ্ট ছকের দিন।
     *
     * ⓘ "সব ধরনে" মানে **খালি লেখা, NULL নয়** — NULL রাখলে unique index
     * কাজ করত না (MySQL-এ NULL ≠ NULL), আর একই কাজে দুইটা ছক বসে যেত:
     * একটা চলত, অন্যটা নীরবে মরে থাকত (V-মাইগ্রেশন ১১ আগস্ট)।
     */
    private function flowFor(string $module, string $action, ?string $documentType): ?ApprovalFlow
    {
        $flows = $this->flows();

        if ($documentType !== null && isset($flows[$module.'|'.$action.'|'.$documentType])) {
            return $flows[$module.'|'.$action.'|'.$documentType];
        }

        return $flows[$module.'|'.$action.'|'] ?? null;
    }

    /**
     * একটা অনুরোধের জন্য কোন ছকটা চলবে।
     *
     * নথির ধরনটা অনুরোধের সারিতেই লেখা আছে (`approvable_type`), তাই
     * সিদ্ধান্তের সময় নথিটা হাতে না থাকলেও **একই ছকে পৌঁছানো যায়** —
     * আর সেটাই উপরের বাগটার সারাই।
     */
    private function flowOf(Approval $approval): ?ApprovalFlow
    {
        return $this->flowFor(
            $approval->module,
            $approval->action,
            class_basename((string) $approval->approvable_type),
        );
    }

    /**
     * এই ছকের কোন কোন স্তরে এই মানুষটা সই দিতে পারেন।
     *
     * ⓘ পুরোটা মেমরিতে — ছক ও রোল দুইটাই একবার তোলা, তাই সারির সংখ্যা
     * যতই হোক এখানে আর কোনো কোয়েরি যায় না।
     *
     * @return list<int>
     */
    private function levelsIn(?ApprovalFlow $flow, User $user): array
    {
        if ($flow === null) {
            return [];
        }

        $roleIds = $this->roleIds($user);
        $levels = [];

        foreach ($flow->steps as $step) {
            $mine = $step->approver_type === ApprovalFlowStep::BY_USER
                ? (int) $step->approver_id === $user->id
                : in_array((int) $step->approver_id, $roleIds, true);

            if ($mine) {
                $levels[] = (int) $step->level;
            }
        }

        return array_values(array_unique($levels));
    }

    /**
     * ইনার রোলগুলো — ব্যবহারকারী প্রতি একবার।
     *
     * `ApprovalFlowStep::allows()` প্রতিবার `$user->roles()` কোয়েরি করে।
     * প্রতিটা সারির প্রতিটা ধাপে সেটা ডাকা মানে একই প্রশ্ন বারবার, তাই
     * উত্তরটা এখানে একবার নিয়ে রাখা হয়। ⓘ `allows()` মুছে ফেলা হয়নি —
     * একটা ধাপ ধরে প্রশ্ন করার জায়গা ওটাই, আর ফর্মগুলো সেটাই ব্যবহার করে।
     *
     * @return list<int>
     */
    private function roleIds(User $user): array
    {
        return $this->roleCache[$user->id] ??= array_map('intval', $user->roles->modelKeys());
    }

    private function assertPending(Approval $approval): void
    {
        if (! $approval->isPending()) {
            throw new RuntimeException(
                "Approval {$approval->id} is already {$approval->status}."
            );
        }
    }

    private function assertCanDecide(Approval $approval, User $user): void
    {
        if (! $this->canDecide($approval, $user)) {
            throw new RuntimeException(
                "User {$user->id} cannot decide approval {$approval->id} at level {$approval->current_level}."
            );
        }
    }
}
