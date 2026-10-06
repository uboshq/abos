<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক জমার এক মাসের অর্জিত মুনাফা — অর্থ-মডিউলের পরিকল্পনা ৪.২ ([[DepositAccrualService]])।
 *
 * ⓘ টাকা খাতায় থাকে ভাউচারে (Dr ১১৬৫ অর্জিত মুনাফা / Cr ৪৩১০ সুদ আয়), আর পরের মাসের প্রথম দিনে উল্টো ভাউচারে ফেরে; এই সারি
 * কেবল বলে কোন মাস, কোন আসলে, কোন হারে, কত — যাতে একই মাস দুবার না বসে। ব্যাংক ঋণের সুদ জমার ([[InterestAccrual]]) জোড়া।
 */
class DepositAccrual extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_deposit_accruals';

    protected $fillable = [
        'company_id', 'branch_id', 'deposit_id', 'for_month', 'base', 'rate', 'amount',
        'voucher_id', 'reversal_voucher_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'base' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function reversalVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'reversal_voucher_id');
    }
}
