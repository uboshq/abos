<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * চালানের একটা লাইন — এক পণ্য, যত এসেছে।
 */
class PurchaseReceiptLine extends Model
{
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_receipt_lines';

    protected $fillable = [
        'purchase_receipt_id', 'product_id', 'purchase_order_line_id',
        'received_qty', 'entered_qty', 'entered_unit_id',
        'rate', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'received_qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class, 'purchase_receipt_line_id');
    }

    /**
     * এই লাইনের কতটুকু বিলে এসেছে।
     *
     * এটাই ২১৬০ খাতটা শূন্যে ফেরার হিসাব: যতটুকু এসেছে তার সবটুকু বিল হলে
     * ওই লাইনের আর কিছু ঝুলে থাকে না।
     */
    public function billedQty(): string
    {
        $billed = $this->billLines()
            ->whereHas('bill', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('qty');

        return (string) ($billed ?: '0');
    }

    public function unbilledQty(): string
    {
        $pending = bcsub((string) $this->received_qty, $this->billedQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
