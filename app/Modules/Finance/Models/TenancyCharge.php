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
 * ⭐ ভাড়াটের এক মাসের ভাড়া দাবি — মাসের শুরুতে Dr ১১২৫ / Cr ভাড়া আয় (মালিকের সিদ্ধান্ত প্র৩; [[TenancyService::charge()]])।
 *
 * ⓘ অঙ্কটা বসার দিনের দর — পরে দর বদলালেও এই মাস নড়ে না।
 */
class TenancyCharge extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_tenancy_charges';

    protected $fillable = ['company_id', 'branch_id', 'tenancy_id', 'for_month', 'amount', 'voucher_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
