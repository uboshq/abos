<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ক্রয় ফেরতের একটা লাইন। */
class PurchaseReturnLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_return_lines';

    protected $fillable = [
        'company_id', 'purchase_return_id', 'product_id', 'purchase_bill_line_id',
        'qty', 'rate', 'tax', 'amount', 'line_no',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseBillLine::class, 'purchase_bill_line_id');
    }
}
