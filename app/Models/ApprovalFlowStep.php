<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Engines\Approval\ApprovalEngine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** অনুমোদনের এক ধাপ — কোন রোল বা কোন ব্যক্তি। */
class ApprovalFlowStep extends Model
{
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const BY_ROLE = 'role';

    public const BY_USER = 'user';

    /**
     * ⭐ চাবি ধরে — কেবল যন্ত্রের নিজের "দ্বিতীয় সই" ধাপে, কখনো ডাটাবেসে নয় (মালিকের ব্যতিক্রম, a6, ২১ অক্টোবর ২০২৬;
     * [[ApprovalEngine::flows()]], [[secondHand()]])। ⓘ ছকের পর্দা এটা বসাতে দেয় না ([[ApprovalFlowRequest]]: কেবল role/user)।
     */
    public const BY_KEY = 'key';

    /**
     * ⓘ "দ্বিতীয় সই" ধাপের চাবিগুলো — সবগুলো থাকলে তবে সইকারী। ⛔ ঘর (attribute) নয়, তাই কোনোদিন সারি হয়ে বসে না।
     *
     * @var list<string>
     */
    private array $keys = [];

    protected $fillable = [
        'approval_flow_id', 'level', 'step_name', 'approver_type', 'approver_id', 'requires_all',
        'sla_hours', 'warn_hours', 'escalate_hours', 'escalate_to_type', 'escalate_to_id',
        'min_approvals', 'from_amount',
    ];

