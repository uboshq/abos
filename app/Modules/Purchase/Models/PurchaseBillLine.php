<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Inventory\Concerns\HasEnteredPack;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * বিলের একটা লাইন।
 */
class PurchaseBillLine extends Model
{
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_bill_lines';

    protected $fillable = [
        'purchase_bill_id', 'product_id', 'purchase_receipt_line_id', 'purchase_order_line_id',
        'qty', 'free_qty', 'entered_qty', 'entered_unit_id',

        /*
         * ফ্রি পরিমাণ কোন প্যাকে লেখা হয়েছিল — উপরের জোড়ার হুবহু নকল।
         *
         * ⚠️ এই দুইটা নাম এখানে না লিখলে কিছুই ভাঙত না, কেবল সারি
         * দুইটা **নীরবে খালি** যেত — আর "১ কার্টন ফ্রি" আবার "১২ পিস"
         * হয়ে ফিরত, ঠিক যে ভুলটা মাইগ্রেশনটা সারাতে এসেছে।
         */
        'entered_free_qty', 'free_unit_id',
        'batch_no', 'expiry_date', 'mrp',
        'rate', 'sales_price', 'pricing_anchor', 'pricing_pct',
        'discount', 'tax', 'tax_variance', 'amount', 'line_no', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'expiry_date' => 'date',
            'mrp' => 'decimal:4',
            'entered_qty' => 'decimal:4',
            'entered_free_qty' => 'decimal:4',
            'rate' => 'decimal:4',

            // সরাসরি ক্রয়ের পর্দায় বসানো বিক্রয়মূল্য — এটাও টাকা, তাই
            // এটাও decimal। cast ছাড়া ছিল, আর তখন markup/margin-এর
            // অঙ্কে একটা `+` লিখলেই PHP float বানিয়ে ফেলত।
            'sales_price' => 'decimal:4',
            'pricing_pct' => 'decimal:4',

            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'amount' => 'decimal:4',

            /*
             * ব্যতিক্রমের সংখ্যাটাও টাকা — তাই decimal, string নয়।
             *
             * cast না দিলে মানটা string হয়ে ফিরত, আর কেউ যোগ করতে
             * গেলে PHP ওটাকে float বানিয়ে ফেলত। এই রিপোতে টাকা
             * কোনোদিন float হয় না ([[MoneyIsNeverAFloatTest]])।
             */
            'tax_variance' => 'decimal:4',
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

    /**
     * চালান ছাড়া সরাসরি আদেশের বিপরীতে বিল।
     *
     * দুইটা জোড়া পরস্পর-বিকল্প: সারিটা হয় চালান ধরে আসে, নয় আদেশ ধরে,
     * নয় কোনোটাই। আদেশ ধরে এলে মালটা এই বিল কনফার্ম করার সময়েই গুদামে
     * ঢোকে — ঠিক সরাসরি ক্রয়ের মতো, কারণ মাল গ্রহণের কোনো কাগজ হয়নি।
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
