<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\HasPublicId;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * আদেশের একটা লাইন।
 *
 * `ordered_qty` জমা থাকে কারণ ওটা একটা ঘোষণা — কোনো চলাচলের যোগফল নয়।
 * `received_qty` জমা থাকে না, ওটা চালানের লাইনগুলো থেকে গোনা হয়; জমা
 * রাখলে একদিন গোনার সাথে মিলত না, আর তখন "কত বাকি" প্রশ্নের দুইটা উত্তর
 * থাকত।
 *
 * company_id নেই — বাবার আছে। সন্তান-টেবিলে আলাদা করে রাখলে দুইটা একদিন
 * আলাদা হয়ে যেতে পারত।
 */
class PurchaseOrderLine extends Model
{
    use HasPublicId;

    protected $table = 'pur_order_lines';

    protected $fillable = [
        'purchase_order_id', 'product_id', 'ordered_qty', 'rate',
        'discount', 'tax', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class, 'purchase_order_line_id');
    }

    /** এই লাইনের বিপরীতে এ পর্যন্ত কত মাল এসেছে। */
    public function receivedQty(): string
    {
        $received = $this->receiptLines()
            ->whereHas('receipt', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('received_qty');

        return (string) ($received ?: '0');
    }

    /** আর কত আসা বাকি — ঋণাত্মক হয় না, বেশি এলে শূন্য। */
    public function pendingQty(): string
    {
        $pending = bcsub((string) $this->ordered_qty, $this->receivedQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
