<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\SalaryHead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বেতনের খাত যোগ, সংশোধন ও নিষ্ক্রিয়করণ।
 *
 * এখানকার একমাত্র অ-তুচ্ছ নিয়ম: মূল বেতন একটাই। শতাংশের প্রতিটা খাত
 * ওটার উপর দাঁড়ায়, তাই দুইটা "মূল" থাকলে বাড়িভাড়া কোনটার অর্ধেক তা
 * নির্ধারিত থাকত না — আর প্রতি মাসে অঙ্কটা বদলে যেতে পারত।
 */
final class SalaryHeadService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SalaryHead
    {
        return DB::transaction(function () use ($data) {
            $code = trim((string) ($data['code'] ?? ''));

            $this->assertCodeIsFree($code);
            $this->assertBasicIsAnEarning($data);

            $head = SalaryHead::create([
                ...$data,
                'code' => $code,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            $this->keepOneBasicOnly($head);

            return $head->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalaryHead $head, array $data): SalaryHead
    {
        return DB::transaction(function () use ($head, $data) {
            $code = isset($data['code']) ? trim((string) $data['code']) : $head->code;

            if ($code !== $head->code) {
                $this->assertCodeIsFree($code, $head->id);
            }

            $this->assertBasicIsAnEarning([...$head->attributesToArray(), ...$data]);

            $head->update([...$data, 'code' => $code]);

            $this->keepOneBasicOnly($head->fresh());

            return $head->fresh();
        });
    }

    public function deactivate(SalaryHead $head): SalaryHead
    {
        /*
         * মূল বেতনের খাত বন্ধ করা যায় না।
         *
         * ওটা বন্ধ হলে শতাংশের প্রতিটা ভাতা শূন্যের শতাংশ হয়ে যেত, আর
         * বেতনশিট চুপচাপ ছোট হয়ে বেরোত — কোনো ভুলের বার্তা ছাড়াই।
         */
        if ($head->is_basic) {
            throw ValidationException::withMessages([
                'is_active' => __('hr::validation.basic_cannot_be_deactivated'),
            ]);
        }

        $head->forceFill(['is_active' => false])->save();

        return $head->fresh();
    }

    /**
     * প্রমিত খাতগুলো — বাংলাদেশে প্রচলিত গড়ন।
     *
     * খালি রেখে "নিজে বানান" বলাটা কাজ ঠেলে দেওয়া: এই ছয়টা না থাকলে
     * প্রথম কর্মীর বেতনই বসানো যায় না।
     */
    public function installDefaults(): int
    {
        $rows = [
            ['BASIC', 'Basic Salary', 'মূল বেতন', SalaryHead::EARNING, SalaryHead::FIXED, true, 10],
            ['HRA', 'House Rent', 'বাড়িভাড়া', SalaryHead::EARNING, SalaryHead::PERCENT_OF_BASIC, false, 20],
            ['MEDICAL', 'Medical Allowance', 'চিকিৎসা ভাতা', SalaryHead::EARNING, SalaryHead::FIXED, false, 30],
            ['CONVEY', 'Conveyance', 'যাতায়াত ভাতা', SalaryHead::EARNING, SalaryHead::FIXED, false, 40],
            ['MOBILE', 'Mobile Allowance', 'মোবাইল ভাতা', SalaryHead::EARNING, SalaryHead::FIXED, false, 50],
            ['PF', 'Provident Fund', 'ভবিষ্য তহবিল', SalaryHead::DEDUCTION, SalaryHead::PERCENT_OF_BASIC, false, 10],
            ['ADVANCE', 'Advance Recovery', 'অগ্রিম কর্তন', SalaryHead::DEDUCTION, SalaryHead::FIXED, false, 20],
        ];

        /*
         * প্রতিটা খাতের হিসাব-খাত সাথেই বসে।
         *
         * ── কেন বসানো, খালি রেখে দেওয়া নয় ──────────────────────────
         * খালি থাকলে বেতন পোস্ট করার সময় সবগুলো ফলব্যাকে গিয়ে পড়ত, আর
         * ভবিষ্য তহবিল "প্রদেয় বেতন"-এ মিশে যেত। তখন "তহবিলে কত জমা
         * দিতে হবে" প্রশ্নের উত্তর খতিয়ানে আর থাকত না।
         *
         * পুরনো কোম্পানির ছকে ২১৩১ বা ১১৩০ না থাকলে সেগুলো null থাকে,
         * আর পোস্টিং তখন ফলব্যাকে চলে — কাজ থামে না।
         */
        $accounts = [
            'PF' => StandardChart::PROVIDENT_FUND_PAYABLE,
            'ADVANCE' => StandardChart::EMPLOYEE_ADVANCE,
        ];

        $made = 0;

        foreach ($rows as [$code, $en, $bn, $kind, $calculation, $isBasic, $order]) {
            if (SalaryHead::query()->where('code', $code)->withTrashed()->exists()) {
                continue;
            }

            $accountCode = $accounts[$code]
                ?? ($kind === SalaryHead::EARNING
                    ? StandardChart::SALARY_EXPENSE
                    : StandardChart::SALARY_PAYABLE);

            SalaryHead::create([
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                'kind' => $kind,
                'calculation' => $calculation,
                'is_basic' => $isBasic,
                'account_id' => Account::query()->where('code', $accountCode)->value('id'),

                /*
                 * ভবিষ্য তহবিল ও অগ্রিম অনুপস্থিতির ভাগে কমে না।
                 *
                 * তিন দিন কামাই করলে বেতন কমে, কিন্তু অগ্রিমের কিস্তি
                 * কমে না — ধার তো পুরোটাই নেওয়া হয়েছিল।
                 */
                'prorated_by_attendance' => $kind === SalaryHead::EARNING,
                'sort_order' => $order,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            $made++;
        }

        return $made;
    }

    /**
     * মূল বেতন একটাই — নতুনটা এলে পুরনোটার পতাকা নামে।
     */
    private function keepOneBasicOnly(SalaryHead $head): void
    {
        if (! $head->is_basic) {
            return;
        }

        SalaryHead::query()
            ->where('is_basic', true)
            ->whereKeyNot($head->getKey())
            ->get()
            ->each(fn (SalaryHead $other) => $other->forceFill(['is_basic' => false])->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertBasicIsAnEarning(array $data): void
    {
        if (($data['is_basic'] ?? false) && ($data['kind'] ?? null) !== SalaryHead::EARNING) {
            throw ValidationException::withMessages([
                'is_basic' => __('hr::validation.basic_must_be_an_earning'),
            ]);
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => __('hr::validation.code_required'),
            ]);
        }

        $taken = SalaryHead::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('hr::validation.code_taken', ['code' => $code]),
            ]);
        }
    }
}
