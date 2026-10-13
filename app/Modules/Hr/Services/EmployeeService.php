<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * কর্মী যোগ, সংশোধন ও অবসান।
 *
 * তিনটা নিয়ম যা ফর্মের যাচাই দিয়ে ধরা যায় না, কারণ এগুলো অন্য সারির
 * সাথে বা নিজের অন্য ঘরের সাথে সম্পর্কিত: কোড অনন্য, ছাড়ার তারিখ যোগ
 * দেওয়ার আগে নয়, আর টাকা পাঠানোর পথ অনুযায়ী দরকারি ঘরগুলো ভরা।
 */
final class EmployeeService
{
    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            /*
             * কোড না দিলে সিরিজ থেকে — মালিকের নির্দেশ (২০২৬-০৮-০৭):
             * "সব জায়গায় কোড অটো বসবে"।
             *
             * নম্বরটা ট্রানজেকশনের ভেতরে, নাহলে কর্মী সেভ ব্যর্থ হলেও
             * কোডটা খরচ হয়ে যেত। হাতে দিলে সেটাই থাকে — পুরনো খাতার
             * কর্মী নম্বর (যেমন ৪৭) ধরে রাখা যায়।
             */
            $code = trim((string) ($data['code'] ?? ''));
            $code = $code !== '' ? $code : $this->numbers->next('EMP');

            $this->assertCodeIsFree($code);
            $this->assertUserIsFree($data['user_id'] ?? null);
            $this->assertDatesMakeSense($data);
            $this->assertPaymentDetailsAreComplete($data);

