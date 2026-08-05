<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
    use SoftDeletes;

    protected $table = 'hr_payslips';

    protected $fillable = [
        'company_id', 'payroll_run_id', 'employee_id',
        'gross', 'deductions', 'net',
        'payment_method', 'bank_name', 'bank_account_name',
        'bank_account_no', 'bank_routing_no', 'mfs_number',
    ];

    protected function casts(): array
    {
        return [
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
        return $this->hasMany(PayslipLine::class)->orderBy('kind')->orderBy('sort_order');
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
