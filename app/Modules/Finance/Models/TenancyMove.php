<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ভাড়াটের টাকার এক নড়াচড়া — প্রতিটা একটা ভাউচার ([[TenancyService]]):
 *
 *     জামানত নেওয়া      Dr নগদ/ব্যাংক  / Cr ২১৫৫
 *     ভাড়া আদায়         Dr নগদ/ব্যাংক  / Cr ১১২৫
 *     জামানত থেকে কাটা   Dr ২১৫৫        / Cr ১১২৫
 *     জামানত ফেরত        Dr ২১৫৫        / Cr নগদ/ব্যাংক
 */
class TenancyMove extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const DEPOSIT_IN = 'deposit_in';

    public const RENT = 'rent';

    public const FROM_DEPOSIT = 'from_deposit';

    public const REFUND = 'refund';

    /** @var list<string> */
    public const KINDS = [self::DEPOSIT_IN, self::RENT, self::FROM_DEPOSIT, self::REFUND];

    /** ⓘ যেগুলো বকেয়া শোধ করে */
    public const SETTLES = [self::RENT, self::FROM_DEPOSIT];

    protected $table = 'fin_tenancy_moves';

    protected $fillable = [
        'company_id', 'branch_id', 'tenancy_id', 'kind', 'moved_on', 'amount', 'money_account_id', 'voucher_id', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'moved_on' => 'date',
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

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }
}
