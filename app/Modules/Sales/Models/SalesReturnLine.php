<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\ReasonCode;
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

        /*
         * ⭐ NEXUS §২৪ — লাইনের নিজের কারণ, নোট আর লট।
         * ⚠️ তালিকায় না থাকলে সেবা পাঠালেও Eloquent নীরবে ফেলে দিত।
         */
        'reason_code_id', 'reason_note', 'batch_id',

        // ⭐ ফ্রি বা উপহারের মাল — ফ্রি ভাণ্ডারে ফেরে, শূন্য দামে (২৭ সেপ্টেম্বর ২০২৬)
        'free_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
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

    /** লাইনের নিজের কারণ — খালি মানে হেডারেরটাই। */
    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    /** কোন লটের মাল ফিরল — মেয়াদোত্তীর্ণ মালে সরবরাহকারীর দাবি আর রিকল এটা ধরেই। */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }
}
