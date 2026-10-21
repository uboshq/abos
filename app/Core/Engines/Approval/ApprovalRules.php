<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\SummarisesAnApproval;
use App\Core\Module\ModuleRegistry;
use App\Models\Approval;

/**
 * ⭐ মডিউলের `approval_rules` — কাজ ধরে সইকারীর চাবি আর ইনবক্সের সারির লেখা (a6, ২১ অক্টোবর ২০২৬; §৮ ⚠️৫, fe-র উত্তর ২ ও ৪)।
 *
 * ⓘ কোর কাজের নাম জানে না — মডিউল বলে: `'salary_change' => ['signer_key' => 'hr.salary.view', 'summary' => …::class]`।
 *  · `signer_key` — এই কাজের ছকের ধাপে এমন কাউকে (মানুষ বা রোল) বসানো যায় না যাঁর হাতে চাবিটা নেই
 *    ([[ApprovalFlowService::create()]]/`update()`); ⛔ নাহলে সইকারী অঙ্ক না দেখেই সই দিতেন।
 *  · `summary` — [[SummarisesAnApproval]]; ইনবক্সের সারিতে কী বদলাচ্ছে ([[ApprovalFacts::of()]])।
 */
final class ApprovalRules
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function signerKey(string $module, string $action): ?string
    {
        $key = $this->modules->get($module)?->approvalRules[$action]['signer_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function summaryOf(Approval $approval): ?string
    {
        $class = $this->modules->get((string) $approval->module)?->approvalRules[(string) $approval->action]['summary'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, SummarisesAnApproval::class)) {
            return null;
        }

        return app($class)->summary($approval);
    }
}
