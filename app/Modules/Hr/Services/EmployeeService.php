<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
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

            $this->assertDatesMakeSense([...$employee->only(['joining_date', 'leaving_date']), ...$data]);
            $this->assertPaymentDetailsAreComplete([...$employee->attributesToArray(), ...$data]);

            $employee->update([...$data, 'code' => $code]);

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

        $employee->forceFill([
            'leaving_date' => $date->toDateString(),
            'is_active' => false,
        ])->save();

        return $employee->fresh();
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
