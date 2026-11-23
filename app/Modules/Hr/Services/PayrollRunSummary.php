<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\SummarisesAnApproval;
use App\Core\Security\FieldSecurity;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Hr\Models\PayrollRun;

/**
 * ⭐ ইনবক্সের সারিতে — একসাথে সইয়ের তালিকা আর ফোনের কার্ডেও — বেতনের রানের মাস, জন আর নিট (fe, ২১ অক্টোবর ২০২৬, a6;
 * [[SummarisesAnApproval]], [[ThePayrollSignatureShowsNoNetWithoutTheKeyTest]])।
 *
 * ⛔ নিট কেবল `hr.salary.view`-এর চোখে — নাহলে "••••" (রানের `net_total`-এর একই চাবি, [[FieldSecurity]])। ⓘ অনুরোধের নিজের
 * অঙ্ক খোলা জমা হয় না ([[ApprovalRules::amountKey()]]), তাই নিট এই সারাংশেই, যেখানে ঢাকনা আছে। ⓘ নিট রানের সই-চাওয়ার দিনের
 * ([[Approval::signedAmount()]]), রানের আজকের নয় — সইকারী যা দেখে সই দেন, সেটাই।
 */
final class PayrollRunSummary implements SummarisesAnApproval
{
    public function summary(Approval $approval): ?string
    {
        $run = PayrollRun::acrossBranches()->find($approval->approvable_id);

        if ($run === null) {
            return null;
        }

        $net = $approval->signedAmount() ?? (string) $run->net_total;

        // ⭐ উৎসব ভাতার রান নিজের নামে — সইকারী যেন "বেতন" ভেবে সই না দেন (a6, ২১ অক্টোবর ২০২৬)
        return __($run->isBonus() ? 'hr::message.bonus_signing_summary' : 'hr::message.payroll_signing_summary', [
            'month' => $run->month?->format('M Y') ?? '—',
            'count' => (int) $run->employee_count,
            'net' => FieldSecurity::visible(PayrollRun::class, 'net_total') ? Money::format($net) : FieldSecurity::mask(),
        ]);
    }
}
