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
 * ক্রয়ে উপহারের সারি — মিল যা সাথে দিল, অথচ বিলে নেই।
 *
 * নমুনা: দশ কার্টন সাবানের সাথে একটা বালতি। বালতিটার কোনো দর নেই, বিলের
 * মোটে সে যোগ হয় না — কিন্তু গুদামে সে ঠিকই ঢোকে, আর একদিন বিক্রিও হয়।
 *
 * কোন পণ্যের বিপরীতে এল সেটা লেখা থাকে, কারণ **সাবানের আসল ক্রয়দর**
 * বের করতে ঠিক ওই জোড়াটাই লাগে: দশ কার্টনের টাকা এগারো কার্টনে ভাগ হয়।
 *
 * ⓘ পরিমাণটা ফ্রি ভাণ্ডারে ঢোকে, কেনা মজুদে নয় — মালিকের নির্দেশ:
 * *"stock-এ free আলাদা manage হবে"*।
 *
 * ⚠️ বিক্রয়ের দিকে হুবহু এই গড়ন
 * ([[App\Modules\Sales\Models\DeliveryChallanGiftLine]])। দুইটা আলাদা হয়ে
 * গেলে "মিল যা দিল" আর "ডিলারকে যা দেওয়া হলো" আর এক ভাষায় পড়া যেত না।
 */
class PurchaseBillGiftLine extends Model
{
    use HasEnteredPack;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_bill_gift_lines';

    protected $fillable = [
        'purchase_bill_id', 'product_id', 'against_product_id',
        'qty', 'entered_qty', 'entered_unit_id', 'remarks', 'line_no',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'entered_qty' => 'decimal:4'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function againstProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'against_product_id');
    }
}
