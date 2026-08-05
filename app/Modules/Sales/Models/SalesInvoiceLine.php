<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** বিলের একটা লাইন। */
class SalesInvoiceLine extends Model
{
    use HasPublicId;

    protected $table = 'sal_invoice_lines';

    protected $fillable = [
        'sales_invoice_id', 'product_id', 'delivery_challan_line_id',
        'qty', 'rate', 'discount', 'tax', 'amount', 'unit_cost',
        'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function challanLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallanLine::class, 'delivery_challan_line_id');
    }
}
