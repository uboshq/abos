<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\SummarisesAnApproval;
use App\Core\Security\FieldSecurity;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use Illuminate\Support\Carbon;

/**
 * ⭐ ইনবক্সের সারিতে — একসাথে সইয়ের তালিকাতেও — বেতন-বদলের আগে আর পরের অঙ্ক, খাত ধরে (fe-র উত্তর ৪, a6, ২১ অক্টোবর ২০২৬;
 * [[SummarisesAnApproval]], [[ASalaryChangeNeedsASecondHandTest]])।
 *
 * ⓘ "আগে" = প্রস্তাবের তারিখে সেই খাতে যা চলছিল (ঐ তারিখ বা তার আগের শেষ সারি), নেই হলে "—"; "পরে" = প্রস্তাবের অঙ্ক।
 * ⛔ অঙ্ক কেবল `hr.salary.view`-এর চোখে — নাহলে "••••" ([[FieldSecurity]]; কাঠামোর `amount`-এর একই চাবি), খাত আর তারিখ থাকে।
 * ⓘ একসাথে সই খোলা ([[BulkApproval]]) — তাই প্রতিটা সারিই নিজের বদল বলে, সইকারী দেখে দেন।
 */
final class SalaryChangeSummary implements SummarisesAnApproval
{
    public function __construct(private readonly SalaryStructureService $salaries) {}

    public function summary(Approval $approval): ?string
    {
        $proposal = $this->salaries->proposalOf($approval);
        $employee = Employee::query()->find($approval->approvable_id);

        if ($proposal === null || $employee === null) {
            return null;
        }

        $sees = FieldSecurity::visible(SalaryStructure::class, 'amount');
        $heads = SalaryHead::query()->whereKey(array_keys($proposal['lines']))->get()->keyBy('id');
        $date = Carbon::parse($proposal['effective_from']);
        $parts = [];

        foreach ($proposal['lines'] as $headId => $after) {
            $before = SalaryStructure::query()
                ->where('employee_id', $employee->id)
                ->where('salary_head_id', $headId)
                ->whereDate('effective_from', '<=', $date->toDateString())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('amount');

            $parts[] = ($heads->get($headId)?->name() ?? '—').': '
                .($sees ? ($before === null ? '—' : Money::format((string) $before)) : FieldSecurity::mask())
                .' → '
                .($sees ? Money::format($after) : FieldSecurity::mask());
        }

        return __('hr::message.salary_change_summary', [
            'date' => $date->format('d M Y'),
            'lines' => implode(' · ', $parts),
        ]);
    }
}
