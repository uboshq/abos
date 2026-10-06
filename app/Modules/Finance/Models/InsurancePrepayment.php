<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক প্রিমিয়ামের কিস্তির এক মাসের অগ্রিম — অর্থ-মডিউলের পরিকল্পনা ৬.৩ ([[InsurancePrepaymentService]])।
 *
 * ⓘ টাকা খাতায় থাকে ভাউচারে (Dr 1136 অগ্রিম বীমা / Cr পরিশোধ ভাউচারের খরচের খাত), আর পরের মাসের প্রথম দিনে উল্টো
 * ভাউচারে ফেরে; এই সারি বলে কোন মাস, মেয়াদের কত দিন বাকি, কত — যাতে একই মাস দুবার না বসে। ⓘ কে বসাল আর কবে —
 * `created_by` আর নিরীক্ষার খাতা ([[IsAudited]])।
 */
class InsurancePrepayment extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_insurance_prepayments';

    protected $fillable = [
        'company_id', 'branch_id', 'policy_id', 'premium_id', 'for_month', 'days_left', 'days_total', 'amount',
        'expense_account_id', 'voucher_id', 'reversal_voucher_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'days_left' => 'integer',
            'days_total' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    public function premium(): BelongsTo
    {
        return $this->belongsTo(InsurancePremium::class, 'premium_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function reversalVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'reversal_voucher_id');
    }

    // ── Drillable — মাস শেষের ভাউচার থেকে পলিসিতে ফেরা ────────────────

    public static function drillSourceType(): string
    {
        return 'insurance_prepayment';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->policy?->policy_no;
    }

    public function drillLabel(): string
    {
        return __('finance::insurance.prepaid_title').' — '.$this->policy?->policy_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.insurance.show', ['policy' => $this->policy_id]];
    }
}
