<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একটা তারিখে একজন কর্মীর বেতন কী দাঁড়ায়।
 *
 * ── কেন হিসাবটা এক জায়গায় ───────────────────────────────────────────
 * একই হিসাব তিন জায়গায় লাগে: কর্মীর পর্দায় "এখন কত পান", বেতনশিট
 * বানানোর সময়, আর বেতন বাড়ানোর আগে "এখন কত" দেখানোর সময়। তিন জায়গায়
 * তিনবার লিখলে একদিন একটায় বাড়িভাড়ার শতাংশ পুরনো থেকে যেত, আর দুই
 * পর্দায় দুই অঙ্ক দেখাত — তখন কোনটা সত্যি তা বলার উপায় থাকত না।
 */
final class SalaryStructureService
{
    /**
     * এই তারিখে কার্যকর প্রতিটা খাত ও তার টাকার অঙ্ক।
     *
     * ফেরত আসে খাত ধরে ধরে, ক্রম সহ — যাতে বেতনশিটে সবসময় একই ক্রমে
     * সারিগুলো ছাপা হয়।
     *
     * @return list<array{head: SalaryHead, amount: string}>
     */
    public function componentsOn(Employee $employee, Carbon $date): array
    {
        $rows = $this->effectiveRows($employee, $date);

        // মূল বেতন আগে বের করা — শতাংশের খাতগুলো এটার উপর দাঁড়ায়
        $basic = '0';

        foreach ($rows as $row) {
            if ($row->salaryHead->is_basic) {
                $basic = (string) $row->amount;
            }
        }

        $components = [];

        foreach ($rows as $row) {
            $head = $row->salaryHead;

            $amount = $head->calculation === SalaryHead::PERCENT_OF_BASIC
                ? bcdiv(bcmul($basic, (string) $row->amount, 6), '100', 4)
                : bcadd((string) $row->amount, '0', 4);

            $components[] = ['head' => $head, 'amount' => $amount];
        }

        usort($components, fn (array $a, array $b) => [$a['head']->kind, $a['head']->sort_order, $a['head']->code]
            <=> [$b['head']->kind, $b['head']->sort_order, $b['head']->code]);

        return $components;
    }

    /**
     * মোট আয়, মোট কর্তন, আর হাতে যা থাকে।
     *
     * @return array{gross: string, deductions: string, net: string}
     */
    public function totalsOn(Employee $employee, Carbon $date): array
    {
        $gross = '0';
        $deductions = '0';

        foreach ($this->componentsOn($employee, $date) as $component) {
            if ($component['head']->isEarning()) {
                $gross = bcadd($gross, $component['amount'], 4);
            } else {
                $deductions = bcadd($deductions, $component['amount'], 4);
            }
        }

        return [
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => bcsub($gross, $deductions, 4),
        ];
    }

    /**
     * একটা খাতে নতুন অঙ্ক বসানো — নির্দিষ্ট তারিখ থেকে।
     *
     * একই তারিখে দ্বিতীয়বার বসালে সেটাই সংশোধন, দ্বিতীয় সারি নয়:
     * এক দিনে এক খাতে দুইটা অঙ্ক থাকলে কোনটা চলছে তা বলার উপায় থাকত না।
     */
    public function set(Employee $employee, SalaryHead $head, string $effectiveFrom, string $amount): SalaryStructure
    {
        $this->assertAmountIsSane($head, $amount);

        return DB::transaction(function () use ($employee, $head, $effectiveFrom, $amount) {
            $row = SalaryStructure::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'salary_head_id' => $head->id,
                    'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
                ],
                [
                    'amount' => $amount,
                    'created_by' => auth()->id(),
                ],
            );

            return $row->fresh();
        });
    }

    /**
     * প্রতিটা খাতের সর্বশেষ কার্যকর সারি।
     *
     * ── কেন "এই তারিখ বা তার আগের সর্বশেষ" ──────────────────────────
     * বেতন প্রতি মাসে বসানো হয় না। জানুয়ারিতে কাঠামো বসিয়ে জুনে
     * বেতনশিট বানালে জুনের কোনো সারি নেই — কিন্তু জানুয়ারিরটাই তো
     * চলছে। ঠিক মিল খুঁজলে ওই কর্মীর বেতনই শূন্য দেখাত।
     *
     * @return list<SalaryStructure>
     */
    private function effectiveRows(Employee $employee, Carbon $date): array
    {
        $rows = SalaryStructure::query()
            ->with('salaryHead')
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        $latest = [];

        // ক্রম অনুযায়ী হাঁটা, তাই পরেরটা আগেরটাকে চাপা দেয় — খাত প্রতি
        // একটাই সারি টিকে থাকে, আর সেটাই সর্বশেষ কার্যকরটা
        foreach ($rows as $row) {
            if ($row->salaryHead !== null && $row->salaryHead->is_active) {
                $latest[$row->salary_head_id] = $row;
            }
        }

        return array_values($latest);
    }

    /**
     * শতাংশের খাতে ১০০-র বেশি, বা যেকোনো খাতে ঋণাত্মক অঙ্ক নয়।
     *
     * ঋণাত্মক কর্তন আসলে একটা ভাতা, আর ঋণাত্মক ভাতা একটা কর্তন — দুইটাই
     * বইকে উল্টো করে দেয়, অথচ সংখ্যাটা দেখতে নিরীহ।
     */
    private function assertAmountIsSane(SalaryHead $head, string $amount): void
    {
        if (bccomp($amount, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'amount' => __('hr::validation.amount_cannot_be_negative'),
            ]);
        }

        if ($head->calculation === SalaryHead::PERCENT_OF_BASIC && bccomp($amount, '100', 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('hr::validation.percent_out_of_range'),
            ]);
        }
    }
}