            $employee = Employee::create([
                ...$data,
                'code' => $code,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            return $employee->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $code = isset($data['code']) ? trim((string) $data['code']) : $employee->code;

            if ($code !== $employee->code) {
                $this->assertCodeIsFree($code, $employee->id);
            }

            if (array_key_exists('user_id', $data)) {
                $this->assertUserIsFree($data['user_id'], (int) $employee->id);
            }

            $this->assertDatesMakeSense([...$employee->only(['joining_date', 'leaving_date']), ...$data]);
            $this->assertPaymentDetailsAreComplete([...$employee->attributesToArray(), ...$data]);

            /*
             * ⛔ চালু থাকা ছাড়ার তারিখের সাথে মেলে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[AGoneEmployeeCannotAskForMoneyTest]])।
             * ⓘ আগে সম্পাদনা `is_active` ছুঁত না: ভুলে অবসান দেওয়া বা আবার নেওয়া কর্মীর ছাড়ার তারিখ মুছলেও তিনি চিরকাল নিষ্ক্রিয়
             * থাকতেন — দাবি "কর্মী চলে গেছেন", ছুটি আর ব্যবস্থাপকের তালিকায় নেই, ঠিক করার কোনো ঘর নেই। এখন তারিখ খালি হলে চালু,
             * আর তারিখ থাকলে কেবল সেটা পেরোলে নিষ্ক্রিয়।
             */
            $active = array_key_exists('leaving_date', $data) ? ['is_active' => $this->stillHere($data['leaving_date'])] : [];

            $employee->update([...$data, 'code' => $code, ...$active]);

            return $employee->fresh();
        });
    }

    /**
     * চাকরির অবসান — মোছা নয় (নিয়ম ৫)।
     *
     * ── কেন মোছা যায় না ─────────────────────────────────────────────
     * গত বছরের বেতনশিটে নামটা থাকতেই হবে। কর্মী মুছে ফেললে ওই শিটটা
     * আর মেলাত না, আর "কাকে কত দেওয়া হয়েছিল" প্রশ্নের উত্তর হারাত।
     *
     * তারিখটা লাগে, কারণ ছাড়ার মাসেও বেতন হয় — ওই মাসের কিছু দিন সে
     * কাজ করেছে। তারিখ ছাড়া কেবল "নিষ্ক্রিয়" লিখলে ওই মাসের বেতনটাই
     * বাদ পড়ে যেত।
     */
    public function endEmployment(Employee $employee, string $leavingDate): Employee
    {
        $date = Carbon::parse($leavingDate);

        if ($employee->joining_date !== null && $date->lt($employee->joining_date)) {
            throw ValidationException::withMessages([
                'leaving_date' => __('hr::validation.leaving_before_joining'),
            ]);
        }

        // ⛔ আগে থেকে লেখা ছাড়ার দিন (নোটিশ) — সেই দিন পেরোনোর আগে নিষ্ক্রিয় নয় (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬)
        $employee->forceFill([
            'leaving_date' => $date->toDateString(),
            'is_active' => $this->stillHere($date->toDateString()),
        ])->save();

        return $employee->fresh();
    }

    /**
     * ছাড়ার তারিখ ধরে চালু কি না — খালি হলে চালু; ছাড়ার দিনটা পর্যন্ত চালু, পরের দিন থেকে নয় (দাবি আর হাজিরার একই নিয়ম,
     * [[ExpenseClaimService::submit()]])।
     */
    private function stillHere(mixed $leavingDate): bool
    {
        return blank($leavingDate) || ! Carbon::parse((string) $leavingDate)->startOfDay()->lt(Carbon::today());
    }

    /**
     * ⛔ এক লগইন এক কর্মীতে — তালার ভিতরে আবার দেখা (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[AnEmployeeAsksForMoneyTest]])।
     *
     * ⓘ ফর্মের `unique` নিয়মটা লেনদেনের বাইরে, আর ডাটাবেজে (company_id, user_id)-এ অনন্য সূচি নেই: দুইটা HR ট্যাব একসাথে সেভ
     * করলে দুইজনেই "জোড়া নেই" পড়ে একই লগইন দুই কর্মীতে বাঁধতেন, আর দাবির পাতা যেকোনো একজনকে ধরত। এখন ব্যবহারকারীর সারিতে
     * তালা (সারিটা আগে থেকেই আছে — না-থাকা কর্মীর সারিতে তালা দেওয়া যায় না), তারপর আবার খোঁজা।
     */
    private function assertUserIsFree(mixed $userId, ?int $exceptId = null): void
    {
        if (blank($userId)) {
            return;
        }

        User::query()->whereKey((int) $userId)->lockForUpdate()->value('id');

        $taken = Employee::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('user_id', (int) $userId)
            ->whereNull('deleted_at')
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'user_id' => __('hr::validation.user_taken'),
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

        $taken = Employee::query()
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDatesMakeSense(array $data): void
    {
        $joining = $data['joining_date'] ?? null;
        $leaving = $data['leaving_date'] ?? null;

        if (blank($joining) || blank($leaving)) {
            return;
        }

        if (Carbon::parse((string) $leaving)->lt(Carbon::parse((string) $joining))) {
            throw ValidationException::withMessages([
                'leaving_date' => __('hr::validation.leaving_before_joining'),
            ]);
        }
    }

    /**
     * টাকা পাঠানোর পথ অনুযায়ী দরকারি ঘরগুলো ভরা আছে কি না।
     *
     * ── কেন এটা যাচাই হয় ────────────────────────────────────────────
     * "ব্যাংক" বেছে হিসাব নম্বর খালি রাখলে বেতনের দিনে ব্যাংক ফাইলে ওই
     * সারিটা খালি যেত — আর ব্যাংক পুরো ফাইলটাই ফেরত দিত। একজনের ভুলে
     * সবার বেতন আটকে যাওয়াটা বেতনের দিনে সবচেয়ে খারাপ ঘটনা।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPaymentDetailsAreComplete(array $data): void
    {
        $method = $data['payment_method'] ?? 'cash';

        if ($method === 'bank' && blank($data['bank_account_no'] ?? null)) {
            throw ValidationException::withMessages([
                'bank_account_no' => __('hr::validation.bank_account_required'),
            ]);
        }

        if ($method === 'mfs' && blank($data['mfs_number'] ?? null)) {
            throw ValidationException::withMessages([
                'mfs_number' => __('hr::validation.mfs_number_required'),
            ]);
        }
    }
}
