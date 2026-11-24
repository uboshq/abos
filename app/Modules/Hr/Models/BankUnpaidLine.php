<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Contracts\ShownInOwnerSelfReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ "ব্যাংক এই লাইন দেয়নি" — বাতিল রানের ব্যাংক ফাইলে যাওয়া বকেয়া মজুরির একটা লাইন, দ্বিতীয় সইয়ের অপেক্ষায় বা তার ফল (fe-র সিদ্ধান্ত ২,
 * ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩; [[HeldWages::askBankUnpaid()]])।
 *
 * ⓘ `pending` — সইয়ের অপেক্ষায়; `released` — সই পড়ল, মজুরি আবার দেওয়ার তালিকায় (একবারই); `rejected` — "না", সারি যেমন ছিল;
 * `cancelled` — লেখক ফিরিয়ে নিলেন।
 */
class BankUnpaidLine extends Model implements ShownInOwnerSelfReport
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    public const PENDING = 'pending';

    public const RELEASED = 'released';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'hr_bank_unpaid_lines';

    protected $fillable = [
        'company_id', 'employee_id', 'payslip_id', 'held_wage_payment_id', 'payroll_run_id', 'amount', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** ⓘ যে বাতিল রানের ব্যাংক ফাইল লাইনটা বহন করেছিল */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** ⓘ মালিকের নিজে-অনুমোদিত কাগজের রিপোর্টে — লিংক কর্মীর পাতায় */
    public static function ownerSelfSource(): array
    {
        return [Employee::drillSourceType(), 'employee_id'];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
