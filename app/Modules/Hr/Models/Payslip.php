<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একজন কর্মীর এক মাসের বেতনশিট।
 *
 * ব্যাংকের ঘরগুলো এখানে কপি হয়ে বসে। কর্মীর সারি থেকে পড়লে আজ কেউ
 * হিসাব নম্বর শুধরালে গত মাসের ব্যাংক ফাইলটাও বদলে যেত — অথচ টাকা
 * তো পুরনো নম্বরেই গিয়েছিল।
 */
class Payslip extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;
    use SoftDeletes;

    protected $table = 'hr_payslips';

    /** ⭐ আটকে থাকা স্লিপের বকেয়া কোন ব্যাংক ফাইলে গেল — একবারই ([[HeldWagePayment]]; fe, ২১ অক্টোবর ২০২৬) *
     * @return HasOne<HeldWagePayment, $this>
     */
    public function heldPayment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(HeldWagePayment::class, 'payslip_id');
    }

    protected $fillable = [
        'company_id', 'payroll_run_id', 'employee_id',
        'gross', 'deductions', 'net',
        'payment_method', 'bank_name', 'bank_account_name',
        'bank_account_no', 'bank_routing_no', 'mfs_number',
    ];

    protected function casts(): array
    {
        return [
            /*
             * বেতন পাঠানোর তথ্য — কর্মীর সারি থেকে এখানে কপি হয়, তাই
             * এনক্রিপশনও সাথে আসতে হয়। এক জায়গায় ঢেকে অন্য জায়গায়
             * খোলা রাখলে ঢাকাটা কেবল কাগজে থাকত।
             */
            'bank_account_no' => 'encrypted',
            'bank_routing_no' => 'encrypted',
            'mfs_number' => 'encrypted',

            'gross' => 'decimal:4',
            'deductions' => 'decimal:4',
            'net' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<PayslipLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)
            // ⛔ আয় আগে, তারপর কর্তন, শেষে প্রতিষ্ঠানের অংশ — নামের বর্ণক্রমে 'deduction' আগে পড়ত (ফোনের চুক্তি; APayslipSaysWhetherItIsSalaryOrBonusTest)
            ->orderByRaw('CASE kind WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [SalaryHead::EARNING, SalaryHead::DEDUCTION])
            ->orderBy('sort_order');
    }

    /** @return HasMany<PayslipLine, $this> */
    public function earnings(): HasMany
    {
        return $this->lines()->where('kind', SalaryHead::EARNING);
    }

    /** @return HasMany<PayslipLine, $this> */
    public function deductionLines(): HasMany
    {
        return $this->lines()->where('kind', SalaryHead::DEDUCTION);
    }
}
