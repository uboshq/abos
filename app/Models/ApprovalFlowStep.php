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

    public function allows(User $user): bool
    {
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