    /** ⛔ ইঞ্জিনের জমানো ছক পুরনো হয় — যে পথেই সারি বদলাক ([[ApprovalEngine::flowsChanged()]]) */
    protected static function booted(): void
    {
        static::saved(fn () => ApprovalEngine::flowsChanged());
        static::deleted(fn () => ApprovalEngine::flowsChanged());
    }

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'requires_all' => 'boolean',
            'sla_hours' => 'integer',
            'warn_hours' => 'integer',
            'escalate_hours' => 'integer',
            'min_approvals' => 'integer',
            'from_amount' => 'decimal:4',
        ];
    }

    /**
     * ⭐ এই অঙ্কের কাগজে ধাপটা সই দেয় কি না — "কত টাকা থেকে" (অনুমোদন পর্দা ভাগ ৩, দফা ১৭)।
     *
     * ⓘ সীমা না থাকলে সবসময়। ⛔ অঙ্ক না জানা থাকলেও সবসময় — অজানা অঙ্কে একটা সই কম চাওয়ার চেয়ে একটা বেশি চাওয়া নিরাপদ,
     * [[ApprovalFlow::appliesTo()]]-এর একই নিয়ম।
     */
    public function signsAt(?string $amount): bool
    {
        if ($this->from_amount === null || $amount === null) {
            return true;
        }

        return bccomp($amount, (string) $this->from_amount, 4) >= 0;
    }

    /**
     * ⭐ অডিটের সারিটা কার খাতায় — ছকের, প্রসঙ্গের নয় (অডিট §৩, ২৭ সেপ্টেম্বর ২০২৬)।
     *
     * ── ⛔ কেন নাম ধরে বলা দরকার ─────────────────────────────────────
     * `approval_flow_steps`-এর নিজের `company_id` নেই; ধাপটা ছকের মাধ্যমে
     * বাঁধা। ⓘ [[IsAudited]] তখন চলতি প্রসঙ্গ থেকে আইডি নিত — আর প্রসঙ্গ
     * জানে না ঐ কোম্পানি সত্যিই এই ছকের কি না
     * ([[AnAuditedModelMustSayWhoseBooksItBelongsToTest]])।
     *
     * ⚠️ `withoutGlobalScopes()` ইচ্ছাকৃত: [[ApprovalFlow]]-এ
     * [[BelongsToCompany]]-র দেয়াল, তাই অন্য কোম্পানির প্রসঙ্গে বসে
     * (সিডার, [[MoneyFlowDefaults]]) `$this->flow` `null` ফেরাত — ⛔ আর
     * [[AuditEngine]] কোম্পানি না পেলে **নিঃশব্দে কিছুই লেখে না**।
     * ⭐ ছকের সারিটাই একমাত্র সৎ উত্তর: ধাপ যার, খাতাও তার।
     */
    public function auditCompanyId(): ?int
    {
        $companyId = ApprovalFlow::query()
            ->withoutGlobalScopes()
            ->whereKey($this->approval_flow_id)
            ->value('company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * ⓘ ছক কোম্পানির নিয়ম, কোনো শাখার নয় — `approval_flows`-এ শাখার ঘরই
     * নেই। ⚠️ তাই `null`-ই সত্য; ⛔ বদলকারীর নিজের শাখা বসালে একই ছকের
     * দুই বদল দুই শাখায় দেখাত, অথচ নিয়মটা একটাই।
     */
    public function auditBranchId(): ?int
    {
        return null;
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    /**
     * ⭐ যন্ত্রের "দ্বিতীয় সই" ধাপ — এক স্তর, চাবি থাকা যেকোনো একজন (মালিকের ব্যতিক্রম, a6, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ সারি নয় (`exists` মিথ্যা), সংরক্ষণও হয় না — ছকের পর্দায় দেখা যায় না, বদলানো যায় না। ⛔ যিনি চাইলেন তিনি সই দেন না —
     * সেটা ইঞ্জিনের সাধারণ নিয়ম ([[ApprovalEngine::canDecide()]]); মালিক দিলে অডিটে `owner_self_signed`।
     *
     * @param  list<string>  $keys
     */
    public static function secondHand(array $keys, string $name): self
    {
        $step = new self;
        $step->forceFill([
            'level' => 1,
            'step_name' => $name,
            'approver_type' => self::BY_KEY,
            'approver_id' => 0,
            'requires_all' => false,
            'min_approvals' => 1,
        ]);
        $step->keys = array_values($keys);

        return $step;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * ⓘ চাবি ধরে ধাপে ইনি আছেন কি না — মালিক (super admin) সবসময়, নাহলে প্রতিটা চাবি চলতি কোম্পানিতে
     * ([[MoneyFlowDefaults::keyHolders()]]-এর একই মানুষ, রোলের বদলে মানুষ ধরে — নিজের নামে দেওয়া চাবিও গোনে)।
     */
    public function heldBy(User $user): bool
    {
        if ($this->keys === []) {
            return false;
        }

        if ($user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return true;
        }

        foreach ($this->keys as $key) {
            if (! $user->can($key)) {
                return false;
            }
        }

        return true;
    }

    public function allows(User $user): bool
    {
        if ($this->approver_type === self::BY_KEY) {
            return $this->heldBy($user);
        }

        if ($this->approver_type === self::BY_USER) {
            return $user->id === (int) $this->approver_id;
        }

        return $user->roles()->whereKey($this->approver_id)->exists();
    }

    /**
     * ⭐ ধাপের ঘড়ি, ঘণ্টায় — SLA, আর SLA না থাকলে "উপরে পাঠাও"-এর ঘণ্টা (fe-র সিদ্ধান্ত খ, ১৪ অক্টোবর ২০২৬; [[AnEscalationClockAloneStillEscalatesTest]])।
     *
     * ⓘ আগে "উপরে পাঠাও (ঘণ্টা)" কোথাও খাটত না — কেবল SLA-র ঘণ্টায় কাগজ দেরি হত আর উপরে যেত, তাই শুধু এই ঘরটা ভরা ধাপ কোনোদিন উপরে
     * যেত না। দুটোই থাকলে SLA-ই খাটে (পর্দায় লেখা)। ⭐ এক জায়গায়: [[ApprovalSla::dueFor()]] বসায়, [[Approval::warnAt()]] উল্টো পড়ে।
     */
    public function clockHours(): ?int
    {
        return $this->sla_hours ?? $this->escalate_hours;
    }
}
