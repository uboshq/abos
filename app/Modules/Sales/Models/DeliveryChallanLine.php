<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** চালানের একটা লাইন — এক পণ্য, যত গেল। */
class DeliveryChallanLine extends Model
{
    use HasPublicId;

    protected $table = 'sal_challan_lines';

    protected $fillable = [
        'delivery_challan_id', 'product_id', 'sales_order_line_id',
        'delivered_qty', 'rate', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'delivered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'delivery_challan_line_id');
    }

    /** এই লাইনের কতটুকু বিলে এসেছে। */
    public function invoicedQty(): string
    {
        $invoiced = $this->invoiceLines()
            ->whereHas('invoice', fn ($q) => $q->where('status', '<>', 'cancelled'))
            ->sum('qty');

        return (string) ($invoiced ?: '0');
    }

    public function uninvoicedQty(): string
    {
        $pending = bcsub((string) $this->delivered_qty, $this->invoicedQty(), 4);

        return bccomp($pending, '0', 4) > 0 ? $pending : '0.0000';
    }
}
