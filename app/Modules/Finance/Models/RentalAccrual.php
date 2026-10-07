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
 * ⭐ এক চুক্তির এক মাসের প্রদেয় ভাড়া — মাসের শুরুতে খরচে বসা অঙ্ক (মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬; [[RentalAccrualService]])।
 *
 * ⓘ দেওয়ার দিন মাসের সারি ([[RentalAdjustment]]) এই অঙ্কটাই ২১৪১ থেকে শোধ করে; দর বদলালেও এখানে যা বসেছে সেটাই প্রদেয়।
 */
class RentalAccrual extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_rental_accruals';

    protected $fillable = [
        'company_id', 'branch_id', 'rental_contract_id', 'for_month', 'amount', 'voucher_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
