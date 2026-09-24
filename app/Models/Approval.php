<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Engines\Approval\ApprovalEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/** একটা অনুমোদনের অনুরোধ। polymorphic — যেকোনো ডকুমেন্টে বসে। */
class Approval extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'approvable_type', 'approvable_id', 'module', 'action',
        'amount', 'status', 'current_level', 'assigned_to', 'payload', 'state_hash',
        'requested_reason', 'requested_by', 'requested_at', 'decided_at',
        'due_at', 'reminded_at', 'escalated_at', 'escalated_to',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'current_level' => 'integer',
            'payload' => 'array',
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * ⭐ সইটা কি **এই** অঙ্কের উপরই দেওয়া হয়েছিল?
     *
     * ── ⛔ কেন এটা লাগল, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * [[ApprovalEngine::approve()]] কাগজটায় হাত দেয় না — কেবল অনুরোধটাকে
     * `approved` করে। ⚠️ মানুষটাকে ফিরে এসে আবার "নিশ্চিত" চাপতে হয়, আর
     * **ঐ দুই চাপের মাঝখানে কাগজটা খসড়াই থাকে, সম্পাদনা করা যায়**।
     *
     * ⛔ ফলে এটা সম্ভব ছিল: ৫০ হাজারের অর্ডারে ব্যবস্থাপক সই দিলেন, তারপর
     * অর্ডারটা ৫ লাখ করে পাশ করিয়ে নেওয়া হলো। ⓘ খাতায় ৫০ হাজারই লেখা
     * থাকত — অর্থাৎ প্রমাণটাও ভুল থাকত।
     *
     * ── ⓘ কেন অঙ্ক, `updated_at` নয় ────────────────────────────────
     * `updated_at` ধরলে সইয়ের পর **যেকোনো** ছোঁয়া — একটা বানান সংশোধনও —
     * সই বাতিল করত, আর মানুষ শিখত সই নেওয়াটা অর্থহীন। ⚠️ অঙ্কটাই সেই
     * জিনিস যার উপর সইটা দেওয়া হয়েছিল ([[ApprovalFlow::appliesTo()]]
     * সীমাটা এর সাথেই মেলায়), তাই প্রশ্নটাও ঐটাই।
     *
     * ⛔ এটা অঙ্কের বাইরের বদল ধরে না — গ্রাহক পাল্টে দিলে সই টিকে থাকে।
     * ⓘ কথাটা লিখে রাখা হলো, নাহলে পরে কেউ ধরে নিত এটা সবটা ঢাকে।
     *
     * ── ⚠️ অঙ্ক নামলেও সই বাতিল ─────────────────────────────────────
     * ⓘ নামা অঙ্ক নিরীহ মনে হয়, কিন্তু *"যা সই হয়েছিল তার চেয়ে কম হলে
     * চলবে"* লিখলেই প্রশ্ন আসে **কত কম**, আর ঐ প্রশ্নের সৎ উত্তর নেই।
     * ⭐ বাস্তবে কারো পথ আটকায় না: নিচে নামলে অঙ্কটা সীমার নিচে পড়ে আর
     * `request()` এমনিতেই `null` ফেরায়।
     */
    public function covers(?string $amount): bool
    {
        /*
         * ⛔ খালি স্ট্রিংটা "শূন্য" নয়, "জানা নেই"।
         *
         * ⚠️ ডাকার জায়গাগুলো `(string) $voucher->amount` লেখে, আর অঙ্কটা
         * null হলে সেটা `''` হয়ে আসে। ⓘ PHP ৮-এ `bccomp('', …)` সরাসরি
         * `ValueError` ছোঁড়ে — অর্থাৎ পাহারাটা বসানোর ফল হত একটা ভেঙে
         * পড়া পোস্টিং, আটকানো নয়।
         */
        $signed = $this->amount === null ? null : (string) $this->amount;

        $signed = ($signed === null || $signed === '') ? null : $signed;
        $amount = ($amount === null || $amount === '') ? null : $amount;

        // ⓘ দুই দিকেই "জানা নেই" — তুলনার কিছু নেই, সইটাই যা ছিল তাই।
        if ($signed === null || $amount === null) {
            return $signed === null && $amount === null;
        }

        return bccomp($signed, $amount, 4) === 0;
    }

    /**
     * ⭐ সইটা যে কাগজে দেওয়া হয়েছিল, কাগজটা এখনো সেটাই?
     *
     * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ─────────────
     * *"যেকোনো ঘর বদলালেই"* সই বাতিল। ⛔ আগে কেবল টাকার
     * অঙ্ক দেখা হত, তাই অঙ্ক ঠিক রেখে পণ্য বদলে দিলে
     * পুরনো সইটাই চলত।
     *
     * ── ⓘ ছাপ না থাকলে পুরনো নিয়ম ──────────────────────
     * লাইভে আগে থেকে বসে থাকা অনুরোধগুলোর কোনো ছাপ নেই।
     * ⚠️ তখন "বদলেছে" বললে ডিপ্লয়ের দিন প্রতিটা অপেক্ষমাণ
     * কাগজ নতুন করে সই চাইত — একদিনে শত কাগজ।
     */
    public function stillCovers(?string $amount, ?string $hash): bool
    {
        if (! $this->covers($amount)) {
            return false;
        }

        if ($this->state_hash === null || $hash === null) {
            return true;
        }

        return hash_equals((string) $this->state_hash, $hash);
    }

    /**
     * ⭐ এখন কার হাতে — ফরওয়ার্ড হয়ে থাকলে।
     *
     * ⚠️ খালি হলে ছকের স্বাভাবিক নিয়ম চলে; ভরা থাকলে **কেবল ইনিই**
     * এই স্তরে সই দিতে পারেন ([[ApprovalEngine::canDecide()]])।
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * ⭐ মনে করানোর সময়৷ সীমার আগেই।
     *
     * ⓘ ধাপে `warn_hours` বসানো না থাকলে সীমার **অর্ধেক**।
     * ⚠️ আন্দাজ, কিন্তু চুপ থাকার চেয়ে ভালো — আর ধাপে
     * বসিয়ে বদলানো যায়।
     */
    /**
     * ⭐ যে কাগজটার জন্য অনুমোদন চাওয়া হয়েছে।
     *
     * ── ⛔ এই সম্পর্কটা এতদিন **ছিল না**, ২৪ সেপ্টেম্বর ২০২৬ ──
     * ⓘ কলাম দুইটা (`approvable_type`, `approvable_id`) প্রথম দিন
     * থেকেই আছে, আর পর্দাগুলো কাগজটা **হাতে খুঁজত**
     * ([[ApprovalInboxController::documentOf()]])।
     *
     * ⚠️ কিন্তু `$approval->approvable` লেখা স্বাভাবিক, আর Eloquent
     * অজানা অ্যাট্রিবিউটে **ব্যতিক্রম ছোঁড়ে না, `null` দেয়**।
     *
     * ⛔ ফল: দুইটা নতুন কোড নীরবে মরা ছিল — *"সইয়ের পর কাগজ
     * বদলেছে"* সারিটা কখনো আসত না, আর শাখা ধরে বসানো
     * কর্তৃত্বের সীমা কোনোদিন খাটত না। ⓘ দুইটাই মেপে ধরা পড়েছে,
     * পড়ে নয়।
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function warnAt(): ?Carbon
    {
        if ($this->due_at === null || $this->requested_at === null) {
            return null;
        }

        $step = $this->currentStep();

        /*
         * ⭐ ঘড়িটা **এই ধাপের**, অনুরোধের দিনের নয় — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────
         * ⓘ ধাপ এগোলে `due_at` নতুন করে বসে, কিন্তু `requested_at`
         * দিন-কয়েকের পুরনো। ⚠️ তাই অনুরোধের দিন থেকে গুনলে
         * দ্বিতীয় ধাপের মানুষ কাগজটা **হাতে পাওয়ার মুহূর্তেই**
         * সতর্কবার্তা পেতেন।
         *
         * ⛔ আর ক্ষতিটা একটা অর্থহীন বার্তার চেয়ে বড়: বার্তাগুলো এত
         * ঘন হত যে কেউ আর পড়ত না — আর তখন সত্যিকারের দেরির
         * বার্তাটা ওই ভিড়ে হারাত।
         *
         * ── ⓘ ধাপের শুরুটা কোথা থেকে ────────────────────────
         * `due_at - sla_hours` — কারণ [[ApprovalSla::dueFor()]] ঠিক ওভাবেই
         * `due_at` বসায়। ⭐ একটাই নিয়ম, উল্টো দিকে পড়া — তাই
         * দুইটা কখনো আলাদা হতে পারে না।
         */
        $start = $step?->sla_hours !== null
            ? $this->due_at->copy()->subHours((int) $step->sla_hours)
            : $this->requested_at;

        if ($step?->warn_hours !== null) {
            return $start->copy()->addHours((int) $step->warn_hours);
        }

        /*
         * ⓘ কিছু না বসালে সময়সীমার **অর্ধেক** — একটা আন্দাজ,
         * কিন্তু চুপ থাকার চেয়ে ভালো, আর ধাপে বসিয়ে বদলানো যায়।
         */
        $minutes = (int) round($start->diffInMinutes($this->due_at) / 2);

        return $start->copy()->addMinutes($minutes);
    }

    /**
     * এই অনুরোধ এখন যে ধাপে দাঁড়িয়ে।
     *
     * ⚠️ নথি-ধরন ধরে ছকটা খোঁজা হয় ([[ApprovalEngine::flowOf]]-এর
     * একই কারণে), নাহলে নথি-নির্দিষ্ট ছকে বসা অনুরোধ ভুল
     * ছকের ধাপ গুনত।
     */
    public function currentStep(): ?ApprovalFlowStep
    {
        /*
         * ⛔ লুকঅাপটা নিজে করা হয় না — ইঞ্জিনকে জিজ্জাসা করা হয়।
         *
         * ── ⚠️ নিজে করতে গিয়ে যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ─────
         * ⓘ ছক সংরক্ষণের সময় *"সব ধরনে"* লেখা হয় **খালি স্ট্রিং**
         * দিয়ে, আর সেটা ইচ্ছাকৃত ([[ApprovalFlowService]]-এ কারণ লেখা:
         * unique index `null` দুইবার আটকাতে পারে না)।
         *
         * ⛔ কিন্তু এখানে fallback-এ `whereNull('document_type')` খোঁজা হত —
         * অর্থাৎ পর্দা থেকে বসানো **প্রতিটা** ছকে এটা `null` ফেরাত।
         *
         * ⚠️ আর তার উপর যা যা দাঁড়ানো, সব নীরবে মরে যেত:
         * ও `warnAt()` ধাপ পেত না, তাই `warn_hours` কখনো খাটত না
         * ও `abos:approvals-due` গন্তব্য পেত না — প্রতিটা দেরি `no_target`
         * ও গন্তব্যের মানুষ কোনোদিন সই দিতে পারতেন না
         *
         * ⭐ তাই নিয়মটা এক জায়গায়: [[ApprovalEngine::stepsFor()]]।
         */
        return app(ApprovalEngine::class)
            ->stepsFor($this)
            ->firstWhere('level', $this->current_level);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    // ── Drillable — নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে যায় ──────────────

    /**
     * ── কেন অনুরোধটাই গন্তব্য, নিচের কাগজটা নয় ──────────────────────
     * প্রলুব্ধ করে ভাবতে যে "১২টা অপেক্ষমাণ" থেকে ক্লিক করলে ক্রয়াদেশটাই
     * খোলা উচিত। কিন্তু পাঠকের প্রশ্নটা তখন **অনুমোদন নিয়ে** — কে আটকে
     * আছে, কোন স্তরে, কে কী মন্তব্য করেছেন। ওই উত্তরগুলো আছে অনুরোধের
     * পর্দায়, আর সেখান থেকে কাগজটাতেও যাওয়া যায়। উল্টোটা নয়।
     */
    public static function drillSourceType(): string
    {
        return 'approval';
    }

    /**
     * অনুরোধের নিজের কোনো নম্বর নেই — কাগজেরটা আছে।
     *
     * তাই মডিউল ও কাজের নাম, আর সাথে আইডি: রিপোর্টে দুইটা সারি একই
     * রকম দেখালে কোনটা কোনটা বলার আর কোনো উপায় থাকত না।
     */
    public function drillDocumentNo(): string
    {
        return $this->module.'.'.$this->action.'#'.$this->getKey();
    }

    public function drillLabel(): string
    {
        return trim((string) $this->requested_reason) !== ''
            ? (string) $this->requested_reason
            : $this->drillDocumentNo();
    }

    public function drillRoute(): array
    {
        return ['approval.inbox.show', ['approval' => $this->id]];
    }
}
