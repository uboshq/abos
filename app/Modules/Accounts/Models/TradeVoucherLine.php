<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ⭐ স্টক ছাড়া বিক্রয়/ক্রয় ভাউচারের সারি — পণ্য (কেবল হিসাবে), সেবা বা খাত ([[TradeVoucher]]) */
class TradeVoucherLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_trade_voucher_lines';

    protected $fillable = [
        'company_id', 'trade_voucher_id', 'line_no', 'line_type', 'product_id', 'item_name', 'description',
        'qty', 'unit', 'rate', 'amount', 'tax_id', 'vat_rate', 'vat_amount', 'total', 'account_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(TradeVoucher::class, 'trade_voucher_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
