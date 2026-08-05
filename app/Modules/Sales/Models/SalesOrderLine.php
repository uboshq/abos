<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** আদেশের একটা লাইন। */
class SalesOrderLine extends Model
{
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_order_lines';

    protected $fillable = [
        'sales_order_id', 'product_id', 'ordered_qty', 'rate',
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
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function challanLines(): HasMany
    {
        return $this->hasMany(DeliveryChallanLine::class, 'sales_order_line_id');
    }

    /** এই লাইনের বিপরীতে এ পর্যন্ত কত মাল গেছে। */
    public function deliveredQty(): string
    {
        $delivered = $this->challanLines()
            ->whereHas('challan', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('delivered_qty');

        return (string) ($delivered ?: '0');
    }

    /** আর কত দেওয়া বাকি — ঋণাত্মক হয় না। */
    public function pendingQty(): string
    {
        $pending = bcsub((string) $this->ordered_qty, $this->deliveredQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
