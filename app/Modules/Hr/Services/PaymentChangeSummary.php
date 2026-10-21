<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\SummarisesAnApproval;
use App\Models\Approval;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentChange;

/**
 * ⭐ ইনবক্সের সারিতে বেতনের ঠিকানা বদল — কার, কোন ঘর, এখন → চাওয়া (মালিকের ব্যতিক্রম, a6, ২১ অক্টোবর ২০২৬; [[PaymentChanges]])।
 *
 * ⛔ নম্বর কেবল `hr.identity.view`-এর চোখে পুরো, নাহলে শেষ চার অঙ্ক ([[PaymentChanges::shown()]], কর্মীর পাতার একই নিয়ম)। ⓘ সারাংশ ছাড়া
 * ইনবক্সে কেবল "বেতনের ঠিকানা বদল" লেখা থাকত — সইকারী জানতেন না কার, কোন হিসাবে।
 */
final class PaymentChangeSummary implements SummarisesAnApproval
{
    public function summary(Approval $approval): ?string
    {
        $change = PaymentChange::query()->find($approval->approvable_id);
        $employee = $change === null ? null : Employee::query()->find($change->employee_id);

        if ($change === null || $employee === null) {
            return null;
        }

        $viewer = auth()->user();
        $now = PaymentChanges::detailsOf($employee);
        $asked = (array) $change->proposed;
        $parts = [];

        foreach (PaymentChange::FIELDS as $field) {
            if (($now[$field] ?? null) === ($asked[$field] ?? null)) {
                continue;
            }

            $parts[] = __('hr::field.'.$field).': '.PaymentChanges::shown($field, $now[$field] ?? null, $viewer)
                .' → '.PaymentChanges::shown($field, $asked[$field] ?? null, $viewer);
        }

        return __('hr::payment_change.summary', ['employee' => $employee->name(), 'lines' => implode(' · ', $parts)]);
    }
}
