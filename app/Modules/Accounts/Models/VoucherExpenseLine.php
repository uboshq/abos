<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * খরচের ব্যবসার লাইন — "কী কিনলাম, কত, কোন খাতে, কোন কেন্দ্রে, কত ভ্যাট" (খরচের ভাউচার ১০০%, ভাগ B; fe অনুমোদিত §১,
 * ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ জাবেদার সারি ([[VoucherLine]]) এই লাইন থেকে বানানো হয় — এক লাইন থেকে খরচের সারি, আর ভ্যাট সমন্বয়যোগ্য হলে আলাদা সারি
 * 1139-এ ([[StandardChart::INPUT_VAT_RECOVERABLE]]), আগাম হলে আগামের খাতে। ⓘ সত্য লাইন; মাথার `gross_amount` কেবল সবার যোগ।
 *
 * ⓘ `vat_tax_id` কেবল id — করের সারি MasterData-র, আর MasterData আগে থেকেই Accounts-এর উপর দাঁড়িয়ে; এখানে সম্পর্ক বাঁধলে চক্র হত।
 * হার আর খাত পোস্টিংয়ের সময় সেবা পড়ে; `vat_amount` কাগজ যা বলেছিল তা-ই থাকে।
 */
final class VoucherExpenseLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_voucher_expense_lines';

    protected $fillable = [
        'voucher_id', 'sort', 'expense_account_id', 'cost_center_id', 'branch_id', 'description',
        'amount', 'vat_tax_id', 'vat_amount', 'vat_recoverable', 'fx_amount',
        'is_prepaid', 'prepaid_months', 'prepaid_start',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'fx_amount' => 'decimal:4',
            'vat_recoverable' => 'boolean',
            'is_prepaid' => 'boolean',
            'prepaid_start' => 'date',
        ];
    }

    /** লাইনের মোট — মূল্য + ভ্যাট, bcmath-এ */
    public function total(): string
    {
        return bcadd((string) $this->amount, (string) $this->vat_amount, 4);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
