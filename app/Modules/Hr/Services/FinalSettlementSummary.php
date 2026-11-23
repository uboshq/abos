<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\SummarisesAnApproval;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Hr\Models\FinalSettlement;

/**
 * ⭐ ইনবক্সের সারিতে চূড়ান্ত পাওনা — কার, শেষ দিন, নিট; নিট কেবল বেতনের চাবিতে (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlements]])।
 */
final class FinalSettlementSummary implements SummarisesAnApproval
{
    public function summary(Approval $approval): ?string
    {
        $settlement = FinalSettlement::query()->with('employee')->find($approval->approvable_id);

        if ($settlement === null || $settlement->employee === null) {
            return null;
        }

        $net = auth()->user()?->can('hr.salary.view') ? Money::format((string) $settlement->net) : '***';

        return __('hr::settlement.summary', [
            'employee' => $settlement->employee->name(),
            'date' => $settlement->last_day->toDateString(),
            'net' => $net,
        ]);
    }
}
