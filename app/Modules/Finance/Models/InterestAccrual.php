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
 * ⭐ এক ঋণের এক মাসের সুদ জমা — অর্থ-মডিউলের পরিকল্পনা ৩.৩ ([[InterestAccrualService]])।
 *
 * ⓘ টাকা খাতায় থাকে ভাউচারে (Dr ৫৩১০ সুদ খরচ / Cr ২১৫০ প্রদেয় সুদ), আর পরের মাসের প্রথম দিনে উল্টো ভাউচারে ফেরে;
 * এই সারি কেবল বলে কোন মাস, কত দিন, কোন জেরে, কত — যাতে একই মাস দুবার না বসে।
 */
class InterestAccrual extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_interest_accruals';

    protected $fillable = [
        'company_id', 'branch_id', 'bank_facility_id', 'for_month', 'days', 'base', 'rate', 'amount',
        'voucher_id', 'reversal_voucher_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'days' => 'integer',
            'base' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(BankFacility::class, 'bank_facility_id');
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
