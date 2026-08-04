<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\HasPublicId;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * বিলের একটা লাইন।
 */
class PurchaseBillLine extends Model
{
    use HasPublicId;

    protected $table = 'pur_bill_lines';

    protected $fillable = [
        'purchase_bill_id', 'product_id', 'purchase_receipt_line_id',
        'qty', 'rate', 'discount', 'tax', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
