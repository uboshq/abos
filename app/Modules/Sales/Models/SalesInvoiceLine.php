<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** বিলের একটা লাইন। */
class SalesInvoiceLine extends Model
{
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_invoice_lines';

    protected $fillable = [
        'sales_invoice_id', 'product_id', 'delivery_challan_line_id',
        'qty', 'entered_qty', 'entered_unit_id',
        'rate', 'price_variance', 'discount', 'tax', 'tax_variance', 'amount', 'unit_cost',
        'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            /*
             * ব্যতিক্রমের সংখ্যাগুলোও টাকা — তাই decimal, string নয়।
             *
             * cast না দিলে মানটা string হয়ে ফিরত, আর কেউ দুইটা সারির
             * পার্থক্য যোগ করতে গেলে PHP ওটাকে float বানিয়ে ফেলত।
             * এই রিপোতে টাকা কোনোদিন float হয় না ([[MoneyIsNeverAFloatTest]])।
             */
            'price_variance' => 'decimal:4',
            'tax_variance' => 'decimal:4',
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
