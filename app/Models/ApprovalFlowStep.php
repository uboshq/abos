<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
        'min_approvals',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'requires_all' => 'boolean',
            'sla_hours' => 'integer',
            'warn_hours' => 'integer',
            'escalate_hours' => 'integer',
            'min_approvals' => 'integer',
        ];
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
}
