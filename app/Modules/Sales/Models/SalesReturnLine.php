<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ফেরতের একটা লাইন — কোন পণ্য, কতটা, আর সেটা আবার বিক্রি করা যাবে কি না। */
class SalesReturnLine extends Model
{
    use BelongsToCompany;
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_return_lines';

    protected $fillable = [
        'company_id', 'sales_return_id', 'product_id', 'sales_invoice_line_id',
        'qty', 'entered_qty', 'entered_unit_id',
        'rate', 'tax', 'amount', 'to_hold', 'line_no',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',
            'to_hold' => 'boolean',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }
}
