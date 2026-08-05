<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
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

    protected $table = 'hr_payslip_lines';

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
}
