<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * উপহারের সারি — অন্য একটা পণ্য, বিক্রির জন্য নয়।
 *
 * নমুনায় ডিটারজেন্ট পাউডার ২ কেজির সাথে বালতি ১৮ লিটার। বালতিটা কেনা হয়নি,
 * বেচাও হচ্ছে না — তাই তার কোনো দর নেই, আর বিলের মোটেও সে যোগ হয় না।
 *
 * কোন পণ্যের সাথে গেল সেটা লেখা থাকে, কারণ প্রস্তুতকারকের কাছে হিসাব দিতে
 * ঠিক ওই জোড়াটাই লাগে: "কত ডিটারজেন্টের বিপরীতে কত বালতি"।
 *
 * পরিমাণটা ফ্রি ভাণ্ডার থেকে কাটে, বিক্রির মজুদ থেকে নয়।
 */
class DeliveryChallanGiftLine extends Model
{
    use HasPublicId;

    protected $table = 'sal_challan_gift_lines';

    protected $fillable = [
        'delivery_challan_id', 'product_id', 'against_product_id',
        'qty', 'remarks', 'line_no',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
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
