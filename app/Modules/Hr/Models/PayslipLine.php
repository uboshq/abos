<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * শিটের একটা সারি — কোন খাতে কত।
 *
 * খাতের নামটাও কপি হয়ে বসে, শুধু id নয়: খাত নিষ্ক্রিয় হলে বা নাম
 * বদলালে পুরনো শিট ছাপার সময় সারিটা নামহীন হয়ে যেত।
 */
class PayslipLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_payslip_lines';

    /**
     * ⭐ তৃতীয় ধরন — প্রতিষ্ঠানের অংশ: কর্মীর আয়ও নয়, কর্তনও নয় (ভবিষ্য তহবিলে প্রতিষ্ঠানের চাঁদা; a6, ২১ অক্টোবর ২০২৬,
     * [[ProvidentFund]])। ⓘ বেতনের স্লিপে দেখা যায় ("প্রতিষ্ঠানের অংশ"), কিন্তু মোট আয়, কর্তন আর নিটে ঢোকে না; খাতায় খরচ
     * ডেবিট, তহবিলের দায় ক্রেডিট ([[PayrollService::ledgerLines()]])। ⛔ খাতের ধরন ([[SalaryHead::KINDS]]) নয় — কোনো খাত
     * এই ধরনের হয় না, সারিটা হিসাব থেকে বসে।
     */
    public const EMPLOYER = 'employer';

    /** ⓘ হিসাব থেকে বসা সারির কোড — খাতের কোড নয়, তাই কোনো খাতের সাথে মেলে না */
    public const PF_EMPLOYER_CODE = 'PF_ER';

    protected $fillable = [
        'company_id', 'payslip_id', 'salary_head_id',
        'head_code', 'head_name_en', 'head_name_bn',
        'kind', 'amount', 'sort_order', 'account_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** @return BelongsTo<SalaryHead, $this> */
    public function salaryHead(): BelongsTo
    {
        return $this->belongsTo(SalaryHead::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** ব্যবহারকারীর ভাষায় খাতের নাম — শিটে যা লেখা ছিল। */
    public function headName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->head_name_bn)) {
            return $this->head_name_bn;
        }

        return (string) $this->head_name_en;
    }

    public function isEarning(): bool
    {
        return $this->kind === SalaryHead::EARNING;
    }

    /** ⓘ প্রতিষ্ঠানের অংশ — কর্মীর নিটের বাইরে ([[EMPLOYER]]) */
    public function isEmployerShare(): bool
    {
        return $this->kind === self::EMPLOYER;
    }
}
