<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * বাজেটের এক সারি — এক খাত, এক মাস, আর চাইলে এক বিভাগ।
 *
 * ⓘ কেন এই আকার — মাইগ্রেশন
 * `2026_11_23_100000_the_months_plan_lived_in_nobodys_book`-এ।
 */
class Budget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_budgets';

    protected $fillable = [
        'company_id', 'year', 'month', 'account_id', 'cost_center_id', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
